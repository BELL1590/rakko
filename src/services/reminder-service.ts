/**
 * 通知（予約完了 / 乗車前リマインド）の送信制御。
 *
 * - 二重送信は notifications の UNIQUE(booking_id, notification_type) で防ぐ
 * - Messaging API の成功は「配信要求済み(requested)」として扱い、到達保証とはみなさない
 * - 友だち未追加・ブロック等は skipped として記録する
 */

import {
  claimNotification,
  finishNotification,
  getBookingById,
  getTripById,
  getUserById,
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
 * 通知1件を送信する。既に送信権が無い場合は 'already'。
 */
export async function dispatchNotification(
  db: D1Database,
  env: Bindings,
  params: {
    bookingId: number;
    type: NotificationType;
    lineUserId: string | null;
    text: string;
  },
  deps: DispatchDeps = {},
  now: string = nowUtc(),
): Promise<DispatchOutcome> {
  const claimed = await claimNotification(
    db,
    params.bookingId,
    params.type,
    MAX_NOTIFICATION_ATTEMPTS,
    now,
  );
  if (!claimed) return 'already';

  if (!params.lineUserId) {
    await finishNotification(
      db,
      params.bookingId,
      params.type,
      'skipped',
      'no LINE user (admin proxy booking)',
      now,
    );
    return 'skipped';
  }

  if (!hasLineMessagingConfig(env)) {
    await finishNotification(
      db,
      params.bookingId,
      params.type,
      'skipped',
      'messaging channel access token is not configured',
      now,
    );
    return 'skipped';
  }

  const result = await pushTextMessage({
    accessToken: env.LINE_MESSAGING_CHANNEL_ACCESS_TOKEN as string,
    to: params.lineUserId,
    text: params.text,
    fetchImpl: deps.fetchImpl,
  });

  if (result.ok) {
    await finishNotification(db, params.bookingId, params.type, 'requested', null, now);
    return 'requested';
  }

  // 4xx（友だち未追加・ブロック等）は再試行しても変わらないため skipped で確定させる
  const status = result.retryable ? 'failed' : 'skipped';
  await finishNotification(db, params.bookingId, params.type, status, result.error, now);
  return status;
}

/** 予約完了通知。予約処理をブロックせず、失敗しても予約はロールバックしない。 */
export async function sendBookingConfirmation(
  db: D1Database,
  env: Bindings,
  bookingId: number,
  deps: DispatchDeps = {},
  now: string = nowUtc(),
): Promise<DispatchOutcome> {
  const booking = await getBookingById(db, bookingId);
  if (!booking || booking.status !== 'confirmed') return 'skipped';

  // 管理者代理予約はLINE通知を送らない
  if (booking.source === 'admin' || booking.user_id === null) return 'skipped';

  const user = await getUserById(db, booking.user_id);
  const trip = await getTripById(db, booking.trip_id, now);
  if (!trip) return 'skipped';

  return await dispatchNotification(
    db,
    env,
    {
      bookingId,
      type: 'booking_confirmation',
      lineUserId: user?.line_user_id ?? null,
      text: buildBookingConfirmationText(trip, booking.party_size),
    },
    deps,
    now,
  );
}

export interface ReminderRunSummary {
  checked: number;
  requested: number;
  failed: number;
  skipped: number;
  already: number;
}

/**
 * reminder_at を過ぎた便の確定予約へリマインドを送る。
 * Cron Trigger から5分おきに呼ばれる想定。
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
    const text = buildReminderText(
      {
        direction: target.direction,
        origin: target.origin,
        destination: target.destination,
        depart_at: target.depart_at,
      },
      target.party_size,
    );

    const outcome = await dispatchNotification(
      db,
      env,
      {
        bookingId: target.booking_id,
        type: 'reminder',
        lineUserId: target.line_user_id,
        text,
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
