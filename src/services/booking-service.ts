/**
 * 予約のドメインロジック。
 * 入力検証・定員制御・所有者チェックはすべてここ（＝サーバー側）で行う。
 */

import {
  cancelBookingRow,
  getBookingById,
  getLatestConfirmedBooking,
  getTripById,
  insertBookingIfCapacityAllows,
} from '../db/queries';
import type { BookingWithTrip } from '../db/types';
import { nowUtc } from '../lib/time';

export const MIN_PARTY_SIZE = 1;
export const MAX_PARTY_SIZE = 4;

export type BookingErrorCode =
  | 'VALIDATION'
  | 'TRIP_NOT_FOUND'
  | 'CLOSED'
  | 'DEPARTED'
  | 'FULL'
  | 'DUPLICATE'
  | 'NOT_AGREED';

export interface CreateBookingInput {
  tripId: number;
  userId: number | null;
  source: 'line' | 'admin';
  representativeName: string;
  phone: string;
  partySize: number;
  companionNames: string[];
  agreed: boolean;
}

export type CreateBookingResult =
  | { ok: true; bookingId: number }
  | { ok: false; code: BookingErrorCode; message: string };

export interface ValidatedBooking {
  representativeName: string;
  phone: string;
  partySize: number;
  companionNames: string[];
}

export type ValidationResult =
  | { ok: true; value: ValidatedBooking }
  | { ok: false; code: BookingErrorCode; message: string };

/** 電話番号: 数字・ハイフン・括弧・+ のみ、数字10〜11桁。 */
const PHONE_PATTERN = /^[0-9+\-() ]{10,20}$/;

export function validateBookingInput(input: {
  representativeName: string;
  phone: string;
  partySize: unknown;
  companionNames: string[];
  agreed: boolean;
  requireAgreement: boolean;
}): ValidationResult {
  const representativeName = input.representativeName.trim();
  if (!representativeName) {
    return { ok: false, code: 'VALIDATION', message: '代表者氏名を入力してください。' };
  }
  if (representativeName.length > 50) {
    return { ok: false, code: 'VALIDATION', message: '代表者氏名が長すぎます。' };
  }

  const phone = input.phone.trim();
  if (!phone) {
    return { ok: false, code: 'VALIDATION', message: '電話番号を入力してください。' };
  }
  const digits = phone.replace(/\D/g, '');
  if (!PHONE_PATTERN.test(phone) || digits.length < 10 || digits.length > 11) {
    return {
      ok: false,
      code: 'VALIDATION',
      message: '電話番号の形式が正しくありません（数字10〜11桁）。',
    };
  }

  const partySize = Number(input.partySize);
  if (!Number.isInteger(partySize)) {
    return { ok: false, code: 'VALIDATION', message: '人数を選択してください。' };
  }
  if (partySize < MIN_PARTY_SIZE || partySize > MAX_PARTY_SIZE) {
    return {
      ok: false,
      code: 'VALIDATION',
      message: `人数は${MIN_PARTY_SIZE}〜${MAX_PARTY_SIZE}名で選択してください。`,
    };
  }

  const companionNames = input.companionNames
    .slice(0, MAX_PARTY_SIZE - 1)
    .map((name) => name.trim())
    .filter((name) => name.length > 0);

  if (companionNames.length < partySize - 1) {
    return { ok: false, code: 'VALIDATION', message: '同行者の氏名をすべて入力してください。' };
  }
  if (companionNames.some((name) => name.length > 50)) {
    return { ok: false, code: 'VALIDATION', message: '同行者氏名が長すぎます。' };
  }

  if (input.requireAgreement && !input.agreed) {
    return { ok: false, code: 'NOT_AGREED', message: '注意事項への同意が必要です。' };
  }

  return {
    ok: true,
    value: {
      representativeName,
      phone,
      partySize,
      // 代表者を含めた人数なので、同行者は partySize - 1 名
      companionNames: companionNames.slice(0, partySize - 1),
    },
  };
}

function isUniqueViolation(error: unknown): boolean {
  const message = (error as Error)?.message ?? '';
  return /UNIQUE constraint failed/i.test(message);
}

function isCapacityViolation(error: unknown): boolean {
  const message = (error as Error)?.message ?? '';
  return /CAPACITY_EXCEEDED/i.test(message);
}

/**
 * 予約を作成する。
 *
 * 定員超過はアプリ側の条件付きINSERTとDBトリガーの二段で防ぐ。
 * 同一ユーザー・同一便の二重予約は部分UNIQUEインデックスで防ぐ。
 */
export async function createBooking(
  db: D1Database,
  input: CreateBookingInput,
  now: string = nowUtc(),
): Promise<CreateBookingResult> {
  const validation = validateBookingInput({
    representativeName: input.representativeName,
    phone: input.phone,
    partySize: input.partySize,
    companionNames: input.companionNames,
    agreed: input.agreed,
    requireAgreement: input.source === 'line',
  });
  if (!validation.ok) return validation;

  const trip = await getTripById(db, input.tripId, now);
  if (!trip) {
    return { ok: false, code: 'TRIP_NOT_FOUND', message: '便が見つかりません。' };
  }
  if (trip.depart_at <= now) {
    return { ok: false, code: 'DEPARTED', message: 'この便は既に出発しています。' };
  }
  const isAdmin = input.source === 'admin';
  if (!isAdmin && !trip.is_bookable && !trip.is_full) {
    return { ok: false, code: 'CLOSED', message: 'この便は現在受付を停止しています。' };
  }

  let changes = 0;
  try {
    changes = await insertBookingIfCapacityAllows(
      db,
      {
        tripId: trip.id,
        userId: input.userId,
        source: input.source,
        representativeName: validation.value.representativeName,
        phone: validation.value.phone,
        partySize: validation.value.partySize,
        companionNamesJson: JSON.stringify(validation.value.companionNames),
        ignoreBookingWindow: isAdmin,
      },
      now,
    );
  } catch (error) {
    if (isUniqueViolation(error)) {
      return {
        ok: false,
        code: 'DUPLICATE',
        message: 'この便は既に予約済みです。変更する場合は一度キャンセルしてください。',
      };
    }
    if (isCapacityViolation(error)) {
      return { ok: false, code: 'FULL', message: '満席のため予約できませんでした。' };
    }
    throw error;
  }

  if (changes === 0) {
    // 条件付きINSERTが弾いた理由を確定させる
    const latest = await getTripById(db, input.tripId, now);
    if (latest && latest.remaining_seats < validation.value.partySize) {
      return {
        ok: false,
        code: 'FULL',
        message: `残席が不足しています（残り${latest.remaining_seats}席）。`,
      };
    }
    return { ok: false, code: 'CLOSED', message: 'この便は現在予約できません。' };
  }

  const booking = await getLatestConfirmedBooking(db, trip.id, input.userId);
  if (!booking) {
    return { ok: false, code: 'FULL', message: '予約の作成に失敗しました。' };
  }
  return { ok: true, bookingId: booking.id };
}

export type CancelResult =
  | { ok: true }
  | { ok: false; code: 'NOT_FOUND' | 'FORBIDDEN' | 'DEPARTED' | 'ALREADY_CANCELLED'; message: string };

/**
 * 予約をキャンセルする（論理削除）。
 * ユーザー操作では所有者本人のみ許可する。
 */
export async function cancelBooking(
  db: D1Database,
  params: { bookingId: number; userId: number | null; asAdmin: boolean },
  now: string = nowUtc(),
): Promise<CancelResult> {
  const booking = await getBookingById(db, params.bookingId);
  if (!booking) {
    return { ok: false, code: 'NOT_FOUND', message: '予約が見つかりません。' };
  }

  if (!params.asAdmin) {
    if (booking.user_id === null || booking.user_id !== params.userId) {
      // 他人の予約の存在を推測させないため NOT_FOUND 相当のメッセージにする
      return { ok: false, code: 'FORBIDDEN', message: '予約が見つかりません。' };
    }
  }

  if (booking.status === 'cancelled') {
    return { ok: false, code: 'ALREADY_CANCELLED', message: '既にキャンセル済みです。' };
  }

  if (!params.asAdmin) {
    if (booking.depart_at <= now) {
      return { ok: false, code: 'DEPARTED', message: '出発後のキャンセルはできません。' };
    }
    const trip = await getTripById(db, booking.trip_id, now);
    if (trip?.booking_close_at && trip.booking_close_at <= now) {
      return {
        ok: false,
        code: 'DEPARTED',
        message: 'キャンセル受付は締め切られました。お手数ですが直接お問い合わせください。',
      };
    }
  }

  const changes = await cancelBookingRow(
    db,
    {
      bookingId: params.bookingId,
      userId: params.userId,
      requireOwner: !params.asAdmin,
    },
    now,
  );
  if (changes === 0) {
    return { ok: false, code: 'NOT_FOUND', message: '予約が見つかりません。' };
  }
  return { ok: true };
}

/** 所有者チェック付きの予約取得。他人の予約IDを渡しても null になる。 */
export async function getOwnedBooking(
  db: D1Database,
  bookingId: number,
  userId: number,
): Promise<BookingWithTrip | null> {
  const booking = await getBookingById(db, bookingId);
  if (!booking) return null;
  if (booking.user_id === null || booking.user_id !== userId) return null;
  return booking;
}
