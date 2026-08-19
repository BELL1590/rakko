# 汎用予約システム Phase 2 — UI/UX 差し替えパッケージ（handoff-v2）

D1〜D6 で確定したデザインを、現行実装へそのまま適用できる形にしたものです。
**表示層（CSS と views）だけの変更**で、ルート・POST先・name属性・認証・予約ロジック・DB・LINE連携・Cron には一切手を入れていません。

| 項目 | 値 |
| --- | --- |
| 基準ブランチ | `main` |
| 基準コミット | `b839ec25a4f9c101751b24d1d1bd1a054ac22127` |
| テスト baseline | 114 tests |
| 変更ファイル | 上書き **7件** ／ 新規 **1件** ／ 手パッチ **2件** |

- 旧 `handoff/`（Phase 1）とは分離した独立パッケージです。両者を混ぜないでください。
- migration の追加なし。`0002_seed_trips.sql` も変更なし。
- 参照プロトタイプ：`汎用予約 公開ページ Phase2.dc.html`（D1〜D6 の全画面が動きます）

---

## 1. 適用手順

```bash
# 上書きコピー（7ファイル）
cp handoff-v2/src/views/reserve-page.ts        src/views/reserve-page.ts
cp handoff-v2/src/views/slot-parts.ts          src/views/slot-parts.ts
cp handoff-v2/src/views/home.ts                src/views/home.ts
cp handoff-v2/src/views/my-bookings.ts         src/views/my-bookings.ts
cp handoff-v2/src/views/admin/dashboard.ts     src/views/admin/dashboard.ts
cp handoff-v2/src/views/admin/pages.ts         src/views/admin/pages.ts
cp handoff-v2/src/views/admin/slot-detail.ts   src/views/admin/slot-detail.ts

# 新規ファイル（1ファイル）
cp handoff-v2/src/styles/ui-v2.css.ts          src/styles/ui-v2.css.ts

# 手パッチ（2ファイル・下記 §2 参照）
#   src/styles/app.css.ts   … 3行
#   src/views/layout.ts     … isFewSeats() / fewSeatsThreshold() を追加し seatBadge() を差し替え

npm run typecheck
npm test   # baseline 114 tests が全PASSすること
```

---

## 2. 手パッチ（2ファイル）

### 2-1. `src/styles/app.css.ts` — 3行

既存の巨大な `APP_CSS` テンプレートリテラルは触りません。末尾に追加CSSを連結するだけです。

**(a) ファイル先頭に import を追加**

```ts
import { UI_V2_CSS } from './ui-v2.css';
```

**(b) 既存の宣言を書き換え**

```diff
-export const APP_CSS = `
+const BASE_CSS = `
```

**(c) ファイル末尾に1行追加**

```ts
export const APP_CSS = BASE_CSS + UI_V2_CSS;
```

`APP_CSS` という export 名・型は変わらないので、`src/routes/public.ts` の `/assets/app.css` 配信は無変更です。

### 2-2. `src/views/layout.ts` — 「残りわずか」の判定を1箇所に集約

現行は絶対値6席で判定しているため、**定員6名の貸切枠が常に「残り6席（＝空）／残りわずか」と誤表示**されます。
定員の25%と6席の小さい方をしきい値にし、**公開側のバッジと管理側の「残席わずか」一覧で同じ述語を使います**（さもなければ、客には「空席あり」と見えている枠がスタッフにはアラートとして並びます）。

```diff
-export function seatBadge(trip: { is_full: boolean; remaining_seats: number }): string {
-  if (trip.is_full) return '<span class="seat-badge is-full">満席</span>';
-  if (trip.remaining_seats <= 6) return '<span class="seat-badge is-few">残りわずか</span>';
-  return '<span class="seat-badge is-open">空席あり</span>';
-}
+/** 「残りわずか」のしきい値。定員6名の貸切枠を常に満席直前扱いしないよう割合でも見る。 */
+export function fewSeatsThreshold(capacity?: number | null): number {
+  if (!capacity || capacity <= 0) return 6;
+  return Math.min(6, Math.max(1, Math.ceil(capacity * 0.25)));
+}
+
+/**
+ * 残席が「わずか」か。
+ * 公開側のバッジと管理側のアラートで必ずこれを使う（判定を二重に書かない）。
+ */
+export function isFewSeats(slot: {
+  remaining_seats: number;
+  capacity?: number | null;
+}): boolean {
+  return slot.remaining_seats > 0 && slot.remaining_seats <= fewSeatsThreshold(slot.capacity);
+}
+
+export function seatBadge(trip: {
+  is_full: boolean;
+  remaining_seats: number;
+  capacity?: number;
+}): string {
+  if (trip.is_full) return '<span class="seat-badge is-full">満席</span>';
+  if (isFewSeats(trip)) return '<span class="seat-badge is-few">残りわずか</span>';
+  return '<span class="seat-badge is-open">空席あり</span>';
+}
```

`capacity` は任意引数なので、既存の呼び出し（`{ is_full, remaining_seats }` のみ）はそのままコンパイルできます。
`handoff-v2/src/views/admin/dashboard.ts` はこの `isFewSeats` / `fewSeatsThreshold` を import しているので、**このパッチは必須**です。

---

## 3. 現行ファイルとの差分（ファイル別）

### 3-1. `src/views/reserve-page.ts` — D1 / D2（最も差分が大きい）

| 変更 | 内容 |
| --- | --- |
| 枠カードの配色 | `index % 2 === 1 ? ' is-return'` を**削除**。アウフグース4枠で赤緑赤緑になり色が意味を持たなくなるため。ブランド赤に統一し、選択状態を `.is-selected`（枠線＋リング）で表す |
| 選択中サマリー | 下部固定バーに「選択中N件／合計M名」と枠ごとの明細を出す。JSでDOMから組み立て（`data-slot-name` / `data-slot-when` / `data-slot-where`） |
| 下部固定CTA | `.sticky-cta` を追加。未入力があるとdisabledのまま、下に「未入力：枠を選択 / 電話番号 …」を出す |
| 送信前の確認 | `#reserve-confirm`（`.confirm-panel`）を**同一フォーム内**に追加。ルート・POST先・APIの追加なし |
| `max_party_size === 1` | 人数ラジオを出さず `<input type="hidden" name="party_size_{id}" value="1">` ＋「この枠はお1人ずつのご予約です。」。同行者欄も生成しない |
| 人数ボタンの列数 | `max_party_size > 4` は3列（`.party--wide`）にしてボタンを細くしすぎない |
| 人数ヒント | 残席が最大人数を下回るとき「残りN席のため、M名以上は選択できません。」に切り替え |
| 受付期間の反映 | `booking_open_at` / `booking_close_at` を公開UIに出す。開始前は「8月20日(木) 12:00から受付開始」、受付中で締切ありなら「8月25日(月) 13:00まで受付」、経過後は「8月25日(月) 13:00に受付を終了しました」（`slotTiming()`） |
| 状態の区別 | 受付開始前 / 受付中 / 受付終了 / 受付停止中 / 満席 を `slotState()` で1つに決め、カード右上のラベル・席数表示・期間案内をすべてそこから導く。`is_bookable=false` を一律「受付終了」に丸めない |
| 満席・受付停止 | 一覧から消さず残す。理由の文言は `slotSeats()` / `slotTiming()` に集約（二重表示対策） |
| no-JS の複数名予約 | 同行者欄の `hidden` を**サーバー側で付けない**。JS無効時は最大人数分の欄が常に見え、2名以上でも入力・POSTできる（§6 参照） |
| 引数 | `nowUtc?: string` を**任意**で追加（省略時は現在時刻）。既存の呼び出しは無変更で動きます |

### 3-2. `src/views/slot-parts.ts` — 状態判定の一元化＋表示バグ修正

**新規 export：`slotState()` / `slotTiming()`**。枠の状態はこの1関数だけが決めます。

```ts
export type SlotState = 'open' | 'before_open' | 'closed_time' | 'suspended' | 'full';
```

判定の優先順位（上から順）：

| 順 | 条件 | 状態 | 表示 |
| --- | --- | --- | --- |
| 1 | `booking_status === 'closed'` | `suspended` | 受付停止中 |
| 2 | `booking_close_at <= now` | `closed_time` | 受付終了 |
| 3 | `booking_open_at > now` | `before_open` | 受付開始前 |
| 4 | `is_full` | `full` | 満席 |
| 5 | 上記以外 | `open` | 受付中 |

運営が手で止めた状態（`suspended`）を最優先にしているのは、締切前でも「今は受け付けない」という運用判断が他のどの理由よりも強いためです。

| その他の変更 | 内容 |
| --- | --- |
| 二重表示の解消 | 満席時に `seatsText`（満席）と `seatBadge()`（満席）が並んで**「満席　満席」**と出ていたのを、バッジを出さないように修正 |
| 受付停止枠 | 予約できない枠が「残り6席」と誘導していたのを、席数ではなく状態語を表示するよう変更 |
| 引数 | `slotSeats()` / `slotStateLabel()` に任意の `nowUtc` を追加（既存の呼び出しは無変更で動きます） |

### 3-3. `src/views/home.ts` — D4

| 変更 | 内容 |
| --- | --- |
| **3分類** | 「受付中 / 受付開始前 / 受付終了」に分ける。`is_bookable=false` を一律で受付終了に丸めない |
| 分類ルール | 1枠でも `open` → 受付中。`open` が無く `before_open` があれば → 受付開始前。それ以外（満席・受付終了・受付停止のみ）→ 受付終了 |
| 受付開始前の扱い | 受付中セクションの下に独立セクションとして置き、「8月20日(木) 12:00から受付開始」を主役にする。開始が早い順に並べる |
| 枠の1行表示 | 各枠に状態語（残りN席 / 満席 / 開始前 / 停止中 / 終了）を右寄せで出す |
| 一部受付終了の明示 | 受付中ページに予約できない枠が混ざる場合「一部受付終了」バッジを追加 |
| 締切の案内 | 受付中ページに締切が設定されていれば、最も早いものを「◯月◯日 ◯◯:◯◯まで受付」として出す |
| 受付終了カードの内訳 | 全枠満席なら「満席」、全枠停止なら「受付停止中」、それ以外は「受付終了」と出し分ける |
| `seatBadge` 呼び出し | `capacity` を渡すように変更（§2-2 の定員相対判定を効かせるため） |
| 引数 | `nowUtc?: string` を**任意**で追加（省略時は現在時刻） |

### 3-4. `src/views/my-bookings.ts` — D3

| 変更 | 内容 |
| --- | --- |
| `booking_group_id` のグルーピング | 同じ `booking_group_id` を持つ予約が2件以上あるとき `.booking-group` で囲み、「まとめて予約 / N件・計M名 / 予約日時」のヘッダーを付ける |
| 1件だけ残ったグループ | 片方キャンセル済みなどで1件になったグループは囲まず単独カードとして扱う |
| 枠単位キャンセルの明示 | グループ枠内に「まとめて予約した枠も、キャンセルは枠ごとに行います。片方だけの取り消しができます。」を追加 |
| 配色 | `index % 2 === 1 ? ' is-return'` を削除 |
| 予約IDの表記 | 「・まとめて予約」の重複表示をやめ、グループ枠のバッジに集約 |

### 3-5. `src/views/admin/dashboard.ts` — D5

| 変更 | 内容 |
| --- | --- |
| 運用サマリー | `.kpi-grid` を最上部に追加（本日の予約人数 / 本日の受付予定 / 残席わずか / 公開中ページ） |
| 本日の受付予定 | JSTの当日に一致する枠を時刻順に一覧化し、各行から `/admin/slots/:id` へ1タップ |
| 残席わずか | 予約可能かつ定員に対して残りが少ない枠を別カードで一覧化（残席の少ない順）。判定は公開側のバッジと同じ `isFewSeats()` を使う |
| 白ベース化 | ページ別の状況は既存の `.admin-card` を維持しつつ、全体を業務UIのグレー背景＋白カードに寄せる |
| 引数 | `nowUtc?: string` を**任意**で追加（省略時は現在時刻）。既存の呼び出しは無変更で動きます |

### 3-6. `src/views/admin/pages.ts` — D5

| 変更 | 内容 |
| --- | --- |
| 一覧のカード化 | `.page-row` に置換。消化率バー・公開URL・編集/複製/全枠CSV/公開切替を1カードに収める |
| 全枠CSVの追加 | 一覧から `/admin/reservations/:id/roster.csv` へ直接飛べるリンクを追加（既存ルート） |
| ページ編集フォーム | 「基本情報 / 予約設定」の2セクションに分割（`.form-section`）。公開状態と種別、最大枠数と受付呼称を2カラム化 |
| 枠フォーム | `slotFormFields()` を「枠の内容 / 定員 / 受付期間・リマインド」の3セクションに分割 |
| 定員の注意書き | 「1予約あたりの最大人数を1にすると、公開側では人数選択UIを表示しません」を `.form-note` で明示 |
| 枠一覧のボタン | 「予約一覧・設定」→「名簿・受付」に文言変更（当日の用途に合わせる） |

### 3-7. `src/views/admin/slot-detail.ts` — D6

| 変更 | 内容 |
| --- | --- |
| 並び替え | サマリー → 受付確認 → 名簿（検索）→ CSV → 代理予約 → 枠の設定。当日の操作順に合わせる |
| KPI追加 | 予約人数（`booked_seats / capacity`）と `checkin_label` 済み人数を `.kpi-grid` で最上部に |
| 検索中の明示 | 検索時は「名簿（N件・検索結果）」「（検索結果のみ）」と表示し、枠全体の数と混同させない |
| CSVの分離 | 「この枠の名簿CSV」と「（ページ名）の全枠CSV」を縦に並べて役割を明確化 |
| 見出し | 「予約一覧」→「名簿」に統一（指示書の名簿画面に合わせる） |

### 3-8. `src/styles/ui-v2.css.ts` — 新規

追加のみで、既存クラスの削除・改名は一切ありません。追加クラス：

`.slot-card` `.slot-card.is-selected` `.slot-pick` `.slot-toggle` `.slot-fields` `.slot-single-party`
`.party--wide` `.sticky-cta*` `.confirm-panel` `.confirm-lead` `.confirm-slot*`
`.booking-group*` `.section-head` `.slot-lines` `.slot-line__*` `.page-closed*`
`.companion-group*` `.slot-timing` `.seats.is-closed` `.seats.is-waiting` `.slot-card.is-waiting`
`.kpi-grid` `.kpi*` `.list-card*` `.badge-few` `.page-row*` `.admin-card-plain`
`.form-section*` `.form-note` `.field-2col`

管理画面の背景切り替えに `body:has(.admin-header)` を使っています。`:has` 未対応環境ではクリーム地のまま表示され、レイアウトは崩れません。

---

## 4. 維持必須の name 属性

**1つも変更していません。** 差し替え後もこの形で送信されます。

### 公開予約ページ（`reserve-page.ts`）

| name | 備考 |
| --- | --- |
| `csrf_token` | hidden |
| `slot_selected` | チェックボックス・value は `slot.id`・複数 |
| `party_size_{slotId}` | ラジオ。`max_party_size === 1` の枠のみ **hidden で value="1"** |
| `companion_{slotId}` | テキスト・同名複数 |
| `representative_name` | |
| `phone` | |
| `agreed` | value は `1` |

### 管理画面

| 画面 | name |
| --- | --- |
| 管理ログイン | `csrf_token` `username` `password` |
| 予約ページ作成・編集 | `csrf_token` `title` `slug` `description` `status` `page_type` `checkin_label` `requires_line_login`(=1) `allow_multi_slot_booking`(=1) `max_slots_per_checkout` |
| 公開状態の切替 | `csrf_token` `status`（`published` / `closed`） |
| 予約枠 作成・編集 | `csrf_token` `name` `description` `start_at` `end_at` `origin` `destination` `location` `capacity` `max_party_size` `booking_open_at` `booking_close_at` `reminder_at` `booking_status`（`open`/`closed`/`hidden`） `sort_order` |
| 受付人数の操作 | `csrf_token` `slot_id` `op`（`dec` / `inc` / `all`） |
| 予約の取消（管理） | `csrf_token` `slot_id` |
| 管理者代理予約 | `csrf_token` `representative_name` `phone` `party_size` `companion_names_text` |
| 名簿検索 | `q`（GET） |

---

## 5. POST先・route（すべて現行のまま／追加なし）

### 公開側

| メソッド | パス |
| --- | --- |
| GET | `/` |
| GET | `/reserve/:slug` |
| POST | `/reserve/:slug/book` |
| GET | `/my-bookings` |
| GET | `/bookings/:id` |
| POST | `/bookings/:id/cancel` |
| GET | `/login` ・ POST `/auth/line/start` ・ POST `/auth/demo/login` |
| GET | `/assets/app.css` |

### 管理側

| メソッド | パス |
| --- | --- |
| GET | `/admin` |
| POST | `/admin/login` ・ POST `/admin/logout` |
| GET | `/admin/reservations` ・ `/admin/reservations/new` ・ `/admin/reservations/:id` |
| POST | `/admin/reservations` ・ `/admin/reservations/:id` |
| POST | `/admin/reservations/:id/duplicate` ・ `/admin/reservations/:id/status` |
| POST | `/admin/reservations/:id/slots` |
| GET | `/admin/slots/:id`（`?q=` 付き） ・ POST `/admin/slots/:id` |
| POST | `/admin/slots/:id/bookings` |
| POST | `/admin/bookings/:id/checkin` ・ POST `/admin/bookings/:id/cancel` |
| GET | `/admin/reservation-slots/:id/roster.csv` ・ `/admin/reservations/:id/roster.csv` |
| POST | `/admin/reminders/run` |

**一括予約の確認ステップ・受付期間の表示・トップの3分類のために追加したルート・POST先・API・DBカラムは一切ありません。**
確認は `#reserve-confirm` セクションの開閉のみ、受付期間は既存カラム `booking_open_at` / `booking_close_at` の読み取りのみで実現しています。

---

## 6. JS依存と no-JS フォールバック

`reserve-page.ts` の `bodyEnd` スクリプトが担うのは次の4つだけです。

1. 枠チェックに応じた人数・同行者欄の出し入れ（`hidden` / `required` / `disabled` の同期）
2. 下部固定CTAの選択中サマリー生成と、未入力項目の表示
3. 確認セクションの開閉と内容の流し込み
4. 二重送信の抑止

**原則：サーバーが出力するHTMLは「全部見える」状態。JSは絞り込む方向にしか働きません。**
（例外は `#sticky-cta` と `#confirm-dismiss` の2つだけ。どちらもJS専用の操作系で、no-JSでは機能しないため隠します。）

### JavaScript が無効な場合の挙動

| 要素 | no-JS時 |
| --- | --- |
| `#reserve-confirm`（確認セクション） | **最初から開いた状態**（`hidden` はJSが付ける）。中の `#submit-button` からそのまま `POST /reserve/:slug/book` に送信できる |
| 同行者欄 | **最大人数分がすべて表示される**。サーバー側では `hidden` も `disabled` も付けません。利用者は選んだ人数に応じて上から順に記入します（案内文を `.companion-group__lead` に常時表示） |
| 人数ラジオ | 全枠ぶんが表示される（`.slot-fields` の `hidden` はJSが付ける） |
| `#sticky-cta`（下部固定CTA） | サーバー側で `hidden` を付けて出力。JSが外すので、no-JSでは**表示されない**（押せないボタンが残らない） |
| `#confirm-dismiss`（内容を変更する） | サーバー側で `hidden`。JSが外す |
| 枠未選択のガード | JS無効時はサーバー側のバリデーションに委ねる（従来と同じ） |

#### 同行者欄の `required` について

no-JS で2名以上の予約を成立させるため、同行者欄には **`required` を付けていません**。

- JS有効時：`syncBlock()` が「選択中の枠 × `party_size - 1` 個」だけを表示し、その欄に `required` を動的付与。余った欄は `hidden` + `disabled`（＝POSTされない）
- JS無効時：全欄が表示され、`required` なし・`disabled` なし。空欄はブラウザが `companion_{slotId}=` として送るか、値が空のまま送られる

**サーバー側は空文字を除外して `party_size - 1` 件を検証する既存実装のままで動きます。**
もし空文字を除外していない場合のみ、`src/routes/booking.ts` の同行者パース箇所に `.filter(Boolean)` 相当が必要です（現行実装では `parseCompanionNames` 系で除外済みのため、変更不要と判断しています）。

つまり **no-JS = 「枠チェック＋人数ラジオ＋同行者欄（最大人数分）＋代表者入力＋確認セクションの送信ボタン」という従来のフォーム1枚**として成立します。

`booking-detail.ts` のキャンセル2段階（Phase 1 で導入）も同じ方式です。JS無効時は確認セクションが開いた状態＋`confirm()` にフォールバックします。

---

## 7. 必須文字列（既存テストが検査）

差し替え後も**そのまま含まれている**ことを確認済みです。変更しないでください。

| 文字列 | 場所 |
| --- | --- |
| `選択した予約をまとめて確定する` | `reserve-page.ts` の `#submit-button`。確認セクション内の最終送信ボタンとして残しています |
| `ダッシュボード` | `admin/dashboard.ts` の `<h2>` と `layout()` の管理ナビ |
| `あなたの予約` | `my-bookings.ts` の `<h2>` |
| `デモログイン` | `login.ts`（本パッケージでは未変更） |
| `DEMO_MODE must be disabled in production` | サーバー側（未変更） |

その他、テストが参照し得る id / data属性も維持しています：
`reserve-form` `submit-button` `representative_name` `phone` `agreed`
`slot_{id}` `party_size_{slotId}_{n}` `companion_{slotId}_{index}`
`data-slot-block` `data-slot-toggle` `data-slot-fields` `.companion-field` `data-companion-index`
`data-companion-group`（新規） `data-confirm-panel` `data-sticky-cta`

---

## 8. UIのみの変更であることの確認

| 対象 | 変更 |
| --- | --- |
| ルート定義（`src/routes/*`） | なし |
| 予約ロジック・定員制御・二重予約防止（`src/services/*`） | なし |
| 認証・セッション・CSRF（LINEログイン / 管理ログイン） | なし |
| D1スキーマ・migration | なし（`0002` も `0003` も変更・追加なし） |
| LINE連携（通知・リマインド・友だち判定） | なし |
| Cron Trigger | なし |
| CSV出力ロジック | なし（リンクの置き場所だけ変更） |
| `src/db/types.ts` | なし |
| 既存カラムの読み取り | `booking_open_at` / `booking_close_at` / `booking_status` を表示に使うのみ。書き込み・スキーマ変更なし |
| 型の変更 | `layout.ts` に `isFewSeats()` / `fewSeatsThreshold()` を追加、`slot-parts.ts` に `slotState()` / `slotTiming()` / `SlotState` を追加、`seatBadge()` に任意 `capacity`、`slotSeats()`・`slotStateLabel()`・`homePage()`・`reservePage()`・`adminDashboardPage()` に任意 `nowUtc` を追加のみ。いずれも既存呼び出しは無変更でコンパイル可 |

差し替え後は `npm run typecheck` → `npm test` の順で実行し、**baseline 114 tests** の全PASSを確認してください。

### 時刻比較について

`slotState()` は `booking_open_at` / `booking_close_at` を **ISO8601文字列のまま辞書順比較**しています。DBに格納されている値がすべてUTCのISO文字列（`2026-08-20T03:00:00.000Z` 形式）で揃っている前提です。既存の `is_bookable` の算出と同じ前提なので、そこが崩れていなければ判定は一致します。

---

## 9. 運用時の申し送り

1. **定員**：seed は40席のまま。本番は管理画面（`POST /admin/slots/:id`）から24席へ変更してください。
2. **`max_party_size = 1` の枠**：公開側で人数UIが消えます。アウフグースなど1名ずつの回はこの設定にしてください。
3. **`checkin_label`**：「乗車 / 受付 / 来場」を切り替えると管理画面の見出しとボタン文言が追従します。バス以外のイベントでは「受付」が自然です。
4. **`origin` の分割表示**：「池袋西口 マクドナルド前辺り」を先頭の空白で機械的に分割しています（`splitPlace()`）。将来 `origin` / `origin_note` にカラム分割するのが本来の形です。
5. **残席しきい値**：`fewSeatsThreshold()` の 25%／6席は運用を見て調整してください。1箇所（`layout.ts`）を直せば、公開側のバッジと管理側の「残席わずか」一覧の両方に反映されます。
