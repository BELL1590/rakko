import { esc } from '../lib/html';
import { formatJstLong, formatJstTime } from '../lib/time';
import { layout, noticeCard, priceInfoCard, seatBadge, splitPlace } from './layout';
import type { TripWithAvailability } from '../db/types';

/**
 * 便カード。
 * 「行き / 帰り」「出発地 → 到着地」「日時」「残席」を色に依存せず読み取れる順に置く。
 */
function tripCard(trip: TripWithAvailability): string {
  const isReturn = trip.direction === 'return';
  const dirLabel = isReturn ? '帰り' : '行き';
  const tag = isReturn ? '草加 発' : '池袋 発';
  const cardClass = `card trip-card${isReturn ? ' is-return' : ''}${trip.is_full ? ' is-full' : ''}`;

  const long = formatJstLong(trip.depart_at);
  const time = formatJstTime(trip.depart_at);
  const date = long.endsWith(time) ? long.slice(0, long.length - time.length) : long;

  const from = splitPlace(trip.origin);
  const to = splitPlace(trip.destination);

  const state = trip.is_full
    ? '満席'
    : trip.is_bookable
      ? '受付中'
      : '受付停止中';

  const seats = trip.is_full
    ? `<p class="seats is-full">満席${seatBadge(trip)}</p>
       <p class="muted" style="margin:6px 0 0">現在この便は予約できません</p>`
    : `<p class="seats">残り <span class="seats-num">${trip.remaining_seats}</span> 席 / ${trip.capacity}席${seatBadge(trip)}</p>`;

  let action: string;
  if (trip.is_full) {
    action = `<span class="btn is-disabled" style="margin-top:14px">満席</span>`;
  } else if (!trip.is_bookable) {
    action = `<span class="btn is-disabled" style="margin-top:14px">受付停止中</span>
      <p class="muted center" style="margin:8px 0 0">現在この便は予約を受け付けていません</p>`;
  } else {
    action = `<a class="btn${isReturn ? ' btn-return' : ''}" style="margin-top:14px" href="/trips/${esc(trip.slug)}/book">${dirLabel}を予約する</a>`;
  }

  return `<article class="${cardClass}" aria-label="${dirLabel}便">
  <div class="trip-card__head">
    <span class="trip-card__dir">${dirLabel}<span class="trip-card__tag">${tag}</span></span>
    <span class="trip-card__state">${state}</span>
  </div>
  <div class="trip-card__body">
    <p class="trip-when">
      <span class="trip-date">${esc(date)}</span>
      <span class="trip-time">${esc(time)}</span>
    </p>
    <div class="route">
      <span class="route__col from">
        <span class="route__label">出発・集合</span>
        <span class="route__place">${esc(from.main)}</span>
        ${from.sub ? `<span class="route__sub">${esc(from.sub)}</span>` : ''}
      </span>
      <span class="route__arrow" aria-hidden="true">▶</span>
      <span class="route__col to">
        <span class="route__label">到着</span>
        <span class="route__place">${esc(to.main)}</span>
        ${to.sub ? `<span class="route__sub">${esc(to.sub)}</span>` : ''}
      </span>
    </div>
    ${seats}
    ${action}
  </div>
</article>`;
}

export function homePage(params: {
  trips: TripWithAvailability[];
  userName: string | null;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const content = `
<section class="hero" style="margin:-16px -16px 16px">
  <h1>らっこ号で<br>草加健康センターへ行こう!!</h1>
  <span class="hero-sub">池袋便</span>
  <p>池袋西口から草加健康センターまで直行。<br>行き・帰りそれぞれご予約いただけます。</p>
</section>

<h2>便を選ぶ</h2>
${
  params.trips.length === 0
    ? '<p class="muted">現在ご案内できる便はありません。</p>'
    : params.trips.map(tripCard).join('\n')
}

<div class="notice" style="margin-bottom:16px">
  ご予約にはLINEログインが必要です。予約完了のお知らせと乗車前のリマインドをLINEでお送りします。
</div>

<p><a class="btn btn-secondary" href="/my-bookings">マイ予約を確認する</a></p>

<h2>料金のご案内</h2>
${priceInfoCard()}

<h2>注意事項</h2>
${noticeCard()}
`;

  return layout(
    {
      title: 'らっこ号 池袋便 予約 | 草加健康センター',
      userName: params.userName,
      alert: params.alert ?? null,
    },
    content,
  );
}
