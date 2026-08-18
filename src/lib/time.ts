/**
 * 時刻ユーティリティ。
 *
 * - DBはUTC（ISO8601 / `YYYY-MM-DDTHH:MM:SSZ`）で保存する。
 * - 画面表示は必ず Asia/Tokyo へ変換する。
 * - サーバーのローカルタイムには一切依存しない。
 */

export const JST_TIME_ZONE = 'Asia/Tokyo';

const WEEKDAY_JA = ['日', '月', '火', '水', '木', '金', '土'] as const;

/** Date を DB保存用のUTC文字列へ。 */
export function toUtcString(date: Date): string {
  return `${date.toISOString().slice(0, 19)}Z`;
}

/** 現在時刻のUTC文字列。 */
export function nowUtc(now: Date = new Date()): string {
  return toUtcString(now);
}

/** DBのUTC文字列を Date へ。不正値は null。 */
export function parseUtc(value: string): Date | null {
  const normalized = value.endsWith('Z') ? value : `${value}Z`;
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

type JstParts = {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
  weekday: string;
};

function jstParts(date: Date): JstParts {
  const formatter = new Intl.DateTimeFormat('en-US', {
    timeZone: JST_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  });
  const parts: Record<string, string> = {};
  for (const part of formatter.formatToParts(date)) {
    parts[part.type] = part.value;
  }
  const year = Number(parts.year);
  const month = Number(parts.month);
  const day = Number(parts.day);
  // 曜日は UTC+9 のカレンダー日から算出する（ローカルタイム非依存）
  const weekdayIndex = new Date(Date.UTC(year, month - 1, day)).getUTCDay();
  return {
    year,
    month,
    day,
    hour: Number(parts.hour) % 24,
    minute: Number(parts.minute),
    weekday: WEEKDAY_JA[weekdayIndex] ?? '',
  };
}

function pad2(value: number): string {
  return String(value).padStart(2, '0');
}

/** 例: `8月21日（金）20:00` */
export function formatJstLong(utc: string): string {
  const date = parseUtc(utc);
  if (!date) return '';
  const p = jstParts(date);
  return `${p.month}月${p.day}日（${p.weekday}）${pad2(p.hour)}:${pad2(p.minute)}`;
}

/** 例: `8/21 20:00` */
export function formatJstShort(utc: string): string {
  const date = parseUtc(utc);
  if (!date) return '';
  const p = jstParts(date);
  return `${p.month}/${p.day} ${pad2(p.hour)}:${pad2(p.minute)}`;
}

/** 例: `2026-08-21 20:00`（CSV/管理画面向け） */
export function formatJstIsoLike(utc: string): string {
  const date = parseUtc(utc);
  if (!date) return '';
  const p = jstParts(date);
  return `${p.year}-${pad2(p.month)}-${pad2(p.day)} ${pad2(p.hour)}:${pad2(p.minute)}`;
}

/** 例: `20:00` */
export function formatJstTime(utc: string): string {
  const date = parseUtc(utc);
  if (!date) return '';
  const p = jstParts(date);
  return `${pad2(p.hour)}:${pad2(p.minute)}`;
}

/** `<input type="datetime-local">` 用（JST表記）。 */
export function toJstDatetimeLocal(utc: string): string {
  const date = parseUtc(utc);
  if (!date) return '';
  const p = jstParts(date);
  return `${p.year}-${pad2(p.month)}-${pad2(p.day)}T${pad2(p.hour)}:${pad2(p.minute)}`;
}

/**
 * `<input type="datetime-local">` のJST文字列をUTC保存値へ。
 * 不正な入力は null。
 */
export function fromJstDatetimeLocal(value: string): string | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/.exec(value.trim());
  if (!match) return null;
  const [, y, mo, d, h, mi] = match;
  const utcMillis = Date.UTC(Number(y), Number(mo) - 1, Number(d), Number(h), Number(mi)) -
    9 * 60 * 60 * 1000;
  const date = new Date(utcMillis);
  if (Number.isNaN(date.getTime())) return null;
  return toUtcString(date);
}
