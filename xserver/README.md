# 草加健康センター 予約システム — XSERVER（PHP + MySQL）版

Cloudflare Workers 版（リポジトリ直下の `src/`）と**同じURL・同じ画面・同じ予約仕様**を、
XSERVER のレンタルサーバー（Apache + PHP 8.4 + MySQL）で動かすための実装です。

Workers 版は削除していません。両方が並存し、どちらを本番にするかは運用側で選べます。

---

## 1. 構成

```
xserver/
├── app/                        # アプリ本体（ドキュメントルートの外に置く）
│   ├── App.php                 # 依存の組み立てとルーティング
│   ├── autoload.php            # Composer不要のPSR-4風オートローダ
│   ├── bootstrap.php           # 設定 + DB接続（Web/CLI共通）
│   ├── Auth/
│   │   ├── AdminAuth.php       # 管理者のパスワード認証（password_verify）
│   │   ├── LineLogin.php       # LINE Login（OAuth2 / OIDC + PKCE）
│   │   └── Session.php         # HMAC-SHA256 署名付きCookie / CSRF / PKCE
│   ├── Controllers/
│   │   ├── AdminController.php
│   │   ├── AuthController.php
│   │   ├── BookingController.php
│   │   └── PublicController.php
│   ├── Database/
│   │   ├── Connection.php      # PDO(MySQL) 接続（UTC固定・エミュレート無効）
│   │   ├── Db.php              # first/all/run/insert/scalar/transaction
│   │   └── Migrator.php        # database/migrations/*.sql の適用
│   ├── Http/
│   │   ├── Request.php         # 同名スカラーPOST（slot_selected）も保持する
│   │   ├── Response.php
│   │   └── Router.php          # `/reserve/{slug}` 形式のパターン
│   ├── Repositories/
│   │   ├── BookingRepository.php
│   │   ├── NotificationRepository.php
│   │   ├── SlotRepository.php
│   │   └── UserRepository.php
│   ├── Services/
│   │   ├── BookingService.php  # ★ 予約の中核（トランザクション + FOR UPDATE）
│   │   ├── BookingException.php
│   │   ├── CsvService.php      # 名簿CSV（BOM・数式インジェクション無害化）
│   │   ├── CurlHttpClient.php
│   │   ├── HttpClient.php
│   │   ├── LineMessenger.php   # LINE Messaging API push
│   │   └── ReminderService.php # 予約完了通知・リマインド
│   ├── Support/
│   │   ├── Config.php  ConfigError.php  Html.php  Messages.php  Time.php  Uuid.php
│   └── Views/
│       ├── Layout.php  SlotParts.php
│       ├── HomeView.php  ReserveView.php  BookingDetailView.php
│       ├── MyBookingsView.php  LoginView.php
│       └── admin/AdminDashboardView.php  AdminPagesView.php  AdminSlotDetailView.php
├── bin/
│   ├── migrate.php             # CLI専用: マイグレーション
│   └── cron-reminders.php      # CLI専用: リマインド送信（XSERVER Cron から5分毎）
├── config/
│   └── config.example.php      # これをコピーして config.local.php を作る
├── database/migrations/
│   ├── 0001_initial.sql
│   ├── 0002_seed_rakko.sql
│   ├── 0003_notification_sending_state.sql
│   └── 0004_notification_line_retry_key.sql
├── public/                     # ← ここだけをドキュメントルートにする
│   ├── .htaccess
│   ├── index.php               # フロントコントローラ
│   └── assets/app.css  reserve.js  booking-cancel.js
├── tests/                      # 依存ゼロのテストランナー
└── README.md
```

CSS と クライアントJS は Workers 版の `src/styles/*.ts` / `src/views/*.ts` から
**そのまま抜き出した**ものです。画面の見た目・挙動は Phase 2F と同一です。

---

## 2. 動作要件

| 項目 | 値 |
|---|---|
| PHP | 8.4（`declare(strict_types=1)` / 列挙的 match / readonly promoted properties を使用） |
| 必須拡張 | `pdo_mysql`, `curl`, `mbstring`, `json`, `openssl` |
| MySQL | 5.7 以上（XSERVER の MySQL / MariaDB 10.5+ で動作。`CHECK` 制約と生成列を使用） |
| Web サーバー | Apache + `mod_rewrite`（`.htaccess` 同梱） |
| Composer | **不要**（自前オートローダ） |

---

## 3. データベース

すべて InnoDB / `utf8mb4_unicode_ci`。日時はすべて **UTC** で保存し、表示時に JST へ変換します。

| テーブル | 役割 |
|---|---|
| `users` | LINEユーザー（`line_user_id` が UNIQUE） |
| `reservation_pages` | 予約ページ（slug・公開状態・複数枠予約の可否など） |
| `reservation_slots` | 予約枠（定員・受付期間・リマインド時刻・`reserved_seats`） |
| `bookings` | 予約（`booking_group_id` で一括予約をまとめる） |
| `notifications` | 通知の送信状態（`status` / `claim_token` / `line_retry_key`） |
| `schema_migrations` | 適用済みマイグレーション |

### 二重予約の防止（SQLite の部分ユニークインデックスの代替）

D1(SQLite) では `WHERE status='confirmed'` 付きの部分ユニークインデックスで実現していました。
MySQL には部分インデックスが無いため、**生成列 + UNIQUE** に置き換えています。

```sql
dedupe_key VARCHAR(48) GENERATED ALWAYS AS (
  CASE WHEN status = 'confirmed' AND user_id IS NOT NULL
       THEN CONCAT(user_id, ':', reservation_slot_id) ELSE NULL END
) STORED,
UNIQUE KEY ux_bookings_user_slot_confirmed (dedupe_key)
```

`NULL` は UNIQUE の対象外なので、キャンセル済みと代理予約（`user_id IS NULL`）は重複扱いになりません。

### 定員超過の防止（DB側の最後の砦）

```sql
reserved_seats INT UNSIGNED NOT NULL DEFAULT 0,
CONSTRAINT ck_slots_reserved CHECK (reserved_seats <= capacity)
```

`reserved_seats` は予約・キャンセルと同じトランザクションで増減します。
アプリのバグや手作業のUPDATEで定員を超えようとしても、DBが拒否します。

### 通知の二重送信の防止

`notifications` は `UNIQUE(booking_id, notification_type)` で1予約1通知に限定したうえで、
**送信権を `sending` 状態への原子的な遷移で1プロセスに絞ります**。

```
pending / failed        -> sending    （claim。勝った1プロセスだけが送信する）
sending                 -> requested / failed / skipped
                                      （finish。claim_token が一致する場合のみ）
sending が長時間放置     -> sending    （送信中に落ちたプロセスの後始末）
```

`claim()` は次の単一UPDATEで送信権を取ります。MySQL はUPDATEで行ロックを取ったあとに
WHERE句を再評価するため、同時に走った2つ目のUPDATEは `sending` になった行に一致せず0行になります。

```sql
UPDATE notifications
   SET status = 'sending', claim_token = ?, attempt_count = attempt_count + 1, updated_at = ?
 WHERE booking_id = ? AND notification_type = ?
   AND attempt_count < ?
   AND (status IN ('pending','failed') OR (status = 'sending' AND updated_at < ?))
```

更新できたプロセスだけが `claim_token` を受け取り、Messaging API を呼びます。
`finish()` はその token が一致する場合のみ状態を遷移させるため、
送信中に落ちたプロセスが後から目を覚まして `finish()` しても、
その間に別プロセスが再取得した送信権を壊しません。

放置された `sending` は `NotificationRepository::STALE_SENDING_SECONDS`（600秒）を過ぎると
再試行の対象に戻ります。push の HTTP タイムアウト（10秒）より十分に長くとってあります。

#### ネットワーク境界での二重送信の防止（X-Line-Retry-Key）

`sending` + `claim_token` はアプリ／DB側の同時実行競合を防ぎますが、
**LINEがpushを受理した直後にPHPプロセスが落ち、DBへ `requested` を書けなかった**場合には、
stale再取得後の再試行で同じ通知がもう一度配信されえます。

そこで Push Message の `X-Line-Retry-Key` を**初回送信から必ず**付けています。
LINE側が同じキーのリクエストを重複と判定して `409` を返すため、
ネットワーク境界を越えた冪等性が得られます。`409` は「前回の送信が受理済み」を意味するので、
再送せず `requested` として確定させます（通常の4xxは従来どおり `skipped`）。

| | 役割 | 寿命 |
|---|---|---|
| `claim_token` | 送信権の排他。誰が送っているかを識別する | claim のたびに変わる |
| `line_retry_key` | 同じ通知の同じ内容の再送であることをLINEに伝える | 一度発行したら保持（`COALESCE` で上書きしない） |

5xx / タイムアウト / stale再取得のいずれでも同じ retry key を再利用します。
一括予約で複数通知を1通にまとめて送る場合は、対象キーの集合から
`Uuid::derive()` で決定的に導出します。こうすると
「同じ顔ぶれの再送＝同じキー（LINEが重複排除する）」
「顔ぶれが変わった＝本文も変わるので別キー（ちゃんと届く）」が両立します。

**retry key には有効期限があります。** LINE Platform が retry key を保持するのは
24時間で、それを過ぎると同じキーでも重複と判定されず、そのまま2通目として配信されます。
`LineMessenger::RETRY_KEY_TTL_SECONDS`（23時間。1時間の安全マージン込み）を超えた通知は
**自動再送せず `skipped` で確定**させます（`last_error` に理由を記録）。
丸1日届かなかった通知を無理に送り直すより、二重配信を避けるほうが害が小さいためです。
判定の基準は初回送信試行からの経過時間で、まだ1回も送っていない通知は対象外です。

retry key は必須です。`push()` は不正な形式のキーを渡されると、
ヘッダ無しで（＝重複防止が効かない状態で）送るのではなく、
**Messaging API を呼ぶ前に失敗させます**。

---

## 4. 一括予約の原子性とオーバーブッキング防止

**この実装で最も重要な部分です。** `app/Services/BookingService.php::createGroupBooking()` が、
1トランザクションの中で次の順に処理します。

1. 代表者情報を検証し、選択された枠を **枠ID昇順に並べる**（ロック順を固定してデッドロックを避ける）
2. `BEGIN`
3. 枠ごとに `SELECT * FROM reservation_slots WHERE id = ? FOR UPDATE` で**行ロックを取得**
4. ロック下で再検証（人数・開始済み・受付期間・受付状態・**残席**・同一ユーザーの重複）
5. `INSERT INTO bookings ...` と `UPDATE reservation_slots SET reserved_seats = reserved_seats + ?`
6. すべて成功したら `COMMIT`。1枠でも失敗すれば例外を投げて `ROLLBACK`（**all-or-nothing**）

行ロックを取ったあとに残席を読むため、
「2人が同時に最後の1席を見て、両方が予約できてしまう」競合状態が起きません。
Cloudflare D1(SQLite) は単一ライターのため直列化されていましたが、
MySQL は複数接続が本当に並行するため、この行ロックが必須になります。

検証は `tests/ConcurrencyTest.php` で行っています。
定員10の枠へ **8プロセスが同時に2名ずつ**予約を投げ、
成功が5件・`FULL` が3件・確定席数がちょうど10であることを確認しています。

通知側の排他は `tests/NotificationConcurrencyTest.php` で、
同じ予約・同じ種別へ **8プロセスが同時に claim** し、
送信権を取れるのが1プロセスだけ・Messaging API 相当処理がちょうど1回であることを確認しています。
retry key についても、初回付与・5xx／タイムアウト／stale再取得での再利用・
409での確定・通知ごとの独立性・23時間の期限（境界値を含む）・
不正キーでHTTPリクエストを送らないことを同ファイルで検証しています。

---

## 5. ローカルでの実行

```bash
cd xserver
cp config/config.example.php config/config.local.php
# config.local.php の DB_* と SESSION_SECRET を埋める

php bin/migrate.php            # スキーマ適用
php bin/migrate.php --status   # 未適用の確認

php -S localhost:8787 -t public public/index.php
```

### テスト

```bash
cd xserver
RAKKO_DB_NAME=rakko_test RAKKO_DB_USER=... RAKKO_DB_PASSWORD=... php tests/run.php
php tests/run.php Booking      # ファイル名で絞り込み
```

テスト用DBは**中身が消えます**。本番DBを指さないでください。
設定は `RAKKO_*` 環境変数で上書きできるため、`config.local.php` を書き換える必要はありません。

構文チェック:

```bash
find app bin public tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

---

## 6. XSERVER への配置（本番導入時の手作業）

> 以下はサーバーパネルでの操作です。コードからは実行できません。

### 6-1. PHP バージョン

サーバーパネル →「PHP Ver.切替」→ 対象ドメインを **PHP 8.4** に設定。
`php.ini設定` で `mbstring` / `curl` / `pdo_mysql` が有効なことを確認します。

### 6-2. MySQL データベース

1. サーバーパネル →「MySQL設定」→「MySQL追加」
   - データベース名: 例 `<account>_rakko`
   - 文字コード: **UTF-8（utf8mb4）**
2. 「MySQLユーザ追加」でユーザーを作成し、上のDBに**アクセス権を付与**
3. 「MySQL一覧」に表示される **ホスト名**（`mysqlXXXX.xserver.jp` 形式）を控える

### 6-3. ファイル配置

アプリ本体をドキュメントルートの外に置きます（`config.local.php` の直接ダウンロードを防ぐため）。

```
/home/<account>/<domain>/
├── app-root/            ← ドキュメントルート外
│   ├── app/  bin/  config/  database/  tests/
└── public_html/         ← ドキュメントルート
    ├── .htaccess
    ├── index.php
    └── assets/
```

`xserver/public/` の中身を `public_html/` へ、それ以外を `app-root/` へ配置します。
`public/index.php` は `dirname(__DIR__)` を参照するため、
`public_html` と `app-root` を並べる構成では index.php 冒頭の `$root` を
`/home/<account>/<domain>/app-root` に書き換えてください（1行）。

### 6-4. 設定ファイル

`app-root/config/config.example.php` をコピーして `config.local.php` を作り、次を埋めます。

| キー | 内容 |
|---|---|
| `APP_URL` | `https://reserve.example.com`（末尾スラッシュなし） |
| `APP_ENV` | `production` |
| `SESSION_SECRET` | `openssl rand -base64 48` などで生成した**32文字以上**の値 |
| `DB_HOST` | `mysqlXXXX.xserver.jp` |
| `DB_NAME` / `DB_USER` / `DB_PASSWORD` | 6-2 で作った値 |
| `LINE_LOGIN_CHANNEL_ID` / `_SECRET` | LINE Developers の LINE Login チャネル |
| `LINE_MESSAGING_CHANNEL_ACCESS_TOKEN` | Messaging API チャネルの長期アクセストークン |
| `ADMIN_USERNAME` | 管理者ユーザー名 |
| `ADMIN_PASSWORD_HASH` | `php -r 'echo password_hash("パスワード", PASSWORD_DEFAULT);'` の出力 |
| `DEMO_MODE` | **必ず `false`**（`production` で `true` にすると起動を拒否します） |

`config.local.php` は `.gitignore` 済みです。パーミッションは `600` を推奨します。

### 6-5. SSL

サーバーパネル →「SSL設定」→ 無料独自SSL を追加。
`.htaccess` が HTTP を HTTPS へ 301 リダイレクトします。

### 6-6. スキーマ適用

SSH（または「PHP」→ CLI が使える環境）で:

```bash
/usr/bin/php8.4 /home/<account>/<domain>/app-root/bin/migrate.php
```

`0002_seed_rakko.sql` は「らっこ号 池袋便」の初期データを入れます。
不要なら適用前にファイルを削除してください（適用後の削除は無意味です）。

### 6-7. Cron（リマインド送信）

サーバーパネル →「Cron設定」→「Cron追加」

| 項目 | 値 |
|---|---|
| 分 | `0,5,10,15,20,25,30,35,40,45,50,55` |
| 時 / 日 / 月 / 曜日 | すべて `*` |
| コマンド | `/usr/bin/php8.4 /home/<account>/<domain>/app-root/bin/cron-reminders.php` |

XSERVER の Cron は実行結果をメール通知します。不要なら末尾に `> /dev/null 2>&1` を付けてください。
このスクリプトは CLI 以外から実行すると 403 で終了します。

### 6-8. LINE Developers 側の設定

- LINE Login チャネルの **コールバックURL** に
  `https://<本番ドメイン>/auth/line/callback` を登録
- Messaging API チャネルで **Webhook を無効**（本システムは push のみ使用）
- 応答メッセージ・あいさつメッセージは運用に合わせて設定

### 6-9. 導入後の確認

1. `https://<ドメイン>/healthz` が `{"ok":true}` を返す
2. トップに公開中の予約ページが並ぶ
3. LINEログイン → 予約 → LINEに完了通知が届く
4. `https://<ドメイン>/admin/login` から管理者ログインできる
5. 管理画面で名簿CSVを開き、Excelで文字化けしない
6. Cron を1回手動実行し、`[cron] ... checked=N` が出力される

---

## 7. Cloudflare Workers 版との違い

仕様（URL・画面・予約ルール・LINE・CSV）は同一です。実行基盤の差だけがあります。

| 項目 | Workers 版 | XSERVER 版 |
|---|---|---|
| 実行環境 | Cloudflare Workers（V8） | Apache + PHP 8.4（mod_php / CGI） |
| ルーティング | Hono | `app/Http/Router.php` + `.htaccess` |
| DB | D1（SQLite） | MySQL / InnoDB（PDO） |
| 同時実行 | 単一ライターで直列化 | **トランザクション + `SELECT ... FOR UPDATE`** |
| 重複防止 | 部分ユニークインデックス | 生成列 `dedupe_key` + UNIQUE |
| 定員の最後の砦 | トリガー | `CHECK (reserved_seats <= capacity)` |
| 定期実行 | Cron Trigger（5分毎） | XSERVER Cron（5分毎・CLI） |
| 静的アセット | Worker が文字列を返す | `public/assets/` を Apache が配信 |
| 秘密情報 | `wrangler secret` | `config/config.local.php`（ドキュメントルート外） |
| 通知の排他 | 単一ライターで直列化 | `sending` 状態 + `claim_token` への原子的遷移 |
| 通知の冪等性 | — | `X-Line-Retry-Key`（409を受理済みとして扱う・23時間で失効） |
| セッション | 署名付きCookie（HMAC-SHA256） | 同左（PHPネイティブセッションは使わない） |
| テスト | Vitest（120件） | 同梱ランナー（163件） |

### 移植にあたっての実装上の注意

- **`slot_selected` の扱い**: PHP は同名スカラーのPOSTを最後の1件に潰します。
  `name` 属性を Workers 版と揃えたまま複数枠を受け取るため、
  `Request::fromGlobals()` が生ボディを自前で解析して全ての値を保持します。
- **空の同行者欄**: JavaScript が無効な環境では空欄がPOSTされます。
  `BookingService::validateItem()` が trim/filter するため、バックエンドは変更していません。
- **タイムゾーン**: 接続時に `SET time_zone = '+00:00'` を発行し、
  サーバーのタイムゾーン設定に依存しないようにしています。
- **セッションの期限**: 署名付きCookieは署名だけでは無期限に有効なため、
  `userId()` / `adminUser()` が発行時刻 `iat` を検証し、
  利用者30日・管理者8時間を過ぎたものを無効扱いにします。
