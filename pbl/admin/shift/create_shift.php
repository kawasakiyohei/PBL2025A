<?php

session_start(); // セッションを開始
ini_set('display_errors', "On");
$name = $_SESSION['name'];
$position = $_SESSION['position'];
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
  <meta charset="UTF-8">
  <title>シフト社の勤務表作成ツール</title>
  <link rel="stylesheet" type="text/css" href="/pbl/style/home.css" />
  <style>
    .form {
            text-align: center;
            width: 60%;
            margin: auto;
            background-color: #f0f8ff;
        }
    #form{
      text-align: center;
    }
  </style>
</head>
<body>
<div>
        <div class="header">
            <a href="/pbl/home.php"><h1>愛媛新聞社 シフト管理システム</h1></a>
    </div>
    <button>設定</button>
    <div class="logout">
        <span><?php echo $name;?> さん</span>
        <button onclick="location.href='/pbl/admin/account/logout.php'">ログアウト</button>
    </div>  
<div class="form">
<h2>シフト作成フォーム</h2>
<form action="upload.php" method="post" enctype="multipart/form-data">
<div>
  <table id="form"><tr><th>年を入力：<input type="text" name="year" size="4" placeholder="YYYY" />
            </th><th>月を入力：<input type="text" name="month" size="2" placeholder="MM" /></th>
            <th>部署選択：<select name="department">
            <option value="デジタル報道部配信班">部署A</option>
            <option value="システム部ローテ業務">部署B</option>
            <option value="新聞編集部整理班">部署C</option>
            </select></th></tr>
  </table>

</div>
<div>
  <button type="submit">作成開始</button>
</div>

</form>
</div>
</div>
</body>
</html>
