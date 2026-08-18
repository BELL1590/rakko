/**
 * 予約のドメインロジック。
 * 入力検証・定員制御・所有者チェックはすべてここ（＝サーバー側）で行う。
 *
 * Phase 2 では、同一ページの複数枠をまとめて予約できる。
 * 一括予約は「全枠成功 or 全枠失敗」とし、片方だけ確定させない。
 */

import {
  buildBookingInsert,
  cancelBookingRow,
  getBookingById,
  getPageById,
  getSlotWithPage,
  listConfirmedSlotIdsForUser,
} from '../db/queries';
import type { BookingWithSlot, SlotWithPage } from '../db/types';
import { nowUtc } from '../lib/time';

export const MIN_PARTY_SIZE = 1;
/** 予約枠に max_party_size が設定されていないときの既定上限。 */
export const MAX_PARTY_SIZE = 4;
/** 1予約あたりの人数の絶対上限（DBのCHECK制約と揃える）。 */
export const HARD_MAX_PARTY_SIZE = 20;

export type BookingErrorCode =
  | 'VALIDATION'
  | 'PAGE_NOT_FOUND'
  | 'PAGE_CLOSED'
  | 'SLOT_NOT_FOUND'
  | 'CLOSED'
  | 'DEPARTED'
  | 'FULL'
  | 'DUPLICATE'
  | 'NOT_AGREED'
  | 'NO_SELECTION'
  | 'TOO_MANY_SLOTS';

export interface BookingItemInput {
  slotId: number;
  partySize: number;
  companionNames: string[];
}

export interface CreateGroupBookingInput {
  pageId: number;
  userId: number | null;
  source: 'line' | 'admin';
  representativeName: string;
  phone: string;
  agreed: boolean;
  items: BookingItemInput[];
}

export type GroupBookingFailure = {
  ok: false;
  code: BookingErrorCode;
  message: string;
  /** エラーの原因になった枠（分かる場合） */
  slotId?: number;
};

export type CreateGroupBookingResult =
  | { ok: true; groupId: string | null; bookingIds: number[] }
  | GroupBookingFailure;

/** 電話番号: 数字・ハイフン・括弧・+ のみ、数字10〜11桁。 */
const PHONE_PATTERN = /^[0-9+\-() ]{10,20}$/;

export interface ValidatedContact {
  representativeName: string;
  phone: string;
}

export function validateContact(input: {
  representativeName: string;
  phone: string;
  agreed: boolean;
  requireAgreement: boolean;
}): { ok: true; value: ValidatedContact } | GroupBookingFailure {
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

  if (input.requireAgreement && !input.agreed) {
    return { ok: false, code: 'NOT_AGREED', message: '注意事項への同意が必要です。' };
  }

  return { ok: true, value: { representativeName, phone } };
}

/** 1枠分の人数・同行者を検証する。 */
export function validateItem(
  item: BookingItemInput,
  slot: { name: string; max_party_size: number },
): { ok: true; partySize: number; companionNames: string[] } | GroupBookingFailure {
  const partySize = Number(item.partySize);
  if (!Number.isInteger(partySize)) {
    return { ok: false, code: 'VALIDATION', message: '人数を選択してください。', slotId: undefined };
  }
  const maxPartySize = Math.min(slot.max_party_size, HARD_MAX_PARTY_SIZE);
  if (partySize < MIN_PARTY_SIZE || partySize > maxPartySize) {
    return {
      ok: false,
      code: 'VALIDATION',
      message: `${slot.name}の人数は${MIN_PARTY_SIZE}〜${maxPartySize}名で選択してください。`,
    };
  }

  const companionNames = item.companionNames
    .slice(0, maxPartySize - 1)
    .map((name) => name.trim())
    .filter((name) => name.length > 0);

  if (companionNames.length < partySize - 1) {
    return {
      ok: false,
      code: 'VALIDATION',
      message: `${slot.name}の同行者氏名をすべて入力してください。`,
    };
  }
  if (companionNames.some((name) => name.length > 50)) {
    return { ok: false, code: 'VALIDATION', message: '同行者氏名が長すぎます。' };
  }

  // 代表者を含めた人数なので、同行者は partySize - 1 名
  return { ok: true, partySize, companionNames: companionNames.slice(0, partySize - 1) };
}

function isUniqueViolation(error: unknown): boolean {
  return /UNIQUE constraint failed/i.test((error as Error)?.message ?? '');
}

function isCapacityViolation(error: unknown): boolean {
  return /CAPACITY_EXCEEDED/i.test((error as Error)?.message ?? '');
}

function isPartySizeViolation(error: unknown): boolean {
  return /PARTY_SIZE_EXCEEDED/i.test((error as Error)?.message ?? '');
}

/**
 * 同一ページの1〜複数枠をまとめて予約する。
 *
 * 定員超過・二重予約はDBのトリガーと部分UNIQUEインデックスが拒否し、
 * `db.batch()` の単一トランザクションによって全枠がまとめてロールバックされる。
 */
export async function createGroupBooking(
  db: D1Database,
  input: CreateGroupBookingInput,
  now: string = nowUtc(),
): Promise<CreateGroupBookingResult> {
  const isAdmin = input.source === 'admin';

  const contact = validateContact({
    representativeName: input.representativeName,
    phone: input.phone,
    agreed: input.agreed,
    requireAgreement: !isAdmin,
  });
  if (!contact.ok) return contact;

  const page = await getPageById(db, input.pageId);
  if (!page) {
    return { ok: false, code: 'PAGE_NOT_FOUND', message: '予約ページが見つかりません。' };
  }
  if (!isAdmin && page.status !== 'published') {
    return {
      ok: false,
      code: 'PAGE_CLOSED',
      message: 'この予約ページは現在受け付けていません。',
    };
  }

  // 同じ枠を2回選んでいても1件として扱う
  const seen = new Set<number>();
  const items = input.items.filter((item) => {
    if (seen.has(item.slotId)) return false;
    seen.add(item.slotId);
    return true;
  });

  if (items.length === 0) {
    return {
      ok: false,
      code: 'NO_SELECTION',
      message: '予約する枠を1つ以上選択してください。',
    };
  }
  if (!isAdmin && page.allow_multi_slot_booking === 0 && items.length > 1) {
    return {
      ok: false,
      code: 'TOO_MANY_SLOTS',
      message: 'このページでは一度に1つの枠のみ予約できます。',
    };
  }
  if (!isAdmin && items.length > page.max_slots_per_checkout) {
    return {
      ok: false,
      code: 'TOO_MANY_SLOTS',
      message: `一度に予約できるのは${page.max_slots_per_checkout}枠までです。`,
    };
  }

  // 枠ごとの事前検証（本命の防御はDB側。ここは分かりやすいエラー文言のため）
  const prepared: { slot: SlotWithPage; partySize: number; companionNames: string[] }[] = [];
  for (const item of items) {
    const slot = await getSlotWithPage(db, item.slotId, now);
    if (!slot || slot.reservation_page_id !== page.id) {
      return { ok: false, code: 'SLOT_NOT_FOUND', message: '予約枠が見つかりません。' };
    }

    const validated = validateItem(item, slot);
    if (!validated.ok) return { ...validated, slotId: slot.id };

    if (slot.start_at <= now) {
      return {
        ok: false,
        code: 'DEPARTED',
        message: `${slot.name}は受付を終了しています。`,
        slotId: slot.id,
      };
    }
    if (!isAdmin && !slot.is_bookable && !slot.is_full) {
      return {
        ok: false,
        code: 'CLOSED',
        message: `${slot.name}は現在受付を停止しています。`,
        slotId: slot.id,
      };
    }
    if (slot.remaining_seats < validated.partySize) {
      return {
        ok: false,
        code: 'FULL',
        message:
          `${slot.name}が満席のため、予約は確定していません。選択内容を見直してください。` +
          `（${slot.name}の残席：${slot.remaining_seats}席）`,
        slotId: slot.id,
      };
    }

    prepared.push({
      slot,
      partySize: validated.partySize,
      companionNames: validated.companionNames,
    });
  }

  if (input.userId !== null) {
    const duplicated = await listConfirmedSlotIdsForUser(
      db,
      input.userId,
      prepared.map((entry) => entry.slot.id),
    );
    if (duplicated.length > 0) {
      const slot = prepared.find((entry) => entry.slot.id === duplicated[0])?.slot;
      return {
        ok: false,
        code: 'DUPLICATE',
        message: `${slot?.name ?? 'この枠'}は既に予約済みです。変更する場合は一度キャンセルしてください。`,
        slotId: slot?.id,
      };
    }
  }

  const groupId = prepared.length > 1 ? crypto.randomUUID() : null;

  const statements = prepared.map((entry) =>
    buildBookingInsert(
      db,
      {
        slotId: entry.slot.id,
        userId: input.userId,
        source: input.source,
        representativeName: contact.value.representativeName,
        phone: contact.value.phone,
        partySize: entry.partySize,
        companionNamesJson: JSON.stringify(entry.companionNames),
        bookingGroupId: groupId,
        legacyTripId: entry.slot.legacy_trip_id,
      },
      now,
    ),
  );

  try {
    // batch は単一トランザクション。1文でも失敗すれば全体が取り消される。
    const results = await db.batch(statements);
    const bookingIds = results.map((result) => Number(result.meta?.last_row_id ?? 0));
    if (bookingIds.some((id) => !id)) {
      return { ok: false, code: 'FULL', message: '予約の作成に失敗しました。' };
    }
    return { ok: true, groupId, bookingIds };
  } catch (error) {
    if (isUniqueViolation(error)) {
      return {
        ok: false,
        code: 'DUPLICATE',
        message: '既に予約済みの枠が含まれています。マイ予約をご確認ください。',
      };
    }
    if (isCapacityViolation(error)) {
      return {
        ok: false,
        code: 'FULL',
        message: '満席の枠が含まれるため、予約は確定していません。選択内容を見直してください。',
      };
    }
    if (isPartySizeViolation(error)) {
      return {
        ok: false,
        code: 'VALIDATION',
        message: '1予約あたりの上限人数を超えています。',
      };
    }
    throw error;
  }
}

export interface CreateBookingInput {
  slotId: number;
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

/**
 * 単一枠の予約（管理者代理予約・互換用）。
 * 実体は {@link createGroupBooking} と同じ経路を通る。
 */
export async function createBooking(
  db: D1Database,
  input: CreateBookingInput,
  now: string = nowUtc(),
): Promise<CreateBookingResult> {
  const slot = await getSlotWithPage(db, input.slotId, now);
  if (!slot) {
    return { ok: false, code: 'SLOT_NOT_FOUND', message: '予約枠が見つかりません。' };
  }

  const result = await createGroupBooking(
    db,
    {
      pageId: slot.reservation_page_id,
      userId: input.userId,
      source: input.source,
      representativeName: input.representativeName,
      phone: input.phone,
      agreed: input.agreed,
      items: [
        {
          slotId: input.slotId,
          partySize: input.partySize,
          companionNames: input.companionNames,
        },
      ],
    },
    now,
  );

  if (!result.ok) return { ok: false, code: result.code, message: result.message };
  return { ok: true, bookingId: result.bookingIds[0] as number };
}

export type CancelResult =
  | { ok: true }
  | {
      ok: false;
      code: 'NOT_FOUND' | 'FORBIDDEN' | 'DEPARTED' | 'ALREADY_CANCELLED';
      message: string;
    };

/**
 * 予約をキャンセルする（論理削除）。
 * ユーザー操作では所有者本人のみ許可する。キャンセルは枠単位。
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
    if (booking.start_at <= now) {
      return { ok: false, code: 'DEPARTED', message: '開始後のキャンセルはできません。' };
    }
    if (booking.booking_close_at && booking.booking_close_at <= now) {
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
): Promise<BookingWithSlot | null> {
  const booking = await getBookingById(db, bookingId);
  if (!booking) return null;
  if (booking.user_id === null || booking.user_id !== userId) return null;
  return booking;
}
