import { esc, when } from '../../lib/html';
import { formatJstIsoLike, formatJstLong, toJstDatetimeLocal } from '../../lib/time';
import { layout } from '../layout';
import { parseCompanionNames } from '../../db/types';
import type { BookingWithTrip, NotificationRow, TripWithAvailability } from '../../db/types';

const NOTIFICATION_LABEL: Record<string, string> = {
  pending: '未送信',
  requested: '送信要求済み',
  failed: '失敗',
  skipped: 'スキップ',
};

function notificationCell(
  notifications: NotificationRow[],
  bookingId: number,
): string {
  const rows = notifications.filter((n) => n.booking_id === bookingId);
  if (rows.length === 0) return '<span class="muted">-</span>';
  return rows
    .map((n) => {
      const typeLabel = n.notification_type === 'reminder' ? 'リマインド' : '予約完了';
      const status = NOTIFICATION_LABEL[n.status] ?? n.status;
      return `${typeLabel}：${esc(status)}${n.attempt_count > 1 ? `(${n.attempt_count}回)` : ''}`;
    })
    .join('<br>');
}

export function adminTripDetailPage(params: {
  trip: TripWithAvailability;
  bookings: BookingWithTrip[];
  notifications: NotificationRow[];
  search: string;
  csrfToken: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const { trip } = params;
  const label = trip.direction === 'outbound' ? '行き便' : '帰り便';

  const rows = params.bookings
    .map((booking) => {
      const companions = parseCompanionNames(booking.companion_names_json);
      const cancelled = booking.status === 'cancelled';
      return `<tr${cancelled ? ' style="opacity:.55"' : ''}>
  <td>#${booking.id}</td>
  <td>${esc(formatJstIsoLike(booking.created_at))}</td>
  <td class="wrap-cell">${esc(booking.representative_name)}</td>
  <td>${esc(booking.phone)}</td>
  <td>${booking.party_size}名</td>
  <td class="wrap-cell">${esc(companions.join('、')) || '<span class="muted">-</span>'}</td>
  <td>${booking.source === 'admin' ? '管理者代理' : 'LINE'}</td>
  <td>
    ${
      cancelled
        ? '<span class="muted">-</span>'
        : `<form class="inline-form" method="post" action="/admin/bookings/${booking.id}/checkin">
        <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
        <input type="hidden" name="trip_slug" value="${esc(trip.slug)}">
        <button class="counter-btn" type="submit" name="op" value="dec" aria-label="乗車人数を1減らす">−</button>
        <span style="min-width:52px;text-align:center">${booking.checked_in_count} / ${booking.party_size}</span>
        <button class="counter-btn" type="submit" name="op" value="inc" aria-label="乗車人数を1増やす">＋</button>
        <button class="btn btn-sm btn-secondary" type="submit" name="op" value="all">全員乗車</button>
      </form>`
    }
  </td>
  <td>${
    cancelled
      ? '<span class="badge badge-cancelled">キャンセル</span>'
      : '<span class="badge badge-confirmed">予約済み</span>'
  }</td>
  <td>${notificationCell(params.notifications, booking.id)}</td>
  <td>${
    cancelled
      ? ''
      : `<form method="post" action="/admin/bookings/${booking.id}/cancel" style="margin:0"
          onsubmit="return confirm('予約 #${booking.id} をキャンセルします。よろしいですか？');">
      <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
      <input type="hidden" name="trip_slug" value="${esc(trip.slug)}">
      <button class="btn btn-sm btn-danger" type="submit">キャンセル</button>
    </form>`
  }</td>
</tr>`;
    })
    .join('\n');

  const content = `
<p><a href="/admin">← ダッシュボードへ</a></p>
<h2>${label}</h2>

<section class="card">
  <p style="margin-top:0">${esc(formatJstLong(trip.depart_at))} ／ ${esc(trip.origin)} → ${esc(trip.destination)}</p>
  <p class="stat">${trip.booked_seats} <small>/ ${trip.capacity}名（残り${trip.remaining_seats}席）</small></p>
  <p>受付状態：${
    trip.booking_status === 'open'
      ? '<span class="badge badge-open">受付中</span>'
      : '<span class="badge badge-closed">受付停止</span>'
  }</p>
</section>

<h2>便の設定</h2>
<div class="admin-grid">
  <form class="card" method="post" action="/admin/trips/${esc(trip.slug)}/capacity">
    <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
    <div class="field">
      <label for="capacity">定員</label>
      <input type="number" id="capacity" name="capacity" value="${trip.capacity}" min="0" max="200" required>
      <p class="hint">既存の確定予約人数（${trip.booked_seats}名）を下回る値には変更できません。</p>
    </div>
    <button class="btn btn-sm" type="submit">定員を更新</button>
  </form>

  <form class="card" method="post" action="/admin/trips/${esc(trip.slug)}/reminder">
    <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
    <div class="field">
      <label for="reminder_at">リマインド送信日時（JST）</label>
      <input type="datetime-local" id="reminder_at" name="reminder_at"
        value="${esc(toJstDatetimeLocal(trip.reminder_at))}" required>
      <p class="hint">この時刻を過ぎるとCronがリマインドを送信します。</p>
    </div>
    <button class="btn btn-sm" type="submit">リマインド日時を更新</button>
  </form>

  <form class="card" method="post" action="/admin/trips/${esc(trip.slug)}/status">
    <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
    <p style="margin-top:0"><strong>予約受付</strong></p>
    <div class="btn-row">
      <button class="btn btn-sm" type="submit" name="booking_status" value="open"
        ${trip.booking_status === 'open' ? 'disabled' : ''}>受付開始</button>
      <button class="btn btn-sm btn-secondary" type="submit" name="booking_status" value="closed"
        ${trip.booking_status === 'closed' ? 'disabled' : ''}>受付停止</button>
    </div>
  </form>

  <div class="card">
    <p style="margin-top:0"><strong>エクスポート</strong></p>
    <a class="btn btn-sm btn-secondary" href="/admin/trips/${esc(trip.slug)}/bookings.csv">CSVをダウンロード</a>
  </div>
</div>

<h2>管理者代理予約</h2>
<form class="card" method="post" action="/admin/trips/${esc(trip.slug)}/bookings">
  <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
  <p class="muted" style="margin-top:0">電話・現地対応用。LINE通知は送信されません。定員管理は通常予約と同じです。</p>
  <div class="field">
    <label for="admin_name">代表者氏名</label>
    <input type="text" id="admin_name" name="representative_name" maxlength="50" required>
  </div>
  <div class="field">
    <label for="admin_phone">電話番号</label>
    <input type="tel" id="admin_phone" name="phone" maxlength="20" required>
  </div>
  <div class="field">
    <label for="admin_party_size">人数</label>
    <select id="admin_party_size" name="party_size" required>
      ${[1, 2, 3, 4].map((n) => `<option value="${n}">${n}名</option>`).join('')}
    </select>
  </div>
  <div class="field">
    <label for="admin_companions">同行者氏名（読点・改行区切り）</label>
    <input type="text" id="admin_companions" name="companion_names_text" maxlength="200"
      placeholder="山田花子、佐藤次郎">
  </div>
  <button class="btn btn-sm" type="submit">代理予約を登録</button>
</form>

<h2>予約一覧（${params.bookings.length}件）</h2>
<form class="card" method="get" action="/admin/trips/${esc(trip.slug)}" style="display:flex;gap:8px;align-items:flex-end">
  <div class="field" style="flex:1;margin:0">
    <label for="q">検索（氏名・電話番号・予約ID）</label>
    <input type="search" id="q" name="q" value="${esc(params.search)}">
  </div>
  <button class="btn btn-sm btn-secondary" type="submit">検索</button>
  ${when(
    Boolean(params.search),
    `<a class="btn btn-sm btn-secondary" href="/admin/trips/${esc(trip.slug)}">クリア</a>`,
  )}
</form>

<div class="table-scroll">
<table class="data">
  <thead>
    <tr>
      <th>ID</th><th>予約日時</th><th>代表者</th><th>電話番号</th><th>人数</th>
      <th>同行者</th><th>経路</th><th>乗車人数</th><th>状態</th><th>通知</th><th>操作</th>
    </tr>
  </thead>
  <tbody>
    ${rows || '<tr><td colspan="11" class="muted">該当する予約はありません。</td></tr>'}
  </tbody>
</table>
</div>
`;

  return layout(
    { title: `${label} | らっこ号 管理画面`, admin: true, alert: params.alert ?? null },
    content,
  );
}
