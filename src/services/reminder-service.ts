/**
 * 通知（予約完了 / 開始前リマインド）の送信制御。
 *
 * - 二重送信は notifications の UNIQUE(booking_id, notification_type) で防ぐ
 * - Messaging API の成功は「配信要求済み(requested)」として扱い、到達保証とはみなさない
 * - 友だち未追加・ブロック等は skipped として記録する
 * - リマインドは予約枠(reservation_slots.reminder_at)単位で送る
 */

import {
  claimNotification,
  finishNotification,
  getBookingById,
  getUserById,
  listBookingsByIds,
  listDueReminderTargets,
} from '../db/queries';
import type { NotificationType } from '../db/types';
import { nowUtc } from '../lib/time';
import type { Bindings } from '../env';
import { hasLineMessagingConfig } from '../env';
import {
  buildBookingConfirmationText,
  buildReminderText,
  pushTextMessage,
} from './line-message';

export const MAX_NOTIFICATION_ATTEMPTS = 3;

export type DispatchOutcome = 'requested' | 'failed' | 'skipped' | 'already';

export interface DispatchDeps {
  fetchImpl?: typeof fetch;
}

/**
 * 通知を送信する。複数の予約(bookingIds)を1通にまとめる場合は、
 * すべての予約について送信権を確保できたものだけを対象にする。
 */
export async function dispatchNotification(
  db: D1Database,
  env: Bindings,
  params: {
    bookingIds: number[];
    type: NotificationType;
    lineUserId: string | null;
    /** 送信権を取れた予約IDから本文を組み立てる */
    buildText: (claimedBookingIds: number[]) => string;
  },
  deps: DispatchDeps = {},
  now: string = nowUtc(),
): Promise<DispatchOutcome> {
  const claimed: number[] = [];
  for (const bookingId of params.bookingIds) {
    const ok = await claimNotification(
      db,
      bookingId,
      params.type,
      MAX_NOTIFICATION_ATTEMPTS,
      now,
    );
    if (ok) claimed.push(bookingId);
  }
  if (claimed.length === 0) return 'already';

  const finishAll = async (
    status: 'requested' | 'failed' | 'skipped',
    error: string | null,
  ): Promise<void> => {
    for (const bookingId of claimed) {
      await finishNotification(db, bookingId, params.type, status, error, now);
    }
  };

  if (!params.lineUserId) {
    await finishAll('skipped', 'no LINE user (admin proxy booking)');
    return 'skipped';
  }

  if (!hasLineMessagingConfig(env)) {
    await finishAll('skipped', 'messaging channel access token is not configured');
    return 'skipped';
  }

  const result = await pushTextMessage({
    accessToken: env.LINE_MESSAGING_CHANNEL_ACCESS_TOKEN as string,
    to: params.lineUserId,
    text: params.buildText(claimed),
    fetchImpl: deps.fetchImpl,
  });

  if (result.ok) {
    await finishAll('requested', null);
    return 'requested';
  }

  // 4xx（友だち未追加・ブロック等）は再試行しても変わらないため skipped で確定させる
  const status = result.retryable ? 'failed' : 'skipped';
  await finishAll(status, result.error);
  return status;
}

/**
 * 予約完了通知。予約処理をブロックせず、失敗しても予約はロールバックしない。
 * 一括予約（複数枠）は1通にまとめて送る。
 */
export async function sendBookingConfirmation(
  db: D1Database,
  env: Bindings,
  bookingIds: number[],
  deps: DispatchDeps = {},
  now: string = nowUtc(),
): Promise<DispatchOutcome> {
  const bookings = (await listBookingsByIds(db, bookingIds)).filter(
    (booking) => booking.status === 'confirmed',
  );
  if (bookings.length === 0) return 'skipped';

  const first = bookings[0]!;
  // 管理者代理予約はLINE通知を送らない
  if (first.source === 'admin' || first.user_id === null) return 'skipped';

  const user = await getUserById(db, first.user_id);

  return await dispatchNotification(
    db,
    env,
    {
      bookingIds: bookings.map((booking) => booking.id),
      type: 'booking_confirmation',
      lineUserId: user?.line_user_id ?? null,
      buildText: (claimedIds) =>
        buildBookingConfirmationText({
          pageTitle: first.page_title,
          pageType: first.page_type,
          items: bookings
            .filter((booking) => claimedIds.includes(booking.id))
            .map((booking) => ({
              slot: {
                name: booking.slot_name,
                start_at: booking.start_at,
                origin: booking.origin,
                destination: booking.destination,
                location: booking.location,
              },
              partySize: booking.party_size,
            })),
        }),
    },
    deps,
    now,
  );
}

/** 単一予約の予約完了通知（互換用）。 */
export async function sendBookingConfirmationForBooking(
  db: D1Database,
  env: Bindings,
  bookingId: number,
  deps: DispatchDeps = {},
  now: string = nowUtc(),
): Promise<DispatchOutcome> {
  const booking = await getBookingById(db, bookingId);
  if (!booking) return 'skipped';
  return await sendBookingConfirmation(db, env, [bookingId], deps, now);
}

export interface ReminderRunSummary {
  checked: number;
  requested: number;
  failed: number;
  skipped: number;
  already: number;
}

/**
 * reminder_at を過ぎた予約枠の確定予約へリマインドを送る。
 * Cron Trigger から5分おきに呼ばれる想定。送信単位は枠。
 */
export async function processDueReminders(
  db: D1Database,
  env: Bindings,
  deps: DispatchDeps = {},
  now: string = nowUtc(),
): Promise<ReminderRunSummary> {
  const targets = await listDueReminderTargets(db, now, MAX_NOTIFICATION_ATTEMPTS);
  const summary: ReminderRunSummary = {
    checked: targets.length,
    requested: 0,
    failed: 0,
    skipped: 0,
    already: 0,
  };

  for (const target of targets) {
    const text = buildReminderText({
      pageTitle: target.page_title,
      pageType: target.page_type,
      slot: {
        name: target.slot_name,
        start_at: target.start_at,
        origin: target.origin,
        destination: target.destination,
        location: target.location,
      },
      partySize: target.party_size,
    });

    const outcome = await dispatchNotification(
      db,
      env,
      {
        bookingIds: [target.booking_id],
        type: 'reminder',
        lineUserId: target.line_user_id,
        buildText: () => text,
      },
      deps,
      now,
    );

    if (outcome === 'requested') summary.requested += 1;
    else if (outcome === 'failed') summary.failed += 1;
    else if (outcome === 'skipped') summary.skipped += 1;
    else summary.already += 1;
  }

  return summary;
}
