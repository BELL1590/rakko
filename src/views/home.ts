/** 公開トップ。公開中の予約ページを一覧する。 */

import { esc, when } from '../lib/html';
import { formatJstLong } from '../lib/time';
import { layout, priceInfoCard, seatBadge } from './layout';
import type { PageWithStats, SlotWithAvailability } from '../db/types';

export interface HomePageEntry {
  page: PageWithStats;
  slots: SlotWithAvailability[];
}

function pageCard(entry: HomePageEntry): string {
  const { page } = entry;
  const slots = entry.slots.filter((slot) => slot.is_visible);
  const remaining = slots.reduce((sum, slot) => sum + slot.remaining_seats, 0);
  const capacity = slots.reduce((sum, slot) => sum + slot.capacity, 0);
  const allFull = slots.length > 0 && slots.every((slot) => slot.is_full);
  const anyBookable = slots.some((slot) => slot.is_bookable);
  const first = slots[0];

  const slotLines = slots
    .map(
      (slot) => `<li>
      <span class="slot-line__name">${esc(slot.name)}</span>
      <span class="slot-line__when">${esc(formatJstLong(slot.start_at))}</span>
      <span class="slot-line__seats">${
        slot.is_full ? '満席' : `残り${slot.remaining_seats}席`
      }</span>
    </li>`,
    )
    .join('');

  return `<article class="card trip-card">
  <div class="trip-card__head">
    <span class="trip-card__dir">${esc(page.title)}</span>
    <span class="trip-card__state">${
      allFull ? '満席' : anyBookable ? '受付中' : '受付停止中'
    }</span>
  </div>
  <div class="trip-card__body">
    ${when(Boolean(page.description), `<p class="trip-meta">${esc(page.description)}</p>`)}
    ${when(
      Boolean(first),
      `<p class="trip-when"><span class="trip-date">${esc(
        first ? formatJstLong(first.start_at) : '',
      )}</span></p>`,
    )}
    ${when(slots.length > 0, `<ul class="slot-lines">${slotLines}</ul>`)}
    <p class="seats">残り <span class="seats-num">${remaining}</span> 席 / ${capacity}席${seatBadge(
      { is_full: allFull, remaining_seats: remaining },
    )}</p>
    ${
      anyBookable
        ? `<a class="btn" style="margin-top:14px" href="/reserve/${esc(page.slug)}">この予約ページを開く</a>`
        : `<a class="btn btn-secondary" style="margin-top:14px" href="/reserve/${esc(page.slug)}">内容を見る</a>`
    }
  </div>
</article>`;
}

export function homePage(params: {
  entries: HomePageEntry[];
  userName: string | null;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const content = `
<section class="hero" style="margin:-16px -16px 16px">
  <h1>草加健康センター<br>予約センター</h1>
  <span class="hero-sub">オンライン予約</span>
  <p>ご利用になる予約ページを選んでください。</p>
</section>

<h2>受付中の予約</h2>
${
  params.entries.length === 0
    ? '<div class="card"><p class="muted" style="margin:0">現在公開中の予約はありません。</p></div>'
    : params.entries.map(pageCard).join('\n')
}

<div class="notice" style="margin-bottom:16px">
  ご予約にはLINEログインが必要です。予約完了のお知らせと開始前のリマインドをLINEでお送りします。
</div>

<p><a class="btn btn-secondary" href="/my-bookings">マイ予約を確認する</a></p>

<h2>草加健康センター 館内料金（参考）</h2>
${priceInfoCard()}
`;

  return layout(
    {
      title: '草加健康センター 予約センター',
      userName: params.userName,
      alert: params.alert ?? null,
    },
    content,
  );
}
