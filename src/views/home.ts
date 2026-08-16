import { esc } from '../lib/html';
import { formatJstLong } from '../lib/time';
import { layout, noticeCard, priceInfoCard } from './layout';
import type { TripWithAvailability } from '../db/types';

function tripCard(trip: TripWithAvailability): string {
  const label = trip.direction === 'outbound' ? '行き' : '帰り';
  const badgeClass = trip.direction === 'outbound' ? 'trip-badge' : 'trip-badge is-return';

  const seats = trip.is_full
    ? `<p class="seats is-full">満席</p><p class="muted">現在この便は予約できません</p>`
    : `<p class="seats">残り <span class="seats-num">${trip.remaining_seats}</span> 席 / ${trip.capacity}席</p>`;

  let action: string;
  if (trip.is_full) {
    action = `<span class="btn is-disabled">満席</span>`;
  } else if (!trip.is_bookable) {
    action = `<span class="btn is-disabled">受付停止中</span>
      <p class="muted center" style="margin-top:8px">現在この便は予約を受け付けていません</p>`;
  } else {
    action = `<a class="btn" href="/trips/${esc(trip.slug)}/book">予約する</a>`;
  }

  return `<article class="card trip-card${trip.is_full ? ' is-full' : ''}">
  <span class="${badgeClass}">${label}</span>
  <p class="trip-datetime">${esc(formatJstLong(trip.depart_at))}</p>
  <p class="trip-route">${esc(trip.origin)} → ${esc(trip.destination)}</p>
  <p class="trip-meta">集合場所：${esc(trip.origin)}</p>
  ${seats}
  ${action}
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

<h2>料金のご案内</h2>
${priceInfoCard()}

<h2>注意事項</h2>
${noticeCard()}

<p class="center"><a class="btn btn-secondary" href="/my-bookings">マイ予約を確認する</a></p>
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
