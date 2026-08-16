import { describe, expect, it } from 'vitest';
import {
  formatJstIsoLike,
  formatJstLong,
  formatJstShort,
  formatJstTime,
  fromJstDatetimeLocal,
  toJstDatetimeLocal,
} from '../src/lib/time';

/** 便のseed値（UTC保存）。 */
const OUTBOUND_DEPART_UTC = '2026-08-21T11:00:00Z';
const RETURN_DEPART_UTC = '2026-08-21T23:10:00Z';
const OUTBOUND_REMINDER_UTC = '2026-08-21T08:00:00Z';
const RETURN_REMINDER_UTC = '2026-08-21T22:00:00Z';

describe('JST表示', () => {
  it('行き便は 2026-08-21 20:00 JST として表示される', () => {
    expect(formatJstLong(OUTBOUND_DEPART_UTC)).toBe('8月21日（金）20:00');
    expect(formatJstShort(OUTBOUND_DEPART_UTC)).toBe('8/21 20:00');
    expect(formatJstIsoLike(OUTBOUND_DEPART_UTC)).toBe('2026-08-21 20:00');
    expect(formatJstTime(OUTBOUND_DEPART_UTC)).toBe('20:00');
  });

  it('帰り便は 2026-08-22 08:10 JST として表示される', () => {
    expect(formatJstLong(RETURN_DEPART_UTC)).toBe('8月22日（土）08:10');
    expect(formatJstShort(RETURN_DEPART_UTC)).toBe('8/22 08:10');
    expect(formatJstIsoLike(RETURN_DEPART_UTC)).toBe('2026-08-22 08:10');
    expect(formatJstTime(RETURN_DEPART_UTC)).toBe('08:10');
  });

  it('リマインド時刻は 17:00 / 07:00 JST として表示される', () => {
    expect(formatJstIsoLike(OUTBOUND_REMINDER_UTC)).toBe('2026-08-21 17:00');
    expect(formatJstIsoLike(RETURN_REMINDER_UTC)).toBe('2026-08-22 07:00');
  });

  it('サーバーのローカルタイムに依存しない', () => {
    const original = process.env.TZ;
    try {
      process.env.TZ = 'America/New_York';
      expect(formatJstLong(OUTBOUND_DEPART_UTC)).toBe('8月21日（金）20:00');
      process.env.TZ = 'UTC';
      expect(formatJstLong(OUTBOUND_DEPART_UTC)).toBe('8月21日（金）20:00');
    } finally {
      if (original === undefined) delete process.env.TZ;
      else process.env.TZ = original;
    }
  });

  it('datetime-local の相互変換ができる', () => {
    expect(toJstDatetimeLocal(OUTBOUND_REMINDER_UTC)).toBe('2026-08-21T17:00');
    expect(fromJstDatetimeLocal('2026-08-21T17:00')).toBe('2026-08-21T08:00:00Z');
    expect(fromJstDatetimeLocal('2026-08-22T07:00')).toBe('2026-08-21T22:00:00Z');
    expect(fromJstDatetimeLocal('不正な値')).toBeNull();
  });
});
