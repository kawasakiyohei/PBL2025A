# Copilot / AI エージェント向け必読メモ (プロジェクト: pbl)

以下は、このリポジトリ（`pbl`）で素早く生産的になるための最重要ポイントです。
archive フォルダは開発対象外として扱うこと（読み取り参照のみ）。

## 1) プロジェクトの“大きな絵”
- シンプルなPHPベースのシフト管理アプリ。フロントエンドはHTML/CSSと最小のクライアントJS。サーバは PHP ファイルで直接レンダリング。
- 主な役割:
  - ユーザー認証: `admin/account/` 内のファイル（`login_check.php` 等）でセッション管理。ページ先頭で `session_start()`→`$_SESSION['name']` をチェックするパターン。
  - シフト希望の入力: `inputrequest.php` がフォームを提供し、CSV（`admin/data/req_YYYY_M_<department>.csv`）へ保存する。
  - CSVデータの保管と配布: `admin/data/`（入力／出力の中心）、`dl/` と `uploads/` にもファイルが置かれる。
  - バッチ処理 / シフト作成: `shift/` に Python スクリプト（`digitalstreaming_shift_make.py` 等）があり、ローカルPython環境（`myenv/`）で動作する。

## 2) 重要ディレクトリ & 代表ファイル（参照用）
- `inputrequest.php` — ユーザーの休み希望を入力し、`admin/data/req_{year}_{month}_{department}.csv` に保存する（デフォルト行を作るロジックあり）。
- `admin/data/` — CSV 保管。命名規則: `req_<YYYY>_<M>_<department>.csv` や `req_<YYYY>_<M>_digitalstreaming.csv` 等。
- `admin/account/` — 認証・ログアウト・ユーザー編集等。セッション依存のフローはここを追う。
- `shift/` — Python スクリプト群（シフト生成、CSV編集、アップロード処理）。実行時は `myenv` を使うことを想定。
- `uploads/`, `dl/` — アップロードされたCSVやダウンロード用のコピーが格納される。
- `style/` — CSS（`home.css`, `login.css`）

## 3) 開発 / 実行ワークフロー（Windows / XAMPP 想定）
- Web サーバ: XAMPP の Apache + PHP で動作する想定。ドキュメントルートは `c:\xampp\htdocs\`、ブラウザで `http://localhost/pbl/` を開く。
- PHP ファイルを編集したらブラウザでページを再読み込みして動作確認。
- Python スクリプト実行 (shift): プロジェクトのローカル venv `myenv` を使う。Windows の場合:

```powershell
# cmd.exe では
c:\xampp\htdocs\pbl\myenv\Scripts\activate.bat
# その後 (例)
python shift\digitalstreaming_shift_make.py
```

- ログ/デバッグ:
  - PHP 側はファイル内で `ini_set('display_errors', 1);` が使われていることが多い。
  - Apache のエラーログ（XAMPP control panel）も参照する。

## 4) プロジェクト固有のコードパターン / 注意点
- 手続き的PHP: 多くのページはヘッダ（HTML）と処理（POST処理）を同ファイルで行う。新しい機能は既存のスタイルに合わせる。
- セッションベースの認証: ページの最初に `session_start()` と `if (!isset($_SESSION['name'])) { header('Location: login.php'); exit(); }` を置くパターンを踏襲。
- CSV 書き込み/上書きの流れ:
  1. `req_*.csv` がなければ初期データを作成（ヘッダと職位行など）。
  2. 既存ユーザー名行があれば更新、無ければ追記するロジック（`inputrequest.php` の実装参照）。
- パス参照: HTML内で `/pbl/...` の絶対パスを使っている箇所がある。編集時はルートを壊さないよう相対/絶対パスに注意。
- 文字コード: 日本語コンテンツが中心。CSV を読み書きする際はシステムの既定エンコーディング（Windows では SHIFT-JIS 等の可能性）に注意 — 既存コードは単純な `fputcsv()`/`fgetcsv()` を使っている。

## 5) 変更時のベストプラクティス（このリポジトリに特化）
- 小さな変更を逐次ブラウザで確認する（直接 PHP をレンダリングするワークフロー）。
- CSV を扱う処理はまず `admin/data/` のバックアップコピーを作る（間違って上書きしたときに復元できる）。
- Python スクリプトや新しいライブラリを追加する場合は `myenv` にインストールし、`myenv\Scripts\` を使って実行する。

## 6) 例: よくあるタスクと参照ファイル
- シフト希望を保存するロジックを理解する → `inputrequest.php` を参照。
- 認証フローを確認・修正する → `admin/account/login_check.php`, `admin/account/logout.php`。
- 既存CSVフォーマットに追記／更新処理を追加する → `admin/data/` 内の既存ファイルと `inputrequest.php` の書き込みルールを確認。
- バッチ処理に新しい出力を追加 → `shift/` の Python スクリプトと `myenv/` の仮想環境を使う。

## 7) 何を編集すべきでないか
- `archive/` ディレクトリは作業対象外（参照のみ）。
- 本番公開を想定したDBや外部API呼び出しは見当たらないが、ファイルパスや権限を壊すと実行不能になるため注意。

---
このファイルの内容は現状のリポジトリから直接読み取れる振る舞い・ファイル配置に基づいて作成しました。内容で不明点や補足して欲しい箇所（例えば、特定のファイルの挙動を深掘りする、Python の依存ライブラリ一覧を作る、CSV のエンコーディングポリシーを決める等）があれば教えてください。