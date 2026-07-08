<?php
require_once 'db.php';

$pdo = Db::getConnection();

// 1. Get total packets count
$total_packets = 0;
try {
    $stmt = $pdo->query("SELECT count(*) as total FROM packets");
    $res = $stmt->fetch();
    $total_packets = $res['total'] ?? 0;
} catch (Exception $e) {
    // Table might be empty or not initialized yet
}

// 2. Get protocol breakdown (TCP=6, UDP=17, ICMP=1, ICMPv6=58)
$protocol_counts = [
    'TCP (6)' => 0,
    'UDP (17)' => 0,
    'ICMP (1/58)' => 0,
    'Others' => 0
];
try {
    $stmt = $pdo->query("SELECT ip_proto, count(*) as count FROM packets GROUP BY ip_proto");
    while ($row = $stmt->fetch()) {
        $proto = (int)$row['ip_proto'];
        $count = (int)$row['count'];
        if ($proto === 6) {
            $protocol_counts['TCP (6)'] = $count;
        } elseif ($proto === 17) {
            $protocol_counts['UDP (17)'] = $count;
        } elseif ($proto === 1 || $proto === 58) {
            $protocol_counts['ICMP (1/58)'] += $count;
        } else {
            $protocol_counts['Others'] += $count;
        }
    }
} catch (Exception $e) {
}

// 3. Get recent traffic volume trends (grouped by 10 seconds or minutes)
$chart_labels = [];
$chart_packet_counts = [];
$chart_data_volumes = [];

try {
    // Manticore RT index group by expression
    $stmt = $pdo->query("SELECT (timestamp - (timestamp % 10)) as time_bucket, count(*) as count, sum(payload_len) as volume FROM packets GROUP BY time_bucket ORDER BY time_bucket DESC LIMIT 15");
    $trend_data = [];
    while ($row = $stmt->fetch()) {
        $trend_data[] = $row;
    }
    // Reverse to show chronologically
    $trend_data = array_reverse($trend_data);
    foreach ($trend_data as $row) {
        $chart_labels[] = date('H:i:s', $row['time_bucket']);
        $chart_packet_counts[] = (int)$row['count'];
        $chart_data_volumes[] = round((int)$row['volume'] / 1024, 2); // KB
    }
} catch (Exception $e) {
}

// Default values if no data
if (empty($chart_labels)) {
    $chart_labels = ['N/A'];
    $chart_packet_counts = [0];
    $chart_data_volumes = [0];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Traffic Monitor - Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            overflow-x: hidden;
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

        .hero {
            margin-bottom: 40px;
        }

        .hero h1 {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .hero p {
            color: var(--text-muted);
            font-size: 16px;
        }

        .grid-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(12px);
            transition: transform 0.3s ease, border-color 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            border-color: rgba(255, 255, 255, 0.15);
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-glow);
        }

        .card.green::before { background: var(--accent-green); }
        .card.blue::before { background: var(--accent-blue); }
        .card.purple::before { background: var(--accent-purple); }

        .card-title {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .card-value {
            font-size: 32px;
            font-weight: 800;
        }

        .grid-charts {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }

        @media (max-width: 1024px) {
            .grid-charts {
                grid-template-columns: 1fr;
            }
        }

        .chart-box {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 24px;
            backdrop-filter: blur(12px);
        }

        .chart-box h2 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background-color: var(--accent-green);
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 10px var(--accent-green);
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.4); opacity: 0.5; }
            100% { transform: scale(1); opacity: 1; }
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
            <a href="index.php" class="active">대시보드</a>
            <a href="search.php">실시간 패킷 분석</a>
            <a href="csv_viewer.php">CSV 파일 조회</a>
        </nav>
    </header>

    <main>
        <section class="hero">
            <h1>실시간 대시보드</h1>
            <p>네트워크 인터페이스 감시 및 트래픽 유입 현황 분석 리포트</p>
        </section>

        <section class="grid-stats">
            <div class="card blue">
                <div class="card-title">수집중인 상태</div>
                <div class="card-value" style="display: flex; align-items: center; gap: 10px;">
                    Active <span class="status-dot"></span>
                </div>
            </div>
            <div class="card purple">
                <div class="card-title">누적 수집 패킷 수</div>
                <div class="card-value"><?= number_format($total_packets) ?></div>
            </div>
            <div class="card green">
                <div class="card-title">주요 프로토콜</div>
                <div class="card-value">
                    <?php
                        arsort($protocol_counts);
                        echo key($protocol_counts);
                    ?>
                </div>
            </div>
        </section>

        <section class="grid-charts">
            <div class="chart-box">
                <h2>실시간 트래픽 추이 (10초 단위)</h2>
                <div style="position: relative; height: 350px;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
            <div class="chart-box">
                <h2>프로토콜 점유율</h2>
                <div style="position: relative; height: 350px; display: flex; align-items: center; justify-content: center;">
                    <canvas id="pieChart"></canvas>
                </div>
            </div>
        </section>
    </main>

    <footer>
        &copy; 2026 MAGUX. All rights reserved. Rocky Linux Network Monitor.
    </footer>

    <script>
        // Trend Line Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: '패킷 수 (개)',
                    data: <?= json_encode($chart_packet_counts) ?>,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: '페이로드 크기 (KB)',
                    data: <?= json_encode($chart_data_volumes) ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#f3f4f6' } }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: '#9ca3af' }
                    },
                    y: {
                        position: 'left',
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: '#9ca3af' }
                    },
                    y1: {
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { color: '#9ca3af' }
                    }
                }
            }
        });

        // Protocol Pie Chart
        const pieCtx = document.getElementById('pieChart').getContext('2d');
        const protocolData = <?= json_encode(array_values($protocol_counts)) ?>;
        const protocolLabels = <?= json_encode(array_keys($protocol_counts)) ?>;
        
        new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: protocolLabels,
                datasets: [{
                    data: protocolData,
                    backgroundColor: ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#f3f4f6' }
                    }
                }
            }
        });

        // Auto refresh page every 10 seconds for real-time dashboard feel
        setTimeout(() => {
            window.location.reload();
        }, 10000);
    </script>
</body>
</html>
