/** 管理画面のログイン画面とダッシュボード。 */

import { esc } from '../../lib/html';
import { formatJstLong } from '../../lib/time';
import { layout } from '../layout';
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

/**
 * 管理ダッシュボード。
 * 公開中の予約ページと、その予約枠の埋まり具合を最初に見せる。
 */
export function adminDashboardPage(params: {
  entries: DashboardEntry[];
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const cards = params.entries
    .map((entry) => {
      const slotCards = entry.slots
        .map((slot) => {
          const few = !slot.is_full && slot.remaining_seats <= 6;
          return `<article class="card admin-card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
    <h3 style="margin:0">${esc(slot.name)}</h3>
    <span>${
      slot.booking_status === 'open'
        ? '<span class="badge badge-open">受付中</span>'
        : slot.booking_status === 'hidden'
          ? '<span class="badge badge-proxy">非表示</span>'
          : '<span class="badge badge-closed">受付停止</span>'
    }${slot.is_full ? ' <span class="badge badge-full">満席</span>' : ''}</span>
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
<p><a class="btn btn-secondary" href="/admin/reservations">予約ページの管理</a></p>
${
  params.entries.length === 0
    ? '<div class="card"><p class="muted" style="margin:0">公開中の予約ページはありません。</p></div>'
    : cards
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
