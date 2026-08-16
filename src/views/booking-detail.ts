import { esc, when } from '../lib/html';
import { formatJstLong } from '../lib/time';
import { layout } from './layout';
import { parseCompanionNames, type BookingWithTrip } from '../db/types';

/** 予約完了 / 予約詳細ページ。 */
export function bookingDetailPage(params: {
  booking: BookingWithTrip;
  csrfToken: string;
  userName: string | null;
  justCompleted: boolean;
  notificationNote?: string | null;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const { booking } = params;
  const label = booking.direction === 'outbound' ? '行き' : '帰り';
  const companions = parseCompanionNames(booking.companion_names_json);
  const cancelled = booking.status === 'cancelled';
  const departed = booking.depart_at <= new Date().toISOString();

  const heading = params.justCompleted ? 'ご予約が完了しました' : 'ご予約の詳細';

  const content = `
${when(
  params.justCompleted,
  `<div class="alert alert-success">ご予約ありがとうございます。当日お気をつけてお越しください。</div>`,
)}

<h2>${heading}</h2>

<section class="card">
  <span class="trip-badge${booking.direction === 'return' ? ' is-return' : ''}">${label}</span>
  <p class="trip-datetime">${esc(formatJstLong(booking.depart_at))}</p>
  <p class="trip-route">${esc(booking.origin)} → ${esc(booking.destination)}</p>

  <ul class="summary-list" style="margin-top:12px">
    <li><span class="k">予約ID</span><span class="v">#${booking.id}</span></li>
    <li><span class="k">乗車場所</span><span class="v">${esc(booking.origin)}</span></li>
    <li><span class="k">代表者</span><span class="v">${esc(booking.representative_name)}</span></li>
    <li><span class="k">ご予約人数</span><span class="v">${booking.party_size}名</span></li>
    ${when(
      companions.length > 0,
      `<li><span class="k">同行者</span><span class="v">${esc(companions.join('、'))}</span></li>`,
    )}
    <li><span class="k">ステータス</span><span class="v">${
      cancelled
        ? '<span class="badge badge-cancelled">キャンセル済み</span>'
        : '<span class="badge badge-confirmed">予約済み</span>'
    }</span></li>
  </ul>
</section>

${when(
  Boolean(params.notificationNote),
  `<div class="notice" style="margin-bottom:16px">${esc(params.notificationNote)}</div>`,
)}

<div class="btn-row">
  <a class="btn btn-secondary" href="/my-bookings">マイ予約へ</a>
  ${
    !cancelled && !departed
      ? `<form method="post" action="/bookings/${booking.id}/cancel" style="flex:1 1 auto;margin:0"
          onsubmit="return confirm('この予約をキャンセルします。よろしいですか？');">
        <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
        <button class="btn btn-danger" type="submit">キャンセルする</button>
      </form>`
      : ''
  }
</div>

<p class="center" style="margin-top:16px"><a href="/">トップへ戻る</a></p>
`;

  return layout(
    {
      title: `${heading} | らっこ号 池袋便`,
      userName: params.userName,
      alert: params.alert ?? null,
    },
    content,
  );
}
