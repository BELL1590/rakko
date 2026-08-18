import { beforeEach, afterEach, describe, expect, it } from 'vitest';
import {
  createTestDb,
  createTestPage,
  createTestSlot,
  createTestUser,
  pageIdBySlug,
  slotIdByLegacyTripSlug,
  NOW,
  OUTBOUND_SLUG,
  RAKKO_PAGE_SLUG,
  RETURN_SLUG,
  type TestDatabase,
} from './helpers/db';
import { createGroupBooking } from '../src/services/booking-service';
import {
  createReservationPage,
  createReservationSlot,
  getPageBySlug,
  getSlotById,
  listBookingsByGroup,
  listSlotsByPage,
  updateReservationSlot,
  type SlotInput,
} from '../src/db/queries';

let db: TestDatabase;

const contact = {
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

function slotInput(overrides: Partial<SlotInput> = {}): SlotInput {
  return {
    name: '第1回',
    description: '',
    startAt: '2026-09-01T04:00:00Z',
    endAt: null,
    origin: null,
    destination: null,
    location: '大広間',
    capacity: 10,
    maxPartySize: 4,
    bookingOpenAt: null,
    bookingCloseAt: null,
    reminderAt: null,
    bookingStatus: 'open',
    sortOrder: 1,
    ...overrides,
  };
}

describe('既存の池袋便データの移行', () => {
  it('予約ページと行き/帰りの枠へ移行されている', async () => {
    const page = await getPageBySlug(db.d1, RAKKO_PAGE_SLUG);
    expect(page).not.toBeNull();
    expect(page?.title).toBe('らっこ号 池袋便');
    expect(page?.page_type).toBe('bus');
    expect(page?.status).toBe('published');

    const slots = await listSlotsByPage(db.d1, page!.id, NOW);
    expect(slots.map((slot) => slot.name)).toEqual(['行き', '帰り']);
    // 定員40席・JST 20:00 / 8:10 がそのまま引き継がれている
    expect(slots[0]?.capacity).toBe(40);
    expect(slots[0]?.start_at).toBe('2026-08-21T11:00:00Z');
    expect(slots[0]?.reminder_at).toBe('2026-08-21T08:00:00Z');
    expect(slots[1]?.start_at).toBe('2026-08-21T23:10:00Z');
    expect(slots[1]?.capacity).toBe(40);
  });

  it('旧 trips の slug から枠を解決できる', async () => {
    const outbound = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const inbound = await slotIdByLegacyTripSlug(db.d1, RETURN_SLUG);
    expect(outbound).toBeGreaterThan(0);
    expect(inbound).not.toBe(outbound);
  });
});

describe('予約ページ・予約枠の作成', () => {
  it('バス以外の予約ページを作成できる', async () => {
    const pageId = await createReservationPage(db.d1, {
      slug: 'aufguss-0825',
      title: '鮭山未菜美 アウフグースイベント',
      description: '各回入替制',
      status: 'published',
      pageType: 'event',
      allowMultiSlotBooking: true,
      requiresLineLogin: true,
      maxSlotsPerCheckout: 3,
      checkinLabel: '受付',
    });
    expect(pageId).toBeGreaterThan(0);

    const page = await getPageBySlug(db.d1, 'aufguss-0825');
    expect(page?.title).toBe('鮭山未菜美 アウフグースイベント');
    expect(page?.page_type).toBe('event');
    expect(page?.max_slots_per_checkout).toBe(3);
  });

  it('1ページに複数の予約枠を追加できる', async () => {
    const pageId = await createTestPage(db.d1, { slug: 'aufguss-multi' });
    await createReservationSlot(db.d1, pageId, slotInput({ name: '13:00回', sortOrder: 1 }));
    await createReservationSlot(
      db.d1,
      pageId,
      slotInput({ name: '15:00回', startAt: '2026-09-01T06:00:00Z', sortOrder: 2 }),
    );
    await createReservationSlot(
      db.d1,
      pageId,
      slotInput({ name: '17:00回', startAt: '2026-09-01T08:00:00Z', sortOrder: 3 }),
    );

    const slots = await listSlotsByPage(db.d1, pageId, NOW);
    expect(slots.map((slot) => slot.name)).toEqual(['13:00回', '15:00回', '17:00回']);
  });

  it('枠ごとに残席が独立して管理される', async () => {
    const pageId = await createTestPage(db.d1);
    const a = await createTestSlot(db.d1, pageId, { name: 'A', capacity: 5 });
    const b = await createTestSlot(db.d1, pageId, { name: 'B', capacity: 8, sortOrder: 2 });

    const userId = await createTestUser(db.d1, 'U1');
    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [{ slotId: a, partySize: 2, companionNames: ['同行A'] }],
      },
      NOW,
    );
    expect(result.ok).toBe(true);

    expect((await getSlotById(db.d1, a, NOW))?.remaining_seats).toBe(3);
    expect((await getSlotById(db.d1, b, NOW))?.remaining_seats).toBe(8);
  });
});

describe('管理画面からの予約枠変更', () => {
  it('日付・時刻・定員・最大人数・リマインド・受付期間を変更できる', async () => {
    const pageId = await createTestPage(db.d1);
    const slotId = await createTestSlot(db.d1, pageId, { capacity: 10 });

    await updateReservationSlot(
      db.d1,
      slotId,
      slotInput({
        name: '第1回（変更後）',
        startAt: '2026-09-05T05:30:00Z',
        endAt: '2026-09-05T06:30:00Z',
        capacity: 24,
        maxPartySize: 6,
        bookingOpenAt: '2026-08-20T00:00:00Z',
        bookingCloseAt: '2026-09-04T15:00:00Z',
        reminderAt: '2026-09-05T02:00:00Z',
        bookingStatus: 'closed',
        sortOrder: 5,
      }),
    );

    const slot = await getSlotById(db.d1, slotId, NOW);
    expect(slot?.name).toBe('第1回（変更後）');
    expect(slot?.start_at).toBe('2026-09-05T05:30:00Z');
    expect(slot?.end_at).toBe('2026-09-05T06:30:00Z');
    expect(slot?.capacity).toBe(24);
    expect(slot?.max_party_size).toBe(6);
    expect(slot?.booking_open_at).toBe('2026-08-20T00:00:00Z');
    expect(slot?.booking_close_at).toBe('2026-09-04T15:00:00Z');
    expect(slot?.reminder_at).toBe('2026-09-05T02:00:00Z');
    expect(slot?.booking_status).toBe('closed');
    expect(slot?.is_bookable).toBe(false);
  });

  it('max_party_size を増やすとその人数まで予約できる', async () => {
    const pageId = await createTestPage(db.d1);
    const slotId = await createTestSlot(db.d1, pageId, { capacity: 20, maxPartySize: 4 });
    const userId = await createTestUser(db.d1, 'U1');

    const over = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [{ slotId, partySize: 6, companionNames: ['A', 'B', 'C', 'D', 'E'] }],
      },
      NOW,
    );
    expect(over).toMatchObject({ ok: false, code: 'VALIDATION' });

    await updateReservationSlot(db.d1, slotId, slotInput({ capacity: 20, maxPartySize: 6 }));

    const ok = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [{ slotId, partySize: 6, companionNames: ['A', 'B', 'C', 'D', 'E'] }],
      },
      NOW,
    );
    expect(ok.ok).toBe(true);
  });

  it('受付開始前・締切後は予約できない', async () => {
    const pageId = await createTestPage(db.d1);
    const slotId = await createTestSlot(db.d1, pageId, {
      bookingOpenAt: '2026-08-20T00:00:00Z',
      bookingCloseAt: '2026-08-25T00:00:00Z',
    });
    const userId = await createTestUser(db.d1, 'U1');
    const items = [{ slotId, partySize: 1, companionNames: [] }];

    const beforeOpen = await createGroupBooking(
      db.d1,
      { ...contact, pageId, userId, items },
      '2026-08-19T00:00:00Z',
    );
    expect(beforeOpen).toMatchObject({ ok: false, code: 'CLOSED' });

    const afterClose = await createGroupBooking(
      db.d1,
      { ...contact, pageId, userId, items },
      '2026-08-26T00:00:00Z',
    );
    expect(afterClose).toMatchObject({ ok: false, code: 'CLOSED' });

    const inWindow = await createGroupBooking(
      db.d1,
      { ...contact, pageId, userId, items },
      '2026-08-21T00:00:00Z',
    );
    expect(inWindow.ok).toBe(true);
  });

  it('ページを非公開にすると予約できない', async () => {
    const pageId = await createTestPage(db.d1, { status: 'draft' });
    const slotId = await createTestSlot(db.d1, pageId);
    const userId = await createTestUser(db.d1, 'U1');

    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [{ slotId, partySize: 1, companionNames: [] }],
      },
      NOW,
    );
    expect(result).toMatchObject({ ok: false, code: 'PAGE_CLOSED' });
  });
});

describe('同一ページの複数枠まとめて予約', () => {
  async function rakko(): Promise<{ pageId: number; outbound: number; inbound: number }> {
    return {
      pageId: await pageIdBySlug(db.d1, RAKKO_PAGE_SLUG),
      outbound: await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG),
      inbound: await slotIdByLegacyTripSlug(db.d1, RETURN_SLUG),
    };
  }

  it('単一枠の予約ができる（行きのみ）', async () => {
    const { pageId, outbound, inbound } = await rakko();
    const userId = await createTestUser(db.d1, 'U1');

    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [{ slotId: outbound, partySize: 2, companionNames: ['同行A'] }],
      },
      NOW,
    );

    expect(result.ok).toBe(true);
    if (!result.ok) return;
    // 単一枠なら booking_group_id は付けない
    expect(result.groupId).toBeNull();
    expect(result.bookingIds).toHaveLength(1);
    expect((await getSlotById(db.d1, inbound, NOW))?.booked_seats).toBe(0);
  });

  it('帰りのみの予約ができる', async () => {
    const { pageId, outbound, inbound } = await rakko();
    const userId = await createTestUser(db.d1, 'U1');

    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [{ slotId: inbound, partySize: 1, companionNames: [] }],
      },
      NOW,
    );

    expect(result.ok).toBe(true);
    expect((await getSlotById(db.d1, outbound, NOW))?.booked_seats).toBe(0);
    expect((await getSlotById(db.d1, inbound, NOW))?.booked_seats).toBe(1);
  });

  it('行き+帰りを1回の送信でまとめて予約できる', async () => {
    const { pageId, outbound, inbound } = await rakko();
    const userId = await createTestUser(db.d1, 'U1');

    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [
          { slotId: outbound, partySize: 3, companionNames: ['A', 'B'] },
          { slotId: inbound, partySize: 3, companionNames: ['A', 'B'] },
        ],
      },
      NOW,
    );

    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(result.bookingIds).toHaveLength(2);
    expect(result.groupId).toBeTruthy();

    // DB上は2件の独立した予約
    const group = await listBookingsByGroup(db.d1, result.groupId as string);
    expect(group).toHaveLength(2);
    expect(group.map((booking) => booking.reservation_slot_id).sort()).toEqual(
      [outbound, inbound].sort(),
    );
    expect((await getSlotById(db.d1, outbound, NOW))?.booked_seats).toBe(3);
    expect((await getSlotById(db.d1, inbound, NOW))?.booked_seats).toBe(3);
  });

  it('枠ごとに人数が違う一括予約ができる', async () => {
    const { pageId, outbound, inbound } = await rakko();
    const userId = await createTestUser(db.d1, 'U1');

    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [
          { slotId: outbound, partySize: 4, companionNames: ['A', 'B', 'C'] },
          { slotId: inbound, partySize: 2, companionNames: ['A'] },
        ],
      },
      NOW,
    );

    expect(result.ok).toBe(true);
    expect((await getSlotById(db.d1, outbound, NOW))?.booked_seats).toBe(4);
    expect((await getSlotById(db.d1, inbound, NOW))?.booked_seats).toBe(2);
  });

  it('一方が満席なら全体が失敗し、片方だけ確定しない', async () => {
    const { pageId, outbound, inbound } = await rakko();
    await db.d1
      .prepare('UPDATE reservation_slots SET capacity = 1 WHERE id = ?1')
      .bind(inbound)
      .run();

    const filler = await createTestUser(db.d1, 'FILLER');
    await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId: filler,
        items: [{ slotId: inbound, partySize: 1, companionNames: [] }],
      },
      NOW,
    );

    const userId = await createTestUser(db.d1, 'U1');
    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [
          { slotId: outbound, partySize: 2, companionNames: ['A'] },
          { slotId: inbound, partySize: 2, companionNames: ['A'] },
        ],
      },
      NOW,
    );

    expect(result).toMatchObject({ ok: false, code: 'FULL' });
    if (result.ok) return;
    expect(result.message).toContain('予約は確定していません');
    // 行きも確定していないこと（全枠失敗）
    expect((await getSlotById(db.d1, outbound, NOW))?.booked_seats).toBe(0);
    expect((await getSlotById(db.d1, inbound, NOW))?.booked_seats).toBe(1);
  });

  it('DBトリガー発火時も一括予約全体がロールバックされる', async () => {
    const pageId = await createTestPage(db.d1);
    const a = await createTestSlot(db.d1, pageId, { name: 'A', capacity: 10 });
    const b = await createTestSlot(db.d1, pageId, { name: 'B', capacity: 2, sortOrder: 2 });

    // Bの残席は2。事前チェックを通ってもトリガーで弾かれる状況を作る
    const filler = await createTestUser(db.d1, 'FILLER');
    await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId: filler,
        items: [{ slotId: b, partySize: 2, companionNames: ['A'] }],
      },
      NOW,
    );

    const userId = await createTestUser(db.d1, 'U1');
    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [
          { slotId: a, partySize: 1, companionNames: [] },
          { slotId: b, partySize: 1, companionNames: [] },
        ],
      },
      NOW,
    );

    expect(result.ok).toBe(false);
    expect((await getSlotById(db.d1, a, NOW))?.booked_seats).toBe(0);
    expect((await getSlotById(db.d1, b, NOW))?.booked_seats).toBe(2);
  });

  it('同一ユーザーが同じ枠を含めて再送信すると全体が拒否される', async () => {
    const { pageId, outbound, inbound } = await rakko();
    const userId = await createTestUser(db.d1, 'U1');

    await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [{ slotId: outbound, partySize: 1, companionNames: [] }],
      },
      NOW,
    );

    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [
          { slotId: outbound, partySize: 1, companionNames: [] },
          { slotId: inbound, partySize: 1, companionNames: [] },
        ],
      },
      NOW,
    );

    expect(result).toMatchObject({ ok: false, code: 'DUPLICATE' });
    // 帰りも確定していない
    expect((await getSlotById(db.d1, inbound, NOW))?.booked_seats).toBe(0);
  });

  it('選択が空なら拒否される', async () => {
    const { pageId } = await rakko();
    const userId = await createTestUser(db.d1, 'U1');
    const result = await createGroupBooking(
      db.d1,
      { ...contact, pageId, userId, items: [] },
      NOW,
    );
    expect(result).toMatchObject({ ok: false, code: 'NO_SELECTION' });
  });

  it('max_slots_per_checkout を超える選択は拒否される', async () => {
    const pageId = await createTestPage(db.d1, { maxSlots: 2 });
    const slotIds = [
      await createTestSlot(db.d1, pageId, { name: 'A' }),
      await createTestSlot(db.d1, pageId, { name: 'B', sortOrder: 2 }),
      await createTestSlot(db.d1, pageId, { name: 'C', sortOrder: 3 }),
    ];
    const userId = await createTestUser(db.d1, 'U1');

    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: slotIds.map((slotId) => ({ slotId, partySize: 1, companionNames: [] })),
      },
      NOW,
    );
    expect(result).toMatchObject({ ok: false, code: 'TOO_MANY_SLOTS' });
  });

  it('複数枠予約を許可しないページでは1枠しか選べない', async () => {
    const pageId = await createTestPage(db.d1, { allowMulti: 0 });
    const a = await createTestSlot(db.d1, pageId, { name: 'A' });
    const b = await createTestSlot(db.d1, pageId, { name: 'B', sortOrder: 2 });
    const userId = await createTestUser(db.d1, 'U1');

    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId,
        userId,
        items: [
          { slotId: a, partySize: 1, companionNames: [] },
          { slotId: b, partySize: 1, companionNames: [] },
        ],
      },
      NOW,
    );
    expect(result).toMatchObject({ ok: false, code: 'TOO_MANY_SLOTS' });
  });

  it('他ページの枠を混ぜた予約は拒否される', async () => {
    const pageA = await createTestPage(db.d1, { slug: 'page-a' });
    const pageB = await createTestPage(db.d1, { slug: 'page-b' });
    const slotA = await createTestSlot(db.d1, pageA, { name: 'A' });
    const slotB = await createTestSlot(db.d1, pageB, { name: 'B' });
    const userId = await createTestUser(db.d1, 'U1');

    const result = await createGroupBooking(
      db.d1,
      {
        ...contact,
        pageId: pageA,
        userId,
        items: [
          { slotId: slotA, partySize: 1, companionNames: [] },
          { slotId: slotB, partySize: 1, companionNames: [] },
        ],
      },
      NOW,
    );
    expect(result).toMatchObject({ ok: false, code: 'SLOT_NOT_FOUND' });
  });

  it('同時アクセスでも枠の定員を超えない', async () => {
    const pageId = await createTestPage(db.d1);
    const slotId = await createTestSlot(db.d1, pageId, { capacity: 5 });

    const userIds = await Promise.all(
      Array.from({ length: 5 }, (_, i) => createTestUser(db.d1, `RACE-${i}`)),
    );

    const results = await Promise.all(
      userIds.map((userId) =>
        createGroupBooking(
          db.d1,
          {
            ...contact,
            pageId,
            userId,
            items: [{ slotId, partySize: 2, companionNames: ['A'] }],
          },
          NOW,
        ),
      ),
    );

    expect(results.filter((result) => result.ok)).toHaveLength(2);
    const slot = await getSlotById(db.d1, slotId, NOW);
    expect(slot?.booked_seats).toBeLessThanOrEqual(5);
    expect(slot?.booked_seats).toBe(4);
  });
});
