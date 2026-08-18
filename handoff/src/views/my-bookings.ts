import { esc, when } from '../lib/html';
import { formatJstLong, formatJstTime } from '../lib/time';
import { layout, splitPlace } from './layout';
import { parseCompanionNames, type BookingWithTrip } from '../db/types';

/**
 * ログイン中ユーザー本人の予約一覧。
 * 「行き / 帰り」「状態」「人数」を一目で判断できるようにする。
 * キャンセルは詳細ページの確認セクションへ誘導し、この一覧では確定させない。
 */
export function myBookingsPage(params: {
  bookings: BookingWithTrip[];
  csrfToken: string;
  userName: string | null;
  nowUtc: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const cards = params.bookings
    .map((booking) => {
      const isReturn = booking.direction === 'return';
      const label = isReturn ? '帰り' : '行き';
      const cancelled = booking.status === 'cancelled';
      const departed = booking.depart_at <= params.nowUtc;
      const companions = parseCompanionNames(booking.companion_names_json);

      const long = formatJstLong(booking.depart_at);
      const time = formatJstTime(booking.depart_at);
      const date = long.endsWith(time) ? long.slice(0, long.length - time.length) : long;
      const from = splitPlace(booking.origin);
      const to = splitPlace(booking.destination);

      const inactive = cancelled || departed;

      return `<article class="card trip-card${isReturn ? ' is-return' : ''}${inactive ? ' is-full' : ''}">
  <div class="trip-card__head">
    <span class="trip-card__dir">${label}</span>
    <span class="trip-card__state">${
      cancelled ? 'キャンセル済み' : departed ? '運行終了' : '予約済み'
    }</span>
  </div>
  <div class="trip-card__body">
    <p class="trip-when">
      <span class="trip-date">${esc(date)}</span>
      <span class="trip-time">${esc(time)}</span>
    </p>
    <p class="trip-route">${esc(from.main)} → ${esc(to.main)}</p>
    <p class="trip-meta">${booking.party_size}名${
      companions.length > 0 ? ` ・ 同行者：${esc(companions.join('、'))}` : ''
    }<br>予約ID #${booking.id} ・ ${
      cancelled
        ? '<span class="badge badge-cancelled">キャンセル済み</span>'
        : '<span class="badge badge-confirmed">予約済み</span>'
    }</p>
    <div class="btn-row" style="margin-top:14px">
      <a class="btn btn-secondary btn-sm" href="/bookings/${booking.id}">予約の詳細</a>
      ${
        !cancelled && !departed
          ? `<a class="btn btn-danger-outline btn-sm" href="/bookings/${booking.id}">キャンセル手続き</a>`
          : ''
      }
    </div>
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
  `<p class="muted">キャンセルは各予約の詳細ページから、内容を確認したうえでお手続きいただけます。</p>
   <p style="margin-top:16px"><a class="btn btn-secondary" href="/">別の便を予約する</a></p>`,
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
