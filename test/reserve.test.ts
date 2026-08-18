/** 公開予約ページ（/reserve/:slug）のHTTPレベルの検証。 */

import { beforeEach, afterEach, describe, expect, it } from 'vitest';
import {
  createTestDb,
  createTestPage,
  createTestSlot,
  slotIdByLegacyTripSlug,
  NOW,
  OUTBOUND_SLUG,
  RAKKO_PAGE_SLUG,
  RETURN_SLUG,
  type TestDatabase,
} from './helpers/db';
import { TestClient, testEnv } from './helpers/client';
import { getSlotById } from '../src/db/queries';

let db: TestDatabase;
let client: TestClient;

beforeEach(async () => {
  db = createTestDb();
  client = new TestClient(testEnv({ DB: db.d1 }));
  const csrf = await client.csrfTokenFrom('/login');
  await client.post('/auth/demo/login', {
    csrf_token: csrf,
    demo_user_id: 'demo-user-001',
    demo_display_name: 'デモユーザー',
    redirect_to: '/',
  });
});

afterEach(() => {
  db.close();
});

async function csrfFor(path: string): Promise<string> {
  return await client.csrfTokenFrom(path);
}

describe('公開トップ', () => {
  it('公開中の予約ページが一覧に出る', async () => {
    const response = await client.get('/');
    const html = await response.text();
    expect(response.status).toBe(200);
    expect(html).toContain('らっこ号 池袋便');
    expect(html).toContain('/reserve/rakko-ikebukuro');
  });

  it('下書きのページは公開一覧に出ない', async () => {
    await createTestPage(db.d1, { slug: 'hidden-page', title: '内部イベント', status: 'draft' });
    const html = await (await client.get('/')).text();
    expect(html).not.toContain('内部イベント');
  });
});

describe('予約ページの表示', () => {
  it('枠ごとの選択チェックボックスと人数ラジオが出る', async () => {
    const outbound = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const inbound = await slotIdByLegacyTripSlug(db.d1, RETURN_SLUG);

    const html = await (await client.get(`/reserve/${RAKKO_PAGE_SLUG}`)).text();
    expect(html).toContain('らっこ号 池袋便');
    expect(html).toContain(`name="slot_selected" value="${outbound}"`);
    expect(html).toContain(`name="slot_selected" value="${inbound}"`);
    expect(html).toContain(`name="party_size_${outbound}" value="4"`);
    expect(html).toContain(`name="companion_${inbound}"`);
    expect(html).toContain('選択した予約をまとめて確定する');
  });

  it('残席を超える人数は選べない（disabled）', async () => {
    const pageId = await createTestPage(db.d1, { slug: 'few-seats' });
    const slotId = await createTestSlot(db.d1, pageId, { capacity: 2, maxPartySize: 4 });

    const html = await (await client.get('/reserve/few-seats')).text();
    const radio3 = new RegExp(
      `name="party_size_${slotId}" value="3"[^>]*disabled`,
      's',
    );
    expect(radio3.test(html)).toBe(true);
  });

  it('未ログインならログイン導線を出す', async () => {
    const anonymous = new TestClient(testEnv({ DB: db.d1 }));
    const html = await (await anonymous.get(`/reserve/${RAKKO_PAGE_SLUG}`)).text();
    expect(html).toContain('LINEでログインして予約する');
    expect(html).not.toContain('選択した予約をまとめて確定する');
  });

  it('存在しないページは404', async () => {
    const response = await client.get('/reserve/no-such-page');
    expect(response.status).toBe(404);
  });
});

describe('複数枠のまとめて予約（HTTP）', () => {
  it('行き4名・帰り2名をまとめて送信できる', async () => {
    const outbound = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const inbound = await slotIdByLegacyTripSlug(db.d1, RETURN_SLUG);
    const csrf = await csrfFor(`/reserve/${RAKKO_PAGE_SLUG}`);

    const response = await client.post(`/reserve/${RAKKO_PAGE_SLUG}/book`, {
      csrf_token: csrf,
      slot_selected: [String(outbound), String(inbound)],
      [`party_size_${outbound}`]: '4',
      [`companion_${outbound}`]: ['同行A', '同行B', '同行C'],
      [`party_size_${inbound}`]: '2',
      [`companion_${inbound}`]: ['同行A'],
      representative_name: '山田太郎',
      phone: '090-1234-5678',
      agreed: '1',
    });

    expect(response.status).toBe(303);
    expect(response.headers.get('location')).toMatch(/\/bookings\/\d+\?completed=1/);

    expect((await getSlotById(db.d1, outbound, NOW))?.booked_seats).toBe(4);
    expect((await getSlotById(db.d1, inbound, NOW))?.booked_seats).toBe(2);

    // 予約詳細に同時予約の枠がまとめて出る
    const detailUrl = response.headers.get('location') as string;
    const detail = await (await client.get(detailUrl)).text();
    expect(detail).toContain('ご予約が完了しました');
    expect(detail).toContain('同時に予約した枠');

    // マイ予約は予約ページ単位でまとまる
    const mine = await (await client.get('/my-bookings')).text();
    expect(mine).toContain('あなたの予約');
    expect(mine).toContain('らっこ号 池袋便');
    expect(mine).toContain('まとめて予約');
  });

  it('片方が満席なら全体が確定せず、フォームがエラー付きで再表示される', async () => {
    const outbound = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const inbound = await slotIdByLegacyTripSlug(db.d1, RETURN_SLUG);
    await db.d1
      .prepare('UPDATE reservation_slots SET capacity = 0 WHERE id = ?1')
      .bind(inbound)
      .run();

    const csrf = await csrfFor(`/reserve/${RAKKO_PAGE_SLUG}`);
    const response = await client.post(`/reserve/${RAKKO_PAGE_SLUG}/book`, {
      csrf_token: csrf,
      slot_selected: [String(outbound), String(inbound)],
      [`party_size_${outbound}`]: '2',
      [`companion_${outbound}`]: ['同行A'],
      [`party_size_${inbound}`]: '2',
      [`companion_${inbound}`]: ['同行A'],
      representative_name: '山田太郎',
      phone: '090-1234-5678',
      agreed: '1',
    });

    expect(response.status).toBe(400);
    const html = await response.text();
    expect(html).toContain('予約は確定していません');
    // 行きも確定していない
    expect((await getSlotById(db.d1, outbound, NOW))?.booked_seats).toBe(0);
  });

  it('枠を選ばずに送信すると拒否される', async () => {
    const csrf = await csrfFor(`/reserve/${RAKKO_PAGE_SLUG}`);
    const response = await client.post(`/reserve/${RAKKO_PAGE_SLUG}/book`, {
      csrf_token: csrf,
      representative_name: '山田太郎',
      phone: '090-1234-5678',
      agreed: '1',
    });
    expect(response.status).toBe(400);
    expect(await response.text()).toContain('予約する枠を1つ以上選択してください');
  });

  it('CSRFトークンが無い送信は処理されない', async () => {
    const outbound = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const response = await client.post(`/reserve/${RAKKO_PAGE_SLUG}/book`, {
      slot_selected: String(outbound),
      [`party_size_${outbound}`]: '1',
      representative_name: '山田太郎',
      phone: '090-1234-5678',
      agreed: '1',
    });
    expect(response.headers.get('location')).toContain('msg=csrf');
    expect((await getSlotById(db.d1, outbound, NOW))?.booked_seats).toBe(0);
  });

  it('同意なしでは予約できない', async () => {
    const outbound = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const csrf = await csrfFor(`/reserve/${RAKKO_PAGE_SLUG}`);
    const response = await client.post(`/reserve/${RAKKO_PAGE_SLUG}/book`, {
      csrf_token: csrf,
      slot_selected: String(outbound),
      [`party_size_${outbound}`]: '1',
      representative_name: '山田太郎',
      phone: '090-1234-5678',
    });
    expect(response.status).toBe(400);
    expect(await response.text()).toContain('注意事項への同意が必要です');
  });

  it('未ログインの送信はログインへ誘導される', async () => {
    const anonymous = new TestClient(testEnv({ DB: db.d1 }));
    const outbound = await slotIdByLegacyTripSlug(db.d1, OUTBOUND_SLUG);
    const response = await anonymous.post(`/reserve/${RAKKO_PAGE_SLUG}/book`, {
      slot_selected: String(outbound),
      [`party_size_${outbound}`]: '1',
      representative_name: '山田太郎',
      phone: '090-1234-5678',
      agreed: '1',
    });
    expect(response.status).toBe(303);
    expect(response.headers.get('location')).toContain('/login');
  });
});

describe('旧URLの互換', () => {
  it('/trips/:slug/book は新しい予約ページへリダイレクトする', async () => {
    const response = await client.get(`/trips/${OUTBOUND_SLUG}/book`);
    expect(response.status).toBe(303);
    expect(response.headers.get('location')).toBe(`/reserve/${RAKKO_PAGE_SLUG}`);
  });
});
