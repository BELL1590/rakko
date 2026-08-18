# らっこ号 池袋便 — UI/UX 差し替え手順書

デザイン確定版の差し替えコードです。**表示層（CSS と views）のみ**を変更します。
ルート、POST先、name属性、認証処理、予約ロジック、DB処理、Cron、migration には手を入れません。

- 対象ブランチ: `claude/new-session-poey53`
- 定員: `migrations/0002_seed_trips.sql` は **40席のまま変更不要**（本番運用時に管理画面から24席へ変更）
- migration の追加なし

---

## 1. 差し替え手順

```bash
# handoff/src/ 配下のファイルを、リポジトリの同じパスへ上書きコピー
cp handoff/src/styles/app.css.ts        src/styles/app.css.ts
cp handoff/src/views/layout.ts          src/views/layout.ts
cp handoff/src/views/home.ts            src/views/home.ts
cp handoff/src/views/login.ts           src/views/login.ts
cp handoff/src/views/booking-form.ts    src/views/booking-form.ts
cp handoff/src/views/booking-detail.ts  src/views/booking-detail.ts
cp handoff/src/views/my-bookings.ts     src/views/my-bookings.ts
cp handoff/src/views/admin/dashboard.ts src/views/admin/dashboard.ts
cp handoff/src/views/admin/trip-detail.ts src/views/admin/trip-detail.ts

npm run typecheck
npm test
```

差し替え対象は上記9ファイルのみ。他のファイルは変更しません。

---

## 2. デザインコンセプト

ポスターの赤・クリーム・黒・黄・緑と昭和レトロの活気を**ヘッダーとヒーローに集約**し、
フォームや一覧は現代的でシンプルなモバイルUIに保ちます。ポスターのレイアウトは移植しません。

- 赤（`--red #d0121b`）は全面には使わず、ヘッダー・ヒーロー・主CTA・便カードの帯に限定
- 背景はクリーム（`--cream #fdf6e6`）、本文は黒茶（`--ink #1b1613`）で長文の可読性を優先
- 行き = 赤 / 帰り = 緑 は**補助**。判別は「行き・帰り」の大きな文字、`▶` の矢印、出発地→到着地の並び、日時の大サイズで行う
- 主CTAは1画面に1つ。副操作は `btn-secondary`、危険操作は `btn-danger` / `btn-danger-outline` で見た目を明確に分離
- タップ領域は主要ボタン52〜60px、副ボタン46px、カウンターボタン52px（すべて44px以上）
- 本文16px、日時・残席・人数は大サイズ。360〜430pxで折り返しが破綴しないことを基準に調整

---

## 3. ファイル別 — 変更理由と維持すべき既存仕様

### 3-1. `src/styles/app.css.ts`

**変更理由**
- 既存のクラス名をすべて残したまま、トークン（色・角丸・余白）を整理し `--space-*` / `--radius-*` を追加
- 便カード用に `.trip-card__head` `.trip-card__dir` `.route` `.seat-badge` を追加。行き/帰りの判別を色以外の要素で担保するため
- 人数選択のラジオボタン用に `.party` `.party__opt` を追加（select を置き換えるため）
- 管理画面の予約一覧をカード化するため `.book-row` `.counter-btn`（52px化）`.progress` `.search-form` `.settings-group` を追加
- キャンセル確認セクション用に `.cancel-panel` を追加
- `:focus-visible` を全体に定義（キーボード操作時の可視化）
- 横スクロールテーブル用の `.table-scroll` / `table.data` は**削除**（予約一覧をカード化して未使用になったため）

**維持すべき既存仕様**
- `APP_CSS` という名前の named export。`src/routes/public.ts` が `/assets/app.css` として配信
- 既存クラス名（`.card` `.btn` `.btn-secondary` `.btn-line` `.btn-danger` `.btn-sm` `.badge-*` `.alert-*` `.notice` `.trip-badge` `.trip-datetime` `.trip-route` `.trip-meta` `.seats` `.seats-num` `.summary-list` `.price-table` `.notes` `.field` `.hint` `.req` `.checkbox-field` `.inline-form` `.counter-btn` `.stat` `.admin-grid` `.admin-wrap` `.admin-header` `.site-header` `.site-footer` `.wrap` `.hero` `.stack` `.muted` `.center` `.btn-row` `.header-nav` `.header-logout`）はすべて残存

### 3-2. `src/views/layout.ts`

**変更理由**
- ヘッダーのタップ領域を34pxに統一し、インラインstyleだった `.header-logout` をCSSへ移動
- 表示用ヘルパーを2つ追加
  - `splitPlace()`: 「池袋西口 マクドナルド前辺り」を主要地名と補足に分けて表示するため（**DBの値は変更しない**）
  - `seatBadge()`: 残席を「空席あり / 残りわずか / 満席」の文字でも示すため（色依存の回避）

**維持すべき既存仕様**
- `layout()` `priceInfoCard()` `noticeCard()` の export 名・シグネチャ・`LayoutOptions` の各プロパティ（`title` `userName` `admin` `alert` `bodyEnd`）
- `<html lang="ja">`、viewport、`theme-color`、`robots`（admin時 noindex）、`/assets/app.css` の読み込み
- 管理レイアウト時のナビ内 `ダッシュボード` リンクと `POST /admin/logout` フォーム（`test/auth.test.ts` が「ダッシュボード」の文字列を検査）
- ユーザー側ナビの `/my-bookings` リンク、フッターの文言

### 3-3. `src/views/home.ts`

**変更理由**
- 便カードを「行き/帰りの帯 → 日時 → 出発地▶到着地 → 残席 → CTA」の順に再構成。LINE/X から来た初見のユーザーが5秒で判断できる順序にした
- CTAの文言を `予約する` → `行きを予約する` / `帰りを予約する` に変更（取り違え防止）
- 満席・受付停止の表示を `.is-full` / `.is-disabled` のまま維持しつつ、残席バッジで状態を文字でも提示
- 「マイ予約を確認する」を便カードの直下へ移動（従来はページ最下部）

**維持すべき既存仕様**
- `homePage({ trips, userName, alert })` のシグネチャ（`src/routes/public.ts` から呼び出し）
- 予約リンクは `/trips/${trip.slug}/book`
- `trip.is_full` / `trip.is_bookable` の判定順（満席 → 受付停止 → 予約可）
- `esc()` を通した出力、`formatJstLong()` によるJST表示

### 3-4. `src/views/login.ts`

**変更理由**
- 「なぜLINEログインが必要か」を3点のリストで先に提示し、CTAを1つに絞った
- 取得情報の範囲を明記（不安の解消）
- 友だち追加は「なくても予約可能・リマインドには必要」を1つの notice に集約

**維持すべき既存仕様**
- `loginPage({ redirectTo, demoMode, lineConfigured, csrfToken, alert })` のシグネチャ
- `POST /auth/line/start` と hidden の `csrf_token` / `redirect_to`
- `lineConfigured === false` のときの未設定メッセージ（環境変数名を含む）
- DEMO_MODE 時の `POST /auth/demo/login` と `demo_user_id` / `demo_display_name`、見出し文言 **「開発用：デモログイン」**（`test/auth.test.ts` が「デモログイン」を検査）

### 3-5. `src/views/booking-form.ts`

**変更理由（今回いちばん大きい変更）**
- `<select name="party_size">` を、**同じ name の `<input type="radio" name="party_size" value="1..4">` + ラベル**に置換。高齢のお客様でもスマホで押しやすい60pxのボタンにした
  - POSTされる値の形は従来と完全に同一（`party_size=2` など）
  - 残席不足の選択肢は従来同様 `disabled`（サーバー側の判定が本体）
  - `select` は常に値を持つため、`values.partySize` が残席を超える場合は1名を選択済みにして「必ず1つ選ばれている」状態を維持
- 便情報を上部にカードで固定表示（何を予約中か見失わせない）
- 同意チェックを枠付きの `.checkbox-field` にして押しやすくした

**維持すべき既存仕様**
- `bookingFormPage({ trip, values, csrfToken, userName, friendPromptUrl?, isLineFriend, alert })` のシグネチャと `BookingFormValues` の export
- `POST /trips/${trip.slug}/book`、hidden `csrf_token`
- name属性：`representative_name` `phone` `party_size` `companion_names`（複数・同名）`agreed`（value="1"）
- id：`booking-form` `submit-button` `representative_name` `phone` `companion_${index}` `agreed`
- `.companion-field` + `data-companion-index` + `hidden` 属性による同行者欄の出し入れ、`required` / `disabled` の同期
- 二重送信抑止（`submitting` フラグ、ボタン `disabled` と「送信中…」表示）
- `MAX_PARTY_SIZE` を `booking-service` から参照（1〜4の上限をサーバーと共有）
- `isLineFriend === 0` のときの友だち追加 notice

> スクリプトの変更点は `partySelect.addEventListener('change', …)` を
> `partyRadios.forEach(r => r.addEventListener('change', …))` に置き換えた点のみです。

### 3-6. `src/views/booking-detail.ts`

**変更理由**
- 予約完了のファーストビューを大きくし、チェックマークと「ご予約が完了しました」を最初に見せた
- **キャンセルを2段階に変更**（誤操作対策）。「この予約をキャンセルする」→ 内容を再表示した確認セクション →「キャンセルを確定する」
  - **ルートもPOST先も追加・変更していません。**同一ページ内でセクションを開閉するだけ
  - JSが無効な環境では確認セクションが最初から開いており、従来の `onsubmit="return confirm(...)"` がそのまま働く
  - JSが有効な場合は確認セクション自体が確認UIになるため `onsubmit` を外す（二重確認の回避）
- 「マイ予約へ / トップへ戻る」を並べ、危険操作と分離

**維持すべき既存仕様**
- `bookingDetailPage({ booking, csrfToken, userName, justCompleted, notificationNote, alert })` のシグネチャ
- `POST /bookings/${booking.id}/cancel` と hidden `csrf_token`
- キャンセル可否の判定（`status === 'cancelled'` / `depart_at <= now`）
- `justCompleted` 時の成功アラートと `notificationNote` の表示位置
- `parseCompanionNames()` による同行者の復元、`予約ID #${booking.id}` の表示

### 3-7. `src/views/my-bookings.ts`

**変更理由**
- 行き/帰りを帯付きカードにし、状態（予約済み / キャンセル済み / 運行終了）・人数・同行者・予約IDを1カードで判断できるようにした
- 一覧からの即キャンセルをやめ、**詳細ページの確認セクションへ誘導**（`confirm()` 一発での取り消しを避ける）

**維持すべき既存仕様**
- `myBookingsPage({ bookings, csrfToken, userName, nowUtc, alert })` のシグネチャ（`csrfToken` は互換のため受け取りを維持）
- 見出し **「あなたの予約」**（`test/auth.test.ts` が検査）
- 0件時の「まだご予約はありません。」＋「便を見る」
- 詳細リンクは `/bookings/${booking.id}`、`nowUtc` との比較による出発済み判定
- キャンセルの実処理は `POST /bookings/:id/cancel`（詳細ページ側で送信）

### 3-8. `src/views/admin/dashboard.ts`

**変更理由**
- 管理画面はポスター調にせず、白ベース・高コントラストを維持。行き/帰りを上端の色帯だけで区別
- 予約人数・定員・残席を数値優先でレイアウトし、残席6以下は赤字（`.is-few`）で注意喚起
- 消化率のプログレスバーを追加（一目で埋まり具合が分かる）

**維持すべき既存仕様**
- `adminLoginPage({ csrfToken, alert })` と `adminDashboardPage({ trips, alert })` の export 名・シグネチャ
- `POST /admin/login` と `username` / `password` / `csrf_token`
- 見出し **「ダッシュボード」**（`test/auth.test.ts` が検査）
- 便詳細リンクは `/admin/trips/${trip.slug}`
- `POST /admin/reminders/run` の「リマインド処理を今すぐ実行」
- `layout({ admin: true })` による noindex ヘッダー

### 3-9. `src/views/admin/trip-detail.ts`

**変更理由**
- 当日はスマホで操作するため、**横スクロールのテーブルを1件1カードに変更**。氏名・人数・電話・同行者・通知・状態を縦に読める形にした
- 乗車チェックのボタンを52pxに拡大し、`−` `＋` `全員乗車` を1行に配置。電話番号は `tel:` リンクにして当日連絡を短縮
- 乗車済み合計のサマリーとプログレスバーを追加
- 便の設定（定員・リマインド日時・受付状態・CSV）を1枚のカードに整理し、受付状態の変更に確認ダイアログを追加（誤操作防止）
- 代理予約フォームの先頭に「LINE通知は送信されません」を `alert-info` で明示

**維持すべき既存仕様**
- `adminTripDetailPage({ trip, bookings, notifications, search, csrfToken, alert })` のシグネチャ
- すべてのPOST先とname/value
  - 乗車：`POST /admin/bookings/${id}/checkin`、`op=dec|inc|all`、`trip_slug`
  - 予約取消：`POST /admin/bookings/${id}/cancel`、`trip_slug`
  - 定員：`POST /admin/trips/${slug}/capacity`、`capacity`
  - リマインド：`POST /admin/trips/${slug}/reminder`、`reminder_at`（`toJstDatetimeLocal` の値）
  - 受付状態：`POST /admin/trips/${slug}/status`、`booking_status=open|closed`
  - 代理予約：`POST /admin/trips/${slug}/bookings`、`representative_name` `phone` `party_size` `companion_names_text`
  - 検索：`GET /admin/trips/${slug}`、`q`
  - CSV：`GET /admin/trips/${slug}/bookings.csv`
- 通知ラベルの対応（`pending`→未送信 / `requested`→送信要求済み / `failed`→失敗 / `skipped`→スキップ、`attempt_count > 1` で回数表示）
- キャンセル済み行の減光表示、`parseCompanionNames()` の使用
- 代理予約の人数は `select name="party_size"`（管理画面は入力効率優先のため select を維持）

---

## 4. テスト・型チェックへの影響

| 観点 | 影響 |
| --- | --- |
| ルート / POST先 | 変更なし |
| name属性・値 | 変更なし（`party_size` は select → radio だが name と値の形は同一） |
| 認証・セッション・CSRF | 変更なし |
| 予約ロジック / 定員制御 / 二重予約防止 | 変更なし（views からは呼び出していない） |
| D1・migration | 変更なし |
| Cron・リマインド | 変更なし |
| 既存テストが検査している文字列 | 「ダッシュボード」「デモログイン」「あなたの予約」「DEMO_MODE must be disabled in production」いずれも維持 |
| `npm run typecheck` | 新規 export は `splitPlace` / `seatBadge` のみ。`noUnusedLocals` に触れる未使用ローカルなし |

差し替え後は `npm run typecheck` → `npm test` の順で実行し、59テストのPASSを確認してください。

---

## 5. 残課題 / 運用時の申し送り

1. **定員**: seed は40席。本番は管理画面から24席へ変更（`POST /admin/trips/:slug/capacity`）
2. **らっこキャラクター・バス画像**: 現在は未使用（アイコンは絵文字1点のみ）。画像を使う場合はヘッダー右側の小サイズに限定し、フォーム背景には敷かない
3. **`origin` の分割表示**: 「池袋西口 マクドナルド前辺り」を先頭の空白で機械的に分割しています。将来 `origin` / `origin_note` にカラム分割するのが本来の形です（今回はDB変更なしのため表示層で処理）
4. **キャンセル確認**: JS無効時は確認セクションが開いた状態 + `confirm()` のフォールバック。この二段構成は E2E を追加するとより安全です
5. **満席時のトップ**: 満席カードのCTAは `is-disabled` のみ。押せない理由の文言を近くに置いていますが、実際の判定はサーバー側です
