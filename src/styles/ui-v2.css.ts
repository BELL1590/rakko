/**
 * Phase 2 UI（D1〜D6）で追加するCSS。
 *
 * 既存の `app.css.ts` の APP_CSS には手を入れず、末尾に連結して使う。
 * 既存クラス名は一切変更・削除していない（追加のみ）。
 *
 * 適用方法（app.css.ts への2行の変更）:
 *   1. 先頭に  import { UI_V2_CSS } from './ui-v2.css';
 *   2. 既存の  export const APP_CSS = `  を  const BASE_CSS = `  に変更
 *   3. ファイル末尾に  export const APP_CSS = BASE_CSS + UI_V2_CSS;  を追加
 */
export const UI_V2_CSS = `
/* ============================================================
   D1 / D2  公開予約ページ
   ============================================================ */

/* 枠カードの選択状態。行き/帰りの赤緑交互をやめ、
   ブランド赤を基本にして「選択されているか」だけを色で示す。 */
.slot-card { border-color: var(--ink); }
.slot-card .trip-card__head { background: var(--red); }
.slot-card.is-full .trip-card__head { background: var(--ink-mute); }
.slot-card.is-selected {
  border-color: var(--red);
  box-shadow: 0 0 0 3px rgba(208, 18, 27, 0.15);
}

.slot-pick {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px dashed rgba(27, 22, 19, 0.3);
}
.slot-toggle { min-height: 56px; }
.slot-card.is-selected .slot-toggle { border-color: var(--red); background: #fff7f7; }
.slot-toggle input { accent-color: var(--red); }
.slot-fields { margin-top: 14px; }
.slot-fields > .field:last-child { margin-bottom: 0; }
.slot-single-party { margin: 0; font-size: 0.88rem; color: var(--ink-soft); }

/* 1予約あたりの最大人数が多い枠は3列にして、ボタンを細くしすぎない */
.party.party--wide { grid-template-columns: repeat(3, 1fr); }

/* 同行者欄をグループとして見せる（no-JSでは最大人数分が常に見える） */
.companion-group {
  margin-top: 14px;
  padding: 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: rgba(246, 232, 200, 0.45);
}
.companion-group__lead { margin: 0 0 4px; font-size: 0.85rem; font-weight: 800; }
.companion-group .hint { margin-top: 0; }
.companion-group .field:last-child { margin-bottom: 0; }

/* 受付期間の案内 */
.slot-timing {
  margin: 10px 0 0;
  font-size: 0.8rem;
  line-height: 1.6;
  color: var(--ink-soft);
}
.slot-timing.is-waiting { color: var(--ink); }
.slot-timing strong { font-weight: 800; }
.seats.is-closed, .seats.is-waiting { color: var(--ink-soft); font-weight: 800; }
.slot-card.is-waiting { opacity: 0.94; }
.slot-card.is-waiting .trip-card__head { background: #6b625a; }
.section-head h2.is-waiting { color: var(--ink-soft); border-left-color: #6b625a; }
.section-head .count.is-waiting { background: #f1ece2; color: var(--ink-soft); }

/* 下部固定CTA + 選択中サマリー */
.sticky-cta {
  position: sticky;
  bottom: 0;
  z-index: 20;
  margin: 16px -16px 0;
  padding: 12px 16px calc(14px + env(safe-area-inset-bottom));
  background: rgba(253, 246, 230, 0.97);
  border-top: 2px solid var(--border);
  box-shadow: 0 -6px 18px rgba(27, 22, 19, 0.12);
}
.sticky-cta__summary { margin-bottom: 10px; }
.sticky-cta__head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 7px;
  font-size: 0.82rem;
  font-weight: 900;
}
.sticky-cta__total { margin-left: auto; font-weight: 700; color: var(--ink-soft); }
.sticky-cta__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 5px; }
.sticky-cta__list li {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 0.8rem;
  line-height: 1.4;
}
.sticky-cta__list .s-name { font-weight: 800; flex: none; }
.sticky-cta__list .s-when {
  color: var(--ink-soft);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sticky-cta__list .s-size { margin-left: auto; font-weight: 800; flex: none; }
.sticky-cta .btn { min-height: 56px; }
.sticky-cta__hint { margin: 7px 0 0; font-size: 0.74rem; color: var(--ink-soft); text-align: center; }

/* 送信前の確認セクション（同一フォーム内。JS無効時は最初から開いている） */
.confirm-panel {
  margin-top: 16px;
  border: 2px solid var(--ink);
  border-radius: var(--radius);
  background: #fff;
  padding: 16px;
  box-shadow: var(--shadow);
  scroll-margin-top: 16px;
}
.confirm-panel h3 { margin: 0 0 12px; font-size: 1.1rem; }
.confirm-lead {
  background: var(--cream-deep);
  border: 2px dashed var(--red);
  border-radius: 10px;
  padding: 10px 12px;
  margin: 0 0 14px;
  font-size: 0.85rem;
  line-height: 1.7;
}
.confirm-slot {
  border: 2px solid var(--ink);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 12px;
}
.confirm-slot__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 9px 13px;
  background: var(--red);
  color: #fff;
}
.confirm-slot__name { font-size: 1.05rem; font-weight: 900; }
.confirm-slot__size {
  background: var(--yellow);
  color: var(--ink);
  border-radius: 999px;
  padding: 4px 11px;
  font-size: 0.78rem;
  font-weight: 800;
  flex: none;
}
.confirm-slot__body { padding: 13px; }
.confirm-slot__when { margin: 0; font-size: 1.05rem; font-weight: 900; line-height: 1.35; }
.confirm-slot__where { margin: 5px 0 0; font-size: 0.85rem; font-weight: 700; }
.confirm-slot__comp {
  margin: 11px 0 0;
  padding-top: 11px;
  border-top: 1px dashed rgba(27, 22, 19, 0.25);
  font-size: 0.8rem;
  color: var(--ink-soft);
}

/* ============================================================
   D3  マイ予約：まとめて予約のグルーピング
   ============================================================ */

.booking-group {
  border: 2px solid var(--red);
  border-radius: var(--radius-lg);
  background: rgba(208, 18, 27, 0.04);
  padding: 11px 11px 12px;
  margin-bottom: 16px;
}
.booking-group__head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 10px;
  padding: 0 3px;
}
.booking-group__badge {
  background: var(--red);
  color: #fff;
  border-radius: 999px;
  padding: 5px 11px;
  font-size: 0.74rem;
  font-weight: 800;
}
.booking-group__meta { font-size: 0.78rem; font-weight: 700; color: var(--ink-soft); }
.booking-group__at { margin-left: auto; font-size: 0.72rem; color: var(--ink-mute); }
.booking-group .card { margin-bottom: 10px; }
.booking-group .card:last-of-type { margin-bottom: 0; }
.booking-group__note { margin: 10px 3px 0; font-size: 0.75rem; color: var(--ink-soft); line-height: 1.6; }

/* ============================================================
   D4  公開トップ：受付中 / 受付終了の分離
   ============================================================ */

.section-head { display: flex; align-items: center; gap: 9px; margin: 28px 0 12px; }
.section-head h2 { margin: 0; }
.section-head .count {
  border-radius: 999px;
  padding: 4px 11px;
  font-size: 0.74rem;
  font-weight: 800;
  background: #e6f4ec;
  color: var(--green);
}
.section-head .count.is-closed { background: #f1ece2; color: var(--ink-soft); }
.section-head h2.is-closed { color: var(--ink-soft); border-left-color: var(--ink-mute); }

.slot-lines { list-style: none; margin: 12px 0 0; padding: 0; }
.slot-lines li {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 7px 0;
  border-bottom: 1px dashed rgba(27, 22, 19, 0.2);
  font-size: 0.85rem;
}
.slot-lines li:last-child { border-bottom: none; }
.slot-line__name { font-weight: 800; flex: none; }
.slot-line__when {
  color: var(--ink-soft);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.slot-line__seats { margin-left: auto; font-weight: 700; flex: none; }

/* 受付終了のページは畳んで軽く見せる */
.page-closed {
  border: 2px solid var(--border);
  border-radius: var(--radius);
  background: #fff;
  padding: 14px;
  margin-bottom: 12px;
  opacity: 0.78;
}
.page-closed__head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.page-closed__title { font-size: 0.95rem; font-weight: 900; color: var(--ink-soft); min-width: 0; }
.page-closed__meta { margin: 8px 0 0; font-size: 0.8rem; color: var(--ink-soft); }

/* ============================================================
   D5 / D6  管理画面：白ベースの業務UI
   ============================================================ */

/* 管理画面だけ背景を業務向けのグレーに。:has 未対応環境ではクリーム地のまま（表示は崩れない）。 */
body:has(.admin-header) { background: #f4f6f8; }
.admin-wrap h2 {
  font-size: 1.05rem;
  border-left-color: var(--red);
  margin: 24px 0 12px;
}
.admin-wrap h3 { font-size: 0.95rem; }

.admin-card-plain {
  background: #fff;
  border: 1px solid #d8dde3;
  border-radius: 10px;
  padding: 15px;
  margin-bottom: 14px;
}

/* 運用サマリーのKPI */
.kpi-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
@media (min-width: 720px) { .kpi-grid { grid-template-columns: repeat(4, 1fr); } }
.kpi {
  background: #fff;
  border: 1px solid #d8dde3;
  border-top: 4px solid var(--ink);
  border-radius: 10px;
  padding: 13px;
}
.kpi.is-primary { border-top-color: var(--red); }
.kpi.is-alert { border-color: #f0c9c9; border-top-color: var(--danger); }
.kpi.is-ok { border-top-color: var(--green); }
.kpi__label { font-size: 0.74rem; font-weight: 700; color: #5b6470; line-height: 1.4; }
.kpi__value { margin: 7px 0 0; font-size: 1.55rem; font-weight: 800; line-height: 1; }
.kpi__value small { font-size: 0.74rem; font-weight: 700; color: #5b6470; }
.kpi__note { margin: 6px 0 0; font-size: 0.7rem; color: #5b6470; line-height: 1.4; }

/* 一覧テーブル風カード（本日の受付予定 / 残席わずか） */
.list-card {
  background: #fff;
  border: 1px solid #d8dde3;
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 16px;
}
.list-card.is-alert { border-color: #f0c9c9; }
.list-card__head {
  padding: 11px 13px;
  background: #fafbfc;
  border-bottom: 1px solid #d8dde3;
  font-size: 0.82rem;
  font-weight: 800;
}
.list-card.is-alert .list-card__head {
  background: #fdf5f5;
  border-bottom-color: #f0c9c9;
  color: #991b1b;
}
.list-card__row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 13px;
  border-bottom: 1px solid #eef1f4;
}
.list-card__row:last-child { border-bottom: none; }
.list-card__time { width: 52px; flex: none; font-size: 0.95rem; font-weight: 800; }
.list-card__main { flex: 1; min-width: 0; }
.list-card__name {
  /* span のままだと text-overflow が効かず、右のバッジへ文字が重なる */
  display: block;
  font-size: 0.85rem;
  font-weight: 700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.list-card__sub {
  display: block;
  margin: 3px 0 0;
  font-size: 0.72rem;
  color: #5b6470;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.list-card__num { flex: none; text-align: right; }
.list-card__num strong { display: block; font-size: 0.9rem; font-weight: 800; }
.list-card__num small { font-size: 0.7rem; color: #5b6470; }
.list-card__row .btn { width: auto; flex: none; }
.badge-few { background: #fbe6e6; color: var(--danger); border-radius: 6px; padding: 5px 9px; font-size: 0.75rem; font-weight: 800; }

/* 予約ページ一覧（管理） */
.page-row {
  background: #fff;
  border: 1px solid #d8dde3;
  border-top: 4px solid var(--red);
  border-radius: 10px;
  padding: 14px;
  margin-bottom: 12px;
}
.page-row.is-draft { border-top-color: #b9c0c9; }
.page-row__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 9px; }
.page-row__title { font-size: 0.92rem; font-weight: 800; line-height: 1.35; min-width: 0; }
.page-row__meta { margin: 7px 0 0; font-size: 0.74rem; color: #5b6470; }
.page-row__stat { margin: 9px 0 0; display: flex; align-items: baseline; gap: 7px; }
.page-row__stat strong { font-size: 1.35rem; font-weight: 800; line-height: 1; }
.page-row__stat span { font-size: 0.74rem; font-weight: 700; color: #5b6470; }
.page-row__url { margin: 9px 0 0; font-size: 0.7rem; color: #5b6470; word-break: break-all; }
.page-row .btn-row { margin-top: 11px; }
.page-row .btn { min-height: 44px; font-size: 0.8rem; }

/* 管理画面のフォームは白ベースの落ち着いた枠線に寄せる */
.admin-wrap input[type="text"], .admin-wrap input[type="tel"], .admin-wrap input[type="password"],
.admin-wrap input[type="number"], .admin-wrap input[type="datetime-local"],
.admin-wrap input[type="search"], .admin-wrap select {
  border: 1px solid #b9c0c9;
  border-radius: 8px;
  min-height: 48px;
}
.admin-wrap input:focus, .admin-wrap select:focus {
  border-color: var(--ink);
  outline: 2px solid rgba(27, 22, 19, 0.2);
}
.admin-wrap .card { border: 1px solid #d8dde3; border-radius: 10px; box-shadow: none; }
.admin-wrap .btn { border-radius: 8px; box-shadow: none; }
.admin-wrap .btn:active { transform: none; }
.admin-wrap .btn-secondary { border: 1px solid #d8dde3; }
.admin-wrap .btn-secondary:hover { background: #f4f6f8; }
.admin-wrap .counter-btn { border: 1px solid #b9c0c9; }
.admin-wrap .counter-btn:hover { background: #eef1f4; }
.admin-wrap .book-row { border: 1px solid #d8dde3; border-radius: 10px; box-shadow: none; }
.admin-wrap .book-row.is-boarded { border-color: var(--green); }
.admin-wrap .book-row__meta a { font-weight: 700; }

/* 枠設定フォームのグルーピング */
.form-section { margin-bottom: 16px; }
.form-section__title {
  font-size: 0.88rem;
  font-weight: 800;
  margin: 0 0 10px;
  padding-left: 11px;
  border-left: 6px solid var(--red);
}
.form-note {
  background: #fff8dd;
  border: 1px solid #e5c477;
  border-radius: 8px;
  padding: 11px;
  font-size: 0.75rem;
  line-height: 1.6;
  margin: 0 0 14px;
}
.field-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 11px; }
.field-2col .field { margin-bottom: 0; }
`;
