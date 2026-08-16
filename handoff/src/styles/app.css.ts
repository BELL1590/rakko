/**
 * アプリ全体のCSS。Workersはファイルシステムを持たないため、文字列として保持し
 * `/assets/app.css` から配信する。
 *
 * デザイン: 草加健康センターの告知ポスターを意識した赤 × クリーム × 黒 × 黄色。
 * ただし可読性最優先、モバイルファースト（360〜430px）、タップ領域44px以上。
 * 行き / 帰りは「文字・矢印・地名」で区別し、色は補助にとどめる。
 */
export const APP_CSS = `
:root {
  --red: #d0121b;
  --red-dark: #a30d15;
  --cream: #fdf6e6;
  --cream-deep: #f6e8c8;
  --ink: #1b1613;
  --ink-soft: #4a423c;
  --ink-mute: #8a8078;
  --yellow: #ffd400;
  --green: #1f7a4d;
  --green-dark: #145635;
  --line-green: #06c755;
  --border: #e0d3b4;
  --danger: #b3261e;
  --danger-dark: #7d1a15;
  --radius-sm: 8px;
  --radius: 12px;
  --radius-lg: 16px;
  --space-1: 8px;
  --space-2: 12px;
  --space-3: 16px;
  --space-4: 24px;
  --shadow: 0 2px 8px rgba(27, 22, 19, 0.12);
}

* { box-sizing: border-box; }

html { -webkit-text-size-adjust: 100%; }

body {
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, "Hiragino Sans", "Noto Sans JP",
    "Yu Gothic", Meiryo, sans-serif;
  background: var(--cream);
  color: var(--ink);
  font-size: 16px;
  line-height: 1.7;
}

a { color: var(--red-dark); }
a:hover { color: var(--ink); }

:focus-visible { outline: 3px solid rgba(208, 18, 27, 0.45); outline-offset: 2px; }

/* ---------- ヘッダー / フッター ---------- */

.site-header {
  background: var(--red);
  color: #fff;
  padding: 12px 16px;
  border-bottom: 5px solid var(--yellow);
}
.site-header a { color: #fff; text-decoration: none; }
.site-header .brand { font-weight: 900; font-size: 1.02rem; letter-spacing: 0.02em; line-height: 1.25; }
.site-header .header-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.site-header .header-nav { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; }
.site-header .header-nav a,
.site-header .header-logout {
  border: 1px solid rgba(255, 255, 255, 0.6);
  border-radius: 999px;
  padding: 6px 12px;
  min-height: 34px;
  display: inline-flex;
  align-items: center;
  background: none;
  color: #fff;
  font-size: 0.85rem;
  cursor: pointer;
}

.wrap { max-width: 680px; margin: 0 auto; padding: 16px; }

.site-footer {
  margin-top: 32px;
  padding: 20px 16px 40px;
  background: var(--ink);
  color: #e9e2d6;
  font-size: 0.82rem;
  text-align: center;
}
.site-footer p { margin: 0 0 6px; }
.site-footer a { color: var(--yellow); }

/* ---------- ヒーロー / 見出し ---------- */

.hero {
  background: var(--red);
  color: #fff;
  padding: 24px 16px 28px;
  text-align: center;
}
.hero h1 {
  margin: 0 0 12px;
  font-size: 1.6rem;
  font-weight: 900;
  line-height: 1.35;
  text-wrap: pretty;
  text-shadow: 2px 2px 0 rgba(0, 0, 0, 0.18);
}
.hero .hero-sub {
  display: inline-block;
  background: var(--yellow);
  color: var(--ink);
  font-weight: 900;
  padding: 6px 18px;
  border-radius: 999px;
  font-size: 0.98rem;
}
.hero p { margin: 14px 0 0; font-size: 0.92rem; }

h2 {
  font-size: 1.15rem;
  font-weight: 900;
  margin: 28px 0 12px;
  padding-left: 11px;
  border-left: 6px solid var(--red);
  line-height: 1.4;
}
h3 { font-size: 1rem; margin: 20px 0 8px; }

/* ---------- カード ---------- */

.card {
  background: #fff;
  border: 2px solid var(--border);
  border-radius: var(--radius);
  padding: 16px;
  margin-bottom: 16px;
  box-shadow: var(--shadow);
}
.card > *:first-child { margin-top: 0; }
.card > *:last-child { margin-bottom: 0; }

/* 便カード。ヘッダー帯で行き / 帰りを最初に読ませる */
.trip-card { padding: 0; overflow: hidden; border-color: var(--ink); }
.trip-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 14px;
  background: var(--red);
}
.trip-card.is-return .trip-card__head { background: var(--green); }
.trip-card.is-full .trip-card__head { background: var(--ink-mute); }
.trip-card__dir {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: #fff;
  font-size: 1.4rem;
  font-weight: 900;
  line-height: 1;
}
.trip-card__tag {
  border: 1.5px solid var(--yellow);
  border-radius: 5px;
  padding: 4px 7px;
  color: var(--yellow);
  font-size: 0.72rem;
  font-weight: 700;
}
.trip-card__state {
  background: rgba(0, 0, 0, 0.25);
  border-radius: 999px;
  padding: 6px 11px;
  color: #fff;
  font-size: 0.78rem;
  font-weight: 700;
}
.trip-card__body { padding: 16px 14px; }

.trip-when { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.trip-when .trip-date { font-size: 1.35rem; font-weight: 900; line-height: 1.2; }
.trip-when .trip-time { font-size: 1.9rem; font-weight: 900; line-height: 1; color: var(--red); }
.trip-card.is-return .trip-when .trip-time { color: var(--green); }

.route {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 8px;
  margin: 14px 0;
  padding: 12px 0;
  border-top: 1px dashed rgba(27, 22, 19, 0.3);
  border-bottom: 1px dashed rgba(27, 22, 19, 0.3);
}
.route__col { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.route__col.to { text-align: right; }
.route__label { font-size: 0.68rem; font-weight: 700; color: var(--ink-mute); }
.route__place { font-size: 1.02rem; font-weight: 900; line-height: 1.25; }
.route__sub { font-size: 0.7rem; color: var(--ink-soft); line-height: 1.35; }
.route__arrow { font-size: 1.25rem; font-weight: 900; color: var(--red); }
.trip-card.is-return .route__arrow { color: var(--green); }

.seats { margin: 0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 0.95rem; font-weight: 800; }
.seats .seats-num { color: var(--red); font-size: 1.5rem; }
.trip-card.is-return .seats .seats-num { color: var(--green); }
.seats.is-full { color: var(--ink-soft); }
.seat-badge { border-radius: 5px; padding: 5px 8px; font-size: 0.75rem; font-weight: 700; margin-left: auto; }
.seat-badge.is-few { background: #fbe6e6; color: var(--danger); }
.seat-badge.is-open { background: #e6f4ec; color: var(--green); }
.seat-badge.is-full { background: #f1ece2; color: var(--ink-soft); }

/* ---------- バッジ ---------- */

.badge { display: inline-block; border-radius: 999px; padding: 4px 12px; font-size: 0.8rem; font-weight: 700; }
.badge-open { background: #e6f4ec; color: var(--green); }
.badge-closed { background: #f1ece2; color: var(--ink-soft); }
.badge-full { background: #fbe6e6; color: var(--danger); }
.badge-cancelled { background: #f1ece2; color: var(--ink-soft); }
.badge-confirmed { background: #e6f4ec; color: var(--green); }
.badge-line { background: #e6f4ec; color: var(--green); }
.badge-proxy { background: #fff8dd; color: #8a5a00; }

.trip-badge {
  display: inline-block;
  background: var(--red);
  color: #fff;
  font-weight: 900;
  border-radius: 6px;
  padding: 4px 14px;
  font-size: 0.95rem;
  margin-bottom: 8px;
}
.trip-badge.is-return { background: var(--green); }
.trip-badge.is-inactive { background: var(--ink-mute); }

.trip-datetime { font-size: 1.3rem; font-weight: 900; line-height: 1.4; margin: 0; }
.trip-route { font-size: 0.98rem; font-weight: 700; margin: 4px 0 0; }
.trip-meta { color: var(--ink-soft); font-size: 0.86rem; margin: 6px 0 0; }

/* ---------- ボタン ---------- */

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  min-height: 54px;
  padding: 12px 20px;
  border: none;
  border-radius: var(--radius);
  background: var(--red);
  color: #fff;
  font-size: 1.05rem;
  font-weight: 900;
  text-decoration: none;
  cursor: pointer;
  box-shadow: 0 3px 0 var(--red-dark);
}
.btn:active { transform: translateY(2px); box-shadow: 0 1px 0 var(--red-dark); }
.btn[disabled], .btn.is-disabled {
  background: #cfc6b6; box-shadow: none; color: #fff; pointer-events: none;
}
.btn-secondary {
  background: #fff; color: var(--ink); border: 2px solid var(--border);
  box-shadow: none; font-weight: 700;
}
.btn-secondary:hover { background: var(--cream-deep); }
.btn-return { background: var(--green); box-shadow: 0 3px 0 var(--green-dark); }
.btn-return:active { box-shadow: 0 1px 0 var(--green-dark); }
.btn-line { background: var(--line-green); box-shadow: 0 3px 0 #049a44; }
.btn-danger { background: var(--danger); box-shadow: 0 3px 0 var(--danger-dark); }
.btn-danger-outline {
  background: #fff; color: var(--danger); border: 2px solid var(--danger); box-shadow: none; font-weight: 700;
}
.btn-sm { min-height: 46px; width: auto; padding: 8px 16px; font-size: 0.92rem; }

.btn-row { display: flex; gap: 8px; flex-wrap: wrap; }
.btn-row .btn { width: auto; flex: 1 1 auto; }
.btn-stack { display: flex; flex-direction: column; gap: 10px; }

/* ---------- 注意・アラート ---------- */

.notice {
  background: var(--cream-deep);
  border: 2px dashed var(--red);
  border-radius: var(--radius);
  padding: 12px 14px;
  font-size: 0.9rem;
}
.notice strong { font-weight: 800; }

.alert {
  border-radius: var(--radius);
  padding: 12px 14px;
  margin-bottom: 16px;
  font-weight: 700;
  font-size: 0.95rem;
}
.alert-error { background: #fbe6e6; color: var(--danger); border: 2px solid var(--danger); }
.alert-success { background: #e6f4ec; color: var(--green); border: 2px solid var(--green); }
.alert-info { background: #fff8dd; color: var(--ink); border: 2px solid var(--yellow); }

.done-head { display: flex; align-items: flex-start; gap: 12px; }
.done-head .done-mark {
  flex: none; width: 44px; height: 44px; border-radius: 50%;
  background: var(--green); color: #fff; font-size: 1.5rem; font-weight: 900;
  display: flex; align-items: center; justify-content: center; line-height: 1;
}
.done-head h2 { margin: 0; border: none; padding: 0; font-size: 1.25rem; }

/* ---------- 料金 / 注意リスト ---------- */

.price-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
.price-table th, .price-table td {
  border-bottom: 1px solid var(--border); padding: 10px 4px; text-align: left;
}
.price-table td:last-child { text-align: right; font-weight: 900; white-space: nowrap; font-size: 1.1rem; }
.price-table tr:last-child th, .price-table tr:last-child td { border-bottom: none; }

ul.notes { padding-left: 1.2em; margin: 8px 0; font-size: 0.9rem; }
ul.notes li { margin-bottom: 6px; }

/* ---------- フォーム ---------- */

form .field { margin-bottom: 18px; }
form label { display: block; font-weight: 700; margin-bottom: 6px; font-size: 0.95rem; }
form .req { color: var(--red); font-size: 0.78rem; margin-left: 6px; }
form .hint { color: var(--ink-soft); font-size: 0.82rem; margin-top: 5px; }

input[type="text"], input[type="tel"], input[type="password"],
input[type="number"], input[type="datetime-local"], input[type="search"], select {
  width: 100%;
  min-height: 50px;
  padding: 10px 12px;
  font-size: 16px;
  border: 2px solid var(--border);
  border-radius: 10px;
  background: #fff;
  color: var(--ink);
}
input:focus, select:focus { outline: 3px solid rgba(208, 18, 27, 0.35); border-color: var(--red); }
input[aria-invalid="true"] { border-color: var(--danger); }

/* 人数選択。select ではなく大きなラジオボタンで選ぶ（name/値は従来どおり） */
.party { display: grid; grid-template-columns: repeat(4, 1fr); gap: 9px; }
.party__opt { position: relative; margin: 0; }
.party__opt input {
  position: absolute; inset: 0; width: 100%; height: 100%; margin: 0;
  opacity: 0; cursor: pointer;
}
.party__opt span {
  display: flex; align-items: center; justify-content: center;
  min-height: 60px;
  border: 2px solid var(--border);
  border-radius: 11px;
  background: #fff;
  font-size: 1.2rem;
  font-weight: 900;
  transition: transform 0.1s ease, background 0.12s ease;
}
.party__opt input:checked + span {
  background: var(--red); border-color: var(--red); color: #fff;
  box-shadow: 0 4px 0 var(--ink); transform: translateY(-2px);
}
.party.is-return .party__opt input:checked + span { background: var(--green); border-color: var(--green); }
.party__opt input:focus-visible + span { outline: 3px solid rgba(208, 18, 27, 0.45); outline-offset: 2px; }
.party__opt input:disabled { cursor: not-allowed; }
.party__opt input:disabled + span { background: #f1ece2; border-color: var(--border); color: #a09689; }
.party__opt input:disabled + span::after { content: "×"; margin-left: 4px; font-size: 0.85rem; }

.checkbox-field {
  display: flex; align-items: center; gap: 10px;
  min-height: 50px; padding: 8px 12px;
  border: 2px solid var(--ink); border-radius: 10px; background: #fff;
}
.checkbox-field input[type="checkbox"] { width: 24px; height: 24px; margin: 0; flex: none; accent-color: var(--red); }
.checkbox-field label { margin: 0; font-weight: 700; font-size: 0.92rem; }

.summary-list { list-style: none; margin: 0; padding: 0; }
.summary-list li {
  display: flex; justify-content: space-between; gap: 12px;
  padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 0.95rem;
}
.summary-list li:last-child { border-bottom: none; }
.summary-list li .k { color: var(--ink-soft); font-weight: 700; flex: none; }
.summary-list li .v { text-align: right; font-weight: 700; }

.stack > * + * { margin-top: 12px; }
.muted { color: var(--ink-soft); font-size: 0.88rem; }
.center { text-align: center; }
.danger-text { color: var(--danger); font-weight: 800; }

/* キャンセル確認（予約詳細ページ内。ルート追加なし） */
.cancel-panel { border: 2px solid var(--danger); border-radius: var(--radius); padding: 14px; background: #fff; }
.cancel-panel h3 { margin: 0 0 10px; color: var(--danger); font-size: 1.05rem; }
.cancel-panel .cancel-lead {
  background: #fbe6e6; color: var(--danger); border-radius: 10px;
  padding: 10px 12px; font-weight: 800; margin: 0 0 12px;
}

/* ---------- 管理画面 ---------- */

.admin-header { background: var(--ink); border-bottom-color: var(--red); }
.admin-wrap { max-width: 1000px; }
.admin-grid { display: grid; gap: 16px; grid-template-columns: 1fr; }
@media (min-width: 720px) { .admin-grid { grid-template-columns: 1fr 1fr; } }

.stat { font-size: 1.9rem; font-weight: 900; margin: 10px 0 0; line-height: 1; }
.stat small { font-size: 0.85rem; font-weight: 700; color: var(--ink-soft); }
.stat-remaining { margin: 8px 0 0; font-size: 1rem; font-weight: 800; }
.stat-remaining.is-few { color: var(--danger); }

.admin-card { border-top: 5px solid var(--red); }
.admin-card.is-return { border-top-color: var(--green); }

.book-row { border: 2px solid var(--border); border-radius: var(--radius); background: #fff; padding: 14px; box-shadow: var(--shadow); }
.book-row.is-boarded { border-color: var(--green); }
.book-row.is-cancelled { opacity: 0.6; }
.book-row__head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.book-row__id { font-size: 0.75rem; color: var(--ink-mute); }
.book-row__name { font-size: 1.05rem; font-weight: 900; }
.book-row__size { background: var(--cream-deep); border-radius: 6px; padding: 3px 8px; font-size: 0.82rem; font-weight: 700; }
.book-row__meta { margin: 8px 0 0; font-size: 0.82rem; color: var(--ink-soft); line-height: 1.7; }
.book-row__foot { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.book-row__count { margin-left: auto; font-size: 1.05rem; font-weight: 900; }
.book-row__count small { font-size: 0.78rem; font-weight: 700; color: var(--ink-soft); }

.inline-form { display: flex; align-items: center; gap: 8px; margin: 10px 0 0; flex-wrap: wrap; }
.counter-btn {
  min-width: 52px; min-height: 52px; border: 2px solid var(--border);
  background: #fff; border-radius: var(--radius-sm);
  font-size: 1.4rem; font-weight: 900; cursor: pointer; color: var(--ink);
}
.counter-btn:hover { background: var(--cream-deep); }
.inline-form .btn-all { flex: 1 1 120px; min-height: 52px; }

.progress { height: 8px; border-radius: 999px; background: #f1ece2; overflow: hidden; margin-top: 10px; }
.progress > span { display: block; height: 100%; background: var(--green); }

.search-form { display: flex; gap: 8px; align-items: flex-end; }
.search-form .field { flex: 1; margin: 0; }

.settings-group + .settings-group { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); }
`;
