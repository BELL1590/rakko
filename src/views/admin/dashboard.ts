import { esc } from '../../lib/html';
import { formatJstLong } from '../../lib/time';
import { layout } from '../layout';
import type { TripWithAvailability } from '../../db/types';

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
    { title: '管理者ログイン | らっこ号', admin: true, alert: params.alert ?? null },
    content,
  );
}

export function adminDashboardPage(params: {
  trips: TripWithAvailability[];
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const cards = params.trips
    .map((trip) => {
      const label = trip.direction === 'outbound' ? '行き便' : '帰り便';
      const statusBadge =
        trip.booking_status === 'open'
          ? '<span class="badge badge-open">受付中</span>'
          : '<span class="badge badge-closed">受付停止</span>';
      return `<article class="card">
  <h3 style="margin-top:0">${label}</h3>
  <p class="muted" style="margin-top:0">${esc(formatJstLong(trip.depart_at))} ／ ${esc(trip.origin)} → ${esc(trip.destination)}</p>
  <p class="stat">${trip.booked_seats} <small>/ ${trip.capacity}名</small></p>
  <p>残り：<strong>${trip.remaining_seats}</strong>席 ${statusBadge} ${
    trip.is_full ? '<span class="badge badge-full">満席</span>' : ''
  }</p>
  <a class="btn btn-sm btn-secondary" href="/admin/trips/${esc(trip.slug)}">便の詳細・予約一覧</a>
</article>`;
    })
    .join('\n');

  const content = `
<h2>ダッシュボード</h2>
<div class="admin-grid">${cards}</div>
<h2>その他</h2>
<div class="card">
  <p style="margin-top:0">リマインドはCron Trigger（5分毎）で自動送信されます。</p>
  <form method="post" action="/admin/reminders/run" style="margin:0">
    <button class="btn btn-secondary btn-sm" type="submit">リマインド処理を今すぐ実行</button>
  </form>
</div>
`;

  return layout(
    { title: '管理ダッシュボード | らっこ号', admin: true, alert: params.alert ?? null },
    content,
  );
}
