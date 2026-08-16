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
import { getTripById, insertBookingIfCapacityAllows } from '../src/db/queries';

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

async function setCapacity(tripId: number, capacity: number): Promise<void> {
  await db.d1.prepare('UPDATE trips SET capacity = ?2 WHERE id = ?1').bind(tripId, capacity).run();
}

/** 直接INSERTして席を埋める（ユーザーごとに1件までなので複数ユーザーを使う）。 */
async function fillSeats(tripId: number, seats: number, prefix = 'FILL'): Promise<void> {
  let remaining = seats;
  let index = 0;
  while (remaining > 0) {
    const size = Math.min(4, remaining);
    const userId = await createTestUser(db.d1, `${prefix}-${tripId}-${index}`);
    const result = await createBooking(
      db.d1,
      {
        ...base,
        tripId,
        userId,
        partySize: size,
        companionNames: ['A', 'B', 'C'].slice(0, size - 1),
      },
      NOW,
    );
    if (!result.ok) throw new Error(`fillSeats failed: ${result.code}`);
    remaining -= size;
    index += 1;
  }
}

describe('定員制御', () => {
  it('残席ぴったりの予約は成功する', async () => {
    const tripId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    await setCapacity(tripId, 10);
    await fillSeats(tripId, 7);

    const userId = await createTestUser(db.d1, 'LAST');
    const result = await createBooking(
      db.d1,
      { ...base, tripId, userId, partySize: 3, companionNames: ['A', 'B'] },
      NOW,
    );

    expect(result.ok).toBe(true);
    const trip = await getTripById(db.d1, tripId, NOW);
    expect(trip?.booked_seats).toBe(10);
    expect(trip?.remaining_seats).toBe(0);
    expect(trip?.is_full).toBe(true);
  });

  it('残席を超える予約は拒否される', async () => {
    const tripId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    await setCapacity(tripId, 10);
    await fillSeats(tripId, 8);

    const userId = await createTestUser(db.d1, 'OVER');
    const result = await createBooking(
      db.d1,
      { ...base, tripId, userId, partySize: 3, companionNames: ['A', 'B'] },
      NOW,
    );

    expect(result).toMatchObject({ ok: false, code: 'FULL' });
    const trip = await getTripById(db.d1, tripId, NOW);
    expect(trip?.booked_seats).toBe(8);
  });

  it('capacity=40 / booked=39 / request=2 を拒否する', async () => {
    const tripId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    await setCapacity(tripId, 40);
    await fillSeats(tripId, 39);

    const trip = await getTripById(db.d1, tripId, NOW);
    expect(trip?.booked_seats).toBe(39);
    expect(trip?.remaining_seats).toBe(1);

    const userId = await createTestUser(db.d1, 'OVER40');
    const result = await createBooking(
      db.d1,
      { ...base, tripId, userId, partySize: 2, companionNames: ['A'] },
      NOW,
    );

    expect(result).toMatchObject({ ok: false, code: 'FULL' });
    const after = await getTripById(db.d1, tripId, NOW);
    expect(after?.booked_seats).toBe(39);
  });

  it('DBレベル（トリガー）でも定員超過INSERTを拒否する', async () => {
    const tripId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    await setCapacity(tripId, 4);
    await fillSeats(tripId, 3);

    // 条件付きINSERTを迂回して素のINSERTを試みる
    await expect(
      db.d1
        .prepare(
          `INSERT INTO bookings
             (trip_id, user_id, source, representative_name, phone, party_size, status, created_at, updated_at)
           VALUES (?1, NULL, 'admin', '直接INSERT', '0312345678', 2, 'confirmed', ?2, ?2)`,
        )
        .bind(tripId, NOW)
        .run(),
    ).rejects.toThrow(/CAPACITY_EXCEEDED/);

    const trip = await getTripById(db.d1, tripId, NOW);
    expect(trip?.booked_seats).toBe(3);
  });

  it('cancelled→confirmed へ戻すUPDATEも定員を超えるなら拒否される', async () => {
    const tripId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    await setCapacity(tripId, 4);

    const userA = await createTestUser(db.d1, 'A');
    const created = await createBooking(
      db.d1,
      { ...base, tripId, userId: userA, partySize: 3, companionNames: ['A', 'B'] },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');
    await cancelBooking(db.d1, { bookingId: created.bookingId, userId: userA, asAdmin: true }, NOW);

    // 空いた3席を別のユーザーが押さえる
    await fillSeats(tripId, 3, 'REFILL');

    await expect(
      db.d1
        .prepare(`UPDATE bookings SET status = 'confirmed' WHERE id = ?1`)
        .bind(created.bookingId)
        .run(),
    ).rejects.toThrow(/CAPACITY_EXCEEDED/);
  });

  it('条件付きINSERTは満席時に0行しか追加しない', async () => {
    const tripId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    await setCapacity(tripId, 2);
    await fillSeats(tripId, 2);

    const changes = await insertBookingIfCapacityAllows(
      db.d1,
      {
        tripId,
        userId: null,
        source: 'admin',
        representativeName: 'あふれ',
        phone: '0312345678',
        partySize: 1,
        companionNamesJson: '[]',
        ignoreBookingWindow: true,
      },
      NOW,
    );
    expect(changes).toBe(0);
  });

  it('キャンセルすると残席が即座に回復する', async () => {
    const tripId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    await setCapacity(tripId, 4);

    const userId = await createTestUser(db.d1, 'U1');
    const created = await createBooking(
      db.d1,
      { ...base, tripId, userId, partySize: 4, companionNames: ['A', 'B', 'C'] },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');

    let trip = await getTripById(db.d1, tripId, NOW);
    expect(trip?.remaining_seats).toBe(0);
    expect(trip?.is_bookable).toBe(false);

    await cancelBooking(db.d1, { bookingId: created.bookingId, userId, asAdmin: false }, NOW);

    trip = await getTripById(db.d1, tripId, NOW);
    expect(trip?.remaining_seats).toBe(4);
    expect(trip?.is_full).toBe(false);
    expect(trip?.is_bookable).toBe(true);
  });

  it('行きが満席でも帰りは予約できる', async () => {
    const outbound = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    const inbound = await tripIdBySlug(db.d1, RETURN_SLUG);
    await setCapacity(outbound, 4);
    await fillSeats(outbound, 4);

    const outboundTrip = await getTripById(db.d1, outbound, NOW);
    expect(outboundTrip?.is_full).toBe(true);

    const userId = await createTestUser(db.d1, 'RETURN-ONLY');
    const result = await createBooking(
      db.d1,
      { ...base, tripId: inbound, userId, partySize: 3, companionNames: ['A', 'B'] },
      NOW,
    );
    expect(result.ok).toBe(true);
  });

  it('同時に複数リクエストが来ても定員を超えない', async () => {
    const tripId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    await setCapacity(tripId, 5);

    const userIds = await Promise.all(
      Array.from({ length: 5 }, (_, i) => createTestUser(db.d1, `RACE-${i}`)),
    );

    const results = await Promise.all(
      userIds.map((userId) =>
        createBooking(
          db.d1,
          { ...base, tripId, userId, partySize: 2, companionNames: ['A'] },
          NOW,
        ),
      ),
    );

    const succeeded = results.filter((result) => result.ok).length;
    expect(succeeded).toBe(2);

    const trip = await getTripById(db.d1, tripId, NOW);
    expect(trip?.booked_seats).toBeLessThanOrEqual(5);
    expect(trip?.booked_seats).toBe(4);
  });
});
