/**
 * アプリ全体のCSS。Workersはファイルシステムを持たないため、文字列として保持し
 * `/assets/app.css` から配信する。
 *
 * デザイン: 草加健康センターの告知ポスターを意識した赤 × クリーム × 黒 × 黄色。
 * ただし可読性最優先、モバイルファースト、タップ領域44px以上。
 */
export const APP_CSS = `
:root {
  --red: #d0121b;
  --red-dark: #a30d15;
  --cream: #fdf6e6;
  --cream-deep: #f6e8c8;
  --ink: #1b1613;
  --ink-soft: #4a423c;
  --yellow: #ffd400;
  --green: #1f7a4d;
  --line-green: #06c755;
  --border: #e0d3b4;
  --danger: #b3261e;
  --radius: 12px;
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

.site-header {
  background: var(--red);
  color: #fff;
  padding: 14px 16px;
  border-bottom: 5px solid var(--yellow);
}
.site-header a { color: #fff; text-decoration: none; }
.site-header .brand { font-weight: 800; font-size: 1.05rem; letter-spacing: 0.02em; }
.site-header .header-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.site-header .header-nav { display: flex; gap: 10px; font-size: 0.85rem; }
.site-header .header-nav a {
  border: 1px solid rgba(255,255,255,0.6);
  border-radius: 999px;
  padding: 6px 12px;
  min-height: 32px;
  display: inline-flex;
  align-items: center;
}

.wrap { max-width: 680px; margin: 0 auto; padding: 16px; }

.hero {
  background: var(--red);
  color: #fff;
  padding: 24px 16px 28px;
  text-align: center;
}
.hero h1 {
  margin: 0 0 8px;
  font-size: 1.6rem;
  font-weight: 900;
  line-height: 1.35;
  text-shadow: 2px 2px 0 rgba(0,0,0,0.18);
}
.hero .hero-sub {
  display: inline-block;
  background: var(--yellow);
  color: var(--ink);
  font-weight: 800;
  padding: 4px 16px;
  border-radius: 999px;
  font-size: 0.95rem;
}
.hero p { margin: 12px 0 0; font-size: 0.92rem; }

h2 {
  font-size: 1.15rem;
  font-weight: 800;
  margin: 28px 0 12px;
  padding-left: 12px;
  border-left: 6px solid var(--red);
}
h3 { font-size: 1rem; margin: 20px 0 8px; }

.card {
  background: #fff;
  border: 2px solid var(--border);
  border-radius: var(--radius);
  padding: 16px;
  margin-bottom: 16px;
  box-shadow: var(--shadow);
}

.trip-card { border-color: var(--red); border-width: 2px; }
.trip-card.is-full { border-color: var(--border); opacity: 0.92; }

.trip-badge {
  display: inline-block;
  background: var(--red);
  color: #fff;
  font-weight: 800;
  border-radius: 6px;
  padding: 2px 14px;
  font-size: 0.95rem;
  margin-bottom: 8px;
}
.trip-badge.is-return { background: var(--green); }

.trip-datetime { font-size: 1.35rem; font-weight: 900; line-height: 1.4; }
.trip-route { font-size: 1rem; font-weight: 700; margin-top: 4px; }
.trip-meta { color: var(--ink-soft); font-size: 0.88rem; margin-top: 6px; }

.seats {
  margin: 12px 0;
  font-size: 1.05rem;
  font-weight: 800;
}
.seats .seats-num { color: var(--red); font-size: 1.5rem; }
.seats.is-full { color: var(--ink-soft); }

.badge {
  display: inline-block;
  border-radius: 999px;
  padding: 2px 12px;
  font-size: 0.8rem;
  font-weight: 700;
}
.badge-open { background: #e6f4ec; color: var(--green); }
.badge-closed { background: #f1ece2; color: var(--ink-soft); }
.badge-full { background: #fbe6e6; color: var(--danger); }
.badge-cancelled { background: #f1ece2; color: var(--ink-soft); }
.badge-confirmed { background: #e6f4ec; color: var(--green); }

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  min-height: 52px;
  padding: 12px 20px;
  border: none;
  border-radius: var(--radius);
  background: var(--red);
  color: #fff;
  font-size: 1.05rem;
  font-weight: 800;
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
.btn-line { background: var(--line-green); box-shadow: 0 3px 0 #049a44; }
.btn-danger { background: var(--danger); box-shadow: 0 3px 0 #7d1a15; }
.btn-sm { min-height: 44px; width: auto; padding: 8px 16px; font-size: 0.9rem; }

.btn-row { display: flex; gap: 8px; flex-wrap: wrap; }
.btn-row .btn { width: auto; flex: 1 1 auto; }

.notice {
  background: var(--cream-deep);
  border: 2px dashed var(--red);
  border-radius: var(--radius);
  padding: 12px 14px;
  font-size: 0.9rem;
}

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

.price-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
.price-table th, .price-table td {
  border-bottom: 1px solid var(--border); padding: 8px 4px; text-align: left;
}
.price-table td:last-child { text-align: right; font-weight: 800; white-space: nowrap; }

ul.notes { padding-left: 1.2em; margin: 8px 0; font-size: 0.9rem; }
ul.notes li { margin-bottom: 6px; }

form .field { margin-bottom: 18px; }
form label { display: block; font-weight: 700; margin-bottom: 6px; font-size: 0.95rem; }
form .req { color: var(--red); font-size: 0.8rem; margin-left: 6px; }
form .hint { color: var(--ink-soft); font-size: 0.82rem; margin-top: 4px; }

input[type="text"], input[type="tel"], input[type="password"],
input[type="number"], input[type="datetime-local"], input[type="search"], select {
  width: 100%;
  min-height: 48px;
  padding: 10px 12px;
  font-size: 16px;
  border: 2px solid var(--border);
  border-radius: 10px;
  background: #fff;
  color: var(--ink);
}
input:focus, select:focus { outline: 3px solid rgba(208,18,27,0.35); border-color: var(--red); }

.checkbox-field { display: flex; align-items: flex-start; gap: 10px; }
.checkbox-field input[type="checkbox"] { width: 24px; height: 24px; margin-top: 2px; flex: none; }
.checkbox-field label { margin: 0; font-weight: 600; }

.summary-list { list-style: none; margin: 0; padding: 0; }
.summary-list li {
  display: flex; justify-content: space-between; gap: 12px;
  padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 0.95rem;
}
.summary-list li .k { color: var(--ink-soft); font-weight: 700; flex: none; }
.summary-list li .v { text-align: right; font-weight: 700; }

.stack > * + * { margin-top: 12px; }
.muted { color: var(--ink-soft); font-size: 0.88rem; }
.center { text-align: center; }

.site-footer {
  margin-top: 32px; padding: 20px 16px 40px;
  background: var(--ink); color: #e9e2d6; font-size: 0.82rem; text-align: center;
}
.site-footer a { color: var(--yellow); }

/* ---- 管理画面 ---- */
.admin-header { background: var(--ink); border-bottom-color: var(--red); }
.admin-wrap { max-width: 1000px; }
.table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
table.data { width: 100%; border-collapse: collapse; font-size: 0.88rem; background: #fff; }
table.data th, table.data td {
  border: 1px solid var(--border); padding: 8px; text-align: left; white-space: nowrap;
}
table.data th { background: var(--cream-deep); font-weight: 800; }
table.data td.wrap-cell { white-space: normal; min-width: 140px; }
.inline-form { display: inline-flex; align-items: center; gap: 4px; margin: 0; }
.inline-form input[type="number"] { width: 72px; min-height: 44px; }
.counter-btn {
  min-width: 44px; min-height: 44px; border: 2px solid var(--border);
  background: #fff; border-radius: 8px; font-size: 1.1rem; font-weight: 800; cursor: pointer;
}
.admin-grid { display: grid; gap: 16px; grid-template-columns: 1fr; }
@media (min-width: 720px) { .admin-grid { grid-template-columns: 1fr 1fr; } }
.stat { font-size: 1.6rem; font-weight: 900; }
.stat small { font-size: 0.9rem; font-weight: 700; color: var(--ink-soft); }
`;
