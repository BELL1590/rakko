/**
 * 管理画面: 予約枠の詳細（予約一覧 / 受付確認 / 代理予約 / 枠の設定 / CSV）。
 *
 * 当日はスマホで操作するため、予約一覧は1件=1カード。
 * 受付人数の −/＋/全員 は 52px のタップ領域。電話番号は tel: リンク。
 */

import { esc, when } from '../../lib/html';
import { formatJstIsoLike, formatJstLong } from '../../lib/time';
import { layout } from '../layout';
import { slotFormFields } from './pages';
import { parseCompanionNames } from '../../db/types';
import type {
  BookingWithSlot,
  NotificationRow,
  ReservationPageRow,
  SlotWithAvailability,
} from '../../db/types';

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

export function adminSlotDetailPage(params: {
  page: ReservationPageRow;
  slot: SlotWithAvailability;
  bookings: BookingWithSlot[];
  notifications: NotificationRow[];
  search: string;
  csrfToken: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const { slot, page } = params;
  const label = page.checkin_label || '受付';

  const active = params.bookings.filter((b) => b.status !== 'cancelled');
  const seatsTotal = active.reduce((sum, b) => sum + b.party_size, 0);
  const checkedTotal = active.reduce((sum, b) => sum + b.checked_in_count, 0);

  const rows = params.bookings
    .map((booking) => {
      const companions = parseCompanionNames(booking.companion_names_json);
      const cancelled = booking.status === 'cancelled';
      const done = booking.checked_in_count >= booking.party_size;
      const partial = booking.checked_in_count > 0 && !done;
      const stateBadge = cancelled
        ? '<span class="badge badge-cancelled">キャンセル</span>'
        : done
          ? `<span class="badge badge-confirmed">${esc(label)}済み</span>`
          : partial
            ? `<span class="badge badge-proxy">一部${esc(label)}</span>`
            : `<span class="badge badge-closed">未${esc(label)}</span>`;

      return `<article class="book-row${done && !cancelled ? ' is-boarded' : ''}${
        cancelled ? ' is-cancelled' : ''
      }">
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
    )}${booking.booking_group_id ? ' ・ まとめて予約' : ''}
  </p>
  <div class="book-row__foot">
    ${stateBadge}
    <span class="book-row__count">${booking.checked_in_count} <small>/ ${booking.party_size} ${esc(label)}</small></span>
  </div>
  ${
    cancelled
      ? ''
      : `<form class="inline-form" method="post" action="/admin/bookings/${booking.id}/checkin">
    <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
    <input type="hidden" name="slot_id" value="${slot.id}">
    <button class="counter-btn" type="submit" name="op" value="dec" aria-label="${esc(label)}人数を1減らす">−</button>
    <button class="counter-btn" type="submit" name="op" value="inc" aria-label="${esc(label)}人数を1増やす">＋</button>
    <button class="btn btn-sm btn-secondary btn-all" type="submit" name="op" value="all">全員${esc(label)}</button>
  </form>
  <form method="post" action="/admin/bookings/${booking.id}/cancel" style="margin:10px 0 0"
      onsubmit="return confirm('予約 #${booking.id} をキャンセルします。よろしいですか？');">
    <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
    <input type="hidden" name="slot_id" value="${slot.id}">
    <button class="btn btn-sm btn-danger-outline" type="submit">この予約をキャンセル</button>
  </form>`
  }
</article>`;
    })
    .join('\n');

  const content = `
<p><a href="/admin/reservations/${page.id}">← ${esc(page.title)}へ</a></p>
<h2>${esc(slot.name)}</h2>

<section class="card">
  <p class="muted" style="margin-top:0">${esc(page.title)} ／ ${esc(formatJstLong(slot.start_at))}
    ${slot.origin && slot.destination ? `／ ${esc(slot.origin)} → ${esc(slot.destination)}` : ''}
    ${!slot.origin && slot.location ? `／ ${esc(slot.location)}` : ''}</p>
  <p class="stat">${slot.booked_seats} <small>/ ${slot.capacity}名（残り${slot.remaining_seats}席）</small></p>
  <p style="margin:10px 0 0">受付状態：${
    slot.booking_status === 'open'
      ? '<span class="badge badge-open">受付中</span>'
      : slot.booking_status === 'hidden'
        ? '<span class="badge badge-proxy">非表示</span>'
        : '<span class="badge badge-closed">受付停止</span>'
  } <span class="muted">${
    slot.reminder_at
      ? `リマインド ${esc(formatJstLong(slot.reminder_at))} 送信予定`
      : 'リマインド設定なし'
  }</span></p>
</section>

<h2>${esc(label)}確認</h2>
<section class="card">
  <p class="stat" style="margin-top:0">${checkedTotal} <small>/ ${seatsTotal}名 ${esc(label)}済み</small></p>
  <div class="progress" aria-hidden="true"><span style="width:${
    seatsTotal > 0 ? Math.min(100, Math.round((checkedTotal / seatsTotal) * 100)) : 0
  }%"></span></div>
  <p class="muted" style="margin-bottom:0">下の予約一覧から、1件ずつ${esc(label)}人数を記録してください。</p>
</section>

<h2>予約一覧（${params.bookings.length}件）</h2>
<form class="card search-form" method="get" action="/admin/slots/${slot.id}">
  <div class="field">
    <label for="q">検索（氏名・電話番号・予約ID）</label>
    <input type="search" id="q" name="q" value="${esc(params.search)}">
  </div>
  <button class="btn btn-sm btn-secondary" type="submit">検索</button>
  ${when(
    Boolean(params.search),
    `<a class="btn btn-sm btn-secondary" href="/admin/slots/${slot.id}">クリア</a>`,
  )}
</form>

<div class="stack">
  ${rows || '<p class="muted">該当する予約はありません。</p>'}
</div>

<h2>名簿の出力</h2>
<section class="card">
  <div class="btn-row">
    <a class="btn btn-sm btn-secondary" href="/admin/reservation-slots/${slot.id}/roster.csv">この枠の名簿CSV</a>
    <a class="btn btn-sm btn-secondary" href="/admin/reservations/${page.id}/roster.csv">イベント全体のCSV</a>
  </div>
  <p class="hint" style="margin-bottom:0">確定済みの予約のみを出力します（UTF-8 BOM付き）。
    キャンセルを含める場合は末尾に <code>?include=cancelled</code> を付けてください。</p>
</section>

<h2>管理者代理予約</h2>
<form class="card" method="post" action="/admin/slots/${slot.id}/bookings">
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
      ${Array.from({ length: slot.max_party_size }, (_, i) => i + 1)
        .map((n) => `<option value="${n}">${n}名</option>`)
        .join('')}
    </select>
    <p class="hint">残席は${slot.remaining_seats}席です。超過分はサーバー側で拒否されます。</p>
  </div>
  <div class="field">
    <label for="admin_companions">同行者氏名（読点・改行区切り）</label>
    <input type="text" id="admin_companions" name="companion_names_text" maxlength="200"
      placeholder="山田花子、佐藤次郎">
  </div>
  <button class="btn btn-sm" type="submit">代理予約を登録</button>
</form>

<h2>予約枠の設定</h2>
${slotFormFields({
  action: `/admin/slots/${slot.id}`,
  csrfToken: params.csrfToken,
  submitLabel: '予約枠を保存',
  pageType: page.page_type,
  slot,
})}
`;

  return layout(
    { title: `${slot.name} | ${page.title} | 管理画面`, admin: true, alert: params.alert ?? null },
    content,
  );
}
