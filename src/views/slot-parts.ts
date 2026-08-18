/** 予約枠カードの共通パーツ（公開ページ / 予約詳細 / マイ予約で使い回す）。 */

import { esc } from '../lib/html';
import { formatJstLong, formatJstTime } from '../lib/time';
import { splitPlace, seatBadge } from './layout';

export interface SlotView {
  start_at: string;
  end_at?: string | null;
  origin: string | null;
  destination: string | null;
  location: string | null;
}

/** 「8月21日（金）」と「20:00」に分けて大きく見せる。 */
export function slotWhen(slot: { start_at: string; end_at?: string | null }): string {
  const long = formatJstLong(slot.start_at);
  const time = formatJstTime(slot.start_at);
  const date = long.endsWith(time) ? long.slice(0, long.length - time.length) : long;
  const end = slot.end_at ? `〜${formatJstTime(slot.end_at)}` : '';
  return `<p class="trip-when">
      <span class="trip-date">${esc(date)}</span>
      <span class="trip-time">${esc(time)}${esc(end)}</span>
    </p>`;
}

/**
 * 出発地 ▶ 到着地。会場のみの枠では会場表示にする。
 * バス以外の用途でも破綻しないよう、値の有無で切り替える。
 */
export function slotRoute(slot: SlotView): string {
  if (slot.origin && slot.destination) {
    const from = splitPlace(slot.origin);
    const to = splitPlace(slot.destination);
    return `<div class="route">
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
    </div>`;
  }

  const place = slot.location ?? slot.origin;
  if (!place) return '';
  const parts = splitPlace(place);
  return `<div class="route route--single">
    <span class="route__col from">
      <span class="route__label">${slot.location ? '会場' : '集合'}</span>
      <span class="route__place">${esc(parts.main)}</span>
      ${parts.sub ? `<span class="route__sub">${esc(parts.sub)}</span>` : ''}
    </span>
  </div>`;
}

/** 残席表示（満席・残りわずかは文字でも示す）。 */
export function slotSeats(slot: {
  is_full: boolean;
  remaining_seats: number;
  capacity: number;
}): string {
  if (slot.is_full) {
    return `<p class="seats is-full">満席${seatBadge(slot)}</p>
       <p class="muted" style="margin:6px 0 0">現在この枠は予約できません</p>`;
  }
  return `<p class="seats">残り <span class="seats-num">${slot.remaining_seats}</span> 席 / ${slot.capacity}席${seatBadge(slot)}</p>`;
}

/** 受付状態のラベル。 */
export function slotStateLabel(slot: {
  is_full: boolean;
  is_bookable: boolean;
  booking_status: string;
}): string {
  if (slot.is_full) return '満席';
  if (slot.is_bookable) return '受付中';
  if (slot.booking_status === 'closed') return '受付停止中';
  return '受付前';
}
