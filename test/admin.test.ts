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
  type TestDatabase,
} from './helpers/db';
import { TestClient, testEnv } from './helpers/client';
import { createGroupBooking } from '../src/services/booking-service';
import { getPageBySlug, getSlotById, listSlotsByPage } from '../src/db/queries';

let db: TestDatabase;
let client: TestClient;

beforeEach(async () => {
  db = createTestDb();
  client = new TestClient(testEnv({ DB: db.d1 }));
  const csrf = await client.csrfTokenFrom('/admin/login');
  await client.post('/admin/login', {
    csrf_token: csrf,
    username: 'staff',
    password: 'staff-password',
  });
});

afterEach(() => {
  db.close();
});

/** 管理画面のフォームからCSRFトークンを取得する。 */
async function token(path: string): Promise<string> {
  return await client.csrfTokenFrom(path);
}

describe('予約ページの管理', () => {
  it('予約ページを作成できる', async () => {
    const csrf = await token('/admin/reservations/new');
    const response = await client.post('/admin/reservations', {
      csrf_token: csrf,
      slug: 'private-sauna',
      title: '貸切サウナ',
      description: '90分入替制',
      status: 'published',
      page_type: 'time_slot',
      checkin_label: '受付',
      requires_line_login: '1',
      allow_multi_slot_booking: '1',
      max_slots_per_checkout: '2',
    });

    expect(response.status).toBe(303);
    expect(response.headers.get('location')).toContain('msg=page_created');

    const page = await getPageBySlug(db.d1, 'private-sauna');
    expect(page?.title).toBe('貸切サウナ');
    expect(page?.page_type).toBe('time_slot');
    expect(page?.max_slots_per_checkout).toBe(2);
    expect(page?.status).toBe('published');
  });

  it('slugが重複する予約ページは作れない', async () => {
    const csrf = await token('/admin/reservations/new');
    const response = await client.post('/admin/reservations', {
      csrf_token: csrf,
      slug: RAKKO_PAGE_SLUG,
      title: '重複',
      status: 'draft',
      page_type: 'other',
      max_slots_per_checkout: '4',
    });
    expect(response.headers.get('location')).toContain('msg=slug_taken');
  });

  it('不正なslugは拒否される', async () => {
    const csrf = await token('/admin/reservations/new');
    const response = await client.post('/admin/reservations', {
      csrf_token: csrf,
      slug: '日本語スラッグ',
      title: 'テスト',
      status: 'draft',
      page_type: 'other',
      max_slots_per_checkout: '4',
    });
    expect(response.headers.get('location')).toContain('msg=slug_invalid');
  });

  it('ページを非公開（受付停止）にできる', async () => {
    const pageId = await pageIdBySlug(db.d1, RAKKO_PAGE_SLUG);
    const csrf = await token('/admin/reservations');
    const response = await client.post(`/admin/reservations/${pageId}/status`, {
      csrf_token: csrf,
      status: 'closed',
    });
    expect(response.status).toBe(303);

    const page = await getPageBySlug(db.d1, RAKKO_PAGE_SLUG);
    expect(page?.status).toBe('closed');

    // 公開トップからも消える
    const top = await client.get('/');
    expect(await top.text()).not.toContain('らっこ号 池袋便');
  });

  it('ページと予約枠をまとめて複製できる', async () => {
    const pageId = await pageIdBySlug(db.d1, RAKKO_PAGE_SLUG);
    const csrf = await token('/admin/reservations');
    const response = await client.post(`/admin/reservations/${pageId}/duplicate`, {
      csrf_token: csrf,
    });
    expect(response.headers.get('location')).toContain('msg=page_duplicated');

    const copy = await getPageBySlug(db.d1, `${RAKKO_PAGE_SLUG}-copy`);
    expect(copy).not.toBeNull();
    // 複製は下書きから始める
    expect(copy?.status).toBe('draft');
    const slots = await listSlotsByPage(db.d1, copy!.id, NOW);
    expect(slots.map((slot) => slot.name)).toEqual(['行き', '帰り']);
  });
});

describe('予約枠の管理', () => {
  it('予約枠を追加できる', async () => {
    const pageId = await pageIdBySlug(db.d1, RAKKO_PAGE_SLUG);
    const csrf = await token(`/admin/reservations/${pageId}`);

    const response = await client.post(`/admin/reservations/${pageId}/slots`, {
      csrf_token: csrf,
      name: '追加便',
      description: '増便',
      start_at: '2026-08-21T22:00',
      capacity: '24',
      max_party_size: '4',
      booking_status: 'open',
      sort_order: '3',
      origin: '池袋西口',
      destination: '草加健康センター',
      reminder_at: '2026-08-21T19:00',
    });
    expect(response.headers.get('location')).toContain('msg=slot_created');

    const slots = await listSlotsByPage(db.d1, pageId, NOW);
    const added = slots.find((slot) => slot.name === '追加便');
    expect(added).toBeDefined();
    // JSTで入力した値がUTCで保存される
    expect(added?.start_at).toBe('2026-08-21T13:00:00Z');
    expect(added?.reminder_at).toBe('2026-08-21T10:00:00Z');
    expect(added?.capacity).toBe(24);
  });

  it('日付・時刻・定員・リマインドを変更できる', async () => {
    const slotId = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const csrf = await token(`/admin/slots/${slotId}`);

    const response = await client.post(`/admin/slots/${slotId}`, {
      csrf_token: csrf,
      name: '行き',
      start_at: '2026-08-21T19:30',
      capacity: '24',
      max_party_size: '4',
      booking_status: 'open',
      sort_order: '1',
      origin: '池袋西口 マクドナルド前辺り',
      destination: '草加健康センター',
      reminder_at: '2026-08-21T16:00',
      booking_close_at: '2026-08-21T12:00',
    });
    expect(response.headers.get('location')).toContain('msg=saved');

    const slot = await getSlotById(db.d1, slotId, NOW);
    expect(slot?.start_at).toBe('2026-08-21T10:30:00Z');
    expect(slot?.capacity).toBe(24);
    expect(slot?.reminder_at).toBe('2026-08-21T07:00:00Z');
    expect(slot?.booking_close_at).toBe('2026-08-21T03:00:00Z');
  });

  it('既存予約人数を下回る定員には変更できない', async () => {
    const pageId = await pageIdBySlug(db.d1, RAKKO_PAGE_SLUG);
    const slotId = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const userId = await createTestUser(db.d1, 'U1');
    await createGroupBooking(
      db.d1,
      {
        pageId,
        userId,
        source: 'line',
        representativeName: '山田太郎',
        phone: '09011112222',
        agreed: true,
        items: [{ slotId, partySize: 4, companionNames: ['A', 'B', 'C'] }],
      },
      NOW,
    );

    const csrf = await token(`/admin/slots/${slotId}`);
    const response = await client.post(`/admin/slots/${slotId}`, {
      csrf_token: csrf,
      name: '行き',
      start_at: '2026-08-21T20:00',
      capacity: '2',
      max_party_size: '4',
      booking_status: 'open',
      sort_order: '1',
    });
    expect(response.headers.get('location')).toContain('msg=capacity_too_small');
    expect((await getSlotById(db.d1, slotId, NOW))?.capacity).toBe(40);
  });

  it('受付停止にすると公開ページから予約できなくなる', async () => {
    const slotId = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const csrf = await token(`/admin/slots/${slotId}`);

    await client.post(`/admin/slots/${slotId}`, {
      csrf_token: csrf,
      name: '行き',
      start_at: '2026-08-21T20:00',
      capacity: '40',
      max_party_size: '4',
      booking_status: 'closed',
      sort_order: '1',
    });

    const slot = await getSlotById(db.d1, slotId, NOW);
    expect(slot?.booking_status).toBe('closed');
    expect(slot?.is_bookable).toBe(false);
  });
});

describe('受付人数の管理', () => {
  it('op=inc / dec / all で受付人数を更新できる', async () => {
    const pageId = await createTestPage(db.d1);
    const slotId = await createTestSlot(db.d1, pageId, { capacity: 10 });
    const userId = await createTestUser(db.d1, 'U1');
    const created = await createGroupBooking(
      db.d1,
      {
        pageId,
        userId,
        source: 'line',
        representativeName: '山田太郎',
        phone: '09011112222',
        agreed: true,
        items: [{ slotId, partySize: 3, companionNames: ['A', 'B'] }],
      },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');
    const bookingId = created.bookingIds[0] as number;

    const csrf = await token(`/admin/slots/${slotId}`);
    const checkedIn = async (): Promise<number> => {
      const row = await db.d1
        .prepare('SELECT checked_in_count AS c FROM bookings WHERE id = ?1')
        .bind(bookingId)
        .first<{ c: number }>();
      return row?.c ?? -1;
    };

    await client.post(`/admin/bookings/${bookingId}/checkin`, {
      csrf_token: csrf,
      slot_id: String(slotId),
      op: 'inc',
    });
    expect(await checkedIn()).toBe(1);

    await client.post(`/admin/bookings/${bookingId}/checkin`, {
      csrf_token: csrf,
      slot_id: String(slotId),
      op: 'dec',
    });
    expect(await checkedIn()).toBe(0);

    await client.post(`/admin/bookings/${bookingId}/checkin`, {
      csrf_token: csrf,
      slot_id: String(slotId),
      op: 'all',
    });
    expect(await checkedIn()).toBe(3);

    // party_size を超えない
    await client.post(`/admin/bookings/${bookingId}/checkin`, {
      csrf_token: csrf,
      slot_id: String(slotId),
      op: 'inc',
    });
    expect(await checkedIn()).toBe(3);
  });
});

describe('管理者代理予約', () => {
  it('LINEログインなしで登録でき、定員管理は同じ', async () => {
    const pageId = await createTestPage(db.d1);
    const slotId = await createTestSlot(db.d1, pageId, { capacity: 3 });
    const csrf = await token(`/admin/slots/${slotId}`);

    const ok = await client.post(`/admin/slots/${slotId}/bookings`, {
      csrf_token: csrf,
      representative_name: '電話 太郎',
      phone: '03-1234-5678',
      party_size: '2',
      companion_names_text: '同行1',
    });
    expect(ok.headers.get('location')).toContain('msg=booking_created');

    const row = await db.d1
      .prepare('SELECT user_id, source, party_size FROM bookings WHERE reservation_slot_id = ?1')
      .bind(slotId)
      .first<{ user_id: number | null; source: string; party_size: number }>();
    expect(row?.user_id).toBeNull();
    expect(row?.source).toBe('admin');

    // 残席を超える代理予約は拒否される
    const over = await client.post(`/admin/slots/${slotId}/bookings`, {
      csrf_token: csrf,
      representative_name: '電話 次郎',
      phone: '03-1234-5678',
      party_size: '2',
      companion_names_text: '同行2',
    });
    expect(over.headers.get('location')).toContain('msg=trip_full');
    expect((await getSlotById(db.d1, slotId, NOW))?.booked_seats).toBe(2);
  });
});

describe('名簿CSVのダウンロード', () => {
  it('予約枠CSVとページ全体CSVを取得できる', async () => {
    const pageId = await createTestPage(db.d1, { slug: 'csv-page', title: 'CSVテスト' });
    const slotId = await createTestSlot(db.d1, pageId, { name: '第1回', capacity: 10 });
    const userId = await createTestUser(db.d1, 'U1');
    await createGroupBooking(
      db.d1,
      {
        pageId,
        userId,
        source: 'line',
        representativeName: '山田太郎',
        phone: '09011112222',
        agreed: true,
        items: [{ slotId, partySize: 2, companionNames: ['山田花子'] }],
      },
      NOW,
    );

    const slotCsv = await client.get(`/admin/reservation-slots/${slotId}/roster.csv`);
    expect(slotCsv.status).toBe(200);
    expect(slotCsv.headers.get('content-type')).toContain('text/csv');
    expect(slotCsv.headers.get('content-disposition')).toContain('attachment');
    // Response.text() は仕様上BOMを取り除くため、バイト列で確認する
    const slotBytes = new Uint8Array(await slotCsv.clone().arrayBuffer());
    expect([slotBytes[0], slotBytes[1], slotBytes[2]]).toEqual([0xef, 0xbb, 0xbf]);
    const slotBody = await slotCsv.text();
    expect(slotBody).toContain('山田太郎');
    expect(slotBody).toContain('山田花子');

    const pageCsv = await client.get(`/admin/reservations/${pageId}/roster.csv`);
    expect(pageCsv.status).toBe(200);
    const pageBody = await pageCsv.text();
    expect(pageBody).toContain('"予約枠名"');
    expect(pageBody).toContain('第1回');
  });

  it('未認証ではCSVを取得できない', async () => {
    const anonymous = new TestClient(testEnv({ DB: db.d1 }));
    const slotId = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const response = await anonymous.get(`/admin/reservation-slots/${slotId}/roster.csv`);
    expect(response.status).toBe(303);
    expect(response.headers.get('location')).toContain('/admin/login');
  });
});

describe('旧URLの互換', () => {
  it('/admin/trips/:slug は新しい予約枠画面へリダイレクトする', async () => {
    const slotId = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const response = await client.get(`/admin/trips/${OUTBOUND_SLUG}`);
    expect(response.status).toBe(303);
    expect(response.headers.get('location')).toBe(`/admin/slots/${slotId}`);
  });
});
