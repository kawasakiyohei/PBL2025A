<?php

session_start(); // セッションを開始
ini_set('display_errors', "On");
$name = $_SESSION['name'];
$position = $_SESSION['position'];
// 表示用部署ラベル
$department_label = $_SESSION['department_label'] ?? ($_SESSION['department'] ?? '');

// セッションに'position'が保存されているかを確認
if (isset($_SESSION['position']) && $_SESSION['position'] === 'admin') {
    // 'admin'の場合はページを表示
} else {
    // 'admin'でない場合、user_error.phpにリダイレクト
    header('Location: user_error.php');
    exit; // リダイレクト後に処理を停止
}

/*
if (isset($_SESSION['position']) && $_SESSION['position'] === 'admin') {
    // 'admin'の場合はページを表示
    // セッションに'employeenumber'が保存されているかを確認
    if (isset($_SESSION['employeenumber'])) {
        // セッションに保存されているemployeenumberを使用
        $employeenumber = $_SESSION['employeenumber'];    
    } else {
        // employeenumberがない場合の処理
        header('Location: error.php?source=account_edit');
        exit;
    }
} else {
    // 'admin'でない場合、user_error.phpにリダイレクト
    header('Location: user_error.php');
    exit; // リダイレクト後に処理を停止
}
*/
?>
<html>
    <head>
    <link rel="stylesheet" type="text/css" href="/pbl/style/home.css" />
    </head>
    <body>
    <div class="header">
            <a href="./home.php"><h1>愛媛新聞社 シフト管理システム</h1></a>
    </div>
    <button>設定</button>
    <div class="logout">
        <span><?php echo $name;?> さん</span>
        <button onclick="location.href='./admin/account/logout.php'">ログアウト</button>
    </div>  
    </body>
</html>
<?php
// CSVファイルのパスを決定: GET パラメータで year/month/department を受け付けるか、
// 指定がなければ最新の schedule_*.csv を表示する
$dataDir = __DIR__ . '/admin/data';
$csvFile = '';
if (isset($_GET['year']) && isset($_GET['month']) && isset($_GET['department'])) {
    $y = intval($_GET['year']);
    $m = intval($_GET['month']);
    $dep = preg_replace('/[^a-z0-9_\-]/i', '_', $_GET['department']);
    $candidate = $dataDir . '/schedule_' . $y . '_' . $m . '_' . $dep . '.csv';
    if (file_exists($candidate)) {
        $csvFile = $candidate;
    }
}

// GET 指定がなかったかファイルが無かった場合は最新版を探す
if ($csvFile === '') {
    $files = glob($dataDir . '/schedule_*.csv');
    if ($files !== false && count($files) > 0) {
        // 最終更新時刻でソートして最新を取る
        usort($files, function($a, $b){
            return filemtime($b) - filemtime($a);
        });
        $csvFile = $files[0];
    }
}

if ($csvFile !== '') {
    echo "表示中の CSV: " . htmlspecialchars(basename($csvFile)) . "\n";
    // ファイルが存在するか確認
    if (file_exists($csvFile)) {
            // ファイルを開いて CSV をパースし、create_schedule.php と同じ見た目で表示する
            $raw = @file_get_contents($csvFile);
            if ($raw === false) {
                echo "ファイルを開けませんでした。";
                return;
            }
            // BOM 除去
            if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
            // 文字コード検出
            $det = @mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP', 'ISO-2022-JP', 'ASCII'], true);
            echo "<!-- detected encoding: " . htmlspecialchars($det) . " -->\n";
            if ($det !== 'UTF-8' && $det !== false) {
                $raw = mb_convert_encoding($raw, 'UTF-8', $det);
            }
            // メモリストリームに流す
            $h = fopen('php://memory', 'r+');
            fwrite($h, $raw);
            rewind($h);
            $header = fgetcsv($h);
            $daysInMonth = 0;
            if (is_array($header)) {
                $daysInMonth = count($header) - 1; // 1列目は名前
            }

            $employees = [];
            $generated_result = [];
            $rowCount = 0;
            while (($row = fgetcsv($h)) !== false) {
                if (!isset($row[0])) continue;
                $name = trim($row[0]);
                if ($name === '') continue;
                $rowCount++;
                $employees[] = ['id' => $name, 'name' => $name];
                $generated_result[$name] = [];
                for ($i = 1; $i < count($row); $i++) {
                    $day = $i; // assume header day numbers or sequential
                    $cell = trim($row[$i]);
                    $type = '';
                    $time = '';
                    if ($cell !== '') {
                        $parts = preg_split('/[\|\/\s,]+/', $cell);
                        foreach ($parts as $p) {
                            $p = trim($p);
                            if ($p === '') continue;
                            // 簡易判定: 公休/特殊休 は type、それ以外は 1-2 文字を time、それ以外を type とする
                            if ($p === '公休' || $p === '特殊休') {
                                $type = $p;
                            } elseif (mb_strlen($p) <= 2) {
                                $time = $p;
                            } else {
                                $type = $p;
                            }
                        }
                        if ($type === '' && $time !== '') $type = '出勤';
                    }
                    $generated_result[$name][$day] = ['type' => $type, 'time' => $time];
                }
            }
            fclose($h);
            echo "<!-- parsed rows: " . intval($rowCount) . " -->\n";

            // create_schedule のスタイルを模した簡易 CSS
            echo "<style>table{border-collapse:separate;border-spacing:0;width:100%;border:1px solid #e0e0e0;}th,td{border:none;padding:6px;text-align:center;vertical-align:middle;}tbody tr+tr td{border-top:1px solid #f0f0f0;}thead th+th{border-left:1px solid #f0f0f0;}.name-cell{width:180px;text-align:left;padding-left:8px;} .work-day{background:#eafae9;} .non-work{background:#faf0f4;} .disabled-day{background:#f9f9f9;color:#999;} </style>";

            // 前半テーブル
            echo "<div class='half-title'>前半（1〜16日）</div>";
            echo "<table>";
            echo "<thead><tr><th>名前</th>";
            for ($d = 1; $d <= 16; $d++) {
                $is_disabled = ($d > $daysInMonth);
                echo "<th" . ($is_disabled ? " class='disabled-day'" : "") . ">" . $d . "</th>";
            }
            echo "</tr></thead>";
            echo "<tbody>";
            foreach ($employees as $emp) {
                $ename = $emp['name'];
                echo "<tr>";
                echo "<td class='name-cell' rowspan='2'>" . htmlspecialchars($ename) . "</td>";
                for ($d = 1; $d <= 16; $d++) {
                    $is_disabled = ($d > $daysInMonth);
                    $rawType = '';
                    $rawTime = '';
                    if (!$is_disabled && isset($generated_result[$ename][$d])) {
                        $rawType = $generated_result[$ename][$d]['type'];
                        $rawTime = $generated_result[$ename][$d]['time'];
                    }
                    $displayType = $rawType;
                    $displayTime = $rawTime;
                    if ($displayType === '' && $displayTime !== '') $displayType = '出勤';
                    $tdClass = $is_disabled ? 'disabled-day' : ($displayType === '出勤' ? 'work-day' : ($displayType !== '' ? 'non-work' : ''));
                    echo "<td class='" . $tdClass . "'>";
                    if ($is_disabled) {
                        echo "&nbsp;";
                    } else {
                        echo htmlspecialchars($displayType);
                    }
                    echo "</td>";
                }
                echo "</tr>";
                // second row: times
                echo "<tr>";
                for ($d = 1; $d <= 16; $d++) {
                    $is_disabled = ($d > $daysInMonth);
                    $rawTime = '';
                    $rawType = '';
                    if (!$is_disabled && isset($generated_result[$ename][$d])) {
                        $rawType = $generated_result[$ename][$d]['type'];
                        $rawTime = $generated_result[$ename][$d]['time'];
                    }
                    $displayType2 = $rawType;
                    if ($displayType2 === '' && $rawTime !== '') $displayType2 = '出勤';
                    $tdClass2 = $is_disabled ? 'disabled-day' : ($displayType2 === '出勤' ? 'work-day' : ($displayType2 !== '' ? 'non-work' : ''));
                    echo "<td class='" . $tdClass2 . "'>";
                    if ($is_disabled) {
                        echo "&nbsp;";
                    } else {
                        echo htmlspecialchars($rawTime);
                    }
                    echo "</td>";
                }
                echo "</tr>";
            }
            echo "</tbody></table>";

            // 後半テーブル
            echo "<div class='half-title'>後半（17〜31日）</div>";
            echo "<table>";
            echo "<thead><tr><th>名前</th>";
            for ($d = 17; $d <= 31; $d++) {
                $is_disabled = ($d > $daysInMonth);
                echo "<th" . ($is_disabled ? " class='disabled-day'" : "") . ">" . $d . "</th>";
            }
            echo "</tr></thead>";
            echo "<tbody>";
            foreach ($employees as $emp) {
                $ename = $emp['name'];
                echo "<tr>";
                echo "<td class='name-cell' rowspan='2'>" . htmlspecialchars($ename) . "</td>";
                for ($d = 17; $d <= 31; $d++) {
                    $is_disabled = ($d > $daysInMonth);
                    $rawType = '';
                    $rawTime = '';
                    if (!$is_disabled && isset($generated_result[$ename][$d])) {
                        $rawType = $generated_result[$ename][$d]['type'];
                        $rawTime = $generated_result[$ename][$d]['time'];
                    }
                    $displayType = $rawType;
                    $displayTime = $rawTime;
                    if ($displayType === '' && $displayTime !== '') $displayType = '出勤';
                    $tdClass = $is_disabled ? 'disabled-day' : ($displayType === '出勤' ? 'work-day' : ($displayType !== '' ? 'non-work' : ''));
                    echo "<td class='" . $tdClass . "'>";
                    if ($is_disabled) {
                        echo "&nbsp;";
                    } else {
                        echo htmlspecialchars($displayType);
                    }
                    echo "</td>";
                }
                echo "</tr>";
                echo "<tr>";
                for ($d = 17; $d <= 31; $d++) {
                    $is_disabled = ($d > $daysInMonth);
                    $rawTime = '';
                    $rawType = '';
                    if (!$is_disabled && isset($generated_result[$ename][$d])) {
                        $rawType = $generated_result[$ename][$d]['type'];
                        $rawTime = $generated_result[$ename][$d]['time'];
                    }
                    $displayType2 = $rawType;
                    if ($displayType2 === '' && $rawTime !== '') $displayType2 = '出勤';
                    $tdClass2 = $is_disabled ? 'disabled-day' : ($displayType2 === '出勤' ? 'work-day' : ($displayType2 !== '' ? 'non-work' : ''));
                    echo "<td class='" . $tdClass2 . "'>";
                    if ($is_disabled) {
                        echo "&nbsp;";
                    } else {
                        echo htmlspecialchars($rawTime);
                    }
                    echo "</td>";
                }
                echo "</tr>";
            }
            echo "</tbody></table>";
    } else {
        echo "CSVファイルが見つかりません。";
    }
} else {
    echo "表示する CSV が見つかりません。";
}
?>
