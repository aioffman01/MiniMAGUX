<?php
require_once 'db.php';

$pdo = Db::getConnection();

$where_clauses = [];
$params = [];

// Filter parameters
$src_ip = $_GET['src_ip'] ?? '';
$dst_ip = $_GET['dst_ip'] ?? '';
$src_port = $_GET['src_port'] ?? '';
$dst_port = $_GET['dst_port'] ?? '';
$protocol = $_GET['protocol'] ?? '';

if ($src_ip !== '') {
    $where_clauses[] = "src_ip = :src_ip";
    $params[':src_ip'] = $src_ip;
}
if ($dst_ip !== '') {
    $where_clauses[] = "dst_ip = :dst_ip";
    $params[':dst_ip'] = $dst_ip;
}
if ($src_port !== '') {
    $where_clauses[] = "src_port = :src_port";
    $params[':src_port'] = (int)$src_port;
}
if ($dst_port !== '') {
    $where_clauses[] = "dst_port = :dst_port";
    $params[':dst_port'] = (int)$dst_port;
}
if ($protocol !== '') {
    $where_clauses[] = "ip_proto = :ip_proto";
    $params[':ip_proto'] = (int)$protocol;
}

$query_str = "SELECT * FROM packets";
if (!empty($where_clauses)) {
    $query_str .= " WHERE " . implode(" AND ", $where_clauses);
}
$query_str .= " ORDER BY timestamp DESC LIMIT 50";

$packets = [];
$error_msg = "";
try {
    $stmt = $pdo->prepare($query_str);
    $stmt->execute($params);
    $packets = $stmt->fetchAll();
} catch (Exception $e) {
    $error_msg = "Manticore Search Query Error: " . $e->getMessage();
}

function getProtoName($num) {
    switch ($num) {
        case 6: return 'TCP';
        case 17: return 'UDP';
        case 1: return 'ICMP';
        case 58: return 'ICMPv6';
        default: return 'Proto(' . $num . ')';
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Traffic Monitor - Real-Time Analysis</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: rgba(255, 255, 255, 0.03);
            --card-border: rgba(255, 255, 255, 0.08);
            --primary-glow: linear-gradient(135deg, #3b82f6, #8b5cf6);
            --accent-green: #10b981;
            --accent-blue: #3b82f6;
            --accent-purple: #8b5cf6;
            --text-color: #f3f4f6;
            --text-muted: #9ca3af;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 40px;
            border-bottom: 1px solid var(--card-border);
            backdrop-filter: blur(10px);
            background-color: rgba(11, 15, 25, 0.8);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo {
            font-size: 24px;
            font-weight: 800;
            background: var(--primary-glow);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        nav a {
            color: var(--text-muted);
            text-decoration: none;
            margin-left: 20px;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        nav a.active, nav a:hover {
            color: var(--text-color);
        }

        main {
            padding: 40px;
            flex: 1;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
        }

        .search-box {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            backdrop-filter: blur(12px);
        }

        .search-box h2 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            align-items: flex-end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .form-group input, .form-group select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--card-border);
            color: var(--text-color);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
        }

        .form-group input:focus, .form-group select:focus {
            border-color: #3b82f6;
        }

        .btn-submit {
            background: var(--primary-glow);
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.3s;
            height: 41px;
        }

        .btn-submit:hover {
            opacity: 0.9;
        }

        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            overflow-x: auto;
            backdrop-filter: blur(12px);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }

        th, td {
            padding: 14px 20px;
            border-bottom: 1px solid var(--card-border);
        }

        th {
            background: rgba(255, 255, 255, 0.02);
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
        }

        tbody tr {
            cursor: pointer;
            transition: background-color 0.2s;
        }

        tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge.tcp { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .badge.udp { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; }
        .badge.icmp { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .badge.other { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }

        /* Modal styling */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: #111827;
            border: 1px solid var(--card-border);
            width: 90%;
            max-width: 600px;
            border-radius: 16px;
            padding: 24px;
            position: relative;
        }

        .modal-header {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-close {
            cursor: pointer;
            font-size: 24px;
            color: var(--text-muted);
        }

        .modal-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            max-height: 400px;
            overflow-y: auto;
            font-size: 14px;
        }

        .detail-item {
            border-bottom: 1px solid rgba(255,255,255,0.05);
            padding-bottom: 8px;
        }

        .detail-label {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        footer {
            text-align: center;
            padding: 30px;
            border-top: 1px solid var(--card-border);
            color: var(--text-muted);
            font-size: 14px;
        }
    </style>
</head>
<body>

    <header>
        <div class="logo">MAGUX Traffic Monitor</div>
        <nav>
            <a href="index.php">대시보드</a>
            <a href="search.php" class="active">실시간 패킷 분석</a>
            <a href="csv_viewer.php">CSV 파일 조회</a>
        </nav>
    </header>

    <main>
        <section class="search-box">
            <h2>패킷 조건 필터링</h2>
            <form method="GET" action="search.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="src_ip">출발지 IP (Src IP)</label>
                        <input type="text" id="src_ip" name="src_ip" value="<?= htmlspecialchars($src_ip) ?>" placeholder="예: 192.168.1.10">
                    </div>
                    <div class="form-group">
                        <label for="dst_ip">목적지 IP (Dst IP)</label>
                        <input type="text" id="dst_ip" name="dst_ip" value="<?= htmlspecialchars($dst_ip) ?>" placeholder="예: 8.8.8.8">
                    </div>
                    <div class="form-group">
                        <label for="src_port">출발지 포트 (Src Port)</label>
                        <input type="number" id="src_port" name="src_port" value="<?= htmlspecialchars($src_port) ?>" placeholder="80">
                    </div>
                    <div class="form-group">
                        <label for="dst_port">목적지 포트 (Dst Port)</label>
                        <input type="number" id="dst_port" name="dst_port" value="<?= htmlspecialchars($dst_port) ?>" placeholder="443">
                    </div>
                    <div class="form-group">
                        <label for="protocol">프로토콜</label>
                        <select id="protocol" name="protocol">
                            <option value="">전체</option>
                            <option value="6" <?= $protocol === '6' ? 'selected' : '' ?>>TCP</option>
                            <option value="17" <?= $protocol === '17' ? 'selected' : '' ?>>UDP</option>
                            <option value="1" <?= $protocol === '1' ? 'selected' : '' ?>>ICMP</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn-submit">검색</button>
                    </div>
                </div>
            </form>
        </section>

        <?php if ($error_msg): ?>
            <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; padding: 15px; border-radius: 8px; margin-bottom: 20px; color: #ef4444;">
                <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <section class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>시간</th>
                        <th>인터페이스</th>
                        <th>프로토콜</th>
                        <th>출발지 주소</th>
                        <th>목적지 주소</th>
                        <th>포트 (S -> D)</th>
                        <th>TCP Flags</th>
                        <th>크기 (Bytes)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($packets)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--text-muted);">조회 결과 패킷이 없습니다.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($packets as $packet): 
                            $proto = (int)$packet['ip_proto'];
                            $badge_class = 'other';
                            if ($proto === 6) $badge_class = 'tcp';
                            elseif ($proto === 17) $badge_class = 'udp';
                            elseif ($proto === 1 || $proto === 58) $badge_class = 'icmp';
                        ?>
                            <tr onclick="showDetail(<?= htmlspecialchars(json_encode($packet)) ?>)">
                                <td><?= date('Y-m-d H:i:s', $packet['timestamp']) ?></td>
                                <td><?= htmlspecialchars($packet['interface']) ?></td>
                                <td><span class="badge <?= $badge_class ?>"><?= getProtoName($proto) ?></span></td>
                                <td><?= htmlspecialchars($packet['src_ip'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($packet['dst_ip'] ?: '-') ?></td>
                                <td><?= $packet['src_port'] ? ($packet['src_port'] . ' &rarr; ' . $packet['dst_port']) : '-' ?></td>
                                <td><?= htmlspecialchars($packet['tcp_flags'] ?: '-') ?></td>
                                <td><?= number_format($packet['payload_len']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>

    <!-- Detail Modal -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span>패킷 주요 정보 (20개 필드 상세)</span>
                <span class="modal-close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Dynamically populated -->
            </div>
        </div>
    </div>

    <footer>
        &copy; 2026 MAGUX. All rights reserved. Rocky Linux Network Monitor.
    </footer>

    <script>
        const modal = document.getElementById('detailModal');
        const modalBody = document.getElementById('modalBody');

        function showDetail(packet) {
            let html = '';
            
            const fieldNames = {
                timestamp: '수집 시각 (Unix Timestamp)',
                interface: '네트워크 인터페이스',
                src_mac: '출발지 MAC 주소',
                dst_mac: '목적지 MAC 주소',
                eth_type: '이더넷 타입 (EtherType)',
                ip_ver: 'IP 버전',
                src_ip: '출발지 IP',
                dst_ip: '목적지 IP',
                ip_ttl: 'IP TTL',
                ip_proto: 'IP 프로토콜 번호',
                src_port: '출발지 포트',
                dst_port: '목적지 포트',
                tcp_seq: 'TCP Sequence 번호',
                tcp_ack: 'TCP Acknowledgment 번호',
                tcp_flags: 'TCP Flags',
                tcp_win: 'TCP Window 크기',
                udp_len: 'UDP 길이',
                icmp_type: 'ICMP 타입',
                icmp_code: 'ICMP 코드',
                payload_len: '페이로드 크기 (Bytes)'
            };

            const dateStr = new Date(packet.timestamp * 1000).toLocaleString('ko-KR');

            for (const [key, value] of Object.entries(packet)) {
                if (key === 'id') continue;
                let displayVal = value;
                if (key === 'timestamp') {
                    displayVal = `${value} (${dateStr})`;
                }
                html += `
                    <div class="detail-item">
                        <div class="detail-label">${fieldNames[key] || key}</div>
                        <div class="detail-value">${displayVal !== null && displayVal !== '' ? displayVal : '-'}</div>
                    </div>
                `;
            }
            
            modalBody.innerHTML = html;
            modal.style.display = 'flex';
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
