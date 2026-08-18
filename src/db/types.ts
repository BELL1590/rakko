/** D1 のテーブル行に対応する型。 */

export type Direction = 'outbound' | 'return';
export type PageStatus = 'draft' | 'published' | 'closed' | 'archived';
export type PageType = 'bus' | 'event' | 'time_slot' | 'other';
export type SlotBookingStatus = 'open' | 'closed' | 'hidden';
export type BookingStatus = 'confirmed' | 'cancelled';
export type BookingSource = 'line' | 'admin';
export type TripBookingStatus = 'open' | 'closed';
export type NotificationType = 'booking_confirmation' | 'reminder';
export type NotificationStatus = 'pending' | 'requested' | 'failed' | 'skipped';

export interface UserRow {
  id: number;
  line_user_id: string;
  line_display_name: string;
  line_picture_url: string | null;
  is_line_friend: number | null;
  created_at: string;
  updated_at: string;
}

export interface TripRow {
  id: number;
  slug: string;
  direction: Direction;
  origin: string;
  destination: string;
  /** UTC ISO8601 */
  depart_at: string;
  /** UTC ISO8601 */
  reminder_at: string;
  capacity: number;
  booking_status: TripBookingStatus;
  booking_open_at: string | null;
  booking_close_at: string | null;
  created_at: string;
  updated_at: string;
}

/** 便 + 集計済みの予約状況。 */
export interface TripWithAvailability extends TripRow {
  booked_seats: number;
  remaining_seats: number;
  is_full: boolean;
  /** 受付中かつ満席でなく出発前 */
  is_bookable: boolean;
}

/** 予約ページ（イベント全体）。 */
export interface ReservationPageRow {
  id: number;
  slug: string;
  title: string;
  description: string;
  status: PageStatus;
  page_type: PageType;
  /** 0/1 */
  allow_multi_slot_booking: number;
  /** 0/1 */
  requires_line_login: number;
  max_slots_per_checkout: number;
  /** 受付確認のUI文言（乗車 / 受付 / 来場 など） */
  checkin_label: string;
  created_at: string;
  updated_at: string;
}

/** 予約枠（利用者が実際に予約する1枠）。 */
export interface ReservationSlotRow {
  id: number;
  reservation_page_id: number;
  name: string;
  description: string;
  /** UTC ISO8601 */
  start_at: string;
  end_at: string | null;
  origin: string | null;
  destination: string | null;
  location: string | null;
  capacity: number;
  max_party_size: number;
  booking_open_at: string | null;
  booking_close_at: string | null;
  reminder_at: string | null;
  booking_status: SlotBookingStatus;
  sort_order: number;
  legacy_trip_id: number | null;
  created_at: string;
  updated_at: string;
}

/** 予約枠 + 集計済みの予約状況。 */
export interface SlotWithAvailability extends ReservationSlotRow {
  booked_seats: number;
  remaining_seats: number;
  is_full: boolean;
  /** 受付中・満席でない・開始前・受付期間内 */
  is_bookable: boolean;
  /** 一覧に表示してよいか（hidden 以外） */
  is_visible: boolean;
}

/** 予約枠 + 所属ページの情報。 */
export interface SlotWithPage extends SlotWithAvailability {
  page_slug: string;
  page_title: string;
  page_status: PageStatus;
  page_type: PageType;
  checkin_label: string;
  max_slots_per_checkout: number;
  allow_multi_slot_booking: number;
  requires_line_login: number;
}

/** ページ + 集計。 */
export interface PageWithStats extends ReservationPageRow {
  slot_count: number;
  booked_seats: number;
  capacity_total: number;
}

export interface BookingRow {
  id: number;
  reservation_slot_id: number;
  /** 旧モデルの参照（段階移行のため保持） */
  trip_id: number | null;
  /** 同一ページの複数枠を一括予約したときのグループID */
  booking_group_id: string | null;
  user_id: number | null;
  source: BookingSource;
  representative_name: string;
  phone: string;
  party_size: number;
  companion_names_json: string;
  status: BookingStatus;
  checked_in_count: number;
  cancelled_at: string | null;
  created_at: string;
  updated_at: string;
}

/** 予約 + 予約枠・ページ情報（画面表示用）。 */
export interface BookingWithSlot extends BookingRow {
  slot_name: string;
  slot_description: string;
  start_at: string;
  end_at: string | null;
  origin: string | null;
  destination: string | null;
  location: string | null;
  max_party_size: number;
  reminder_at: string | null;
  booking_close_at: string | null;
  reservation_page_id: number;
  page_slug: string;
  page_title: string;
  page_type: PageType;
  checkin_label: string;
}

export interface NotificationRow {
  id: number;
  booking_id: number;
  notification_type: NotificationType;
  status: NotificationStatus;
  attempt_count: number;
  last_error: string | null;
  requested_at: string | null;
  created_at: string;
  updated_at: string;
}

/** リマインド送信対象の1件分。 */
export interface ReminderTarget {
  booking_id: number;
  reservation_slot_id: number;
  party_size: number;
  representative_name: string;
  line_user_id: string | null;
  is_line_friend: number | null;
  slot_name: string;
  origin: string | null;
  destination: string | null;
  location: string | null;
  start_at: string;
  page_title: string;
  page_type: PageType;
}

export function parseCompanionNames(json: string): string[] {
  try {
    const parsed = JSON.parse(json);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((v): v is string => typeof v === 'string');
  } catch {
    return [];
  }
}
