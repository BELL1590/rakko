/**
 * ログイン中ユーザー本人の予約一覧。
 *
 * D3 の変更点:
 * - 予約ページ単位のグルーピングに加え、booking_group_id が同じ予約を
 *   「まとめて予約」として1つの枠で囲む（同時に取った枠だと分かるようにする）
 * - キャンセルは枠ごとであることを明記（グループ全体が消えるわけではない）
 * - 枠カードの赤緑交互配色を廃止
 */

import { esc, when } from '../lib/html';
import { formatJstIsoLike, formatJstLong, formatJstTime } from '../lib/time';
import { layout } from './layout';
import { parseCompanionNames, type BookingWithSlot } from '../db/types';

function bookingCard(booking: BookingWithSlot, nowUtc: string): string {
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

  return `<article class="card trip-card slot-card${inactive ? ' is-full' : ''}">
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
    }<br>予約ID #${booking.id} ・ ${
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

/** booking_group_id が同じ予約を「まとめて予約」として囲む。 */
function groupBlocks(bookings: BookingWithSlot[], nowUtc: string): string {
  const grouped = new Map<string, BookingWithSlot[]>();
  const singles: BookingWithSlot[] = [];

  for (const booking of bookings) {
    const key = booking.booking_group_id;
    if (!key) {
      singles.push(booking);
      continue;
    }
    const list = grouped.get(key) ?? [];
    list.push(booking);
    grouped.set(key, list);
  }

  const blocks: string[] = [];

  for (const [, list] of grouped) {
    // 1件しか残っていないグループ（片方だけキャンセル済み等）は囲まない
    if (list.length < 2) {
      singles.push(...list);
      continue;
    }
    const activeList = list.filter((b) => b.status !== 'cancelled');
    const seats = activeList.reduce((sum, b) => sum + b.party_size, 0);
    const first = list[0];

    blocks.push(`<div class="booking-group">
  <div class="booking-group__head">
    <span class="booking-group__badge">まとめて予約</span>
    <span class="booking-group__meta">${list.length}件${
      seats > 0 ? ` ・ 計${seats}名` : ''
    }</span>
    <span class="booking-group__at">${esc(first ? formatJstIsoLike(first.created_at) : '')}</span>
  </div>
  ${list.map((booking) => bookingCard(booking, nowUtc)).join('\n')}
  <p class="booking-group__note">まとめて予約した枠も、キャンセルは枠ごとに行います。片方だけの取り消しができます。</p>
</div>`);
  }

  // 単独予約は元の並び順を尊重して後ろに出す
  const singleIds = new Set(singles.map((b) => b.id));
  const orderedSingles = bookings.filter((b) => singleIds.has(b.id));
  blocks.push(...orderedSingles.map((booking) => bookingCard(booking, nowUtc)));

  return blocks.join('\n');
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
${groupBlocks(group.bookings, params.nowUtc)}
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
