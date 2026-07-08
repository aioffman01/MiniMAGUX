<?php
// Path to the CSV logs directory (sibling to frontend)
$csv_dir = __DIR__ . '/../backend/csv_logs';

// Determine the active file name for the current hour (e.g., traffic_20260708_22.csv)
$current_hour_file = 'traffic_' . date('Ymd_H') . '.csv';

// Read all CSV files in the directory
$csv_files = [];
if (is_dir($csv_dir)) {
    $files = scandir($csv_dir);
    foreach ($files as $file) {
        if (preg_match('/^traffic_\d{8}_\d{2}\.csv$/', $file)) {
            $csv_files[] = $file;
        }
    }
    // Sort files to show newest first
    rsort($csv_files);
}

// Check if a specific file is selected for viewing
$selected_file = $_GET['file'] ?? '';
$csv_data = [];
$csv_headers = [];
$error_msg = '';
$is_active_file = false;

if ($selected_file !== '') {
    // Basic security check to prevent path traversal
    if (!preg_match('/^traffic_\d{8}_\d{2}\.csv$/', $selected_file)) {
        $error_msg = "올바르지 않은 파일 형식입니다.";
    } elseif ($selected_file === $current_hour_file) {
        $is_active_file = true;
        $error_msg = "현재 시간대의 파일('${selected_file}')은 백엔드 수집기가 패킷을 기록 중이므로 조회할 수 없습니다.";
    } else {
        $full_path = $csv_dir . '/' . $selected_file;
        if (file_exists($full_path)) {
            if (($handle = fopen($full_path, "r")) !== FALSE) {
                // Get header row
                if (($headers = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $csv_headers = $headers;
                }
                // Read up to 200 rows to prevent memory overflow
                $row_count = 0;
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE && $row_count < 200) {
                    $csv_data[] = $data;
                    $row_count++;
                }
                fclose($handle);
            } else {
                $error_msg = "파일을 여는 동안 오류가 발생했습니다.";
            }
        } else {
            $error_msg = "존재하지 않는 파일입니다.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Traffic Monitor - CSV Log Viewer</title>
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
            --accent-red: #ef4444;
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
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
        }

        @media (max-width: 900px) {
            main {
                grid-template-columns: 1fr;
            }
        }

        .sidebar {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 20px;
            height: fit-content;
        }

        .sidebar h2 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .file-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .file-item {
            padding: 12px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--card-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-decoration: none;
            color: var(--text-color);
            transition: all 0.2s ease;
        }

        .file-item.active-writing {
            border-color: rgba(239, 68, 68, 0.3);
            background: rgba(239, 68, 68, 0.05);
            cursor: not-allowed;
            pointer-events: none;
            opacity: 0.8;
        }

        .file-item:not(.active-writing):hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: var(--accent-blue);
        }

        .file-item.selected {
            background: rgba(59, 130, 246, 0.1);
            border-color: var(--accent-blue);
        }

        .badge-status {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
        }

        .badge-status.writing {
            background: rgba(239, 68, 68, 0.2);
            color: var(--accent-red);
        }

        .badge-status.ready {
            background: rgba(16, 185, 129, 0.2);
            color: var(--accent-green);
        }

        .viewer-container {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            overflow: hidden;
        }

        .viewer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 15px;
        }

        .viewer-title {
            font-size: 20px;
            font-weight: 700;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--accent-red);
            padding: 15px;
            border-radius: 8px;
            color: var(--accent-red);
            font-size: 14px;
        }

        .table-responsive {
            overflow-x: auto;
            max-height: 500px;
            border: 1px solid var(--card-border);
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }

        th, td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--card-border);
            white-space: nowrap;
        }

        th {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-muted);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
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
            <a href="search.php">실시간 패킷 분석</a>
            <a href="csv_viewer.php" class="active">CSV 파일 조회</a>
        </nav>
    </header>

    <main>
        <!-- Sidebar containing file list -->
        <section class="sidebar">
            <h2>CSV 로그 파일 목록</h2>
            <ul class="file-list">
                <?php if (empty($csv_files)): ?>
                    <li style="color: var(--text-muted); font-size: 14px; text-align: center; padding: 20px;">생성된 CSV 파일이 없습니다.</li>
                <?php else: ?>
                    <?php foreach ($csv_files as $file): 
                        $is_writing = ($file === $current_hour_file);
                        $is_selected = ($file === $selected_file);
                        
                        $item_class = 'file-item';
                        if ($is_writing) $item_class .= ' active-writing';
                        if ($is_selected) $item_class .= ' selected';
                        
                        // Human readable time label from filename
                        preg_match('/traffic_(\d{8})_(\d{2})\.csv/', $file, $matches);
                        $date_part = isset($matches[1]) ? substr($matches[1], 0, 4) . '-' . substr($matches[1], 4, 2) . '-' . substr($matches[1], 6, 2) : $file;
                        $hour_part = isset($matches[2]) ? $matches[2] . '시' : '';
                        $label = $date_part . ' ' . $hour_part;
                    ?>
                        <li>
                            <a href="csv_viewer.php?file=<?= urlencode($file) ?>" class="<?= $item_class ?>">
                                <span><?= htmlspecialchars($label) ?></span>
                                <?php if ($is_writing): ?>
                                    <span class="badge-status writing">작업 중</span>
                                <?php else: ?>
                                    <span class="badge-status ready">조회 가능</span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </section>

        <!-- Viewer content pane -->
        <section class="viewer-container">
            <div class="viewer-header">
                <div class="viewer-title">
                    <?php 
                    if ($selected_file !== '') {
                        echo "조회 중: " . htmlspecialchars($selected_file);
                    } else {
                        echo "CSV 파일 내용 뷰어";
                    }
                    ?>
                </div>
            </div>

            <?php if ($error_msg !== ''): ?>
                <div class="error-message">
                    <?= htmlspecialchars($error_msg) ?>
                </div>
            <?php elseif ($selected_file === ''): ?>
                <div style="text-align: center; color: var(--text-muted); padding: 60px 20px;">
                    왼쪽 목록에서 조회하고자 하는 CSV 로그 파일을 선택해 주세요.<br>
                    <span style="font-size: 12px; color: var(--accent-red); margin-top: 10px; display: inline-block;">※ '작업 중' 상태인 현재 시간대의 파일은 수집기 보호를 위해 조회가 비활성화됩니다.</span>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <?php foreach ($csv_headers as $hdr): ?>
                                    <th><?= htmlspecialchars($hdr) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($csv_data as $row): ?>
                                <tr>
                                    <?php foreach ($row as $cell): ?>
                                        <td><?= htmlspecialchars($cell) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); text-align: right;">
                    ※ 브라우저 메모리 부하 방지를 위해 최대 상위 200개 라인까지만 출력됩니다.
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        &copy; 2026 MAGUX. All rights reserved. Rocky Linux Network Monitor.
    </footer>

</body>
</html>
