# らっこ号 池袋便 予約システム（MVP）

草加健康センター「らっこ号 池袋便」の予約システムです。
LINE・Xの投稿からスマホでアクセスし、LINEログイン後に「行き」「帰り」を **別々に** 予約できます。
スタッフは管理画面から予約・残席・乗車状況を管理できます。

---

## 1. システム概要

### 対象イベント

| | 行き（outbound） | 帰り（return） |
| --- | --- | --- |
| 日時 | 2026年8月21日（金）20:00 | 2026年8月22日（土）8:10 |
| 出発地 | 池袋西口 マクドナルド前辺り | 草加健康センター |
| 到着地 | 草加健康センター | 池袋西口 |
| リマインド初期値 | 8/21 17:00 JST | 8/22 7:00 JST |
| 定員（初期値） | 40席 | 40席 |

日時・定員・リマインド時刻はすべてDBの `trips` テーブルから読み込みます（コード・UIへのハードコードなし）。
定員・受付状態・リマインド時刻は管理画面から変更できます。

### 主な機能

- 公開トップ（残席リアルタイム表示・満席表示・料金参考情報・注意事項）
- LINEログイン（OAuth 2.0 / OIDC、state検証 + PKCE + nonce検証）
- 予約（1〜4名、同行者氏名、注意事項同意）
- 行き・帰りは完全に独立した予約（片道のみ／両方どちらも可）
- オーバーブッキング防止（条件付きINSERT + DBトリガー）
- 同一LINEユーザーの同一便二重予約防止（部分UNIQUEインデックス）
- マイ予約 / キャンセル（論理削除・残席へ即時反映・再予約可）
- LINE予約完了通知・乗車前リマインド（Cron Trigger、二重送信防止）
- 管理画面（ダッシュボード・予約一覧・検索・乗車人数管理・定員/受付/リマインド変更・代理予約・CSV出力）
- DEMO_MODE（LINE認証情報なしでローカル全画面確認）

### 料金（システム上の参考表示のみ・決済は非対応）

- 入館料 2,250円（リクライニングシート利用・館内着・タオルセット付）
- 深夜2:00以降は深夜料金1,500円が自動加算

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

外部サービスはCloudflareとLINE以外に追加していません。

### ディレクトリ構成

```text
src/
  index.ts               Workersエントリポイント（fetch / scheduled）
  env.ts                 バインディング定義・環境判定・DEMO_MODE安全弁
  routes/                public / auth / booking / admin / cron
  services/              line-login / line-message / booking-service / reminder-service / session
  db/                    queries.ts（prepared statement）/ types.ts
  views/                 SSRテンプレート（admin/ 配下に管理画面）
  lib/                   time（UTC↔JST）/ html（エスケープ）/ messages
  styles/app.css.ts      アプリCSS（/assets/app.css で配信）
migrations/              0001_initial.sql / 0002_seed_trips.sql
test/                    booking / capacity / auth / reminder / time
```

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
| `0001_initial.sql` | users / trips / bookings / notifications、各種制約・インデックス・定員トリガー |
| `0002_seed_trips.sql` | 行き・帰り2便の初期データ（UTC保存） |

---

## 6. DEMO_MODE での起動

LINEの認証情報が無い状態でも全画面を確認できます。

1. `.dev.vars` に `DEMO_MODE=true` と `ENVIRONMENT=development` を設定
2. `npm run dev`
3. `/login` を開き「デモユーザーでログイン」を押す（既定: `demo-user-001` / `デモユーザー`）

ローカルで確認できる一連の流れ:

1. トップ表示（残席・満席表示）
2. デモLINEログイン
3. 行き3名予約
4. 帰り2名予約
5. マイ予約確認
6. キャンセル（残席が即座に戻る）
7. 管理画面（`/admin`）確認
8. 乗車チェック（`全員乗車` / `+` / `-`）

**production では DEMO_MODE を有効にできません。**
`ENVIRONMENT=production` かつ `DEMO_MODE=true` の場合、すべてのリクエストが設定エラーで停止します。
またデモログインURL（`POST /auth/demo/login`）も production では機能しません。

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
`BASE_URL` と登録値が一致していないと認証に失敗します。

### 9. LINE公式アカウントとのリンク設定

LINE Login Channel の「リンクされたLINE公式アカウント」に、草加健康センターの公式アカウント
（Messaging API Channel）を設定します。

- ログイン時に友だち追加オプション（`bot_prompt=aggressive`）が表示されます
- **友だち追加を拒否してもご予約は可能**です
- 予約画面には「LINEリマインドを受け取るには公式アカウントの友だち追加が必要」と明示しています
- 友だち状態は Friendship Status API で取得し `users.is_line_friend` に保存します（取得できない場合はNULL＝不明）

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

`--env production` を使う場合は各コマンドへ付与してください。

`BASE_URL` / `DEMO_MODE` / `ENVIRONMENT` は機密ではないため `wrangler.jsonc` の `vars` で管理します。
**パスワードやトークンを `wrangler.jsonc` へ平文で書かないでください。**

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

---

## 12. Cron Trigger 設定

`wrangler.jsonc` で設定済みです。

```jsonc
"triggers": { "crons": ["*/5 * * * *"] }
```

5分おきに `scheduled` ハンドラが起動し、`trips.reminder_at` を過ぎた便の確定予約へリマインドを送ります。

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

`notifications` は `UNIQUE(booking_id, notification_type)` を持ち、同一予約・同一種別の二重送信をDB制約で防ぎます。

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

---

## 14. 管理者ログイン設定

- URL: `/admin`
- 認証: `ADMIN_USERNAME` / `ADMIN_PASSWORD`（MVPでは単一管理者）
- 認証後は署名付きセッションCookie（HttpOnly / SameSite=Strict / 本番はSecure / 有効期限8時間 / パス限定 `/admin`）
- ログアウトはヘッダーのボタンから

管理画面でできること:

- ダッシュボード: 行き・帰りの予約数 / 定員 / 残席 / 受付状態
- 便詳細: 予約一覧（予約日時・代表者・電話番号・人数・同行者・LINE/代理・乗車人数・状態・通知状態）
- 検索（氏名・電話番号・予約ID）
- 乗車人数の更新（`全員乗車` / `+` / `-`、0〜party_sizeの範囲）
- 予約のキャンセル
- 受付開始 / 受付停止
- 定員変更（既存予約人数を下回る値は拒否）
- リマインド日時変更（JSTで入力→UTCで保存）
- 管理者代理予約（`source=admin` / `user_id=null` / LINE通知なし / 定員管理は通常予約と同一）
- CSV出力（UTF-8 BOM付き）

---

## 15. テスト方法

```bash
npm test          # Vitest
npm run typecheck # tsc --noEmit（src / test 両方）
```

テストは本番と同じ `migrations/*.sql` を `node:sqlite` へ適用し、CHECK制約・部分UNIQUEインデックス・
定員トリガーを含めて検証します。HTTPレベルのテストは Hono アプリを直接 `fetch` して確認します。

カバーしている主なケース:

| 分類 | 内容 |
| --- | --- |
| 予約 | 1名/4名成功、0名・5名拒否、同行者不足拒否、未同意拒否、電話番号検証 |
| 独立性 | 行き・帰りが別レコード、片道のみの予約、両方予約 |
| 重複 | 同一ユーザー同一便の二重予約拒否、キャンセル後の再予約 |
| 定員 | 残席ぴったり成功、超過拒否、40/39/2の拒否、トリガーによるDBレベル拒否、UPDATE経由の超過拒否、キャンセルで残席回復、行き満席でも帰りは予約可、並行リクエストで定員を超えない |
| 権限 | 他人の予約の閲覧不可（HTTPで404）・キャンセル不可、管理画面の未認証拒否、CSRFトークン検証、セッション改ざん検知、オープンリダイレクト防止 |
| LINE | 認可URLのstate/PKCE/scope、id_tokenの署名・nonce・期限検証 |
| リマインド | reminder_at前は送らない/到達後に対象、二重送信なし、キャンセル済みへ送らない、出発済みへ送らない、行き帰り独立、友だち未追加(4xx)はskipped、5xxは最大3回再試行、代理予約はskipped |
| 時刻 | 20:00 JST / 8:10 JST の表示、サーバーのローカルタイム非依存、JST↔UTC変換 |
| DEMO_MODE | 開発環境で疑似ログイン可、productionでは全リクエスト停止＋デモログイン不可 |

---

## 16. セキュリティ上の実装

- LINE OAuth の `state` 検証（署名付きCookieに保存し、コールバックで一致確認）
- PKCE（S256）と `nonce` 検証、`id_token` のHS256署名検証（iss / aud / exp も確認）
- CSRF対策（署名付きCookie + フォームhiddenのdouble submit）
- 出力は全てHTMLエスケープ（`src/lib/html.ts`）、フラッシュメッセージはコード経由で任意文字列を表示しない
- D1はすべてprepared statement + bind（SQL文字列連結なし）
- セッションCookieは HttpOnly / SameSite / production では Secure
- 予約の所有者チェック（他人の予約IDを指定しても404）
- 入力値はサーバー側で再検証（人数1〜4・同行者数・電話番号形式・同意）
- 電話番号は管理画面（スタッフ）のみに表示
- アクセストークン等は一切ログ出力しない。Cronログは件数のみ
- Secretは `.gitignore` 済みの `.dev.vars` / Wrangler Secrets で管理

---

## 17. MVPで実装していないもの

決済、座席指定、キャンセル待ち、SMS/メール通知、顧客分析、ポイント、クーポン、多言語対応、
ネイティブアプリ、LINEミニアプリ化、会員ランク、QRチェックイン。
