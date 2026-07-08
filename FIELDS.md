# MiniMAGUX Network Traffic Monitor - Packet Fields Specification

본 문서는 C++ 수집기(`collector`)가 네트워크 인터페이스에서 실시간으로 추출하여 CSV 파일 및 Manticore Search 엔진에 기록하는 **20가지 주요 패킷 메타데이터 필드**의 상세 기술 명세서입니다.

---

## 📋 20개 패킷 필드 일람표

| 번호 | 필드명 (CSV & DB) | 데이터 타입 (C++ / Manticore) | 소스 프로토콜 계층 | 설명 | 예시 값 |
| :--- | :--- | :--- | :--- | :--- | :--- |
| 1 | `timestamp` | `long long` / `timestamp` | System | 패킷이 네트워크 카드(NIC)에 도달한 시각 (Unix Epoch) | `1710000000` (2026-07-08 22:00:00) |
| 2 | `interface` | `std::string` / `string` | System | 패킷을 수집한 네트워크 인터페이스 카드 명칭 | `"eth0"`, `"any"` |
| 3 | `src_mac` | `std::string` / `string` | Ethernet | 출발지 하드웨어 MAC 주소 | `"00:0c:29:ab:cd:ef"` |
| 4 | `dst_mac` | `std::string` / `string` | Ethernet | 목적지 하드웨어 MAC 주소 | `"00:0c:29:fe:dc:ba"` |
| 5 | `eth_type` | `unsigned int` / `uint` | Ethernet | 이더넷 프레임 상위 프로토콜 타입 (EtherType) | `2048` (IPv4 = `0x0800`), `34525` (IPv6 = `0x86DD`) |
| 6 | `ip_ver` | `unsigned int` / `uint` | IP Header | 인터넷 프로토콜(IP)의 버전 | `4` (IPv4), `6` (IPv6) |
| 7 | `src_ip` | `std::string` / `string` | IP Header | 출발지 IP 주소 (IPv4 또는 IPv6 형식) | `"192.168.1.50"`, `"fe80::1"` |
| 8 | `dst_ip` | `std::string` / `string` | IP Header | 목적지 IP 주소 (IPv4 또는 IPv6 형식) | `"8.8.8.8"`, `"2001:4860:4860::8888"` |
| 9 | `ip_ttl` | `unsigned int` / `uint` | IP Header | 패킷 수명 지표 (IPv4: TTL / IPv6: Hop Limit) | `64`, `128` |
| 10 | `ip_proto` | `unsigned int` / `uint` | IP Header | IP 상위 계층 전송 프로토콜 번호 | `6` (TCP), `17` (UDP), `1` (ICMP), `58` (ICMPv6) |
| 11 | `src_port` | `unsigned int` / `uint` | TCP / UDP | 출발지 전송 계층 포트 번호 (비전송계층일 경우 `0`) | `51423`, `80` |
| 12 | `dst_port` | `unsigned int` / `uint` | TCP / UDP | 목적지 전송 계층 포트 번호 (비전송계층일 경우 `0`) | `443`, `53` |
| 13 | `tcp_seq` | `unsigned long long` / `bigint` | TCP Header | TCP 데이터 흐름 동기화를 위한 Sequence 번호 (TCP 외 `0`) | `105432098` |
| 14 | `tcp_ack` | `unsigned long long` / `bigint` | TCP Header | 상대측 수신 확인 지표인 Acknowledgment 번호 (TCP 외 `0`) | `398402931` |
| 15 | `tcp_flags` | `std::string` / `string` | TCP Header | TCP 접속 흐름 제어 플래그 조합 (TCP 외 공백) | `"SYN"`, `"SYN,ACK"`, `"ACK,PSH"` |
| 16 | `tcp_win` | `unsigned int` / `uint` | TCP Header | TCP 수신 버퍼 여유 공간 크기 (TCP 외 `0`) | `64240`, `29200` |
| 17 | `udp_len` | `unsigned int` / `uint` | UDP Header | UDP 헤더와 데이터를 합친 전체 크기 (UDP 외 `0`) | `45`, `1024` |
| 18 | `icmp_type` | `unsigned int` / `uint` | ICMP Header | ICMP 통제 메시지의 타입 번호 (ICMP 외 `0`) | `8` (Echo Request), `0` (Echo Reply) |
| 19 | `icmp_code` | `unsigned int` / `uint` | ICMP Header | ICMP 타입에 종속된 세부 코드 번호 (ICMP 외 `0`) | `0` |
| 20 | `payload_len` | `unsigned int` / `uint` | Payload | 헤더 제외 데이터 실탑재 내용(페이로드)의 순 크기 (Bytes) | `0` (헤더만 있는 경우), `1460` |

---

## 🔬 계층별 파싱 상세

수집기는 원본 패킷의 헤더 길이 정보들을 분석하여 페이로드 크기를 오차 없이 정확히 산출합니다.

### 1. Link Layer (이더넷 계층)
- 고정 14바이트 크기의 이더넷 헤더에서 MAC 주소와 EtherType을 가져옵니다.
- EtherType에 따라 IP 계층 파싱 여부를 분기합니다.

### 2. Network Layer (인터넷 계층)
- **IPv4**: 헤더에 포함된 헤더 길이 필드(`ip_hl * 4`)를 계산하여 IP 가변 옵션 헤더 크기까지 유연하게 차감합니다.
- **IPv6**: 고정 40바이트 크기의 헤더를 기반으로 파싱합니다.
- IP 프로토콜(`ip_p`/`ip6_nxt`)을 분석하여 전송 계층 파싱 여부를 분기합니다.

### 3. Transport / Control Layer (전송 및 제어 계층)
- **TCP**: 가변 옵션 필드가 포함된 헤더 크기(`th_off * 4`)를 파싱하여 정확한 TCP 데이터 경계를 찾습니다.
- **UDP**: 고정 8바이트 크기의 UDP 헤더 정보를 파싱합니다.
- **ICMP**: 기본 8바이트 제어 필드를 기반으로 Type/Code를 추출합니다.

### 4. Payload Size (`payload_len`) 산출 공식
$$\text{payload\_len} = \text{전체 패킷 캡처 크기} - (\text{이더넷 헤더 크기} + \text{IP 헤더 크기} + \text{전송 계층 헤더 크기})$$
*(순수 데이터가 없는 3-Way Handshake SYN, ACK 패킷 등은 payload_len 값이 `0`으로 기록됩니다.)*

---

## 🗄️ CSV 파일 및 DB 적재 매핑 예시

### CSV 한 줄 기록 형태
```csv
1710000005,eth0,00:0c:29:ab:cd:ef,00:0c:29:fe:dc:ba,2048,4,192.168.1.50,8.8.8.8,64,6,51423,443,105432098,398402931,ACK,29200,0,0,0,1460
```

### Manticore Search SQL RT 테이블 구성
`manticore.conf`가 없더라도 백엔드 수집기가 SQL 포트로 직접 연결하여 아래 스키마의 실시간(RT) 테이블을 생성 및 연동합니다.
```sql
CREATE TABLE packets (
    timestamp timestamp,
    interface string,
    src_mac string,
    dst_mac string,
    eth_type int,
    ip_ver int,
    src_ip string,
    dst_ip string,
    ip_ttl int,
    ip_proto int,
    src_port int,
    dst_port int,
    tcp_seq bigint,
    tcp_ack bigint,
    tcp_flags string,
    tcp_win int,
    udp_len int,
    icmp_type int,
    icmp_code int,
    payload_len int
) type='rt';
```
*(Manticore RT 테이블의 `string` 타입 속성은 텍스트 형태의 빠른 등가 비교 및 키워드 필터링 검색에 인덱싱이 적용됩니다.)*
