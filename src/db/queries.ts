/**
 * D1 アクセス層。
 * SQLはすべて prepared statement + bind で組み立てる（文字列連結禁止）。
 *
 * Phase 2 で trips から reservation_pages / reservation_slots へ一般化した。
 * bookings.trip_id は段階移行のため残しているが、正は reservation_slot_id。
 */

import { nowUtc } from '../lib/time';
import type {
  BookingWithSlot,
  NotificationRow,
  NotificationStatus,
  NotificationType,
  PageStatus,
  PageType,
  PageWithStats,
  ReminderTarget,
  ReservationPageRow,
  ReservationSlotRow,
  SlotWithAvailability,
  SlotWithPage,
  UserRow,
} from './types';

const BOOKING_COLUMNS = [
  'id',
  'reservation_slot_id',
  'trip_id',
  'booking_group_id',
  'user_id',
  'source',
  'representative_name',
  'phone',
  'party_size',
  'companion_names_json',
  'status',
  'checked_in_count',
  'cancelled_at',
  'created_at',
  'updated_at',
]
  .map((column) => `b.${column}`)
  .join(', ');

const BOOKING_JOIN_COLUMNS = `
  s.name AS slot_name, s.description AS slot_description, s.start_at, s.end_at,
  s.origin, s.destination, s.location, s.max_party_size, s.reminder_at, s.booking_close_at,
  s.reservation_page_id,
  p.slug AS page_slug, p.title AS page_title, p.page_type, p.checkin_label
`;

const BOOKING_FROM = `
  FROM bookings b
  JOIN reservation_slots s ON s.id = b.reservation_slot_id
  JOIN reservation_pages p ON p.id = s.reservation_page_id
`;

/** 予約枠 + 確定予約人数の SELECT。 */
const SLOT_AVAILABILITY_SELECT = `
  SELECT s.*,
    COALESCE((
      SELECT SUM(b.party_size) FROM bookings b
      WHERE b.reservation_slot_id = s.id AND b.status = 'confirmed'
    ), 0) AS booked_seats
  FROM reservation_slots s
`;

type SlotAvailabilityRow = ReservationSlotRow & { booked_seats: number };

export function decorateSlot(row: SlotAvailabilityRow, now: string): SlotWithAvailability {
  const booked = Number(row.booked_seats ?? 0);
  const remaining = Math.max(0, row.capacity - booked);
  const started = row.start_at <= now;
  const withinWindow =
    (row.booking_open_at === null || row.booking_open_at <= now) &&
    (row.booking_close_at === null || row.booking_close_at > now);
  return {
    ...row,
    booked_seats: booked,
    remaining_seats: remaining,
    is_full: remaining <= 0,
    is_bookable:
      row.booking_status === 'open' && remaining > 0 && !started && withinWindow,
    is_visible: row.booking_status !== 'hidden',
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
// reservation_pages
// ---------------------------------------------------------------------------

export interface PageInput {
  slug: string;
  title: string;
  description: string;
  status: PageStatus;
  pageType: PageType;
  allowMultiSlotBooking: boolean;
  requiresLineLogin: boolean;
  maxSlotsPerCheckout: number;
  checkinLabel: string;
}

export async function createReservationPage(
  db: D1Database,
  input: PageInput,
  now: string = nowUtc(),
): Promise<number> {
  const result = await db
    .prepare(
      `INSERT INTO reservation_pages
         (slug, title, description, status, page_type, allow_multi_slot_booking,
          requires_line_login, max_slots_per_checkout, checkin_label, created_at, updated_at)
       VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?10)`,
    )
    .bind(
      input.slug,
      input.title,
      input.description,
      input.status,
      input.pageType,
      input.allowMultiSlotBooking ? 1 : 0,
      input.requiresLineLogin ? 1 : 0,
      input.maxSlotsPerCheckout,
      input.checkinLabel,
      now,
    )
    .run();
  return Number(result.meta?.last_row_id ?? 0);
}

export async function updateReservationPage(
  db: D1Database,
  pageId: number,
  input: PageInput,
  now: string = nowUtc(),
): Promise<void> {
  await db
    .prepare(
      `UPDATE reservation_pages
       SET slug = ?2, title = ?3, description = ?4, status = ?5, page_type = ?6,
           allow_multi_slot_booking = ?7, requires_line_login = ?8,
           max_slots_per_checkout = ?9, checkin_label = ?10, updated_at = ?11
       WHERE id = ?1`,
    )
    .bind(
      pageId,
      input.slug,
      input.title,
      input.description,
      input.status,
      input.pageType,
      input.allowMultiSlotBooking ? 1 : 0,
      input.requiresLineLogin ? 1 : 0,
      input.maxSlotsPerCheckout,
      input.checkinLabel,
      now,
    )
    .run();
}

export async function updatePageStatus(
  db: D1Database,
  pageId: number,
  status: PageStatus,
  now: string = nowUtc(),
): Promise<void> {
  await db
    .prepare(`UPDATE reservation_pages SET status = ?2, updated_at = ?3 WHERE id = ?1`)
    .bind(pageId, status, now)
    .run();
}

export async function getPageById(
  db: D1Database,
  id: number,
): Promise<ReservationPageRow | null> {
  return await db
    .prepare(`SELECT * FROM reservation_pages WHERE id = ?1`)
    .bind(id)
    .first<ReservationPageRow>();
}

export async function getPageBySlug(
  db: D1Database,
  slug: string,
): Promise<ReservationPageRow | null> {
  return await db
    .prepare(`SELECT * FROM reservation_pages WHERE slug = ?1`)
    .bind(slug)
    .first<ReservationPageRow>();
}

/** 管理画面の一覧用。枠数と確定予約人数を含む。 */
export async function listPagesWithStats(db: D1Database): Promise<PageWithStats[]> {
  const result = await db
    .prepare(
      `SELECT p.*,
        (SELECT COUNT(*) FROM reservation_slots s WHERE s.reservation_page_id = p.id) AS slot_count,
        COALESCE((
          SELECT SUM(b.party_size) FROM bookings b
          JOIN reservation_slots s2 ON s2.id = b.reservation_slot_id
          WHERE s2.reservation_page_id = p.id AND b.status = 'confirmed'
        ), 0) AS booked_seats,
        COALESCE((
          SELECT SUM(s3.capacity) FROM reservation_slots s3 WHERE s3.reservation_page_id = p.id
        ), 0) AS capacity_total
       FROM reservation_pages p
       ORDER BY (p.status = 'archived') ASC, p.created_at DESC, p.id DESC`,
    )
    .all<PageWithStats>();
  return result.results ?? [];
}

/** 公開中のページのみ（トップページ用）。 */
export async function listPublishedPages(db: D1Database): Promise<PageWithStats[]> {
  const pages = await listPagesWithStats(db);
  return pages.filter((page) => page.status === 'published');
}

// ---------------------------------------------------------------------------
// reservation_slots
// ---------------------------------------------------------------------------

export interface SlotInput {
  name: string;
  description: string;
  startAt: string;
  endAt: string | null;
  origin: string | null;
  destination: string | null;
  location: string | null;
  capacity: number;
  maxPartySize: number;
  bookingOpenAt: string | null;
  bookingCloseAt: string | null;
  reminderAt: string | null;
  bookingStatus: 'open' | 'closed' | 'hidden';
  sortOrder: number;
}

export async function createReservationSlot(
  db: D1Database,
  pageId: number,
  input: SlotInput,
  now: string = nowUtc(),
): Promise<number> {
  const result = await db
    .prepare(
      `INSERT INTO reservation_slots
         (reservation_page_id, name, description, start_at, end_at, origin, destination,
          location, capacity, max_party_size, booking_open_at, booking_close_at,
          reminder_at, booking_status, sort_order, created_at, updated_at)
       VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16, ?16)`,
    )
    .bind(
      pageId,
      input.name,
      input.description,
      input.startAt,
      input.endAt,
      input.origin,
      input.destination,
      input.location,
      input.capacity,
      input.maxPartySize,
      input.bookingOpenAt,
      input.bookingCloseAt,
      input.reminderAt,
      input.bookingStatus,
      input.sortOrder,
      now,
    )
    .run();
  return Number(result.meta?.last_row_id ?? 0);
}

export async function updateReservationSlot(
  db: D1Database,
  slotId: number,
  input: SlotInput,
  now: string = nowUtc(),
): Promise<void> {
  await db
    .prepare(
      `UPDATE reservation_slots
       SET name = ?2, description = ?3, start_at = ?4, end_at = ?5, origin = ?6,
           destination = ?7, location = ?8, capacity = ?9, max_party_size = ?10,
           booking_open_at = ?11, booking_close_at = ?12, reminder_at = ?13,
           booking_status = ?14, sort_order = ?15, updated_at = ?16
       WHERE id = ?1`,
    )
    .bind(
      slotId,
      input.name,
      input.description,
      input.startAt,
      input.endAt,
      input.origin,
      input.destination,
      input.location,
      input.capacity,
      input.maxPartySize,
      input.bookingOpenAt,
      input.bookingCloseAt,
      input.reminderAt,
      input.bookingStatus,
      input.sortOrder,
      now,
    )
    .run();
}

export async function listSlotsByPage(
  db: D1Database,
  pageId: number,
  now: string = nowUtc(),
): Promise<SlotWithAvailability[]> {
  const result = await db
    .prepare(
      `${SLOT_AVAILABILITY_SELECT} WHERE s.reservation_page_id = ?1
       ORDER BY s.sort_order ASC, s.start_at ASC, s.id ASC`,
    )
    .bind(pageId)
    .all<SlotAvailabilityRow>();
  return (result.results ?? []).map((row) => decorateSlot(row, now));
}

export async function getSlotById(
  db: D1Database,
  slotId: number,
  now: string = nowUtc(),
): Promise<SlotWithAvailability | null> {
  const row = await db
    .prepare(`${SLOT_AVAILABILITY_SELECT} WHERE s.id = ?1`)
    .bind(slotId)
    .first<SlotAvailabilityRow>();
  return row ? decorateSlot(row, now) : null;
}

/** 予約枠 + ページ情報。管理画面・予約処理で使う。 */
export async function getSlotWithPage(
  db: D1Database,
  slotId: number,
  now: string = nowUtc(),
): Promise<SlotWithPage | null> {
  const row = await db
    .prepare(
      `SELECT s.*,
        COALESCE((
          SELECT SUM(b.party_size) FROM bookings b
          WHERE b.reservation_slot_id = s.id AND b.status = 'confirmed'
        ), 0) AS booked_seats,
        p.slug AS page_slug, p.title AS page_title, p.status AS page_status,
        p.page_type, p.checkin_label, p.max_slots_per_checkout,
        p.allow_multi_slot_booking, p.requires_line_login
       FROM reservation_slots s
       JOIN reservation_pages p ON p.id = s.reservation_page_id
       WHERE s.id = ?1`,
    )
    .bind(slotId)
    .first<SlotAvailabilityRow & Omit<SlotWithPage, keyof SlotWithAvailability>>();
  if (!row) return null;
  return { ...decorateSlot(row, now), ...row } as SlotWithPage;
}

/** 旧 `/trips/:slug` 互換のための解決。 */
export async function getSlotByLegacyTripSlug(
  db: D1Database,
  tripSlug: string,
  now: string = nowUtc(),
): Promise<SlotWithPage | null> {
  const row = await db
    .prepare(
      `SELECT s.id FROM reservation_slots s
       JOIN trips t ON t.id = s.legacy_trip_id
       WHERE t.slug = ?1`,
    )
    .bind(tripSlug)
    .first<{ id: number }>();
  if (!row) return null;
  return await getSlotWithPage(db, row.id, now);
}

/** 予約枠の確定予約合計人数。 */
export async function getBookedSeats(db: D1Database, slotId: number): Promise<number> {
  const row = await db
    .prepare(
      `SELECT COALESCE(SUM(party_size), 0) AS booked
       FROM bookings WHERE reservation_slot_id = ?1 AND status = 'confirmed'`,
    )
    .bind(slotId)
    .first<{ booked: number }>();
  return Number(row?.booked ?? 0);
}

// ---------------------------------------------------------------------------
// bookings
// ---------------------------------------------------------------------------

export interface BookingInsertParams {
  slotId: number;
  userId: number | null;
  source: 'line' | 'admin';
  representativeName: string;
  phone: string;
  partySize: number;
  companionNamesJson: string;
  bookingGroupId: string | null;
  /** 旧モデル互換のため、移行済み枠なら trip_id も書き込む */
  legacyTripId: number | null;
}

/**
 * 予約INSERTの prepared statement を作る。
 *
 * 定員・1予約あたり人数・二重予約は、DBのトリガーと部分UNIQUEインデックスが
 * 例外を投げて拒否する。複数枠の一括予約では、これらを `db.batch()` に渡すことで
 * 「全枠成功 or 全枠失敗」を保証する（1文でも失敗すればトランザクション全体が戻る）。
 */
export function buildBookingInsert(
  db: D1Database,
  params: BookingInsertParams,
  now: string = nowUtc(),
): D1PreparedStatement {
  return db
    .prepare(
      `INSERT INTO bookings (
         reservation_slot_id, trip_id, booking_group_id, user_id, source,
         representative_name, phone, party_size, companion_names_json,
         status, checked_in_count, created_at, updated_at
       )
       VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, 'confirmed', 0, ?10, ?10)`,
    )
    .bind(
      params.slotId,
      params.legacyTripId,
      params.bookingGroupId,
      params.userId,
      params.source,
      params.representativeName,
      params.phone,
      params.partySize,
      params.companionNamesJson,
      now,
    );
}

/**
 * 定員チェックを含めた条件付きINSERT（単枠用）。
 *
 * 「SELECTで残席確認 → INSERT」の2段構えにすると同時リクエストで定員を超えるため、
 * 残席条件を INSERT ... SELECT の WHERE に埋め込み、1文で判定する。
 * さらにDBトリガー（trg_bookings_capacity_insert）が最終防衛線として働く。
 *
 * @returns 追加された行数（0 なら受付不可 or 満席）
 */
export async function insertBookingIfCapacityAllows(
  db: D1Database,
  params: BookingInsertParams & { ignoreBookingWindow: boolean },
  now: string = nowUtc(),
): Promise<number> {
  const result = await db
    .prepare(
      `INSERT INTO bookings (
         reservation_slot_id, trip_id, booking_group_id, user_id, source,
         representative_name, phone, party_size, companion_names_json,
         status, checked_in_count, created_at, updated_at
       )
       SELECT s.id, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, 'confirmed', 0, ?10, ?10
       FROM reservation_slots s
       WHERE s.id = ?1
         AND s.start_at > ?10
         AND ?8 <= s.max_party_size
         AND (
           ?11 = 1
           OR (
             s.booking_status = 'open'
             AND (s.booking_open_at IS NULL OR s.booking_open_at <= ?10)
             AND (s.booking_close_at IS NULL OR s.booking_close_at > ?10)
           )
         )
         AND (
           COALESCE((
             SELECT SUM(b.party_size) FROM bookings b
             WHERE b.reservation_slot_id = s.id AND b.status = 'confirmed'
           ), 0) + ?8
         ) <= s.capacity`,
    )
    .bind(
      params.slotId,
      params.legacyTripId,
      params.bookingGroupId,
      params.userId,
      params.source,
      params.representativeName,
      params.phone,
      params.partySize,
      params.companionNamesJson,
      now,
      params.ignoreBookingWindow ? 1 : 0,
    )
    .run();
  return result.meta?.changes ?? 0;
}

export async function getBookingById(
  db: D1Database,
  bookingId: number,
): Promise<BookingWithSlot | null> {
  return await db
    .prepare(
      `SELECT ${BOOKING_COLUMNS}, ${BOOKING_JOIN_COLUMNS} ${BOOKING_FROM} WHERE b.id = ?1`,
    )
    .bind(bookingId)
    .first<BookingWithSlot>();
}

export async function listBookingsByIds(
  db: D1Database,
  bookingIds: number[],
): Promise<BookingWithSlot[]> {
  if (bookingIds.length === 0) return [];
  const placeholders = bookingIds.map((_, index) => `?${index + 1}`).join(', ');
  const result = await db
    .prepare(
      `SELECT ${BOOKING_COLUMNS}, ${BOOKING_JOIN_COLUMNS} ${BOOKING_FROM}
       WHERE b.id IN (${placeholders})
       ORDER BY s.sort_order ASC, s.start_at ASC`,
    )
    .bind(...bookingIds)
    .all<BookingWithSlot>();
  return result.results ?? [];
}

export async function listBookingsByGroup(
  db: D1Database,
  groupId: string,
): Promise<BookingWithSlot[]> {
  const result = await db
    .prepare(
      `SELECT ${BOOKING_COLUMNS}, ${BOOKING_JOIN_COLUMNS} ${BOOKING_FROM}
       WHERE b.booking_group_id = ?1
       ORDER BY s.sort_order ASC, s.start_at ASC`,
    )
    .bind(groupId)
    .all<BookingWithSlot>();
  return result.results ?? [];
}

export async function listBookingsByUser(
  db: D1Database,
  userId: number,
): Promise<BookingWithSlot[]> {
  const result = await db
    .prepare(
      `SELECT ${BOOKING_COLUMNS}, ${BOOKING_JOIN_COLUMNS} ${BOOKING_FROM}
       WHERE b.user_id = ?1
       ORDER BY (b.status = 'cancelled') ASC, s.start_at ASC`,
    )
    .bind(userId)
    .all<BookingWithSlot>();
  return result.results ?? [];
}

export async function listBookingsBySlot(
  db: D1Database,
  slotId: number,
  search: string | null,
): Promise<BookingWithSlot[]> {
  const like = search && search.trim() ? `%${search.trim()}%` : null;
  const result = await db
    .prepare(
      `SELECT ${BOOKING_COLUMNS}, ${BOOKING_JOIN_COLUMNS} ${BOOKING_FROM}
       WHERE b.reservation_slot_id = ?1
         AND (?2 IS NULL
              OR b.representative_name LIKE ?2
              OR b.phone LIKE ?2
              OR b.companion_names_json LIKE ?2
              OR CAST(b.id AS TEXT) = ?3)
       ORDER BY b.created_at DESC, b.id DESC`,
    )
    .bind(slotId, like, search?.trim() ?? '')
    .all<BookingWithSlot>();
  return result.results ?? [];
}

/** ページ配下の全予約（ページ全体CSV用）。 */
export async function listBookingsByPage(
  db: D1Database,
  pageId: number,
  includeCancelled: boolean,
): Promise<BookingWithSlot[]> {
  const result = await db
    .prepare(
      `SELECT ${BOOKING_COLUMNS}, ${BOOKING_JOIN_COLUMNS} ${BOOKING_FROM}
       WHERE s.reservation_page_id = ?1
         AND (?2 = 1 OR b.status = 'confirmed')
       ORDER BY s.sort_order ASC, s.start_at ASC, b.id ASC`,
    )
    .bind(pageId, includeCancelled ? 1 : 0)
    .all<BookingWithSlot>();
  return result.results ?? [];
}

/** 予約枠の名簿（CSV用）。 */
export async function listRosterBySlot(
  db: D1Database,
  slotId: number,
  includeCancelled: boolean,
): Promise<BookingWithSlot[]> {
  const result = await db
    .prepare(
      `SELECT ${BOOKING_COLUMNS}, ${BOOKING_JOIN_COLUMNS} ${BOOKING_FROM}
       WHERE b.reservation_slot_id = ?1
         AND (?2 = 1 OR b.status = 'confirmed')
       ORDER BY b.id ASC`,
    )
    .bind(slotId, includeCancelled ? 1 : 0)
    .all<BookingWithSlot>();
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

/** 同一ユーザーが既に確定予約を持っている枠のID一覧。 */
export async function listConfirmedSlotIdsForUser(
  db: D1Database,
  userId: number,
  slotIds: number[],
): Promise<number[]> {
  if (slotIds.length === 0) return [];
  const placeholders = slotIds.map((_, index) => `?${index + 2}`).join(', ');
  const result = await db
    .prepare(
      `SELECT reservation_slot_id FROM bookings
       WHERE user_id = ?1 AND status = 'confirmed'
         AND reservation_slot_id IN (${placeholders})`,
    )
    .bind(userId, ...slotIds)
    .all<{ reservation_slot_id: number }>();
  return (result.results ?? []).map((row) => row.reservation_slot_id);
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

export async function listNotificationsForSlot(
  db: D1Database,
  slotId: number,
): Promise<NotificationRow[]> {
  const result = await db
    .prepare(
      `SELECT n.* FROM notifications n
       JOIN bookings b ON b.id = n.booking_id
       WHERE b.reservation_slot_id = ?1
       ORDER BY n.id DESC`,
    )
    .bind(slotId)
    .all<NotificationRow>();
  return result.results ?? [];
}

/**
 * リマインド送信対象。
 * - reminder_at を過ぎた予約枠（NULLなら送らない）
 * - 開始前（開始済みの枠には送らない）
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
         b.id AS booking_id, b.reservation_slot_id, b.party_size, b.representative_name,
         u.line_user_id, u.is_line_friend,
         s.name AS slot_name, s.origin, s.destination, s.location, s.start_at,
         p.title AS page_title, p.page_type
       FROM bookings b
       JOIN reservation_slots s ON s.id = b.reservation_slot_id
       JOIN reservation_pages p ON p.id = s.reservation_page_id
       LEFT JOIN users u ON u.id = b.user_id
       LEFT JOIN notifications n
         ON n.booking_id = b.id AND n.notification_type = 'reminder'
       WHERE b.status = 'confirmed'
         AND s.reminder_at IS NOT NULL
         AND s.reminder_at <= ?1
         AND s.start_at > ?1
         AND (n.id IS NULL OR (n.status = 'failed' AND n.attempt_count < ?2))
       ORDER BY b.id ASC`,
    )
    .bind(now, maxAttempts)
    .all<ReminderTarget>();
  return result.results ?? [];
}
