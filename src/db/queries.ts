/**
 * D1 アクセス層。
 * SQLはすべて prepared statement + bind で組み立てる（文字列連結禁止）。
 */

import { nowUtc } from '../lib/time';
import type {
  BookingRow,
  BookingWithTrip,
  NotificationRow,
  NotificationStatus,
  NotificationType,
  ReminderTarget,
  TripRow,
  TripWithAvailability,
  UserRow,
} from './types';

const BOOKING_COLUMNS = `
  id, trip_id, user_id, source, representative_name, phone, party_size,
  companion_names_json, status, checked_in_count, cancelled_at, created_at, updated_at
`;

/** 便に紐づく確定予約の合計人数を含む SELECT 断片。 */
const TRIP_AVAILABILITY_SELECT = `
  SELECT
    t.id, t.slug, t.direction, t.origin, t.destination, t.depart_at, t.reminder_at,
    t.capacity, t.booking_status, t.booking_open_at, t.booking_close_at,
    t.created_at, t.updated_at,
    COALESCE((
      SELECT SUM(b.party_size) FROM bookings b
      WHERE b.trip_id = t.id AND b.status = 'confirmed'
    ), 0) AS booked_seats
  FROM trips t
`;

type TripAvailabilityRow = TripRow & { booked_seats: number };

function decorateTrip(row: TripAvailabilityRow, now: string): TripWithAvailability {
  const booked = Number(row.booked_seats ?? 0);
  const remaining = Math.max(0, row.capacity - booked);
  const departed = row.depart_at <= now;
  const withinWindow =
    (row.booking_open_at === null || row.booking_open_at <= now) &&
    (row.booking_close_at === null || row.booking_close_at > now);
  return {
    ...row,
    booked_seats: booked,
    remaining_seats: remaining,
    is_full: remaining <= 0,
    is_bookable:
      row.booking_status === 'open' && remaining > 0 && !departed && withinWindow,
  };
}

// ---------------------------------------------------------------------------
// users
// ---------------------------------------------------------------------------

export async function upsertUserByLineId(
  db: D1Database,
  params: {
    lineUserId: string;
    displayName: string;
    pictureUrl: string | null;
    isLineFriend: boolean | null;
  },
  now: string = nowUtc(),
): Promise<UserRow> {
  const friendValue = params.isLineFriend === null ? null : params.isLineFriend ? 1 : 0;
  await db
    .prepare(
      `INSERT INTO users (line_user_id, line_display_name, line_picture_url, is_line_friend, created_at, updated_at)
       VALUES (?1, ?2, ?3, ?4, ?5, ?5)
       ON CONFLICT (line_user_id) DO UPDATE SET
         line_display_name = excluded.line_display_name,
         line_picture_url  = excluded.line_picture_url,
         is_line_friend    = COALESCE(excluded.is_line_friend, users.is_line_friend),
         updated_at        = excluded.updated_at`,
    )
    .bind(params.lineUserId, params.displayName, params.pictureUrl, friendValue, now)
    .run();

  const user = await getUserByLineId(db, params.lineUserId);
  if (!user) throw new Error('failed to upsert user');
  return user;
}

export async function getUserByLineId(
  db: D1Database,
  lineUserId: string,
): Promise<UserRow | null> {
  return await db
    .prepare(`SELECT * FROM users WHERE line_user_id = ?1`)
    .bind(lineUserId)
    .first<UserRow>();
}

export async function getUserById(db: D1Database, id: number): Promise<UserRow | null> {
  return await db.prepare(`SELECT * FROM users WHERE id = ?1`).bind(id).first<UserRow>();
}

export async function updateFriendStatus(
  db: D1Database,
  userId: number,
  isFriend: boolean | null,
  now: string = nowUtc(),
): Promise<void> {
  await db
    .prepare(`UPDATE users SET is_line_friend = ?2, updated_at = ?3 WHERE id = ?1`)
    .bind(userId, isFriend === null ? null : isFriend ? 1 : 0, now)
    .run();
}

// ---------------------------------------------------------------------------
// trips
// ---------------------------------------------------------------------------

export async function listTrips(
  db: D1Database,
  now: string = nowUtc(),
): Promise<TripWithAvailability[]> {
  const result = await db
    .prepare(`${TRIP_AVAILABILITY_SELECT} ORDER BY t.depart_at ASC`)
    .all<TripAvailabilityRow>();
  return (result.results ?? []).map((row) => decorateTrip(row, now));
}

export async function getTripBySlug(
  db: D1Database,
  slug: string,
  now: string = nowUtc(),
): Promise<TripWithAvailability | null> {
  const row = await db
    .prepare(`${TRIP_AVAILABILITY_SELECT} WHERE t.slug = ?1`)
    .bind(slug)
    .first<TripAvailabilityRow>();
  return row ? decorateTrip(row, now) : null;
}

export async function getTripById(
  db: D1Database,
  id: number,
  now: string = nowUtc(),
): Promise<TripWithAvailability | null> {
  const row = await db
    .prepare(`${TRIP_AVAILABILITY_SELECT} WHERE t.id = ?1`)
    .bind(id)
    .first<TripAvailabilityRow>();
  return row ? decorateTrip(row, now) : null;
}

export async function updateTripCapacity(
  db: D1Database,
  tripId: number,
  capacity: number,
  now: string = nowUtc(),
): Promise<void> {
  await db
    .prepare(`UPDATE trips SET capacity = ?2, updated_at = ?3 WHERE id = ?1`)
    .bind(tripId, capacity, now)
    .run();
}

export async function updateTripBookingStatus(
  db: D1Database,
  tripId: number,
  status: 'open' | 'closed',
  now: string = nowUtc(),
): Promise<void> {
  await db
    .prepare(`UPDATE trips SET booking_status = ?2, updated_at = ?3 WHERE id = ?1`)
    .bind(tripId, status, now)
    .run();
}

export async function updateTripReminderAt(
  db: D1Database,
  tripId: number,
  reminderAtUtc: string,
  now: string = nowUtc(),
): Promise<void> {
  await db
    .prepare(`UPDATE trips SET reminder_at = ?2, updated_at = ?3 WHERE id = ?1`)
    .bind(tripId, reminderAtUtc, now)
    .run();
}

/** 便の確定予約合計人数。 */
export async function getBookedSeats(db: D1Database, tripId: number): Promise<number> {
  const row = await db
    .prepare(
      `SELECT COALESCE(SUM(party_size), 0) AS booked
       FROM bookings WHERE trip_id = ?1 AND status = 'confirmed'`,
    )
    .bind(tripId)
    .first<{ booked: number }>();
  return Number(row?.booked ?? 0);
}

// ---------------------------------------------------------------------------
// bookings
// ---------------------------------------------------------------------------

/**
 * 定員チェックを含めた条件付きINSERT。
 *
 * 「SELECTで残席確認 → INSERT」の2段構えにすると同時リクエストで定員を超えるため、
 * 残席条件を INSERT ... SELECT の WHERE に埋め込み、1文で判定する。
 * さらにDBトリガー（trg_bookings_capacity_insert）が最終防衛線として働く。
 *
 * @returns 追加された行数（0 なら受付不可 or 満席）
 */
export async function insertBookingIfCapacityAllows(
  db: D1Database,
  params: {
    tripId: number;
    userId: number | null;
    source: 'line' | 'admin';
    representativeName: string;
    phone: string;
    partySize: number;
    companionNamesJson: string;
    /** 管理者代理予約は受付停止中でも登録できる */
    ignoreBookingWindow: boolean;
  },
  now: string = nowUtc(),
): Promise<number> {
  const statement = db
    .prepare(
      `INSERT INTO bookings (
         trip_id, user_id, source, representative_name, phone, party_size,
         companion_names_json, status, checked_in_count, created_at, updated_at
       )
       SELECT t.id, ?2, ?3, ?4, ?5, ?6, ?7, 'confirmed', 0, ?8, ?8
       FROM trips t
       WHERE t.id = ?1
         AND t.depart_at > ?8
         AND (
           ?9 = 1
           OR (
             t.booking_status = 'open'
             AND (t.booking_open_at IS NULL OR t.booking_open_at <= ?8)
             AND (t.booking_close_at IS NULL OR t.booking_close_at > ?8)
           )
         )
         AND (
           COALESCE((
             SELECT SUM(b.party_size) FROM bookings b
             WHERE b.trip_id = t.id AND b.status = 'confirmed'
           ), 0) + ?6
         ) <= t.capacity`,
    )
    .bind(
      params.tripId,
      params.userId,
      params.source,
      params.representativeName,
      params.phone,
      params.partySize,
      params.companionNamesJson,
      now,
      params.ignoreBookingWindow ? 1 : 0,
    );

  const result = await statement.run();
  return result.meta?.changes ?? 0;
}

/** 直近で作成された当該ユーザー・便の確定予約。INSERT直後のID取得に使う。 */
export async function getLatestConfirmedBooking(
  db: D1Database,
  tripId: number,
  userId: number | null,
): Promise<BookingRow | null> {
  if (userId === null) {
    return await db
      .prepare(
        `SELECT ${BOOKING_COLUMNS} FROM bookings
         WHERE trip_id = ?1 AND user_id IS NULL AND status = 'confirmed'
         ORDER BY id DESC LIMIT 1`,
      )
      .bind(tripId)
      .first<BookingRow>();
  }
  return await db
    .prepare(
      `SELECT ${BOOKING_COLUMNS} FROM bookings
       WHERE trip_id = ?1 AND user_id = ?2 AND status = 'confirmed'
       ORDER BY id DESC LIMIT 1`,
    )
    .bind(tripId, userId)
    .first<BookingRow>();
}

export async function getBookingById(
  db: D1Database,
  bookingId: number,
): Promise<BookingWithTrip | null> {
  return await db
    .prepare(
      `SELECT ${BOOKING_COLUMNS.split(',').map((c) => `b.${c.trim()}`).join(', ')},
              t.slug AS trip_slug, t.direction, t.origin, t.destination,
              t.depart_at, t.reminder_at
       FROM bookings b JOIN trips t ON t.id = b.trip_id
       WHERE b.id = ?1`,
    )
    .bind(bookingId)
    .first<BookingWithTrip>();
}

export async function listBookingsByUser(
  db: D1Database,
  userId: number,
): Promise<BookingWithTrip[]> {
  const result = await db
    .prepare(
      `SELECT ${BOOKING_COLUMNS.split(',').map((c) => `b.${c.trim()}`).join(', ')},
              t.slug AS trip_slug, t.direction, t.origin, t.destination,
              t.depart_at, t.reminder_at
       FROM bookings b JOIN trips t ON t.id = b.trip_id
       WHERE b.user_id = ?1
       ORDER BY (b.status = 'cancelled') ASC, t.depart_at ASC`,
    )
    .bind(userId)
    .all<BookingWithTrip>();
  return result.results ?? [];
}

export async function listBookingsByTrip(
  db: D1Database,
  tripId: number,
  search: string | null,
): Promise<BookingWithTrip[]> {
  const like = search && search.trim() ? `%${search.trim()}%` : null;
  const result = await db
    .prepare(
      `SELECT ${BOOKING_COLUMNS.split(',').map((c) => `b.${c.trim()}`).join(', ')},
              t.slug AS trip_slug, t.direction, t.origin, t.destination,
              t.depart_at, t.reminder_at
       FROM bookings b JOIN trips t ON t.id = b.trip_id
       WHERE b.trip_id = ?1
         AND (?2 IS NULL
              OR b.representative_name LIKE ?2
              OR b.phone LIKE ?2
              OR b.companion_names_json LIKE ?2
              OR CAST(b.id AS TEXT) = ?3)
       ORDER BY b.created_at DESC, b.id DESC`,
    )
    .bind(tripId, like, search?.trim() ?? '')
    .all<BookingWithTrip>();
  return result.results ?? [];
}

/**
 * 予約をキャンセルする。所有者チェックをSQLのWHEREへ含める。
 * @returns 更新行数（0 なら対象なし＝他人の予約 or すでにキャンセル済み）
 */
export async function cancelBookingRow(
  db: D1Database,
  params: { bookingId: number; userId: number | null; requireOwner: boolean },
  now: string = nowUtc(),
): Promise<number> {
  const result = await db
    .prepare(
      `UPDATE bookings
       SET status = 'cancelled', cancelled_at = ?3, updated_at = ?3
       WHERE id = ?1
         AND status = 'confirmed'
         AND (?4 = 0 OR (user_id IS NOT NULL AND user_id = ?2))`,
    )
    .bind(params.bookingId, params.userId, now, params.requireOwner ? 1 : 0)
    .run();
  return result.meta?.changes ?? 0;
}

export async function updateCheckedInCount(
  db: D1Database,
  bookingId: number,
  checkedInCount: number,
  now: string = nowUtc(),
): Promise<number> {
  const result = await db
    .prepare(
      `UPDATE bookings
       SET checked_in_count = ?2, updated_at = ?3
       WHERE id = ?1 AND status = 'confirmed'
         AND ?2 >= 0 AND ?2 <= party_size`,
    )
    .bind(bookingId, checkedInCount, now)
    .run();
  return result.meta?.changes ?? 0;
}

// ---------------------------------------------------------------------------
// notifications
// ---------------------------------------------------------------------------

/**
 * 通知の送信権を確保する。
 * UNIQUE(booking_id, notification_type) により二重送信をDB制約で防ぐ。
 *
 * @returns true なら送信してよい（このリクエストが権利を取った）
 */
export async function claimNotification(
  db: D1Database,
  bookingId: number,
  type: NotificationType,
  maxAttempts: number,
  now: string = nowUtc(),
): Promise<boolean> {
  await db
    .prepare(
      `INSERT OR IGNORE INTO notifications
         (booking_id, notification_type, status, attempt_count, created_at, updated_at)
       VALUES (?1, ?2, 'pending', 0, ?3, ?3)`,
    )
    .bind(bookingId, type, now)
    .run();

  const result = await db
    .prepare(
      `UPDATE notifications
       SET attempt_count = attempt_count + 1, updated_at = ?3
       WHERE booking_id = ?1 AND notification_type = ?2
         AND status IN ('pending', 'failed')
         AND attempt_count < ?4`,
    )
    .bind(bookingId, type, now, maxAttempts)
    .run();

  return (result.meta?.changes ?? 0) > 0;
}

export async function finishNotification(
  db: D1Database,
  bookingId: number,
  type: NotificationType,
  status: NotificationStatus,
  lastError: string | null,
  now: string = nowUtc(),
): Promise<void> {
  await db
    .prepare(
      `UPDATE notifications
       SET status = ?3,
           last_error = ?4,
           requested_at = CASE WHEN ?3 = 'requested' THEN ?5 ELSE requested_at END,
           updated_at = ?5
       WHERE booking_id = ?1 AND notification_type = ?2`,
    )
    .bind(bookingId, type, status, lastError, now)
    .run();
}

export async function getNotification(
  db: D1Database,
  bookingId: number,
  type: NotificationType,
): Promise<NotificationRow | null> {
  return await db
    .prepare(
      `SELECT * FROM notifications WHERE booking_id = ?1 AND notification_type = ?2`,
    )
    .bind(bookingId, type)
    .first<NotificationRow>();
}

export async function listNotificationsForTrip(
  db: D1Database,
  tripId: number,
): Promise<NotificationRow[]> {
  const result = await db
    .prepare(
      `SELECT n.* FROM notifications n
       JOIN bookings b ON b.id = n.booking_id
       WHERE b.trip_id = ?1
       ORDER BY n.id DESC`,
    )
    .bind(tripId)
    .all<NotificationRow>();
  return result.results ?? [];
}

/**
 * リマインド送信対象。
 * - reminder_at を過ぎた便
 * - 出発前（出発済みの便には送らない）
 * - confirmed の予約のみ（cancelled は対象外）
 * - 未送信、または failed かつ試行回数が上限未満
 */
export async function listDueReminderTargets(
  db: D1Database,
  now: string,
  maxAttempts: number,
): Promise<ReminderTarget[]> {
  const result = await db
    .prepare(
      `SELECT
         b.id AS booking_id, b.trip_id, b.party_size, b.representative_name,
         u.line_user_id, u.is_line_friend,
         t.direction, t.origin, t.destination, t.depart_at
       FROM bookings b
       JOIN trips t ON t.id = b.trip_id
       LEFT JOIN users u ON u.id = b.user_id
       LEFT JOIN notifications n
         ON n.booking_id = b.id AND n.notification_type = 'reminder'
       WHERE b.status = 'confirmed'
         AND t.reminder_at <= ?1
         AND t.depart_at > ?1
         AND (n.id IS NULL OR (n.status = 'failed' AND n.attempt_count < ?2))
       ORDER BY b.id ASC`,
    )
    .bind(now, maxAttempts)
    .all<ReminderTarget>();
  return result.results ?? [];
}
