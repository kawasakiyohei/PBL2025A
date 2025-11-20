<?php
    ini_set('display_errors', "On");

    try {
        // POSTデータを取得
        $employeenumber = $_POST['employeenumber'];
        $pass = $_POST['pass'];

        // HTMLエスケープ処理
        $employeenumber = htmlspecialchars($employeenumber, ENT_QUOTES, 'UTF-8');
        $pass = htmlspecialchars($pass, ENT_QUOTES, 'UTF-8');
        
        // パスワードのハッシュ化（必要に応じて利用）
        //$pass = md5($pass);

        // データベース接続情報
        $dsn = 'mysql:dbname=pbl;host=localhost;charset=utf8';
        $user = 'root';
        $password = '';
        $dbh = new PDO($dsn, $user, $password);
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // SQLクエリ準備（`position`カラムはログインに利用しないので取得しない）
        $sql = 'SELECT name, position, department FROM members WHERE employeenumber=? AND password=?';
        $stmt = $dbh->prepare($sql);

        // バインドするデータを配列に格納
        $data[] = $employeenumber;
        $data[] = $pass;

        // SQL実行
        $stmt->execute($data);

        // データベース接続を閉じる
        $dbh = null;

        // 結果取得
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        // 結果の判定
        if ($rec == false) {
            print 'スタッフコードかパスワードが間違っています<br/>';
            print '<a href="/pbl/login.html">戻る</a>';
        } else {
            // セッション開始とデータ格納
            session_start();
            $_SESSION['login'] = 1;
            $_SESSION['employeenumber'] = $employeenumber;
            $_SESSION['name'] = $rec['name'];
            $_SESSION['position'] = $rec['position'];

            // 部署の英語キーと表示ラベルのマッピング
            // 内部では英語キーを使い、表示用にはラベルを保持する
            $department_map = [
                'digitalstreaming' => 'デジタル報道部配信班',
                'systemrotation' => 'システム部ローテ業務',
                'renewspaper' => '新聞編集部整理班',
            ];

            $raw_dept = $rec['department']; // DB に入っている値（英語キーか日本語ラベル）

            // もし DB に英語キーがそのまま入っていればそれを使う
            if (array_key_exists($raw_dept, $department_map)) {
                $department_en = $raw_dept;
                $department_label = $department_map[$department_en];
            } else {
                // 日本語ラベルが入っている場合は逆引きして英語キーを得る
                $department_en = array_search($raw_dept, $department_map, true);
                if ($department_en === false) {
                    // 未知の部署名なら安全側のデフォルト (digitalstreaming)
                    $department_en = 'digitalstreaming';
                    // 部署ラベルは元の値を保持する（将来的にマップに追加することを推奨）
                    $department_label = $raw_dept;
                } else {
                    $department_label = $raw_dept;
                }
            }

            // セッションには英語キーと表示ラベルの両方を入れておく
            $_SESSION['department'] = $department_en;
            $_SESSION['department_label'] = $department_label;
            header('Location:/pbl/home.php');
            exit();
        }
    } catch (PDOException $e) {
        // データベース接続やSQL実行で発生したエラーをキャッチ
        print 'データベースエラー: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '<br/>';
        print '<a href="/pbl/login.html">戻る</a>';
    } catch (Exception $e) {
        // その他のエラーをキャッチ
        print 'エラーが発生しました: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '<br/>';
        print '<a href="/pbl/login.html">戻る</a>';
    }
?>
