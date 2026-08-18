import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import {
  createTestDb,
  createTestUser,
  tripIdBySlug,
  NOW,
  OUTBOUND_SLUG,
  RETURN_SLUG,
  type TestDatabase,
} from './helpers/db';
import { cancelBooking, createBooking } from '../src/services/booking-service';
import {
  processDueReminders,
  sendBookingConfirmation,
} from '../src/services/reminder-service';
import { getNotification } from '../src/db/queries';
import type { Bindings } from '../src/env';

let db: TestDatabase;

const base = {
  representativeName: '山田太郎',
  phone: '090-1234-5678',
  agreed: true,
  source: 'line' as const,
};

/** 行きのリマインド時刻: 2026-08-21 17:00 JST = 08:00 UTC */
const BEFORE_REMINDER = '2026-08-21T07:59:00Z';
const AFTER_REMINDER = '2026-08-21T08:05:00Z';

function env(overrides: Partial<Bindings> = {}): Bindings {
  return {
    DB: db.d1,
    BASE_URL: 'http://localhost:8787',
    DEMO_MODE: 'true',
    ENVIRONMENT: 'development',
    SESSION_SECRET: 'test-secret',
    LINE_MESSAGING_CHANNEL_ACCESS_TOKEN: 'test-token',
    ...overrides,
  } as Bindings;
}

function okFetch() {
  return vi.fn(async () => new Response('{}', { status: 200 }));
}

function failFetch(status: number, message = 'error') {
  return vi.fn(async () => new Response(JSON.stringify({ message }), { status }));
}

beforeEach(() => {
  db = createTestDb();
});

afterEach(() => {
  db.close();
  vi.restoreAllMocks();
});

async function book(userSuffix: string, slug: string, partySize = 2): Promise<number> {
  const userId = await createTestUser(db.d1, `U-${userSuffix}`);
  const slotId = await tripIdBySlug(db.d1, slug);
  const result = await createBooking(
    db.d1,
    {
      ...base,
      slotId,
      userId,
      partySize,
      companionNames: ['A', 'B', 'C'].slice(0, partySize - 1),
    },
    NOW,
  );
  if (!result.ok) throw new Error(`booking failed: ${result.code}`);
  return result.bookingId;
}

describe('リマインド', () => {
  it('reminder_at 前には送らない', async () => {
    await book('1', OUTBOUND_SLUG);
    const fetchImpl = okFetch();

    const summary = await processDueReminders(
      db.d1,
      env(),
      { fetchImpl: fetchImpl as unknown as typeof fetch },
      BEFORE_REMINDER,
    );

    expect(summary.checked).toBe(0);
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it('reminder_at 到達後に送信対象となる', async () => {
    const bookingId = await book('1', OUTBOUND_SLUG, 3);
    const fetchImpl = okFetch();

    const summary = await processDueReminders(
      db.d1,
      env(),
      { fetchImpl: fetchImpl as unknown as typeof fetch },
      AFTER_REMINDER,
    );

    expect(summary.requested).toBe(1);
    expect(fetchImpl).toHaveBeenCalledTimes(1);

    const notification = await getNotification(db.d1, bookingId, 'reminder');
    expect(notification?.status).toBe('requested');
    expect(notification?.requested_at).toBe(AFTER_REMINDER);

    // 本文に便情報と人数が入る
    const call = fetchImpl.mock.calls[0] as unknown as [string, RequestInit];
    const body = JSON.parse(String(call[1].body)) as {
      to: string;
      messages: { text: string }[];
    };
    expect(body.to).toBe('U-1');
    expect(body.messages[0]?.text).toContain('20:00');
    expect(body.messages[0]?.text).toContain('予約人数：3名');
  });

  it('同一リマインドを二重送信しない', async () => {
    await book('1', OUTBOUND_SLUG);
    const fetchImpl = okFetch();
    const deps = { fetchImpl: fetchImpl as unknown as typeof fetch };

    await processDueReminders(db.d1, env(), deps, AFTER_REMINDER);
    const second = await processDueReminders(db.d1, env(), deps, '2026-08-21T08:10:00Z');

    expect(fetchImpl).toHaveBeenCalledTimes(1);
    expect(second.checked).toBe(0);

    const count = await db.d1
      .prepare(`SELECT COUNT(*) AS c FROM notifications WHERE notification_type = 'reminder'`)
      .first<{ c: number }>();
    expect(count?.c).toBe(1);
  });

  it('キャンセル済み予約へは送信しない', async () => {
    const bookingId = await book('1', OUTBOUND_SLUG);
    const userRow = await db.d1
      .prepare('SELECT user_id FROM bookings WHERE id = ?1')
      .bind(bookingId)
      .first<{ user_id: number }>();
    await cancelBooking(
      db.d1,
      { bookingId, userId: userRow!.user_id, asAdmin: false },
      NOW,
    );

    const fetchImpl = okFetch();
    const summary = await processDueReminders(
      db.d1,
      env(),
      { fetchImpl: fetchImpl as unknown as typeof fetch },
      AFTER_REMINDER,
    );

    expect(summary.checked).toBe(0);
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it('開始済みの枠へは送信しない', async () => {
    await book('1', OUTBOUND_SLUG);
    const fetchImpl = okFetch();

    const summary = await processDueReminders(
      db.d1,
      env(),
      { fetchImpl: fetchImpl as unknown as typeof fetch },
      '2026-08-21T12:00:00Z',
    );

    expect(summary.checked).toBe(0);
  });

  it('行きと帰りでリマインドが独立している', async () => {
    await book('out', OUTBOUND_SLUG);
    await book('ret', RETURN_SLUG);
    const fetchImpl = okFetch();
    const deps = { fetchImpl: fetchImpl as unknown as typeof fetch };

    // 行きのリマインド時刻のみ到達
    const first = await processDueReminders(db.d1, env(), deps, AFTER_REMINDER);
    expect(first.requested).toBe(1);

    // 帰りのリマインド時刻（2026-08-21 22:00 UTC）到達後
    const second = await processDueReminders(db.d1, env(), deps, '2026-08-21T22:05:00Z');
    expect(second.requested).toBe(1);

    const texts = fetchImpl.mock.calls.map((call) => {
      const init = (call as unknown as [string, RequestInit])[1];
      return (JSON.parse(String(init.body)) as { messages: { text: string }[] }).messages[0]?.text;
    });
    expect(texts[0]).toContain('らっこ号 池袋便「行き」のお知らせ');
    expect(texts[1]).toContain('らっこ号 池袋便「帰り」のお知らせ');
    expect(texts[1]).toContain('8:10');
  });

  it('管理者代理予約（LINEユーザーなし）は skipped になる', async () => {
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    const created = await createBooking(
      db.d1,
      {
        slotId,
        userId: null,
        source: 'admin',
        representativeName: '電話太郎',
        phone: '0312345678',
        partySize: 1,
        companionNames: [],
        agreed: true,
      },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');

    const fetchImpl = okFetch();
    const summary = await processDueReminders(
      db.d1,
      env(),
      { fetchImpl: fetchImpl as unknown as typeof fetch },
      AFTER_REMINDER,
    );

    expect(summary.skipped).toBe(1);
    expect(fetchImpl).not.toHaveBeenCalled();
    const notification = await getNotification(db.d1, created.bookingId, 'reminder');
    expect(notification?.status).toBe('skipped');
  });

  it('友だち未追加・ブロック（4xx）は skipped として確定し再送しない', async () => {
    const bookingId = await book('1', OUTBOUND_SLUG);
    const fetchImpl = failFetch(403, 'The account is blocked');
    const deps = { fetchImpl: fetchImpl as unknown as typeof fetch };

    const summary = await processDueReminders(db.d1, env(), deps, AFTER_REMINDER);
    expect(summary.skipped).toBe(1);

    const notification = await getNotification(db.d1, bookingId, 'reminder');
    expect(notification?.status).toBe('skipped');
    expect(notification?.last_error).toContain('403');

    // 再実行しても送り直さない
    await processDueReminders(db.d1, env(), deps, '2026-08-21T08:10:00Z');
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  it('5xx は failed として記録し、最大3回まで再試行する', async () => {
    const bookingId = await book('1', OUTBOUND_SLUG);
    const fetchImpl = failFetch(500, 'internal error');
    const deps = { fetchImpl: fetchImpl as unknown as typeof fetch };

    for (const now of [
      '2026-08-21T08:05:00Z',
      '2026-08-21T08:10:00Z',
      '2026-08-21T08:15:00Z',
      '2026-08-21T08:20:00Z',
      '2026-08-21T08:25:00Z',
    ]) {
      await processDueReminders(db.d1, env(), deps, now);
    }

    expect(fetchImpl).toHaveBeenCalledTimes(3);
    const notification = await getNotification(db.d1, bookingId, 'reminder');
    expect(notification?.status).toBe('failed');
    expect(notification?.attempt_count).toBe(3);
  });

  it('Messaging API 未設定なら skipped として記録する', async () => {
    const bookingId = await book('1', OUTBOUND_SLUG);
    const fetchImpl = okFetch();

    const summary = await processDueReminders(
      db.d1,
      env({ LINE_MESSAGING_CHANNEL_ACCESS_TOKEN: undefined }),
      { fetchImpl: fetchImpl as unknown as typeof fetch },
      AFTER_REMINDER,
    );

    expect(summary.skipped).toBe(1);
    expect(fetchImpl).not.toHaveBeenCalled();
    const notification = await getNotification(db.d1, bookingId, 'reminder');
    expect(notification?.status).toBe('skipped');
  });
});

describe('予約完了通知', () => {
  it('予約完了直後に送信され、二重送信されない', async () => {
    const bookingId = await book('1', OUTBOUND_SLUG, 3);
    const fetchImpl = okFetch();
    const deps = { fetchImpl: fetchImpl as unknown as typeof fetch };

    const first = await sendBookingConfirmation(db.d1, env(), [bookingId], deps, NOW);
    const second = await sendBookingConfirmation(db.d1, env(), [bookingId], deps, NOW);

    expect(first).toBe('requested');
    expect(second).toBe('already');
    expect(fetchImpl).toHaveBeenCalledTimes(1);

    const call = fetchImpl.mock.calls[0] as unknown as [string, RequestInit];
    const body = JSON.parse(String(call[1].body)) as { messages: { text: string }[] };
    expect(body.messages[0]?.text).toContain('予約が完了しました');
    expect(body.messages[0]?.text).toContain('8月21日（金）20:00');
    expect(body.messages[0]?.text).toContain('予約人数：3名');
  });

  it('管理者代理予約では送信しない', async () => {
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    const created = await createBooking(
      db.d1,
      {
        slotId,
        userId: null,
        source: 'admin',
        representativeName: '電話太郎',
        phone: '0312345678',
        partySize: 1,
        companionNames: [],
        agreed: true,
      },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');

    const fetchImpl = okFetch();
    const outcome = await sendBookingConfirmation(
      db.d1,
      env(),
      [created.bookingId],
      { fetchImpl: fetchImpl as unknown as typeof fetch },
      NOW,
    );

    expect(outcome).toBe('skipped');
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it('通知失敗でも予約は維持される', async () => {
    const bookingId = await book('1', OUTBOUND_SLUG);
    const fetchImpl = failFetch(500);

    await sendBookingConfirmation(
      db.d1,
      env(),
      [bookingId],
      { fetchImpl: fetchImpl as unknown as typeof fetch },
      NOW,
    );

    const row = await db.d1
      .prepare('SELECT status FROM bookings WHERE id = ?1')
      .bind(bookingId)
      .first<{ status: string }>();
    expect(row?.status).toBe('confirmed');
  });
});
