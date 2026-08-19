/**
 * 予約枠カードの共通パーツ（公開ページ / 予約詳細 / マイ予約で使い回す）。
 *
 * D1 の修正:
 * - 予約できない枠（満席 / 受付停止 / 受付開始前 / 受付終了）を状態ごとに正確に区別する
 * - 席数と状態の二重表示（「満席 満席」）を防ぐ
 * - 残席バッジの判定は layout.ts の isFewSeats() に集約（公開・管理で同じ述語）
 *
 * 状態の優先順位（上から順に判定）:
 *   1. booking_status === 'closed'      → 受付停止中（運営が手で止めた状態が最優先）
 *   2. booking_close_at を経過           → 受付終了
 *   3. booking_open_at が未来            → 受付開始前
 *   4. 満席                              → 満席
 *   5. それ以外                          → 受付中
 * is_bookable=false をすべて「受付終了」に丸めない。
 */

import { esc } from '../lib/html';
import { formatJstLong, formatJstTime } from '../lib/time';
import { splitPlace, seatBadge, isFewSeats } from './layout';

export interface SlotView {
  start_at: string;
  end_at?: string | null;
  origin: string | null;
  destination: string | null;
  location: string | null;
}

export interface SlotStateInput {
  is_full: boolean;
  booking_status: string;
  booking_open_at?: string | null;
  booking_close_at?: string | null;
}

export type SlotState = 'open' | 'before_open' | 'closed_time' | 'suspended' | 'full';

/** 枠の状態を1つに決める。表示はすべてこの結果から導く。 */
export function slotState(slot: SlotStateInput, nowUtc?: string): SlotState {
  const now = nowUtc ?? new Date().toISOString();
  if (slot.booking_status === 'closed') return 'suspended';
  if (slot.booking_close_at && slot.booking_close_at <= now) return 'closed_time';
  if (slot.booking_open_at && slot.booking_open_at > now) return 'before_open';
  if (slot.is_full) return 'full';
  return 'open';
}

const STATE_LABEL: Record<SlotState, string> = {
  open: '受付中',
  before_open: '受付開始前',
  closed_time: '受付終了',
  suspended: '受付停止中',
  full: '満席',
};

/** カード右上の状態ラベル。 */
export function slotStateLabel(slot: SlotStateInput, nowUtc?: string): string {
  return STATE_LABEL[slotState(slot, nowUtc)];
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

/**
 * 残席表示。状態を表す語は1カードに1回だけにする。
 * 予約できない枠では席数を出さない（受付停止の枠が「残り6席」と誘導してしまうため）。
 */
export function slotSeats(
  slot: SlotStateInput & { remaining_seats: number; capacity: number },
  nowUtc?: string,
): string {
  const state = slotState(slot, nowUtc);

  if (state === 'full') {
    return `<p class="seats is-full">満席</p>`;
  }
  if (state === 'suspended') {
    return `<p class="seats is-closed">受付停止中</p>`;
  }
  if (state === 'closed_time') {
    return `<p class="seats is-closed">受付終了</p>`;
  }
  if (state === 'before_open') {
    // 開始前は残席よりも「いつから予約できるか」が知りたい情報
    return `<p class="seats is-waiting">受付開始前</p>`;
  }

  return `<p class="seats">残り <span class="seats-num">${slot.remaining_seats}</span> 席 / ${slot.capacity}席${seatBadge(slot)}</p>`;
}

/**
 * 受付期間の案内文。booking_open_at / booking_close_at を公開UIに出す。
 * - 受付開始前 → 「8月20日(木) 12:00から受付開始」
 * - 受付中     → 締切が設定されていれば「8月25日(月) 13:00まで受付」
 * - 受付終了   → 「8月25日(月) 13:00に受付を終了しました」
 */
export function slotTiming(
  slot: SlotStateInput & { booking_open_at?: string | null; booking_close_at?: string | null },
  nowUtc?: string,
): string {
  const state = slotState(slot, nowUtc);

  if (state === 'before_open' && slot.booking_open_at) {
    return `<p class="slot-timing is-waiting">
      <strong>${esc(formatJstLong(slot.booking_open_at))}</strong>から受付開始
    </p>`;
  }

  if (state === 'closed_time' && slot.booking_close_at) {
    return `<p class="slot-timing">
      ${esc(formatJstLong(slot.booking_close_at))}に受付を終了しました
    </p>`;
  }

  if (state === 'open' && slot.booking_close_at) {
    return `<p class="slot-timing">
      ${esc(formatJstLong(slot.booking_close_at))}まで受付
    </p>`;
  }

  if (state === 'suspended') {
    return `<p class="slot-timing">現在、この枠の受付を停止しています。</p>`;
  }

  if (state === 'full') {
    return `<p class="slot-timing">キャンセルが出た場合、再度予約できることがあります。</p>`;
  }

  return '';
}

/** 残席が少ないか（管理画面のアラートと同じ判定）。再エクスポート。 */
export { isFewSeats };
