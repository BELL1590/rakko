/**
 * ログイン中ユーザー本人の予約一覧。予約ページ単位でグルーピングする。
 * キャンセルは詳細ページの確認セクションへ誘導し、この一覧では確定させない。
 */

import { esc, when } from '../lib/html';
import { formatJstLong, formatJstTime } from '../lib/time';
import { layout } from './layout';
import { parseCompanionNames, type BookingWithSlot } from '../db/types';

function bookingCard(booking: BookingWithSlot, nowUtc: string, index: number): string {
  const cancelled = booking.status === 'cancelled';
  const started = booking.start_at <= nowUtc;
  const companions = parseCompanionNames(booking.companion_names_json);
  const inactive = cancelled || started;

  const long = formatJstLong(booking.start_at);
  const time = formatJstTime(booking.start_at);
  const date = long.endsWith(time) ? long.slice(0, long.length - time.length) : long;

  const route =
    booking.origin && booking.destination
      ? `${booking.origin} → ${booking.destination}`
      : (booking.location ?? booking.origin ?? '');

  return `<article class="card trip-card${index % 2 === 1 ? ' is-return' : ''}${
    inactive ? ' is-full' : ''
  }">
  <div class="trip-card__head">
    <span class="trip-card__dir">${esc(booking.slot_name)}</span>
    <span class="trip-card__state">${
      cancelled ? 'キャンセル済み' : started ? '受付終了' : '予約済み'
    }</span>
  </div>
  <div class="trip-card__body">
    <p class="trip-when">
      <span class="trip-date">${esc(date)}</span>
      <span class="trip-time">${esc(time)}</span>
    </p>
    ${when(Boolean(route), `<p class="trip-route">${esc(route)}</p>`)}
    <p class="trip-meta">${booking.party_size}名${
      companions.length > 0 ? ` ・ 同行者：${esc(companions.join('、'))}` : ''
    }<br>予約ID #${booking.id}${
      booking.booking_group_id ? ' ・ まとめて予約' : ''
    } ・ ${
      cancelled
        ? '<span class="badge badge-cancelled">キャンセル済み</span>'
        : '<span class="badge badge-confirmed">予約済み</span>'
    }</p>
    <div class="btn-row" style="margin-top:14px">
      <a class="btn btn-secondary btn-sm" href="/bookings/${booking.id}">予約の詳細</a>
      ${
        !cancelled && !started
          ? `<a class="btn btn-danger-outline btn-sm" href="/bookings/${booking.id}">キャンセル手続き</a>`
          : ''
      }
    </div>
  </div>
</article>`;
}

export function myBookingsPage(params: {
  bookings: BookingWithSlot[];
  csrfToken: string;
  userName: string | null;
  nowUtc: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  // 予約ページごとにまとめる
  const groups = new Map<string, { title: string; slug: string; bookings: BookingWithSlot[] }>();
  for (const booking of params.bookings) {
    const key = booking.page_slug;
    const group = groups.get(key) ?? {
      title: booking.page_title,
      slug: booking.page_slug,
      bookings: [],
    };
    group.bookings.push(booking);
    groups.set(key, group);
  }

  const sections = [...groups.values()]
    .map(
      (group) => `<h2>${esc(group.title)}</h2>
${group.bookings.map((booking, index) => bookingCard(booking, params.nowUtc, index)).join('\n')}
<p class="muted"><a href="/reserve/${esc(group.slug)}">${esc(group.title)}の予約ページを開く</a></p>`,
    )
    .join('\n');

  const content = `
<h2>あなたの予約</h2>
${
  params.bookings.length === 0
    ? `<div class="card center"><p>まだご予約はありません。</p>
       <a class="btn" href="/">予約ページを見る</a></div>`
    : sections
}
${when(
  params.bookings.length > 0,
  `<p class="muted">キャンセルは各予約の詳細ページから、内容を確認したうえでお手続きいただけます。</p>
   <p style="margin-top:16px"><a class="btn btn-secondary" href="/">他の予約ページを見る</a></p>`,
)}
`;

  return layout(
    {
      title: 'マイ予約 | 草加健康センター 予約センター',
      userName: params.userName,
      alert: params.alert ?? null,
    },
    content,
  );
}
