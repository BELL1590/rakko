import { esc, when } from '../lib/html';
import { formatJstShort } from '../lib/time';
import { layout } from './layout';
import type { BookingWithTrip } from '../db/types';

/** ログイン中ユーザー本人の予約一覧。 */
export function myBookingsPage(params: {
  bookings: BookingWithTrip[];
  csrfToken: string;
  userName: string | null;
  nowUtc: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const cards = params.bookings
    .map((booking) => {
      const label = booking.direction === 'outbound' ? '行き' : '帰り';
      const cancelled = booking.status === 'cancelled';
      const departed = booking.depart_at <= params.nowUtc;

      return `<article class="card">
  <span class="trip-badge${booking.direction === 'return' ? ' is-return' : ''}">${label}</span>
  <p class="trip-datetime">${esc(formatJstShort(booking.depart_at))}</p>
  <p class="trip-route">${esc(booking.origin)} → ${esc(booking.destination)}</p>
  <p class="trip-meta">${booking.party_size}名 ・ ${
    cancelled
      ? '<span class="badge badge-cancelled">キャンセル済み</span>'
      : '<span class="badge badge-confirmed">予約済み</span>'
  }</p>
  <div class="btn-row" style="margin-top:12px">
    <a class="btn btn-secondary btn-sm" href="/bookings/${booking.id}">詳細</a>
    ${
      !cancelled && !departed
        ? `<form method="post" action="/bookings/${booking.id}/cancel" style="margin:0"
            onsubmit="return confirm('この予約をキャンセルします。よろしいですか？');">
          <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
          <button class="btn btn-danger btn-sm" type="submit">キャンセル</button>
        </form>`
        : ''
    }
  </div>
</article>`;
    })
    .join('\n');

  const content = `
<h2>あなたの予約</h2>
${
  params.bookings.length === 0
    ? `<div class="card center"><p>まだご予約はありません。</p>
       <a class="btn" href="/">便を見る</a></div>`
    : cards
}
${when(
  params.bookings.length > 0,
  `<p class="center" style="margin-top:16px"><a class="btn btn-secondary" href="/">別の便を予約する</a></p>`,
)}
`;

  return layout(
    {
      title: 'マイ予約 | らっこ号 池袋便',
      userName: params.userName,
      alert: params.alert ?? null,
    },
    content,
  );
}
