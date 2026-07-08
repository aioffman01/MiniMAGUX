#include <iostream>
#include <string>
#include <vector>
#include <queue>
#include <chrono>
#include <thread>
#include <mutex>
#include <condition_variable>
#include <fstream>
#include <sstream>
#include <iomanip>
#include <cstring>
#include <pcap.h>
#include <arpa/inet.h>
#include <netinet/ip.h>
#include <netinet/ip6.h>
#include <netinet/tcp.h>
#include <netinet/udp.h>
#include <netinet/ip_icmp.h>
#include <net/ethernet.h>
#include <mysql/mysql.h>
#include <unistd.h>
#include <signal.h>
#include <sys/types.h>

// Struct to store packet details (21 fields, including csv_line)
struct PacketRecord {
    long long timestamp;
    std::string interface_name;
    std::string src_mac;
    std::string dst_mac;
    unsigned int eth_type;
    unsigned int ip_ver;
    std::string src_ip;
    std::string dst_ip;
    unsigned int ip_ttl;
    unsigned int ip_proto;
    unsigned int src_port;
    unsigned int dst_port;
    unsigned long long tcp_seq;
    unsigned long long tcp_ack;
    std::string tcp_flags;
    unsigned int tcp_win;
    unsigned int udp_len;
    unsigned int icmp_type;
    unsigned int icmp_code;
    unsigned int payload_len;
    unsigned int csv_line; // 21st field: line number in CSV file
};

// Global configurations (with default values)
std::string csv_dir = "./csv_logs";
std::string target_interface = "any";
bool use_manticore = true;
std::string manticore_host = "127.0.0.1";
int manticore_port = 9306;

// Concurrency Variables (Producer-Consumer Pattern)
std::vector<PacketRecord> active_buffer;
std::mutex active_buffer_mutex;

std::queue<std::vector<PacketRecord>> work_queue;
std::mutex queue_mutex;
std::condition_variable queue_cv;

const std::string pid_file_path = "./collector.pid";
const std::string config_file_path = "collector.cfg";

pcap_t* pcap_handle = nullptr;
volatile sig_atomic_t keep_running = 1;

// Trim whitespace helper
void trim(std::string& s) {
    if (s.empty()) return;
    s.erase(0, s.find_first_not_of(" \t\r\n"));
    s.erase(s.find_last_not_of(" \t\r\n") + 1);
}

// Function to load configurations from CFG file
void load_config(const std::string& filepath) {
    std::ifstream file(filepath);
    if (!file.is_open()) {
        std::cout << "[Config] Using default configurations (collector.cfg not found)." << std::endl;
        return;
    }

    std::string line;
    while (std::getline(file, line)) {
        size_t comment_pos = line.find('#');
        if (comment_pos != std::string::npos) {
            line = line.substr(0, comment_pos);
        }

        size_t eq_pos = line.find('=');
        if (eq_pos == std::string::npos) continue;

        std::string key = line.substr(0, eq_pos);
        std::string val = line.substr(eq_pos + 1);

        trim(key);
        trim(val);

        if (key == "csv_dir") {
            csv_dir = val;
        } else if (key == "interface") {
            target_interface = val;
        } else if (key == "use_manticore") {
            use_manticore = (val == "true" || val == "1");
        } else if (key == "manticore_host") {
            manticore_host = val;
        } else if (key == "manticore_port") {
            try {
                manticore_port = std::stoi(val);
            } catch (...) {}
        }
    }
    file.close();
    std::cout << "[Config] Loaded configuration from: " << filepath << std::endl;
}

// Function to initialize database and create table if not exists dynamically
void initialize_database() {
    if (!use_manticore) return;

    MYSQL* mysql = mysql_init(nullptr);
    if (!mysql) {
        std::cerr << "[Database] mysql_init failed" << std::endl;
        return;
    }

    if (!mysql_real_connect(mysql, manticore_host.c_str(), "root", "", "", manticore_port, nullptr, 0)) {
        std::cerr << "[Database] Connection to Manticore failed (Dynamic table check skipped): " << mysql_error(mysql) << std::endl;
        mysql_close(mysql);
        return;
    }

    // Dynamic RT index table creation query (with csv_line field)
    std::string create_table_query = 
        "CREATE TABLE IF NOT EXISTS packets ("
        "timestamp timestamp, "
        "interface string, "
        "src_mac string, "
        "dst_mac string, "
        "eth_type int, "
        "ip_ver int, "
        "src_ip string, "
        "dst_ip string, "
        "ip_ttl int, "
        "ip_proto int, "
        "src_port int, "
        "dst_port int, "
        "tcp_seq bigint, "
        "tcp_ack bigint, "
        "tcp_flags string, "
        "tcp_win int, "
        "udp_len int, "
        "icmp_type int, "
        "icmp_code int, "
        "payload_len int, "
        "csv_line int"
        ") type='rt'";

    if (mysql_query(mysql, create_table_query.c_str())) {
        std::cerr << "[Database] Failed to verify/create table 'packets': " << mysql_error(mysql) << std::endl;
    } else {
        std::cout << "[Database] Verified 'packets' table dynamically in Manticore Search." << std::endl;
    }

    mysql_close(mysql);
}

// Helper to count lines of an existing file
uint32_t count_file_lines(const std::string& filename) {
    std::ifstream file(filename);
    if (!file.is_open()) return 0;
    uint32_t lines = 0;
    std::string line;
    while (std::getline(file, line)) {
        lines++;
    }
    file.close();
    return lines;
}

// Global state trackers for line number assigning
std::string last_assigned_filename = "";
uint32_t current_line_offset = 0;

// Function to assign unique CSV line numbers to each record in the batch
void assign_line_numbers(std::vector<PacketRecord>& records) {
    if (records.empty()) return;

    // Determine filename for this batch based on the first record's timestamp
    auto first_ts = records[0].timestamp;
    std::time_t temp_time = first_ts;
    std::stringstream ss;
    ss << csv_dir << "/traffic_" << std::put_time(std::localtime(&temp_time), "%Y%m%d_%H") << ".csv";
    std::string filename = ss.str();

    // Reset line offset tracker if filename changes (hourly rotation or restart)
    if (filename != last_assigned_filename) {
        last_assigned_filename = filename;
        current_line_offset = count_file_lines(filename);
    }

    // If file does not exist yet, writing header will occupy line 1, so data starts at line 2.
    if (current_line_offset == 0) {
        current_line_offset = 1; // Header line offset
    }

    for (auto& r : records) {
        current_line_offset++;
        r.csv_line = current_line_offset;
    }
}

// Write system and capture statistics (e.g. packet drops) to a log file
void log_statistics(unsigned int received, unsigned int dropped, unsigned int if_dropped) {
    std::string mkdir_cmd = "mkdir -p " + csv_dir;
    int unused = system(mkdir_cmd.c_str());
    (void)unused;

    std::string stats_file_path = csv_dir + "/collector_stats.log";
    std::ofstream file(stats_file_path, std::ios::out | std::ios::app);
    if (!file.is_open()) return;

    auto now = std::chrono::system_clock::now();
    auto in_time_t = std::chrono::system_clock::to_time_t(now);
    
    file << "[" << std::put_time(std::localtime(&in_time_t), "%Y-%m-%d %H:%M:%S") << "] "
         << "Interface: " << target_interface << " | "
         << "Received: " << received << " | "
         << "Dropped by Kernel: " << dropped << " | "
         << "Dropped by Interface: " << if_dropped << "\n";
    file.flush();
         file.close();
}

// Helper to convert MAC to string
std::string mac_to_string(const u_char* mac) {
    char buf[18];
    snprintf(buf, sizeof(buf), "%02x:%02x:%02x:%02x:%02x:%02x",
             mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
    return std::string(buf);
}

// Helper to escape SQL string
std::string escape_string(MYSQL* mysql, const std::string& str) {
    if (str.empty()) return "";
    std::vector<char> buffer(str.size() * 2 + 1);
    mysql_real_escape_string(mysql, buffer.data(), str.c_str(), str.size());
    return std::string(buffer.data());
}

// Function to write buffer to CSV file
void write_to_csv(const std::vector<PacketRecord>& records) {
    if (records.empty()) return;

    std::string mkdir_cmd = "mkdir -p " + csv_dir;
    int unused = system(mkdir_cmd.c_str());
    (void)unused;

    auto now = std::chrono::system_clock::now();
    auto in_time_t = std::chrono::system_clock::to_time_t(now);
    std::stringstream ss;
    ss << csv_dir << "/traffic_" << std::put_time(std::localtime(&in_time_t), "%Y%m%d_%H") << ".csv";
    std::string filename = ss.str();

    bool file_has_content = false;
    std::ifstream test_file(filename);
    if (test_file.good()) {
        test_file.seekg(0, std::ios::end);
        if (test_file.tellg() > 0) {
            file_has_content = true;
        }
    }
    test_file.close();

    std::ofstream file(filename, std::ios::out | std::ios::app);
    if (!file.is_open()) {
        return;
    }

    if (!file_has_content) {
        file << "timestamp,interface,src_mac,dst_mac,eth_type,ip_ver,src_ip,dst_ip,ip_ttl,ip_proto,src_port,dst_port,tcp_seq,tcp_ack,tcp_flags,tcp_win,udp_len,icmp_type,icmp_code,payload_len,csv_line\n";
    }

    for (const auto& r : records) {
        file << r.timestamp << ","
             << r.interface_name << ","
             << r.src_mac << ","
             << r.dst_mac << ","
             << r.eth_type << ","
             << r.ip_ver << ","
             << r.src_ip << ","
             << r.dst_ip << ","
             << r.ip_ttl << ","
             << r.ip_proto << ","
             << r.src_port << ","
             << r.dst_port << ","
             << r.tcp_seq << ","
             << r.tcp_ack << ","
             << r.tcp_flags << ","
             << r.tcp_win << ","
             << r.udp_len << ","
             << r.icmp_type << ","
             << r.icmp_code << ","
             << r.payload_len << ","
             << r.csv_line << "\n";
    }
    file.flush();
    file.close();
}

// Function to write buffer to Manticore Search using Batch / Bulk Inserts
void write_to_manticore(const std::vector<PacketRecord>& records) {
    if (!use_manticore || records.empty()) return;

    MYSQL* mysql = mysql_init(nullptr);
    if (!mysql) return;

    if (!mysql_real_connect(mysql, manticore_host.c_str(), "root", "", "", manticore_port, nullptr, 0)) {
        mysql_close(mysql);
        return;
    }

    std::stringstream query;
    query << "INSERT INTO packets (timestamp, interface, src_mac, dst_mac, eth_type, ip_ver, src_ip, dst_ip, ip_ttl, ip_proto, src_port, dst_port, tcp_seq, tcp_ack, tcp_flags, tcp_win, udp_len, icmp_type, icmp_code, payload_len, csv_line) VALUES ";

    for (size_t i = 0; i < records.size(); ++i) {
        const auto& r = records[i];
        query << "("
              << r.timestamp << ", "
              << "'" << escape_string(mysql, r.interface_name) << "', "
              << "'" << escape_string(mysql, r.src_mac) << "', "
              << "'" << escape_string(mysql, r.dst_mac) << "', "
              << r.eth_type << ", "
              << r.ip_ver << ", "
              << "'" << escape_string(mysql, r.src_ip) << "', "
              << "'" << escape_string(mysql, r.dst_ip) << "', "
              << r.ip_ttl << ", "
              << r.ip_proto << ", "
              << r.src_port << ", "
              << r.dst_port << ", "
              << r.tcp_seq << ", "
              << r.tcp_ack << ", "
              << "'" << escape_string(mysql, r.tcp_flags) << "', "
              << r.tcp_win << ", "
              << r.udp_len << ", "
              << r.icmp_type << ", "
              << r.icmp_code << ", "
              << r.payload_len << ", "
              << r.csv_line << ")";
        
        if (i + 1 < records.size()) {
            query << ", ";
        }
    }

    if (mysql_query(mysql, query.str().c_str())) {
        std::cerr << "[Manticore] Bulk insert error: " << mysql_error(mysql) << std::endl;
    }

    mysql_close(mysql);
}

// Consumer Writer Thread Function
void writer_worker() {
    while (true) {
        std::vector<PacketRecord> records_to_write;
        {
            std::unique_lock<std::mutex> lock(queue_mutex);
            queue_cv.wait(lock, [] { return !work_queue.empty() || !keep_running; });

            if (work_queue.empty() && !keep_running) {
                break;
            }

            if (!work_queue.empty()) {
                records_to_write = std::move(work_queue.front());
                work_queue.pop();
            }
        }

        if (!records_to_write.empty()) {
            // Assign CSV Line numbers before sending to outputs
            assign_line_numbers(records_to_write);
            write_to_csv(records_to_write);
            write_to_manticore(records_to_write);
        }
    }
}

// Timer Thread Function (swaps active buffer every 10 seconds and logs drop stats)
void timer_worker() {
    while (keep_running) {
        std::this_thread::sleep_for(std::chrono::seconds(10));
        if (!keep_running) break;

        // Fetch packet drop stats and write to log
        if (pcap_handle) {
            struct pcap_stat stats;
            if (pcap_stats(pcap_handle, &stats) == 0) {
                log_statistics(stats.ps_recv, stats.ps_drop, stats.ps_ifdrop);
            }
        }

        std::vector<PacketRecord> partial_buffer;
        {
            std::lock_guard<std::mutex> lock(active_buffer_mutex);
            if (!active_buffer.empty()) {
                partial_buffer.swap(active_buffer);
            }
        }

        if (!partial_buffer.empty()) {
            {
                std::lock_guard<std::mutex> lock(queue_mutex);
                work_queue.push(std::move(partial_buffer));
            }
            queue_cv.notify_one();
        }
    }
}

// libpcap packet handler callback (Producer)
void packet_handler(u_char* user, const struct pcap_pkthdr* pkthdr, const u_char* packet) {
    if (!keep_running) return;

    PacketRecord record;
    std::memset(&record, 0, sizeof(record));

    record.timestamp = pkthdr->ts.tv_sec;
    record.interface_name = target_interface;

    struct ether_header* eth_hdr = (struct ether_header*)packet;
    record.src_mac = mac_to_string(eth_hdr->ether_shost);
    record.dst_mac = mac_to_string(eth_hdr->ether_dhost);
    record.eth_type = ntohs(eth_hdr->ether_type);

    const u_char* ip_packet = packet + sizeof(struct ether_header);
    int ip_header_len = 0;

    if (record.eth_type == ETHERTYPE_IP) {
        struct ip* ip_hdr = (struct ip*)ip_packet;
        record.ip_ver = 4;
        
        char src_ip_str[INET_ADDRSTRLEN];
        char dst_ip_str[INET_ADDRSTRLEN];
        inet_ntop(AF_INET, &(ip_hdr->ip_src), src_ip_str, INET_ADDRSTRLEN);
        inet_ntop(AF_INET, &(ip_hdr->ip_dst), dst_ip_str, INET_ADDRSTRLEN);
        
        record.src_ip = src_ip_str;
        record.dst_ip = dst_ip_str;
        record.ip_ttl = ip_hdr->ip_ttl;
        record.ip_proto = ip_hdr->ip_p;
        
        ip_header_len = ip_hdr->ip_hl * 4;
    }
    else if (record.eth_type == ETHERTYPE_IPV6) {
        struct ip6_hdr* ip6_hdr = (struct ip6_hdr*)ip_packet;
        record.ip_ver = 6;

        char src_ip_str[INET6_ADDRSTRLEN];
        char dst_ip_str[INET6_ADDRSTRLEN];
        inet_ntop(AF_INET6, &(ip6_hdr->ip6_src), src_ip_str, INET6_ADDRSTRLEN);
        inet_ntop(AF_INET6, &(ip6_hdr->ip6_dst), dst_ip_str, INET6_ADDRSTRLEN);

        record.src_ip = src_ip_str;
        record.dst_ip = dst_ip_str;
        record.ip_ttl = ip6_hdr->ip6_hops;
        record.ip_proto = ip6_hdr->ip6_nxt;

        ip_header_len = 40;
    }
    else {
        return;
    }

    const u_char* transport_packet = ip_packet + ip_header_len;

    if (record.ip_proto == IPPROTO_TCP) {
        struct tcphdr* tcp_hdr = (struct tcphdr*)transport_packet;
        record.src_port = ntohs(tcp_hdr->th_sport);
        record.dst_port = ntohs(tcp_hdr->th_dport);
        record.tcp_seq = ntohl(tcp_hdr->th_seq);
        record.tcp_ack = ntohl(tcp_hdr->th_ack);
        record.tcp_win = ntohs(tcp_hdr->th_win);

        std::vector<std::string> flags;
        if (tcp_hdr->th_flags & TH_SYN) flags.push_back("SYN");
        if (tcp_hdr->th_flags & TH_ACK) flags.push_back("ACK");
        if (tcp_hdr->th_flags & TH_FIN) flags.push_back("FIN");
        if (tcp_hdr->th_flags & TH_RST) flags.push_back("RST");
        if (tcp_hdr->th_flags & TH_PUSH) flags.push_back("PSH");
        if (tcp_hdr->th_flags & TH_URG) flags.push_back("URG");
        
        std::stringstream flag_ss;
        for (size_t i = 0; i < flags.size(); ++i) {
            flag_ss << flags[i] << (i + 1 < flags.size() ? "," : "");
        }
        record.tcp_flags = flag_ss.str();

        int tcp_header_len = tcp_hdr->th_off * 4;
        record.payload_len = pkthdr->len - (sizeof(struct ether_header) + ip_header_len + tcp_header_len);
    }
    else if (record.ip_proto == IPPROTO_UDP) {
        struct udphdr* udp_hdr = (struct udphdr*)transport_packet;
        record.src_port = ntohs(udp_hdr->uh_sport);
        record.dst_port = ntohs(udp_hdr->uh_dport);
        record.udp_len = ntohs(udp_hdr->uh_ulen);
        
        record.payload_len = pkthdr->len - (sizeof(struct ether_header) + ip_header_len + sizeof(struct udphdr));
    }
    else if (record.ip_proto == IPPROTO_ICMP) {
        struct icmphdr* icmp_hdr = (struct icmphdr*)transport_packet;
        record.icmp_type = icmp_hdr->type;
        record.icmp_code = icmp_hdr->code;
        record.payload_len = pkthdr->len - (sizeof(struct ether_header) + ip_header_len + 8);
    }

    std::lock_guard<std::mutex> lock(active_buffer_mutex);
    active_buffer.push_back(record);

    if (active_buffer.size() >= 1000) {
        std::vector<PacketRecord> full_buffer;
        full_buffer.swap(active_buffer);
        {
            std::lock_guard<std::mutex> q_lock(queue_mutex);
            work_queue.push(std::move(full_buffer));
        }
        queue_cv.notify_one();
    }
}

// Signal Handler
void signal_handler(int signo) {
    if (signo == SIGTERM || signo == SIGINT) {
        keep_running = 0;
        if (pcap_handle) {
            pcap_breakloop(pcap_handle);
        }
    }
}

// Stop function for -kill option
void handle_kill_option() {
    std::ifstream pid_file(pid_file_path);
    if (!pid_file.is_open()) {
        std::cerr << "Error: No PID file found at " << pid_file_path << ". Is the collector running?" << std::endl;
        exit(1);
    }

    pid_t pid;
    if (pid_file >> pid) {
        std::cout << "Sending SIGTERM to Collector daemon (PID: " << pid << ")..." << std::endl;
        if (kill(pid, SIGTERM) == 0) {
            std::cout << "Successfully signaled collector daemon to terminate." << std::endl;
            for (int i = 0; i < 5; ++i) {
                if (access(pid_file_path.c_str(), F_OK) == -1) {
                    std::cout << "Collector daemon stopped gracefully." << std::endl;
                    exit(0);
                }
                std::this_thread::sleep_for(std::chrono::seconds(1));
            }
            std::cout << "Daemon did not terminate yet. It might still be flushing buffers." << std::endl;
            exit(0);
        } else {
            std::cerr << "Failed to send signal: " << strerror(errno) << std::endl;
            exit(1);
        }
    } else {
        std::cerr << "Error: Failed to read valid PID from " << pid_file_path << std::endl;
        exit(1);
    }
}

int main(int argc, char* argv[]) {
    if (argc > 1 && (std::strcmp(argv[1], "-kill") == 0 || std::strcmp(argv[1], "--kill") == 0)) {
        handle_kill_option();
        return 0;
    }

    load_config(config_file_path);

    if (argc > 1) {
        target_interface = argv[1];
    }

    // Initialize database dynamically (Auto creates table packets if not exists)
    initialize_database();

    if (daemon(1, 0) < 0) {
        std::cerr << "Failed to daemonize: " << strerror(errno) << std::endl;
        return 1;
    }

    std::ofstream pid_file(pid_file_path);
    if (pid_file.is_open()) {
        pid_file << getpid();
        pid_file.close();
    }

    struct sigaction sa;
    sa.sa_handler = signal_handler;
    sigemptyset(&sa.sa_mask);
    sa.sa_flags = 0;
    sigaction(SIGTERM, &sa, nullptr);
    sigaction(SIGINT, &sa, nullptr);

    char errbuf[PCAP_ERRBUF_SIZE];
    pcap_handle = pcap_open_live(target_interface.c_str(), BUFSIZ, 1, 1000, errbuf);
    if (!pcap_handle) {
        unlink(pid_file_path.c_str());
        return 1;
    }

    // Launch worker threads
    std::thread timer_thread(timer_worker);
    std::thread writer_thread(writer_worker);

    // Start blocking capture loop (Producer)
    pcap_loop(pcap_handle, 0, packet_handler, nullptr);

    // --- Cleanup Phase ---
    // Log final statistics at shutdown
    struct pcap_stat final_stats;
    if (pcap_stats(pcap_handle, &final_stats) == 0) {
        log_statistics(final_stats.ps_recv, final_stats.ps_drop, final_stats.ps_ifdrop);
    }

    pcap_close(pcap_handle);
    
    // Stop the timer thread
    keep_running = 0;
    if (timer_thread.joinable()) {
        timer_thread.join();
    }

    // Flush any remaining packet data in the active buffer to the work queue
    {
        std::lock_guard<std::mutex> lock(active_buffer_mutex);
        if (!active_buffer.empty()) {
            std::vector<PacketRecord> final_buffer;
            final_buffer.swap(active_buffer);
            {
                std::lock_guard<std::mutex> q_lock(queue_mutex);
                work_queue.push(std::move(final_buffer));
            }
        }
    }

    // Notify writer thread to process remaining items and shut down
    {
        std::lock_guard<std::mutex> lock(queue_mutex);
    }
    queue_cv.notify_all();

    // Wait for writer thread to finish all pending disk/DB writes
    if (writer_thread.joinable()) {
        writer_thread.join();
    }

    unlink(pid_file_path.c_str());

    return 0;
}
