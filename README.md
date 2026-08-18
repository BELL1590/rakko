# 草加健康センター 汎用予約システム

草加健康センターの各種予約をオンラインで受け付けるシステムです。
「らっこ号 池袋便」の送迎バス予約から始まり、Phase 2 でアウフグースイベント・貸切サウナ・
宴会など、**バス以外の用途にも使える汎用予約システム**へ拡張しました。

利用者はLINE・Xの投稿からスマホでアクセスし、LINEログイン後に予約できます。
1つの予約ページに複数の予約枠を並べ、**複数枠をまとめて予約**できます。
スタッフは管理画面から予約ページ・予約枠を作成し、日時・定員・受付状態・リマインド時刻を
変更でき、名簿CSVを出力できます。

---

## 1. システム概要

### データモデル

| 概念 | テーブル | 例 |
| --- | --- | --- |
| 予約ページ（イベント全体） | `reservation_pages` | らっこ号 池袋便 / アウフグースイベント / 貸切サウナ |
| 予約枠（実際に予約する1枠） | `reservation_slots` | 行き・帰り / 13:00回・15:00回 / 10:00〜11:00 |
| 予約 | `bookings` | 枠ごとに1件。まとめて予約した分は `booking_group_id` で紐づく |

- 予約枠ごとに **日時・定員・1予約あたり最大人数・受付期間・リマインド時刻・表示順** を持ちます
- バスは `origin` / `destination`、イベントは `location` を使います（未使用の項目はNULL）
- 日時・定員はコードにハードコードせず、すべてDBから読み込みます

### 主な機能

- 公開トップ `/`：公開中の予約ページ一覧
- 公開予約ページ `/reserve/:slug`：枠の一覧・残席・複数枠のまとめて予約
- LINEログイン（OAuth 2.0 / OIDC、state検証 + PKCE + nonce検証 + id_token署名検証）
- 予約（枠ごとに人数を選択。枠ごとに人数が違ってもよい）
- 一括予約は **全枠成功 or 全枠失敗**（片方だけ確定しない）
- オーバーブッキング防止（条件付きINSERT + DBトリガー、枠単位）
- 同一LINEユーザー・同一枠の二重予約防止（部分UNIQUEインデックス）
- マイ予約（予約ページ単位でグルーピング）/ キャンセル（枠単位・論理削除）
- LINE予約完了通知（一括予約は1通にまとめる）・枠ごとの開始前リマインド（Cron 5分毎）
- 管理画面：予約ページの作成/編集/複製/公開停止、予約枠の作成/編集、予約一覧・検索、
  受付人数の記録、代理予約、名簿CSV出力
- DEMO_MODE（LINE認証情報なしでローカル全画面確認）

### 「らっこ号 池袋便」の扱い

Phase 1 の `trips`（行き・帰り）は migration で予約ページ `rakko-ikebukuro` と
2つの予約枠（行き / 帰り）へ移行済みです。既存の予約データも保持されます。
旧URL `/trips/:slug/book` と `/admin/trips/:slug` は新URLへリダイレクトします。

| | 行き | 帰り |
| --- | --- | --- |
| 日時 | 2026年8月21日（金）20:00 | 2026年8月22日（土）8:10 |
| 出発地 | 池袋西口 マクドナルド前辺り | 草加健康センター |
| 到着地 | 草加健康センター | 池袋西口 |
| リマインド初期値 | 8/21 17:00 JST | 8/22 7:00 JST |
| 定員（seed値） | 40席 | 40席 |

> 本番の定員（例：24席）は管理画面から変更してください。seedは変更しません。

---

## 2. 技術構成

| 領域 | 採用技術 |
| --- | --- |
| 実行環境 | Cloudflare Workers |
| 言語 | TypeScript |
| Webフレームワーク | Hono（SSRでHTMLを返す） |
| DB | Cloudflare D1（SQLite）+ SQLマイグレーション |
| フロント | CSS + 最小限のVanilla JS（SPAフレームワーク不使用・モバイルファースト） |
| 定期実行 | Cloudflare Cron Triggers（5分間隔） |
| テスト | Vitest（マイグレーションSQLをそのまま適用して検証） |
| CLI | Wrangler |

### ディレクトリ構成

```text
src/
  index.ts               Workersエントリポイント（fetch / scheduled）
  env.ts                 バインディング定義・環境判定・DEMO_MODE安全弁
  routes/                public / auth / booking(予約) / admin / cron
  services/              line-login / line-message / booking-service /
                         reminder-service / session / csv
  db/                    queries.ts（prepared statement）/ types.ts
  views/                 SSRテンプレート（reserve-page / booking-detail /
                         my-bookings / slot-parts、admin/ 配下に管理画面）
  lib/                   time（UTC↔JST）/ html（エスケープ）/ messages
  styles/app.css.ts      アプリCSS（/assets/app.css で配信）
migrations/              0001〜0004
test/                    booking / capacity / auth / reminder / reservation /
                         admin / csv / reserve / time
handoff/                 UI/UXデザインの受け入れ資料（参照用）
```

### 主なURL

| URL | 内容 |
| --- | --- |
| `/` | 公開中の予約ページ一覧 |
| `/reserve/:slug` | 公開予約ページ（複数枠まとめて予約） |
| `/my-bookings` | マイ予約 |
| `/bookings/:id` | 予約詳細・キャンセル |
| `/admin` | ダッシュボード |
| `/admin/reservations` | 予約ページ一覧・作成 |
| `/admin/reservations/:id` | 予約ページ編集・予約枠一覧・枠追加 |
| `/admin/slots/:id` | 予約枠の予約一覧・受付・代理予約・設定 |
| `/admin/reservation-slots/:id/roster.csv` | 予約枠の名簿CSV |
| `/admin/reservations/:id/roster.csv` | イベント全体の名簿CSV |

CSVは既定で確定済みのみ。`?include=cancelled` でキャンセル分も含められます。

---

## 3. ローカル起動方法

前提: Node.js 22 以上（テストで `node:sqlite` を利用します）。

```bash
npm install
cp .dev.vars.example .dev.vars   # 値を編集（LINEの値は空のままでもDEMO_MODEで動作します）
npm run db:migrate:local
npm run dev                      # http://localhost:8787
```

`.dev.vars` は `.gitignore` 済みです。実際のSecret値をコミットしないでください。

---

## 4. D1データベースの作成

```bash
npx wrangler d1 create rakko-bus
```

出力される `database_id` を `wrangler.jsonc` の `d1_databases[0].database_id`
（および `env.production` 側）へ設定します。バインディング名は `DB` です。

---

## 5. マイグレーション

```bash
npm run db:migrate:local    # ローカル（.wrangler/state 配下）
npm run db:migrate:remote   # 本番D1
```

| ファイル | 内容 |
| --- | --- |
| `0001_initial.sql` | users / trips / bookings / notifications（Phase 1） |
| `0002_seed_trips.sql` | 池袋便2便の初期データ（40席） |
| `0003_reservation_pages_and_slots.sql` | reservation_pages / reservation_slots を追加 |
| `0004_migrate_trips_to_slots.sql` | 既存の便・予約を予約枠モデルへ移行し、定員トリガーを枠単位で再定義 |

過去のmigrationは編集しません。`0004` は bookings を再構築しますが、
**id を含めて全行をコピー**するため既存予約は失われません（`notifications.booking_id` の対応も維持）。
旧 `trips` テーブルと `bookings.trip_id` は段階移行のため残しています（正は `reservation_slot_id`）。

---

## 6. DEMO_MODE での起動

LINEの認証情報が無い状態でも全画面を確認できます。

1. `.dev.vars` に `DEMO_MODE=true` と `ENVIRONMENT=development` を設定
2. `npm run dev`
3. `/login` を開き「デモユーザーでログイン」を押す（既定: `demo-user-001` / `デモユーザー`）

**production では DEMO_MODE を有効にできません。**
`ENVIRONMENT=production` かつ `DEMO_MODE=true` の場合、すべてのリクエストが設定エラーで停止します。
デモログインURL（`POST /auth/demo/login`）も production では機能しません。

---

## 7. LINE Developers 側で必要な設定

前提として、**LINE Login Channel と Messaging API Channel を同一Provider配下**に作成します。

1. LINE Developers Console でProviderを作成（既存があればそれを利用）
2. 同Provider配下に **LINE Login Channel** を作成
   - Channel ID / Channel Secret を控える
   - スコープ `openid` `profile` を有効化
3. 同Provider配下に **Messaging API Channel**（草加健康センターLINE公式アカウント）を用意
   - Channel Access Token（長期）を発行

### 8. LINE Login callback URL

LINE Login Channel の「LINEログイン設定 > コールバックURL」に以下を登録します。

```text
http://localhost:8787/auth/line/callback     # ローカル開発
https://<本番ドメイン>/auth/line/callback     # 本番
```

callback URLは `BASE_URL` + `/auth/line/callback` で組み立てられます。

### 9. LINE公式アカウントとのリンク設定

LINE Login Channel の「リンクされたLINE公式アカウント」に、草加健康センターの公式アカウント
（Messaging API Channel）を設定します。

- ログイン時に友だち追加オプション（`bot_prompt=aggressive`）が表示されます
- **友だち追加を拒否してもご予約は可能**です
- 友だち状態は Friendship Status API で取得し `users.is_line_friend` に保存します

### 10. Messaging API Channel Access Token

発行した長期アクセストークンを `LINE_MESSAGING_CHANNEL_ACCESS_TOKEN` として設定します。
未設定でも予約機能は動作し、通知は `skipped` として記録されます。

---

## 11. Cloudflare Secrets 設定

```bash
npx wrangler secret put LINE_LOGIN_CHANNEL_ID
npx wrangler secret put LINE_LOGIN_CHANNEL_SECRET
npx wrangler secret put LINE_MESSAGING_CHANNEL_ACCESS_TOKEN
npx wrangler secret put SESSION_SECRET          # 例: openssl rand -hex 32
npx wrangler secret put ADMIN_USERNAME
npx wrangler secret put ADMIN_PASSWORD
```

| 変数 | 種別 | 説明 |
| --- | --- | --- |
| `LINE_LOGIN_CHANNEL_ID` | Secret | LINE Login Channel ID |
| `LINE_LOGIN_CHANNEL_SECRET` | Secret | LINE Login Channel Secret（id_token検証にも使用） |
| `LINE_MESSAGING_CHANNEL_ACCESS_TOKEN` | Secret | Messaging API のチャネルアクセストークン |
| `SESSION_SECRET` | Secret | セッションCookie署名鍵（productionでは必須） |
| `ADMIN_USERNAME` / `ADMIN_PASSWORD` | Secret | 管理画面のログイン情報 |
| `BASE_URL` | var | 例: `https://rakko-bus.example.com`（末尾スラッシュなし） |
| `DEMO_MODE` | var | production では必ず `false` |
| `ENVIRONMENT` | var | `development` / `production` |

**パスワードやトークンを `wrangler.jsonc` へ平文で書かないでください。**

---

## 12. Cron Trigger 設定

`wrangler.jsonc` で設定済みです。

```jsonc
"triggers": { "crons": ["*/5 * * * *"] }
```

5分おきに `scheduled` ハンドラが起動し、`reservation_slots.reminder_at` を過ぎた枠の
確定予約へリマインドを送ります（送信単位は予約枠）。`reminder_at` が未設定の枠は送信しません。

ローカルでの動作確認:

```bash
npx wrangler dev --test-scheduled
curl "http://localhost:8787/__scheduled?cron=*/5+*+*+*+*"
```

管理画面のダッシュボードからも「リマインド処理を今すぐ実行」で手動実行できます。

### 通知の状態

| 状態 | 意味 |
| --- | --- |
| `pending` | 送信待ち |
| `requested` | Messaging APIが受け付けた（ユーザーへの到達保証ではない） |
| `failed` | 一時的エラー（5xx/429/ネットワーク）。最大3回まで再試行 |
| `skipped` | 送信対象外（LINEユーザーなし／友だち未追加・ブロック等の4xx／トークン未設定） |

`notifications` は `UNIQUE(booking_id, notification_type)` を持ち、二重送信をDB制約で防ぎます。

---

## 13. 本番デプロイ手順

```bash
npm run typecheck
npm test
npx wrangler d1 create rakko-bus            # 初回のみ。database_id を wrangler.jsonc へ
npm run db:migrate:remote
# Secrets を設定（第11章）
npx wrangler deploy --env production
```

デプロイ前チェック:

- [ ] `ENVIRONMENT=production` / `DEMO_MODE=false`
- [ ] `BASE_URL` が本番ドメインと一致
- [ ] LINE Login のコールバックURLに本番URLを登録済み
- [ ] `SESSION_SECRET` / `ADMIN_PASSWORD` を十分な長さのランダム値で設定
- [ ] 本番の定員を管理画面で設定（seedは40席のまま）

---

## 14. 管理者ログイン設定

- URL: `/admin`
- 認証: `ADMIN_USERNAME` / `ADMIN_PASSWORD`（MVPでは単一管理者）
- 認証後は署名付きセッションCookie（HttpOnly / SameSite=Strict / 本番はSecure / 8時間 / パス限定 `/admin`）

管理画面でできること:

- **予約ページ**：作成・編集・複製・公開/停止、公開URLの確認
- **予約枠**：追加・編集（枠名 / 説明 / 開始・終了日時 / 出発地・到着地・会場 / 定員 /
  1予約あたり最大人数 / 受付開始・締切日時 / リマインド日時 / 受付状態 / 表示順）
- **予約一覧**：代表者・電話（`tel:` リンク）・人数・同行者・予約元・予約日時・受付人数・
  ステータス・通知状態、氏名/電話/予約IDでの検索
- **受付確認**：`全員` / `＋` / `−`（0〜予約人数）。UI文言はページごとに変更可（乗車 / 受付 / 来場）
- **代理予約**：`source=admin` / `user_id=null` / LINE通知なし / 定員管理は通常予約と同一
- **名簿CSV**：予約枠単位・イベント全体（UTF-8 BOM付き、CSVインジェクション対策済み）

---

## 15. テスト方法

```bash
npm test          # Vitest
npm run typecheck # tsc --noEmit（src / test 両方）
```

テストは本番と同じ `migrations/*.sql` を `node:sqlite` へ適用し、CHECK制約・部分UNIQUEインデックス・
定員トリガー・`db.batch()` のトランザクションを含めて検証します。
HTTPレベルのテストは Hono アプリを直接 `fetch` して確認します。

| ファイル | 主な内容 |
| --- | --- |
| `booking.test.ts` | 人数の検証、行き/帰りの独立、二重予約防止、キャンセル、代理予約 |
| `capacity.test.ts` | 残席ぴったり/超過、DBトリガー、キャンセルでの回復、同時アクセス |
| `reservation.test.ts` | 池袋便の移行、ページ/枠の作成、枠設定の変更、複数枠まとめて予約、一方満席で全体失敗 |
| `admin.test.ts` | ページ作成/複製/非公開、枠の追加と日時・定員・リマインド変更、受付人数、代理予約、CSV配信、認可 |
| `reserve.test.ts` | 公開ページ表示、複数枠のHTTP送信、CSRF、未ログイン導線、旧URL互換 |
| `csv.test.ts` | 名簿CSVの列・日本語・BOM・confirmedのみ・CSVインジェクション対策 |
| `reminder.test.ts` | reminder_at前後、二重送信防止、キャンセル済み除外、4xx/5xxの扱い、予約完了通知 |
| `auth.test.ts` | 所有者チェック、管理画面の認可、CSRF、セッション署名、LINE Login（state/PKCE/id_token） |
| `time.test.ts` | JST表示、サーバーのローカルタイム非依存、JST↔UTC変換 |

---

## 16. セキュリティ上の実装

- LINE OAuth の `state` 検証、PKCE（S256）、`nonce` 検証、`id_token` のHS256署名検証
- CSRF対策（署名付きCookie + フォームhiddenのdouble submit）
- 出力は全てHTMLエスケープ、フラッシュメッセージはコード経由で任意文字列を表示しない
- D1はすべてprepared statement + bind（SQL文字列連結なし）
- セッションCookieは HttpOnly / SameSite / production では Secure
- 予約の所有者チェック（他人の予約IDを指定しても404）
- 入力値はサーバー側で再検証（人数・同行者数・電話番号形式・同意・枠数上限）
- CSVはインジェクション対策（`= + - @` 等で始まるセルを無害化）
- 電話番号は管理画面（スタッフ）のみに表示
- アクセストークン等は一切ログ出力しない。Cronログは件数のみ

---

## 17. 現時点で実装していないもの

決済、座席指定、キャンセル待ち、SMS/メール通知、顧客分析、ポイント、クーポン、多言語対応、
ネイティブアプリ、LINEミニアプリ化、会員ランク、QRチェックイン、一括キャンセル。
