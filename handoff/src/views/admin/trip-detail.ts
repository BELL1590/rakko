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

function notificationCell(notifications: NotificationRow[], bookingId: number): string {
  const rows = notifications.filter((n) => n.booking_id === bookingId);
  if (rows.length === 0) return '<span class="muted">-</span>';
  return rows
    .map((n) => {
      const typeLabel = n.notification_type === 'reminder' ? 'リマインド' : '予約完了';
      const status = NOTIFICATION_LABEL[n.status] ?? n.status;
      return `${typeLabel}：${esc(status)}${n.attempt_count > 1 ? `(${n.attempt_count}回)` : ''}`;
    })
    .join(' ／ ');
}

/**
 * 便の詳細 / 予約一覧 / 乗車確認 / 代理予約 / 便の設定。
 *
 * 当日はスマホで操作するため、予約一覧は横スクロールのテーブルではなく
 * 1件=1カードにし、乗車人数の −/＋/全員乗車 を52pxのタップ領域で並べる。
 * フォームのPOST先・name属性・値（op=dec|inc|all 等）は一切変更しない。
 */
export function adminTripDetailPage(params: {
  trip: TripWithAvailability;
  bookings: BookingWithTrip[];
  notifications: NotificationRow[];
  search: string;
  csrfToken: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const { trip } = params;
  const label = trip.direction === 'return' ? '帰り便' : '行き便';

  const activeBookings = params.bookings.filter((b) => b.status !== 'cancelled');
  const seatsTotal = activeBookings.reduce((sum, b) => sum + b.party_size, 0);
  const boardedTotal = activeBookings.reduce((sum, b) => sum + b.checked_in_count, 0);

  const rows = params.bookings
    .map((booking) => {
      const companions = parseCompanionNames(booking.companion_names_json);
      const cancelled = booking.status === 'cancelled';
      const boarded = booking.checked_in_count >= booking.party_size;
      const partial = booking.checked_in_count > 0 && !boarded;
      const stateBadge = cancelled
        ? '<span class="badge badge-cancelled">キャンセル</span>'
        : boarded
          ? '<span class="badge badge-confirmed">乗車済み</span>'
          : partial
            ? '<span class="badge badge-proxy">一部乗車</span>'
            : '<span class="badge badge-closed">未乗車</span>';

      return `<article class="book-row${boarded && !cancelled ? ' is-boarded' : ''}${cancelled ? ' is-cancelled' : ''}">
  <div class="book-row__head">
    <span class="book-row__id">#${booking.id}</span>
    <span class="book-row__name">${esc(booking.representative_name)}</span>
    <span class="book-row__size">${booking.party_size}名</span>
    <span class="badge ${booking.source === 'admin' ? 'badge-proxy' : 'badge-line'}" style="margin-left:auto">${
      booking.source === 'admin' ? '管理者代理' : 'LINE'
    }</span>
  </div>
  <p class="book-row__meta">
    <a href="tel:${esc(booking.phone)}">${esc(booking.phone)}</a><br>
    同行者：${esc(companions.join('、')) || '-'}<br>
    予約 ${esc(formatJstIsoLike(booking.created_at))} ・ 通知 ${notificationCell(
      params.notifications,
      booking.id,
    )}
  </p>
  <div class="book-row__foot">
    ${stateBadge}
    <span class="book-row__count">${booking.checked_in_count} <small>/ ${booking.party_size} 乗車</small></span>
  </div>
  ${
    cancelled
      ? ''
      : `<form class="inline-form" method="post" action="/admin/bookings/${booking.id}/checkin">
    <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
    <input type="hidden" name="trip_slug" value="${esc(trip.slug)}">
    <button class="counter-btn" type="submit" name="op" value="dec" aria-label="乗車人数を1減らす">−</button>
    <button class="counter-btn" type="submit" name="op" value="inc" aria-label="乗車人数を1増やす">＋</button>
    <button class="btn btn-sm btn-secondary btn-all" type="submit" name="op" value="all">全員乗車</button>
  </form>
  <form method="post" action="/admin/bookings/${booking.id}/cancel" style="margin:10px 0 0"
      onsubmit="return confirm('予約 #${booking.id} をキャンセルします。よろしいですか？');">
    <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
    <input type="hidden" name="trip_slug" value="${esc(trip.slug)}">
    <button class="btn btn-sm btn-danger-outline" type="submit">この予約をキャンセル</button>
  </form>`
  }
</article>`;
    })
    .join('\n');

  const content = `
<p><a href="/admin">← ダッシュボードへ</a></p>
<h2>${label}</h2>

<section class="card">
  <p class="muted" style="margin-top:0">${esc(formatJstLong(trip.depart_at))} ／ ${esc(trip.origin)} → ${esc(trip.destination)}</p>
  <p class="stat">${trip.booked_seats} <small>/ ${trip.capacity}名（残り${trip.remaining_seats}席）</small></p>
  <p style="margin:10px 0 0">受付状態：${
    trip.booking_status === 'open'
      ? '<span class="badge badge-open">受付中</span>'
      : '<span class="badge badge-closed">受付停止</span>'
  } <span class="muted">リマインド ${esc(formatJstLong(trip.reminder_at))} 送信予定</span></p>
</section>

<h2>乗車確認</h2>
<section class="card">
  <p class="stat" style="margin-top:0">${boardedTotal} <small>/ ${seatsTotal}名 乗車済み</small></p>
  <div class="progress" aria-hidden="true"><span style="width:${
    seatsTotal > 0 ? Math.min(100, Math.round((boardedTotal / seatsTotal) * 100)) : 0
  }%"></span></div>
  <p class="muted" style="margin-bottom:0">下の予約一覧から、1件ずつ乗車人数を記録してください。</p>
</section>

<h2>予約一覧（${params.bookings.length}件）</h2>
<form class="card search-form" method="get" action="/admin/trips/${esc(trip.slug)}">
  <div class="field">
    <label for="q">検索（氏名・電話番号・予約ID）</label>
    <input type="search" id="q" name="q" value="${esc(params.search)}">
  </div>
  <button class="btn btn-sm btn-secondary" type="submit">検索</button>
  ${when(
    Boolean(params.search),
    `<a class="btn btn-sm btn-secondary" href="/admin/trips/${esc(trip.slug)}">クリア</a>`,
  )}
</form>

<div class="stack">
  ${rows || '<p class="muted">該当する予約はありません。</p>'}
</div>

<h2>管理者代理予約</h2>
<form class="card" method="post" action="/admin/trips/${esc(trip.slug)}/bookings">
  <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
  <div class="alert alert-info" role="note" style="margin-top:0">
    スタッフによる代理予約です。LINEログインは不要ですが、<strong>LINE通知・リマインドは送信されません。</strong>定員管理は通常予約と同じです。
  </div>
  <div class="field">
    <label for="admin_name">代表者氏名<span class="req">必須</span></label>
    <input type="text" id="admin_name" name="representative_name" maxlength="50" required>
  </div>
  <div class="field">
    <label for="admin_phone">電話番号<span class="req">必須</span></label>
    <input type="tel" id="admin_phone" name="phone" inputmode="tel" maxlength="20" required>
  </div>
  <div class="field">
    <label for="admin_party_size">人数<span class="req">必須</span></label>
    <select id="admin_party_size" name="party_size" required>
      ${[1, 2, 3, 4].map((n) => `<option value="${n}">${n}名</option>`).join('')}
    </select>
    <p class="hint">残席は${trip.remaining_seats}席です。超過分はサーバー側で拒否されます。</p>
  </div>
  <div class="field">
    <label for="admin_companions">同行者氏名（読点・改行区切り）</label>
    <input type="text" id="admin_companions" name="companion_names_text" maxlength="200"
      placeholder="山田花子、佐藤次郎">
  </div>
  <button class="btn btn-sm" type="submit">代理予約を登録</button>
</form>

<h2>便の設定</h2>
<section class="card">
  <div class="settings-group">
    <form method="post" action="/admin/trips/${esc(trip.slug)}/capacity" style="margin:0">
      <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
      <div class="field">
        <label for="capacity">定員</label>
        <input type="number" id="capacity" name="capacity" value="${trip.capacity}" min="0" max="200" required>
        <p class="hint">既存の確定予約人数（${trip.booked_seats}名）を下回る値には変更できません。</p>
      </div>
      <button class="btn btn-sm" type="submit">定員を更新</button>
    </form>
  </div>

  <div class="settings-group">
    <form method="post" action="/admin/trips/${esc(trip.slug)}/reminder" style="margin:0">
      <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
      <div class="field">
        <label for="reminder_at">リマインド送信日時（JST）</label>
        <input type="datetime-local" id="reminder_at" name="reminder_at"
          value="${esc(toJstDatetimeLocal(trip.reminder_at))}" required>
        <p class="hint">この時刻を過ぎるとCronがリマインドを送信します。</p>
      </div>
      <button class="btn btn-sm" type="submit">リマインド日時を更新</button>
    </form>
  </div>

  <div class="settings-group">
    <form method="post" action="/admin/trips/${esc(trip.slug)}/status" style="margin:0"
        onsubmit="return confirm('予約の受付状態を変更します。よろしいですか？');">
      <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
      <p style="margin-top:0"><strong>予約受付</strong>　現在：${
        trip.booking_status === 'open'
          ? '<span class="badge badge-open">受付中</span>'
          : '<span class="badge badge-closed">受付停止</span>'
      }</p>
      <div class="btn-row">
        <button class="btn btn-sm" type="submit" name="booking_status" value="open"
          ${trip.booking_status === 'open' ? 'disabled' : ''}>受付開始</button>
        <button class="btn btn-sm btn-danger-outline" type="submit" name="booking_status" value="closed"
          ${trip.booking_status === 'closed' ? 'disabled' : ''}>受付停止</button>
      </div>
    </form>
  </div>

  <div class="settings-group">
    <p style="margin-top:0"><strong>エクスポート</strong></p>
    <a class="btn btn-sm btn-secondary" href="/admin/trips/${esc(trip.slug)}/bookings.csv">CSVをダウンロード</a>
  </div>
</section>
`;

  return layout(
    { title: `${label} | らっこ号 管理画面`, admin: true, alert: params.alert ?? null },
    content,
  );
}
