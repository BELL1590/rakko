/**
 * 管理画面のログイン画面とダッシュボード。
 *
 * D5 の変更点:
 * - 白ベースの業務UI（ポスター調の赤面は使わず、赤はアクセントのみ）
 * - 運用サマリーを最上部に置く（本日の予約人数 / 本日の受付予定 / 残席わずか / 公開中ページ）
 * - 「本日の受付予定」を時刻順の一覧にし、名簿へ1タップで飛べるようにする
 * - 「残席わずか」を独立した一覧にして取りこぼしを防ぐ
 */

import { esc, when } from '../../lib/html';
import { formatJstIsoLike, formatJstLong, formatJstTime } from '../../lib/time';
import { isFewSeats, layout } from '../layout';
import { slotState, slotStateLabel } from '../slot-parts';
import type { PageWithStats, SlotWithAvailability } from '../../db/types';

export function adminLoginPage(params: {
  csrfToken: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const content = `
<h2>管理者ログイン</h2>
<form method="post" action="/admin/login" class="card stack">
  <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
  <div class="field">
    <label for="username">ユーザー名</label>
    <input type="text" id="username" name="username" required autocomplete="username">
  </div>
  <div class="field">
    <label for="password">パスワード</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
  </div>
  <button class="btn" type="submit">ログイン</button>
</form>
`;
  return layout(
    { title: '管理者ログイン | 予約管理', admin: true, alert: params.alert ?? null },
    content,
  );
}

export interface DashboardEntry {
  page: PageWithStats;
  slots: SlotWithAvailability[];
}

interface FlatSlot {
  slot: SlotWithAvailability;
  pageTitle: string;
}

/** JSTの日付部分（YYYY-MM-DD）。 */
function jstDate(iso: string): string {
  return formatJstIsoLike(iso).slice(0, 10);
}

/** 状態バッジの配色。状態の判定そのものは slotState() に任せる。 */
const STATE_BADGE_CLASS: Record<ReturnType<typeof slotState>, string> = {
  open: 'badge-open',
  before_open: 'badge-proxy',
  closed_time: 'badge-closed',
  suspended: 'badge-closed',
  full: 'badge-full',
};

/**
 * 予約枠の状態バッジ。
 * 公開側（slot-parts.ts）と同じ slotState() / slotStateLabel() を使い、
 * 受付中 / 受付開始前 / 受付終了 / 受付停止中 / 満席 の表示を一致させる。
 */
function slotStateBadge(
  slot: SlotWithAvailability,
  nowUtc: string,
): string {
  const cls = STATE_BADGE_CLASS[slotState(slot, nowUtc)];
  return `<span class="badge ${cls}">${esc(slotStateLabel(slot, nowUtc))}</span>`;
}

/**
 * 管理ダッシュボード。
 * 当日の運用に必要な情報（本日の受付予定・残席わずか）を最初に見せる。
 */
export function adminDashboardPage(params: {
  entries: DashboardEntry[];
  /** 省略時は現在時刻。テストから固定したい場合のみ渡す。 */
  nowUtc?: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const nowUtc = params.nowUtc ?? new Date().toISOString();
  const today = jstDate(nowUtc);

  const flat: FlatSlot[] = params.entries.flatMap((entry) =>
    entry.slots.map((slot) => ({ slot, pageTitle: entry.page.title })),
  );

  const todaySlots = flat
    .filter((x) => jstDate(x.slot.start_at) === today)
    .sort((a, b) => a.slot.start_at.localeCompare(b.slot.start_at));

  // 「残席わずか」の判定は公開側のバッジと同じ述語を使う。
  // ここを独自のしきい値にすると、客が「空席あり」と見ている枠が
  // スタッフ側だけアラートに並ぶ（定員6名の貸切枠で実際に起きる）。
  const fewSlots = flat
    .filter((x) => !x.slot.is_full && x.slot.is_bookable && isFewSeats(x.slot))
    .sort((a, b) => a.slot.remaining_seats - b.slot.remaining_seats);

  const todayGuests = todaySlots.reduce((sum, x) => sum + x.slot.booked_seats, 0);
  const publishedCount = params.entries.filter((e) => e.page.status === 'published').length;
  const draftCount = params.entries.filter((e) => e.page.status === 'draft').length;

  const kpis = `<div class="kpi-grid">
  <div class="kpi is-primary">
    <p class="kpi__label">本日の予約人数</p>
    <p class="kpi__value">${todayGuests}<small> 名</small></p>
    <p class="kpi__note">確定済みの合計</p>
  </div>
  <div class="kpi">
    <p class="kpi__label">本日の受付予定</p>
    <p class="kpi__value">${todaySlots.length}<small> 枠</small></p>
    <p class="kpi__note">${
      todaySlots.length > 0
        ? esc(todaySlots.slice(0, 3).map((x) => formatJstTime(x.slot.start_at)).join(' / '))
        : '本日の予定はありません'
    }</p>
  </div>
  <div class="kpi${fewSlots.length > 0 ? ' is-alert' : ''}">
    <p class="kpi__label">残席わずか</p>
    <p class="kpi__value">${fewSlots.length}<small> 枠</small></p>
    <p class="kpi__note">定員に対して残りが少ない枠</p>
  </div>
  <div class="kpi is-ok">
    <p class="kpi__label">公開中ページ</p>
    <p class="kpi__value">${publishedCount}<small> 件</small></p>
    <p class="kpi__note">${draftCount > 0 ? `下書き${draftCount}件` : '下書きなし'}</p>
  </div>
</div>`;

  const todayCard = `<div class="list-card">
  <div class="list-card__head">本日の受付予定</div>
  ${
    todaySlots.length === 0
      ? '<div class="list-card__row"><span class="muted">本日受付予定の枠はありません。</span></div>'
      : todaySlots
          .map(
            (x) => `<div class="list-card__row">
    <span class="list-card__time">${esc(formatJstTime(x.slot.start_at))}</span>
    <span class="list-card__main">
      <span class="list-card__name">${esc(x.slot.name)}</span>
      <span class="list-card__sub">${esc(x.pageTitle)}</span>
    </span>
    <span class="list-card__num">
      <strong>${x.slot.booked_seats}名</strong>
      <small>/ ${x.slot.capacity}</small>
    </span>
    <a class="btn btn-sm" href="/admin/slots/${x.slot.id}">名簿</a>
  </div>`,
          )
          .join('')
  }
</div>`;

  const fewCard = when(
    fewSlots.length > 0,
    `<div class="list-card is-alert">
  <div class="list-card__head">残席わずか</div>
  ${fewSlots
    .map(
      (x) => `<div class="list-card__row">
    <span class="list-card__main">
      <span class="list-card__name">${esc(x.slot.name)}</span>
      <span class="list-card__sub">${esc(x.pageTitle)} ・ ${esc(formatJstLong(x.slot.start_at))}</span>
    </span>
    <span class="badge-few">残り${x.slot.remaining_seats}</span>
    <a class="btn btn-sm btn-secondary" href="/admin/slots/${x.slot.id}">開く</a>
  </div>`,
    )
    .join('')}
</div>`,
  );

  const pageBlocks = params.entries
    .map((entry) => {
      const slotCards = entry.slots
        .map((slot) => {
          const few = !slot.is_full && isFewSeats(slot);
          return `<article class="card admin-card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
    <h3 style="margin:0">${esc(slot.name)}</h3>
    <span>${slotStateBadge(slot, nowUtc)}${
      slot.booking_status === 'hidden' ? ' <span class="badge badge-proxy">非表示</span>' : ''
    }</span>
  </div>
  <p class="muted" style="margin:6px 0 0">${esc(formatJstLong(slot.start_at))}</p>
  <p class="stat">${slot.booked_seats} <small>/ ${slot.capacity}名</small></p>
  <p class="stat-remaining${few ? ' is-few' : ''}">残り ${slot.remaining_seats}席</p>
  <div class="progress" aria-hidden="true"><span style="width:${
    slot.capacity > 0 ? Math.min(100, Math.round((slot.booked_seats / slot.capacity) * 100)) : 0
  }%"></span></div>
  <a class="btn btn-secondary" style="margin-top:14px" href="/admin/slots/${slot.id}">予約一覧・受付</a>
</article>`;
        })
        .join('\n');

      return `<h3 style="margin-top:24px">${esc(entry.page.title)}
  <a class="btn btn-sm btn-secondary" style="margin-left:8px" href="/admin/reservations/${entry.page.id}">設定</a></h3>
${
  entry.slots.length === 0
    ? '<p class="muted">予約枠がまだありません。</p>'
    : `<div class="admin-grid">${slotCards}</div>`
}`;
    })
    .join('\n');

  const content = `
<h2>ダッシュボード</h2>
${kpis}
${todayCard}
${fewCard}

<h2>予約ページ別の状況</h2>
<p><a class="btn btn-secondary btn-sm" href="/admin/reservations">予約ページの管理</a></p>
${
  params.entries.length === 0
    ? '<div class="card"><p class="muted" style="margin:0">公開中の予約ページはありません。</p></div>'
    : pageBlocks
}

<h2>その他</h2>
<div class="card">
  <p style="margin-top:0">リマインドはCron Trigger（5分毎）で自動送信されます。</p>
  <form method="post" action="/admin/reminders/run" style="margin:0">
    <button class="btn btn-secondary btn-sm" type="submit">リマインド処理を今すぐ実行</button>
  </form>
</div>
`;

  return layout(
    { title: '管理ダッシュボード | 予約管理', admin: true, alert: params.alert ?? null },
    content,
  );
}
