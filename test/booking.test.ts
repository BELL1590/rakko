import { beforeEach, afterEach, describe, expect, it } from 'vitest';
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
import { listBookingsByUser } from '../src/db/queries';

let db: TestDatabase;

const base = {
  representativeName: '山田太郎',
  phone: '090-1234-5678',
  agreed: true,
  source: 'line' as const,
};

beforeEach(() => {
  db = createTestDb();
});

afterEach(() => {
  db.close();
});

describe('予約作成', () => {
  it('1名予約が成功する', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);

    const result = await createBooking(
      db.d1,
      { ...base, slotId, userId, partySize: 1, companionNames: [] },
      NOW,
    );

    expect(result.ok).toBe(true);
  });

  it('4名予約が成功し、同行者3名が保存される', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);

    const result = await createBooking(
      db.d1,
      {
        ...base,
        slotId,
        userId,
        partySize: 4,
        companionNames: ['同行者A', '同行者B', '同行者C'],
      },
      NOW,
    );

    expect(result.ok).toBe(true);
    const row = await db.d1
      .prepare('SELECT party_size, companion_names_json FROM bookings WHERE reservation_slot_id = ?1')
      .bind(slotId)
      .first<{ party_size: number; companion_names_json: string }>();
    expect(row?.party_size).toBe(4);
    expect(JSON.parse(row!.companion_names_json)).toEqual(['同行者A', '同行者B', '同行者C']);
  });

  it('0名は拒否される', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);

    const result = await createBooking(
      db.d1,
      { ...base, slotId, userId, partySize: 0, companionNames: [] },
      NOW,
    );

    expect(result).toMatchObject({ ok: false, code: 'VALIDATION' });
  });

  it('5名以上は拒否される', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);

    const result = await createBooking(
      db.d1,
      {
        ...base,
        slotId,
        userId,
        partySize: 5,
        companionNames: ['A', 'B', 'C', 'D'],
      },
      NOW,
    );

    expect(result).toMatchObject({ ok: false, code: 'VALIDATION' });
  });

  it('同行者名が足りない場合は拒否される', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);

    const result = await createBooking(
      db.d1,
      { ...base, slotId, userId, partySize: 3, companionNames: ['A'] },
      NOW,
    );

    expect(result).toMatchObject({ ok: false, code: 'VALIDATION' });
  });

  it('注意事項未同意は拒否される', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);

    const result = await createBooking(
      db.d1,
      { ...base, agreed: false, slotId, userId, partySize: 1, companionNames: [] },
      NOW,
    );

    expect(result).toMatchObject({ ok: false, code: 'NOT_AGREED' });
  });

  it('電話番号の形式が不正な場合は拒否される', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);

    const result = await createBooking(
      db.d1,
      { ...base, phone: '123', slotId, userId, partySize: 1, companionNames: [] },
      NOW,
    );

    expect(result).toMatchObject({ ok: false, code: 'VALIDATION' });
  });
});

describe('行きと帰りの独立性', () => {
  it('行き予約と帰り予約は別レコードとして保持される', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const outbound = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    const inbound = await tripIdBySlug(db.d1, RETURN_SLUG);

    const a = await createBooking(
      db.d1,
      { ...base, slotId: outbound, userId, partySize: 3, companionNames: ['A', 'B'] },
      NOW,
    );
    const b = await createBooking(
      db.d1,
      { ...base, slotId: inbound, userId, partySize: 2, companionNames: ['A'] },
      NOW,
    );

    expect(a.ok).toBe(true);
    expect(b.ok).toBe(true);

    const bookings = await listBookingsByUser(db.d1, userId);
    expect(bookings).toHaveLength(2);
    expect(bookings.map((booking) => booking.slot_name).sort()).toEqual(['帰り', '行き']);
  });

  it('行きだけ・帰りだけの予約もできる', async () => {
    const userA = await createTestUser(db.d1, 'UA');
    const userB = await createTestUser(db.d1, 'UB');
    const outbound = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    const inbound = await tripIdBySlug(db.d1, RETURN_SLUG);

    expect(
      (
        await createBooking(
          db.d1,
          { ...base, slotId: outbound, userId: userA, partySize: 1, companionNames: [] },
          NOW,
        )
      ).ok,
    ).toBe(true);
    expect(
      (
        await createBooking(
          db.d1,
          { ...base, slotId: inbound, userId: userB, partySize: 1, companionNames: [] },
          NOW,
        )
      ).ok,
    ).toBe(true);
  });
});

describe('二重予約の防止', () => {
  it('同一LINEユーザーの同一便二重予約を拒否する', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);

    const first = await createBooking(
      db.d1,
      { ...base, slotId, userId, partySize: 1, companionNames: [] },
      NOW,
    );
    const second = await createBooking(
      db.d1,
      { ...base, slotId, userId, partySize: 2, companionNames: ['A'] },
      NOW,
    );

    expect(first.ok).toBe(true);
    expect(second).toMatchObject({ ok: false, code: 'DUPLICATE' });

    const count = await db.d1
      .prepare(`SELECT COUNT(*) AS c FROM bookings WHERE status = 'confirmed'`)
      .first<{ c: number }>();
    expect(count?.c).toBe(1);
  });

  it('キャンセル後は同じ便を再予約できる', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);

    const first = await createBooking(
      db.d1,
      { ...base, slotId, userId, partySize: 2, companionNames: ['A'] },
      NOW,
    );
    expect(first.ok).toBe(true);
    if (!first.ok) return;

    const cancelled = await cancelBooking(
      db.d1,
      { bookingId: first.bookingId, userId, asAdmin: false },
      NOW,
    );
    expect(cancelled.ok).toBe(true);

    const second = await createBooking(
      db.d1,
      { ...base, slotId, userId, partySize: 4, companionNames: ['A', 'B', 'C'] },
      NOW,
    );
    expect(second.ok).toBe(true);
  });
});

describe('キャンセル', () => {
  it('物理削除せず cancelled_at を記録する', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    const created = await createBooking(
      db.d1,
      { ...base, slotId, userId, partySize: 1, companionNames: [] },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');

    await cancelBooking(db.d1, { bookingId: created.bookingId, userId, asAdmin: false }, NOW);

    const row = await db.d1
      .prepare('SELECT status, cancelled_at FROM bookings WHERE id = ?1')
      .bind(created.bookingId)
      .first<{ status: string; cancelled_at: string | null }>();
    expect(row?.status).toBe('cancelled');
    expect(row?.cancelled_at).toBe(NOW);
  });

  it('出発後はキャンセルできない', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    const created = await createBooking(
      db.d1,
      { ...base, slotId, userId, partySize: 1, companionNames: [] },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');

    const afterDeparture = '2026-08-22T00:00:00Z';
    const result = await cancelBooking(
      db.d1,
      { bookingId: created.bookingId, userId, asAdmin: false },
      afterDeparture,
    );
    expect(result).toMatchObject({ ok: false, code: 'DEPARTED' });
  });

  it('二重キャンセルは拒否される', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    const created = await createBooking(
      db.d1,
      { ...base, slotId, userId, partySize: 1, companionNames: [] },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');

    await cancelBooking(db.d1, { bookingId: created.bookingId, userId, asAdmin: false }, NOW);
    const again = await cancelBooking(
      db.d1,
      { bookingId: created.bookingId, userId, asAdmin: false },
      NOW,
    );
    expect(again).toMatchObject({ ok: false, code: 'ALREADY_CANCELLED' });
  });
});

describe('管理者代理予約', () => {
  it('user_id なし・source=admin で登録でき、受付停止中でも登録できる', async () => {
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    await db.d1
      .prepare(`UPDATE reservation_slots SET booking_status = 'closed' WHERE id = ?1`)
      .bind(slotId)
      .run();

    const result = await createBooking(
      db.d1,
      {
        slotId,
        userId: null,
        source: 'admin',
        representativeName: '電話 太郎',
        phone: '0312345678',
        partySize: 2,
        companionNames: ['同行者A'],
        agreed: true,
      },
      NOW,
    );

    expect(result.ok).toBe(true);
    const row = await db.d1
      .prepare('SELECT user_id, source FROM bookings WHERE reservation_slot_id = ?1')
      .bind(slotId)
      .first<{ user_id: number | null; source: string }>();
    expect(row?.user_id).toBeNull();
    expect(row?.source).toBe('admin');
  });

  it('受付停止中は一般ユーザーの予約を拒否する', async () => {
    const userId = await createTestUser(db.d1, 'U1');
    const slotId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    await db.d1
      .prepare(`UPDATE reservation_slots SET booking_status = 'closed' WHERE id = ?1`)
      .bind(slotId)
      .run();

    const result = await createBooking(
      db.d1,
      { ...base, slotId, userId, partySize: 1, companionNames: [] },
      NOW,
    );
    expect(result).toMatchObject({ ok: false, code: 'CLOSED' });
  });
});
