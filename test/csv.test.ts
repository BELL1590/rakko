import { beforeEach, afterEach, describe, expect, it } from 'vitest';
import {
  createTestDb,
  createTestPage,
  createTestSlot,
  createTestUser,
  NOW,
  type TestDatabase,
} from './helpers/db';
import { buildRosterCsv, sanitizeCsvCell } from '../src/services/csv';
import { cancelBooking, createGroupBooking } from '../src/services/booking-service';
import { listBookingsByPage, listRosterBySlot } from '../src/db/queries';

let db: TestDatabase;

beforeEach(() => {
  db = createTestDb();
});

afterEach(() => {
  db.close();
});

async function seed(): Promise<{ pageId: number; slotA: number; slotB: number }> {
  const pageId = await createTestPage(db.d1, {
    slug: 'aufguss-0825',
    title: '鮭山未菜美 アウフグースイベント',
  });
  const slotA = await createTestSlot(db.d1, pageId, {
    name: '13:00回',
    startAt: '2026-09-01T04:00:00Z',
    capacity: 10,
  });
  const slotB = await createTestSlot(db.d1, pageId, {
    name: '15:00回',
    startAt: '2026-09-01T06:00:00Z',
    capacity: 10,
    sortOrder: 2,
  });
  return { pageId, slotA, slotB };
}

describe('CSVインジェクション対策', () => {
  it('数式として解釈されうるセルをエスケープする', () => {
    expect(sanitizeCsvCell('=1+1')).toBe("'=1+1");
    expect(sanitizeCsvCell('+HYPERLINK("http://evil")')).toBe('\'+HYPERLINK("http://evil")');
    expect(sanitizeCsvCell('-2+3')).toBe("'-2+3");
    expect(sanitizeCsvCell('@SUM(A1)')).toBe("'@SUM(A1)");
    expect(sanitizeCsvCell('\t=1')).toBe("'\t=1");
    // 通常の値はそのまま
    expect(sanitizeCsvCell('山田太郎')).toBe('山田太郎');
    expect(sanitizeCsvCell('090-1234-5678')).toBe('090-1234-5678');
    expect(sanitizeCsvCell('')).toBe('');
  });

  it('氏名が = で始まってもCSVで数式にならない', async () => {
    const { pageId, slotA } = await seed();
    const userId = await createTestUser(db.d1, 'U1');
    await createGroupBooking(
      db.d1,
      {
        pageId,
        userId,
        source: 'line',
        representativeName: '=cmd|calc',
        phone: '09011112222',
        agreed: true,
        items: [{ slotId: slotA, partySize: 2, companionNames: ['@SUM(A1)'] }],
      },
      NOW,
    );

    const csv = buildRosterCsv(await listRosterBySlot(db.d1, slotA, false));
    expect(csv).toContain('"\'=cmd|calc"');
    expect(csv).toContain('"\'@SUM(A1)"');
    expect(csv).not.toContain('"=cmd|calc"');
  });
});

describe('予約枠の名簿CSV', () => {
  it('日本語ヘッダー・BOM付き・同行者列を含む', async () => {
    const { pageId, slotA } = await seed();
    const userId = await createTestUser(db.d1, 'U1');
    await createGroupBooking(
      db.d1,
      {
        pageId,
        userId,
        source: 'line',
        representativeName: '山田太郎',
        phone: '090-1234-5678',
        agreed: true,
        items: [{ slotId: slotA, partySize: 3, companionNames: ['山田花子', '山田次郎'] }],
      },
      NOW,
    );

    const csv = buildRosterCsv(await listRosterBySlot(db.d1, slotA, false));

    // UTF-8 BOM
    expect(csv.charCodeAt(0)).toBe(0xfeff);

    const lines = csv.replace(/^﻿/, '').trim().split('\r\n');
    expect(lines[0]).toBe(
      '"予約番号","予約ページ名","予約枠名","日付","開始時刻","代表者氏名","電話番号",' +
        '"予約人数","同行者1","同行者2","同行者3","受付済人数","予約状態","予約元","予約日時","キャンセル日時"',
    );

    const row = lines[1] as string;
    expect(row).toContain('"鮭山未菜美 アウフグースイベント"');
    expect(row).toContain('"13:00回"');
    expect(row).toContain('"9/1"');
    expect(row).toContain('"13:00"');
    expect(row).toContain('"山田太郎"');
    expect(row).toContain('"090-1234-5678"');
    expect(row).toContain('"3"');
    expect(row).toContain('"山田花子"');
    expect(row).toContain('"山田次郎"');
    expect(row).toContain('"予約済み"');
    expect(row).toContain('"LINE"');
  });

  it('既定では confirmed のみを出力し、指定すればキャンセルも含む', async () => {
    const { pageId, slotA } = await seed();
    const keep = await createTestUser(db.d1, 'KEEP');
    const gone = await createTestUser(db.d1, 'GONE');

    await createGroupBooking(
      db.d1,
      {
        pageId,
        userId: keep,
        source: 'line',
        representativeName: '残る人',
        phone: '09011112222',
        agreed: true,
        items: [{ slotId: slotA, partySize: 1, companionNames: [] }],
      },
      NOW,
    );
    const cancelledBooking = await createGroupBooking(
      db.d1,
      {
        pageId,
        userId: gone,
        source: 'line',
        representativeName: '消える人',
        phone: '09033334444',
        agreed: true,
        items: [{ slotId: slotA, partySize: 1, companionNames: [] }],
      },
      NOW,
    );
    if (!cancelledBooking.ok) throw new Error('setup failed');
    await cancelBooking(
      db.d1,
      { bookingId: cancelledBooking.bookingIds[0] as number, userId: gone, asAdmin: false },
      NOW,
    );

    const confirmedOnly = buildRosterCsv(await listRosterBySlot(db.d1, slotA, false));
    expect(confirmedOnly).toContain('残る人');
    expect(confirmedOnly).not.toContain('消える人');

    const withCancelled = buildRosterCsv(await listRosterBySlot(db.d1, slotA, true));
    expect(withCancelled).toContain('残る人');
    expect(withCancelled).toContain('消える人');
    expect(withCancelled).toContain('"キャンセル"');
  });

  it('受付済人数が反映される', async () => {
    const { pageId, slotA } = await seed();
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
        items: [{ slotId: slotA, partySize: 3, companionNames: ['A', 'B'] }],
      },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');

    await db.d1
      .prepare('UPDATE bookings SET checked_in_count = 2 WHERE id = ?1')
      .bind(created.bookingIds[0])
      .run();

    const csv = buildRosterCsv(await listRosterBySlot(db.d1, slotA, false));
    const row = csv.trim().split('\r\n')[1] as string;
    // 予約人数3 / 受付済2
    expect(row).toContain('"3","A","B","","2"');
  });
});

describe('ページ全体の名簿CSV', () => {
  it('全枠を1つのCSVへ出力し、予約枠名を必ず含む', async () => {
    const { pageId, slotA, slotB } = await seed();
    const userId = await createTestUser(db.d1, 'U1');

    const result = await createGroupBooking(
      db.d1,
      {
        pageId,
        userId,
        source: 'line',
        representativeName: '山田太郎',
        phone: '09011112222',
        agreed: true,
        items: [
          { slotId: slotA, partySize: 2, companionNames: ['同行A'] },
          { slotId: slotB, partySize: 1, companionNames: [] },
        ],
      },
      NOW,
    );
    expect(result.ok).toBe(true);

    const csv = buildRosterCsv(await listBookingsByPage(db.d1, pageId, false));
    const lines = csv.replace(/^﻿/, '').trim().split('\r\n');
    expect(lines).toHaveLength(3);
    expect(lines[0]).toContain('"予約枠名"');
    expect(lines[1]).toContain('"13:00回"');
    expect(lines[2]).toContain('"15:00回"');
  });

  it('同行者が4名以上でも列が失われない', async () => {
    const pageId = await createTestPage(db.d1, { slug: 'big-party' });
    const slotId = await createTestSlot(db.d1, pageId, { capacity: 20, maxPartySize: 6 });
    const userId = await createTestUser(db.d1, 'U1');

    await createGroupBooking(
      db.d1,
      {
        pageId,
        userId,
        source: 'line',
        representativeName: '代表',
        phone: '09011112222',
        agreed: true,
        items: [
          { slotId, partySize: 5, companionNames: ['同1', '同2', '同3', '同4'] },
        ],
      },
      NOW,
    );

    const csv = buildRosterCsv(await listRosterBySlot(db.d1, slotId, false));
    expect(csv).toContain('"同行者4"');
    expect(csv).toContain('"同4"');
  });
});
