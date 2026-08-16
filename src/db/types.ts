/** D1 のテーブル行に対応する型。 */

export type Direction = 'outbound' | 'return';
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

export interface BookingRow {
  id: number;
  trip_id: number;
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

/** 予約 + 便情報（画面表示用）。 */
export interface BookingWithTrip extends BookingRow {
  trip_slug: string;
  direction: Direction;
  origin: string;
  destination: string;
  depart_at: string;
  reminder_at: string;
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
  trip_id: number;
  party_size: number;
  representative_name: string;
  line_user_id: string | null;
  is_line_friend: number | null;
  direction: Direction;
  origin: string;
  destination: string;
  depart_at: string;
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
