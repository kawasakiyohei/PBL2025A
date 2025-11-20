<?php
session_start();
ini_set('display_errors', 1);

// セッションチェック（管理者向けページの想定、必要に応じて変更）
if (!isset($_SESSION['name'])) {
    header('Location: ../../login.php');
    exit();
}

$name = $_SESSION['name'];
$department_session = $_SESSION['department'] ?? '';

// 従業員リストと選択肢（types/times）は下でCSVから読み込むかフォールバックを使う
$employees = [];

$types = ['出勤','公休','特殊休','特別休','出張','リフレッシュ休','慰労休','看護休','介護休','年休','産休','育児休','疫病休','宿直','半宿直'];
$times = ['イ','ロ','ハ','ニ','ホ','へ','ト','チ','A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z'];

// デバッグ用ロガー
$debug_log_path = __DIR__ . '/create_schedule_debug.log';
function write_debug($msg) {
    global $debug_log_path;
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($debug_log_path, "[$ts] " . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

write_debug("-- create_schedule.php loaded (year/month vars will follow when ILP runs)");

// POST 処理: フォーム送信時に schedule 配列を CSV に保存する
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $year = isset($_POST['year']) ? intval($_POST['year']) : intval(date('Y'));
    $month = isset($_POST['month']) ? intval($_POST['month']) : intval(date('n'));
    $department = $_POST['department'] ?? ($department_session ?: 'unknown');

    $schedule = $_POST['schedule'] ?? [];

    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

    $filename = __DIR__ . '/../data/schedule_' . $year . '_' . $month . '_' . preg_replace('/[^a-z0-9_\-]/i', '_', $department) . '.csv';

    // 既存があればバックアップ
    if (file_exists($filename)) {
        $bak = $filename . '.bak_' . date('Ymd_His');
        copy($filename, $bak);
    }

    $fp = fopen($filename, 'w');
    if ($fp === false) {
        echo "ファイルを開けませんでした: " . htmlspecialchars($filename);
        exit;
    }

    // ヘッダ: 名前, 1,2,3...
    $header = array_merge(['名前'], range(1, $daysInMonth));
    fputcsv($fp, $header);

    // 各従業員ごとに行を書き出す
    // 保存時に $employees が未設定の場合は req CSV から補完しておく
    if (count($employees) === 0) {
        $req_filename_post = __DIR__ . '/../data/req_' . $year . '_' . $month . '_' . preg_replace('/[^a-z0-9_\-]/i', '_', $department) . '.csv';
        if (file_exists($req_filename_post) && ($h2 = fopen($req_filename_post, 'r')) !== false) {
            $header2 = fgetcsv($h2);
            while (($row2 = fgetcsv($h2)) !== false) {
                $nm = $row2[0] ?? '';
                if ($nm !== '') {
                    $employees[] = ['id' => $nm, 'name' => $nm];
                }
            }
            fclose($h2);
        }
        // フォールバック
        if (count($employees) === 0) {
            $employees = [
                ['id' => '統括', 'name' => '統括'],
                ['id' => '副部長A', 'name' => '副部長A'],
                ['id' => '副部長B', 'name' => '副部長B'],
                ['id' => '副部長C', 'name' => '副部長C'],
                ['id' => '副部長D', 'name' => '副部長D'],
                ['id' => '部員A', 'name' => '部員A'],
                ['id' => '部員B', 'name' => '部員B'],
                ['id' => '部員C', 'name' => '部員C'],
                ['id' => '臨時・派遣A', 'name' => '臨時・派遣A'],
                ['id' => '臨時・派遣B', 'name' => '臨時・派遣B'],
                ['id' => '臨時・派遣C', 'name' => '臨時・派遣C'],
            ];
        }
        // digitalstreaming 用の固定順序を適用する場合、department をもとに上書き
        if (strpos($department, 'digital') !== false || stripos($department, 'digitalstreaming') !== false) {
            $fixed = [
                '部長A','部長B','副部長A','副部長B','部員A','部員B','部員C',
                '派遣A','派遣B','派遣C','派遣D','派遣E','副部長C','副部長D','部員D',
                '派遣F','派遣G'
            ];
            $newemps = [];
            foreach ($fixed as $f) {
                $newemps[] = ['id' => $f, 'name' => $f];
            }
            $employees = $newemps;
        }
    }

    foreach ($employees as $emp) {
        $row = [];
        $row[] = $emp['name'];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $cellType = $schedule[$emp['id']][$d]['type'] ?? '';
            $cellTime = $schedule[$emp['id']][$d]['time'] ?? '';
            // セルは "種類|時間" 形式で保存
            $row[] = $cellType . '|' . $cellTime;
        }
        fputcsv($fp, $row);
    }

    fclose($fp);

    echo "保存しました: " . htmlspecialchars(basename($filename));
    echo "<br/><a href=\"create_schedule.php\">編集に戻る</a>";
    exit;
}

// GET 表示側: 年/月の初期値
$currentYear = date('Y');
$currentMonth = date('n');
$year = isset($_GET['year']) ? intval($_GET['year']) : $currentYear;
$month = isset($_GET['month']) ? intval($_GET['month']) : $currentMonth;
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$half = (int)ceil($daysInMonth / 2);

// 部署選択肢（識別子 => 表示名）
$department_options = [
    'digitalstreaming' => 'デジタル報道部配信班',
    'systemrotation' => 'システム部ローテ業務',
    'renewspaper' => '新聞編集部整理班',
];

// 入力された部署(クエリ/セッション)を決定
$department_en = $_GET['department'] ?? $department_session ?? 'digitalstreaming';

// department_en がキーでない場合、表示名からキーを逆引きする（セッションに日本語名が入っているケース対策）
if (!array_key_exists($department_en, $department_options)) {
    $found = array_search($department_en, $department_options, true);
    if ($found !== false) {
        $department_en = $found;
    } else {
        // 安全策としてデフォルトにフォールバック
        $department_en = 'digitalstreaming';
    }
}

// req CSV のパス
$req_filename = __DIR__ . '/../data/req_' . $year . '_' . $month . '_' . $department_en . '.csv';

// CSV から従業員リストと希望休を読み込む
$desired_vacations = [];
if (file_exists($req_filename)) {
    if (($h = fopen($req_filename, 'r')) !== false) {
        // ヘッダを読み飛ばす
        $header = fgetcsv($h);
        while (($row = fgetcsv($h)) !== false) {
            $name = $row[0] ?? '';
            $raw = $row[1] ?? '';
            $days = [];
            if (trim($raw) !== '') {
                $parts = preg_split('/\s*,\s*/', trim($raw, "[] "));
                foreach ($parts as $p) {
                    if ($p !== '') {
                        $d = intval($p);
                        if ($d > 0) $days[] = $d;
                    }
                }
            }
            if ($name !== '') {
                $employees[] = ['id' => $name, 'name' => $name];
                $desired_vacations[$name] = $days;
            }
        }
        fclose($h);
    }
}

// フォールバック: CSV がない場合
if (count($employees) === 0) {
    $employees = [
        ['id' => '統括', 'name' => '統括'],
        ['id' => '副部長A', 'name' => '副部長A'],
        ['id' => '副部長B', 'name' => '副部長B'],
        ['id' => '副部長C', 'name' => '副部長C'],
        ['id' => '副部長D', 'name' => '副部長D'],
        ['id' => '部員A', 'name' => '部員A'],
        ['id' => '部員B', 'name' => '部員B'],
        ['id' => '部員C', 'name' => '部員C'],
        ['id' => '臨時・派遣A', 'name' => '臨時・派遣A'],
        ['id' => '臨時・派遣B', 'name' => '臨時・派遣B'],
        ['id' => '臨時・派遣C', 'name' => '臨時・派遣C'],
    ];
}

// 部署ごとの固定スタッフ順を使いたい場合（digitalstreaming の試行用）
if ($department_en === 'digitalstreaming') {
    // ユーザー指定の順序で表示する（CSV にあれば希望休を使い、なければ空にする）
    $fixed = [
        '部長A','部長B','副部長A','副部長B','部員A','部員B','部員C',
        '派遣A','派遣B','派遣C','派遣D','派遣E','副部長C','副部長D','部員D',
        '派遣F','派遣G'
    ];
    $newemps = [];
    foreach ($fixed as $f) {
        $newemps[] = ['id' => $f, 'name' => $f];
        if (!isset($desired_vacations[$f])) $desired_vacations[$f] = [];
    }
    // 上書き：ただしもし CSV に他の従業員が多数いて独自処理したければ別途対応
    $employees = $newemps;
}

// 初期スケジュール: 手動編集時は空で開始する（希望休の自動適用は自動生成時のみ行う）
$initial_schedule = [];
foreach ($employees as $emp) {
    $id = $emp['id'];
    $initial_schedule[$id] = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $initial_schedule[$id][$d] = ['type' => '', 'time' => ''];
    }
}

// 自動作成の実行・結果読み込み
// 破られた希望休の名前一覧（デフォルトは空配列）
$violated_names = [];
$generated_result = [];
if (isset($_GET['run_ilp']) && $_GET['run_ilp'] === '1') {
    switch ($department_en) {
        case 'digitalstreaming':
            $script = __DIR__ . '/digitalstreaming_shift_make.py';
            break;
        case 'systemrotation':
            $script = __DIR__ . '/systemrotation_shift_make.py';
            break;
        case 'renewspaper':
            $script = __DIR__ . '/renewspaper_editing.py';
            break;
        default:
            $script = __DIR__ . '/digitalstreaming_shift_make.py';
    }

    $req_file_for_arg = __DIR__ . '/../data/req_' . $year . '_' . $month . '_' . $department_en . '.csv';
    $python = __DIR__ . '/../../myenv/Scripts/python.exe';
    $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(basename($req_file_for_arg)) . ' ' . escapeshellarg($year) . ' ' . escapeshellarg($month) . ' 2>&1';
    $cwd = __DIR__;
    exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd, $output, $ret);
    echo "<pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
    // Python 側が searchstatus.txt を出力しているか確認し、緩和した場合はユーザに通知する
    $searchstatus = __DIR__ . '/searchstatus.txt';
    $violated_names = [];
    if (file_exists($searchstatus)) {
        $ss = file_get_contents($searchstatus);
        if (preg_match('/STATUS:([A-Z_]+)/', $ss, $m)) {
            $sst = $m[1];
            if ($sst === 'RELAXED') {
                $msg = '';
                if (preg_match('/MESSAGE:(.*)/', $ss, $mm)) $msg = trim($mm[1]);
                // 破られた希望休の従業員名があれば取得
                if (preg_match('/VIOLATED_NAMES:(.*)/', $ss, $vm)) {
                    $raw = trim($vm[1]);
                    if ($raw !== '') {
                        $violated_names = array_map('trim', explode(',', $raw));
                    }
                }
                echo '<div style="padding:10px;border:2px solid #d9534f;background:#fff0f0;color:#900;margin:8px 0;">';
                echo 'ILP は厳しい制約で解を見つけられませんでした。希望休条件を一部ソフトに緩和して代替スケジュールを自動生成しました。';
                if (!empty($violated_names)) {
                    echo '<br/>希望休に沿っていない従業員: ' . htmlspecialchars(implode(', ', $violated_names));
                }
                if ($msg !== '') echo '<br/>' . htmlspecialchars($msg);
                echo '</div>';
            } elseif ($sst === 'FAILED') {
                $msg = '';
                if (preg_match('/MESSAGE:(.*)/', $ss, $mm)) $msg = trim($mm[1]);
                echo '<div style="padding:10px;border:2px solid #d9534f;background:#fff0f0;color:#900;margin:8px 0;">';
                echo 'ILP 実行: 初回・緩和の両方で解を見つけられませんでした。手動での調整をお願いします。';
                if ($msg !== '') echo '<br/>' . htmlspecialchars($msg);
                echo '</div>';
            }
        }
    }

    if ($ret === 0) {
        $sp = __DIR__ . '/searchpath.txt';
        if (file_exists($sp)) {
            $outname = trim(file_get_contents($sp));
            $outpath = __DIR__ . '/../data/' . $outname;
            if (file_exists($outpath)) {
                // ファイルを読み取り、エンコーディングを自動判別して UTF-8 にそろえてから CSV 処理する
                $raw = @file_get_contents($outpath);
                if ($raw === false) {
                    write_debug("Failed to read ILP output file: " . $outpath);
                } else {
                    // BOM があれば除去
                    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
                    // 文字コードを検出（SJIS-win を優先的に含める）
                    $det = @mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP', 'ISO-2022-JP', 'ASCII'], true);
                    write_debug("Detected encoding for ILP CSV: " . var_export($det, true));
                    if ($det !== 'UTF-8' && $det !== false) {
                        // UTF-8 に変換
                        $raw = mb_convert_encoding($raw, 'UTF-8', $det);
                    }
                    // メモリストリームに書き込んで fgetcsv で読み取る
                    $h = fopen('php://memory', 'r+');
                    fwrite($h, $raw);
                    rewind($h);
                    // ヘッダを読んで、どの列が何日を表すかをマップする（堅牢化）
                    $headers = fgetcsv($h);
                    $colToDay = []; // csv 列インデックス => 日付 (1..daysInMonth)
                    // 社員名リストを取得しておく（照合ログ用）
                    $employeeNames = array_map(function($e){ return $e['name']; }, $employees);
                    write_debug("ILP output file opened: " . $outpath);
                    write_debug("CSV headers: " . json_encode($headers, JSON_UNESCAPED_UNICODE));
                    if (is_array($headers)) {
                        for ($ci = 1; $ci < count($headers); $ci++) {
                            $hv = trim($headers[$ci]);
                            // ヘッダが数値（1..daysInMonth）であれば日付列とみなす
                            if (is_numeric($hv)) {
                                $daynum = intval($hv);
                                if ($daynum >= 1 && $daynum <= $daysInMonth) {
                                    $colToDay[$ci] = $daynum;
                                }
                            }
                        }
                        // フォールバック: ヘッダが期待どおりでなければ連番マップを使う
                        if (count($colToDay) === 0) {
                            write_debug("Header did not contain numeric day columns, falling back to sequential mapping");
                            for ($ci = 1; $ci < count($headers); $ci++) {
                                $mapped = $ci; // そのまま列番号を日付として扱う
                                if ($mapped >= 1 && $mapped <= $daysInMonth) $colToDay[$ci] = $mapped;
                            }
                        }
                        write_debug("Column->Day map: " . json_encode($colToDay, JSON_UNESCAPED_UNICODE));
                    }

                    while (($r = fgetcsv($h)) !== false) {
                        $empname = trim($r[0]);
                        // 不要な集計行や空行をスキップする
                        if ($empname === '' || is_numeric($empname)) {
                            write_debug("Skipping row with empty or numeric name: '" . $empname . "'");
                            continue;
                        }
                        // もしヘッダにある types/times の集計行だったらスキップ
                        if (in_array($empname, $types, true) || in_array($empname, $times, true)) {
                            write_debug("Skipping aggregate/type row: '" . $empname . "'");
                            continue;
                        }

                        if (!in_array($empname, $employeeNames, true)) {
                            // 名前が完全一致しない場合、簡易正規化してマッチを試みる
                            $foundMatch = null;
                            $normEmp = preg_replace('/[^\p{L}\p{N}]/u', '', $empname);
                            foreach ($employeeNames as $en) {
                                $normEn = preg_replace('/[^\p{L}\p{N}]/u', '', $en);
                                if ($normEn === $normEmp || mb_strpos($normEn, $normEmp) !== false || mb_strpos($normEmp, $normEn) !== false) {
                                    $foundMatch = $en;
                                    break;
                                }
                            }
                            if ($foundMatch === null) {
                                write_debug("ILP output contains employee not in req CSV -> skipping: '" . $empname . "'");
                                continue;
                            } else {
                                write_debug("ILP output name '" . $empname . "' matched to employee '" . $foundMatch . "'");
                                $empname = $foundMatch;
                            }
                        }

                        $generated_result[$empname] = [];
                        for ($i = 1; $i < count($r); $i++) {
                            if (!isset($colToDay[$i])) {
                                // この列は日付列ではない（カウント列など）
                                write_debug("Skipping non-day column index: " . $i . " for employee " . $empname);
                                continue;
                            }
                            $dayIndex = $colToDay[$i];
                            $cell = trim($r[$i]);
                            $gtype = '';
                            $gtime = '';

                            if ($cell !== '') {
                                // トークン分割: |, /, 空白, カンマ を区切りとする
                                $parts = preg_split('/[\|\/\s,]+/', $cell);
                                foreach ($parts as $p) {
                                    $p = trim($p);
                                    if ($p === '') continue;
                                    // 優先的に types に一致するかチェック
                                    if (in_array($p, $types, true)) {
                                        $gtype = $p;
                                        continue;
                                    }
                                    // 次に times に一致するかチェック
                                    if (in_array($p, $times, true)) {
                                        $gtime = $p;
                                        continue;
                                    }
                                }

                                // 補完ルール: type が空で time がある場合は type を '出勤' にする
                                if ($gtype === '' && $gtime !== '') {
                                    $gtype = '出勤';
                                }

                                // もしどちらも空ならセル全体を type に入れる
                                if ($gtype === '' && $gtime === '') {
                                    // 1文字または2文字で times に含まれるなら time とみなす
                                    if (in_array($cell, $times, true)) {
                                        $gtime = $cell;
                                        $gtype = '出勤';
                                    } else {
                                        $gtype = $cell;
                                    }
                                }
                            }

                            $generated_result[$empname][$dayIndex] = ['type' => $gtype, 'time' => $gtime];
                            write_debug("Parsed cell - emp: '" . $empname . "', col: " . $i . ", day: " . $dayIndex . ", raw: '" . $cell . "', type: '" . $gtype . "', time: '" . $gtime . "'");
                        }
                    }
                    fclose($h);
                    // --- ここで生成結果に穴があれば自動補完する ---
                    // 派遣トップ5 名の名前配列（表示/照合用）
                    $haken_primary_names = ['派遣A','派遣B','派遣C','派遣D','派遣E'];
                    // for each employee ensure entries for all days
                    foreach ($employees as $empRow) {
                        $ename = $empRow['name'];
                        if (!isset($generated_result[$ename])) $generated_result[$ename] = [];
                        for ($d = 1; $d <= $daysInMonth; $d++) {
                            if (!isset($generated_result[$ename][$d]) || $generated_result[$ename][$d]['type'] === '') {
                                // 希望休があれば公休で埋める（ILP で特殊休と分けたければ後処理）
                                $isDesired = in_array($d, $desired_vacations[$ename] ?? [], true);
                                if ($isDesired) {
                                    $generated_result[$ename][$d] = ['type' => '公休', 'time' => ''];
                                    continue;
                                }
                                // 派遣トップ5 は ILP に任せる（穴があれば空のまま）
                                if (in_array($ename, $haken_primary_names, true)) {
                                    // leave empty to allow manual edit
                                    $generated_result[$ename][$d] = ['type' => '', 'time' => ''];
                                    continue;
                                }
                                // 派遣だがトップ5以外 (派遣F/G 等): 平日はロ、土日祝は公休
                                if (mb_substr($ename, 0, 2) === '派遣') {
                                    $wd = date('w', mktime(0,0,0,$month,$d,$year));
                                    if ($wd === 0 || $wd === 6) {
                                        $generated_result[$ename][$d] = ['type' => '公休', 'time' => ''];
                                    } else {
                                        $generated_result[$ename][$d] = ['type' => '出勤', 'time' => 'ロ'];
                                    }
                                    continue;
                                }
                                // 非派遣スタッフ: 休日以外はホ出勤で埋める
                                $wd = date('w', mktime(0,0,0,$month,$d,$year));
                                if ($wd === 0 || $wd === 6) {
                                    $generated_result[$ename][$d] = ['type' => '', 'time' => ''];
                                } else {
                                    $generated_result[$ename][$d] = ['type' => '出勤', 'time' => 'ホ'];
                                }
                            }
                        }
                    }
                }
            }
        }
    } else {
        echo "ILP 実行に失敗しました (コード: $ret)";
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" type="text/css" href="/pbl/style/home.css" />
    <title>シフト作成（骨組み）</title>
    <style>
        /* テーブル外枠は残しつつ、セルの「四角」境界を目立たなくする */
        table { border-collapse: separate; border-spacing: 0; width: 100%; border: 1px solid #e0e0e0; }
        th, td { border: none; padding: 6px; text-align: center; vertical-align: middle; }
        /* 行ごとの薄い区切りを残す（視認性向上） */
        tbody tr + tr td { border-top: 1px solid #f0f0f0; }
        /* ヘッダの縦区切りは最小限に */
        thead th + th { border-left: 1px solid #f0f0f0; }
        .name-cell { width: 180px; text-align: left; padding-left: 8px; }
        /* セレクト要素の枠線を消してフラットにする。矢印が文字を隠さないよう右に余白を確保し、ネイティブ矢印を消して小さなカスタム矢印を表示 */
        .cell-select {
            width: 100%;
            box-sizing: border-box;
            margin-bottom: 4px;
            border: none;
            background: transparent;
            padding: 4px 22px 4px 4px; /* 右に余白を確保 */
            text-align: left;
            min-width: 36px;
            /* ブラウザのネイティブ矢印を消す */
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            /* カスタム小さな矢印（SVGインライン）を右に表示 */
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path fill='%23000' d='M0 0l5 6 5-6z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 6px center;
            background-size: 10px 6px;
        }
        .cell-select:focus { outline: 2px solid rgba(100,150,200,0.35); outline-offset: 1px; }
        /* Internet Explorer / old Edge の矢印非表示対策 */
        .cell-select::-ms-expand { display: none; }
    .violated-name { background: #fff5f5; border-left: 4px solid #d9534f; padding-left: 6px; }
    .half-title { margin-top: 20px; margin-bottom: 8px; font-weight: bold; }
    .disabled-day { background: #fafafa; color: #999; }
    /* 出勤セルのハイライト */
    .work-day { background: #e9f8ee; }
    /* 出勤以外（公休等）は勤務時間も含めて灰色表示 */
    .non-work { background: #f6f6f6; color: #666; }
    /* 出勤時に内部をハイライトするラッパー（td の再構成を避けるため） */
    .work-wrap { background: #e9f8ee; display: inline-block; width: 100%; height: 100%; padding: 2px 0; box-sizing: border-box; }
        .disabled-day select { display: none; }
    </style>
</head>
<body>
<div class="header">
    <a href="/pbl/home.php"><h1>愛媛新聞社 シフト管理システム — シフト作成（骨組み）</h1></a>
</div>
<div class="logout"><span><?php echo htmlspecialchars($name); ?> さん</span></div>

<div style="width:95%; margin: 12px auto;">
    <form method="get" action="create_schedule.php">
        年: <input type="number" name="year" value="<?php echo $year; ?>" style="width:80px;" />
        月: <input type="number" name="month" value="<?php echo $month; ?>" style="width:60px;" />
        部署: <select name="department">
            <?php foreach ($department_options as $k => $v): ?>
                <option value="<?php echo htmlspecialchars($k); ?>" <?php if ($k === $department_en) echo 'selected'; ?>><?php echo htmlspecialchars($v); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">表示</button>
    </form>

    <form method="get" action="create_schedule.php" style="margin-top:8px;">
        <input type="hidden" name="year" value="<?php echo $year; ?>" />
        <input type="hidden" name="month" value="<?php echo $month; ?>" />
        <input type="hidden" name="department" value="<?php echo htmlspecialchars($department_en); ?>" />
        <input type="hidden" name="run_ilp" value="1" />
        <button type="submit">自動作成 (ILP 実行)</button>
    </form>

    <form method="post" action="create_schedule.php">
    <input type="hidden" name="year" value="<?php echo $year; ?>" />
    <input type="hidden" name="month" value="<?php echo $month; ?>" />
    <label>部署: <input type="text" name="department" value="<?php echo htmlspecialchars($department_en); ?>" /></label>

        <div class="half-title">前半（1〜16日）</div>
        <table>
            <thead>
                <tr>
                    <th>名前</th>
                    <?php for ($d = 1; $d <= 16; $d++): ?>
                        <?php $is_disabled = ($d > $daysInMonth); ?>
                        <th class="<?php echo $is_disabled ? 'disabled-day' : ''; ?>"><?php echo $d; ?></th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): ?>
                    <?php $ename = $emp['name']; ?>
                    <tr>
                        <?php $vi_class = in_array($emp['name'], $violated_names, true) ? 'violated-name' : ''; ?>
                        <td class="name-cell <?php echo $vi_class; ?>" rowspan="2"><?php echo htmlspecialchars($emp['name']); ?></td>
                        <?php for ($d = 1; $d <= 16; $d++): ?>
                            <?php $is_disabled = ($d > $daysInMonth);
                                $rawType = '';
                                $rawTime = '';
                                if (!$is_disabled) {
                                    if (isset($generated_result[$ename][$d])) {
                                        $rawType = $generated_result[$ename][$d]['type'];
                                        $rawTime = $generated_result[$ename][$d]['time'] ?? '';
                                    } else if (isset($initial_schedule[$emp['id']][$d])) {
                                        $rawType = $initial_schedule[$emp['id']][$d]['type'];
                                        $rawTime = $initial_schedule[$emp['id']][$d]['time'];
                                    }
                                }
                                // 表示用ルール: time が書かれていて type が空なら type を '出勤' として扱う
                                $displayType = $rawType;
                                $displayTime = $rawTime;
                                if ($displayType === '' && $displayTime !== '') {
                                    $displayType = '出勤';
                                }
                                $tdClass = $is_disabled ? 'disabled-day' : ($displayType === '出勤' ? 'work-day' : ($displayType !== '' ? 'non-work' : ''));
                            ?>
                            <td class="<?php echo $tdClass; ?>">
                                <?php if ($is_disabled): ?>
                                    &nbsp;
                                <?php else: ?>
                                    <!-- 上段: 勤務形態 -->
                                    <select class="cell-select type-select" data-emp="<?php echo htmlspecialchars($emp['id']); ?>" data-day="<?php echo $d; ?>" name="schedule[<?php echo $emp['id']; ?>][<?php echo $d; ?>][type]">
                                        <option value=""></option>
                                        <?php foreach ($types as $t): ?>
                                            <option value="<?php echo htmlspecialchars($t); ?>" <?php if ($displayType === $t) echo 'selected'; ?>><?php echo htmlspecialchars($t); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                    <tr>
                        <?php for ($d = 1; $d <= 16; $d++): ?>
                            <?php $is_disabled = ($d > $daysInMonth);
                                $rawType = '';
                                $rawTime = '';
                                if (!$is_disabled) {
                                    if (isset($generated_result[$ename][$d])) {
                                        $rawType = $generated_result[$ename][$d]['type'];
                                        $rawTime = $generated_result[$ename][$d]['time'] ?? '';
                                    } else if (isset($initial_schedule[$emp['id']][$d])) {
                                        $rawType = $initial_schedule[$emp['id']][$d]['type'];
                                        $rawTime = $initial_schedule[$emp['id']][$d]['time'];
                                    }
                                }
                                // 表示用判定（上段で使用した displayType と合わせる）
                                $displayType2 = $rawType;
                                if ($displayType2 === '' && $rawTime !== '') $displayType2 = '出勤';
                                $tdClass2 = $is_disabled ? 'disabled-day' : ($displayType2 === '出勤' ? 'work-day' : ($displayType2 !== '' ? 'non-work' : ''));
                                $showTime = ($rawTime !== '');
                            ?>
                            <td class="<?php echo $tdClass2; ?>">
                                <?php if ($is_disabled): ?>
                                    &nbsp;
                                <?php else: ?>
                                    <select class="cell-select time-select" data-emp="<?php echo htmlspecialchars($emp['id']); ?>" data-day="<?php echo $d; ?>" name="schedule[<?php echo $emp['id']; ?>][<?php echo $d; ?>][time]" <?php echo $showTime ? '' : 'disabled'; ?>>
                                        <option value=""></option>
                                        <?php foreach ($times as $tm): ?>
                                            <option value="<?php echo htmlspecialchars($tm); ?>" <?php if ($rawTime === $tm) echo 'selected'; ?>><?php echo htmlspecialchars($tm); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="half-title">後半（17〜31日）</div>
        <table>
            <thead>
                <tr>
                    <th>名前</th>
                    <?php for ($d = 17; $d <= 31; $d++): ?>
                        <?php $is_disabled = ($d > $daysInMonth); ?>
                        <th class="<?php echo $is_disabled ? 'disabled-day' : ''; ?>"><?php echo $d; ?></th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): ?>
                    <?php $ename = $emp['name']; ?>
                    <tr>
                        <?php $vi_class = in_array($emp['name'], $violated_names, true) ? 'violated-name' : ''; ?>
                        <td class="name-cell <?php echo $vi_class; ?>" rowspan="2"><?php echo htmlspecialchars($emp['name']); ?></td>
                        <?php for ($d = 17; $d <= 31; $d++): ?>
                            <?php $is_disabled = ($d > $daysInMonth);
                                $rawType = '';
                                $rawTime = '';
                                if (!$is_disabled) {
                                    if (isset($generated_result[$ename][$d])) {
                                        $rawType = $generated_result[$ename][$d]['type'];
                                        $rawTime = $generated_result[$ename][$d]['time'] ?? '';
                                    } else if (isset($initial_schedule[$emp['id']][$d])) {
                                        $rawType = $initial_schedule[$emp['id']][$d]['type'];
                                        $rawTime = $initial_schedule[$emp['id']][$d]['time'];
                                    }
                                }
                                $displayType = $rawType;
                                $displayTime = $rawTime;
                                if ($displayType === '' && $displayTime !== '') {
                                    $displayType = '出勤';
                                }
                                $tdClass = $is_disabled ? 'disabled-day' : ($displayType === '出勤' ? 'work-day' : '');
                            ?>
                            <td class="<?php echo $tdClass; ?>">
                                <?php if ($is_disabled): ?>
                                    &nbsp;
                                <?php else: ?>
                                    <select class="cell-select type-select" data-emp="<?php echo htmlspecialchars($emp['id']); ?>" data-day="<?php echo $d; ?>" name="schedule[<?php echo $emp['id']; ?>][<?php echo $d; ?>][type]">
                                        <option value=""></option>
                                        <?php foreach ($types as $t): ?>
                                            <option value="<?php echo htmlspecialchars($t); ?>" <?php if ($displayType === $t) echo 'selected'; ?>><?php echo htmlspecialchars($t); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                    <tr>
                        <?php for ($d = 17; $d <= 31; $d++): ?>
                            <?php $is_disabled = ($d > $daysInMonth);
                                $rawType = '';
                                $rawTime = '';
                                if (!$is_disabled) {
                                    if (isset($generated_result[$ename][$d])) {
                                        $rawType = $generated_result[$ename][$d]['type'];
                                        $rawTime = $generated_result[$ename][$d]['time'] ?? '';
                                    } else if (isset($initial_schedule[$emp['id']][$d])) {
                                        $rawType = $initial_schedule[$emp['id']][$d]['type'];
                                        $rawTime = $initial_schedule[$emp['id']][$d]['time'];
                                    }
                                }
                                $displayType2 = $rawType;
                                if ($displayType2 === '' && $rawTime !== '') $displayType2 = '出勤';
                                $tdClass2 = $is_disabled ? 'disabled-day' : ($displayType2 === '出勤' ? 'work-day' : ($displayType2 !== '' ? 'non-work' : ''));
                                $showTime = ($rawTime !== '');
                            ?>
                            <td class="<?php echo $tdClass2; ?>">
                                <?php if ($is_disabled): ?>
                                    &nbsp;
                                <?php else: ?>
                                    <select class="cell-select time-select" data-emp="<?php echo htmlspecialchars($emp['id']); ?>" data-day="<?php echo $d; ?>" name="schedule[<?php echo $emp['id']; ?>][<?php echo $d; ?>][time]" <?php echo $showTime ? '' : 'disabled'; ?>>
                                        <option value=""></option>
                                        <?php foreach ($times as $tm): ?>
                                            <option value="<?php echo htmlspecialchars($tm); ?>" <?php if ($rawTime === $tm) echo 'selected'; ?>><?php echo htmlspecialchars($tm); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:12px;">
            <button type="submit">CSV に保存</button>
        </div>
    </form>
</div>
</body>
<script>
document.addEventListener('DOMContentLoaded', function(){
    function updateFromType(typeEl){
        var emp = typeEl.getAttribute('data-emp');
        var day = typeEl.getAttribute('data-day');
        var type = typeEl.value;
        var timeSelector = '.time-select[data-emp="'+emp+'"][data-day="'+day+'"]';
        var timeEl = document.querySelector(timeSelector);
        if(!timeEl) return;
        var topTd = typeEl.closest('td');
        var bottomTd = timeEl.closest('td');
        if(type === '出勤'){
            if(topTd){ topTd.classList.remove('non-work'); topTd.classList.add('work-day'); }
            if(bottomTd){ bottomTd.classList.remove('non-work'); bottomTd.classList.add('work-day'); }
            timeEl.disabled = false;
            if(!timeEl.value) timeEl.value = 'F';
        } else if(type === ''){
            if(topTd){ topTd.classList.remove('work-day','non-work'); }
            if(bottomTd){ bottomTd.classList.remove('work-day','non-work'); }
            timeEl.disabled = true;
        } else {
            if(topTd){ topTd.classList.remove('work-day'); topTd.classList.add('non-work'); }
            if(bottomTd){ bottomTd.classList.remove('work-day'); bottomTd.classList.add('non-work'); }
            timeEl.disabled = true;
        }
    }
    document.querySelectorAll('.type-select').forEach(function(el){
        el.addEventListener('change', function(){ updateFromType(el); });
        // initial sync
        updateFromType(el);
    });
});
</script>
</html>
