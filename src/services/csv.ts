/**
 * 名簿CSVの生成。
 *
 * - UTF-8 BOM 付き（Excelで日本語が文字化けしない）
 * - CSVインジェクション対策: 数式として解釈されうる先頭文字をエスケープする
 */

import { formatJstIsoLike, formatJstShort, formatJstTime } from '../lib/time';
import { parseCompanionNames, type BookingWithSlot } from '../db/types';

const BOM = '﻿';

/**
 * Excel/Sheets が数式として評価しうる文字で始まるセルを無害化する。
 * 例: `=1+1`, `+HYPERLINK(...)`, `-2+3`, `@SUM(...)`、および先頭のタブ/CR。
 */
export function sanitizeCsvCell(value: string): string {
  if (value === '') return '';
  return /^[=+\-@\t\r]/.test(value) ? `'${value}` : value;
}

export function csvCell(value: string | number | null | undefined): string {
  const text = value === null || value === undefined ? '' : String(value);
  return `"${sanitizeCsvCell(text).replace(/"/g, '""')}"`;
}

export function csvRow(cells: (string | number | null | undefined)[]): string {
  return cells.map(csvCell).join(',');
}

/** 同行者列の数（最低3列。それ以上の予約があれば拡張する）。 */
function companionColumnCount(bookings: BookingWithSlot[]): number {
  const max = bookings.reduce(
    (acc, booking) => Math.max(acc, parseCompanionNames(booking.companion_names_json).length),
    0,
  );
  return Math.max(3, max);
}

/**
 * 名簿CSVを組み立てる。予約枠単位・ページ全体のどちらでも同じ列構成にする。
 */
export function buildRosterCsv(bookings: BookingWithSlot[]): string {
  const companionColumns = companionColumnCount(bookings);

  const header = [
    '予約番号',
    '予約ページ名',
    '予約枠名',
    '日付',
    '開始時刻',
    '代表者氏名',
    '電話番号',
    '予約人数',
    ...Array.from({ length: companionColumns }, (_, index) => `同行者${index + 1}`),
    '受付済人数',
    '予約状態',
    '予約元',
    '予約日時',
    'キャンセル日時',
  ];

  const rows = bookings.map((booking) => {
    const companions = parseCompanionNames(booking.companion_names_json);
    const startedAt = formatJstIsoLike(booking.start_at);
    return csvRow([
      booking.id,
      booking.page_title,
      booking.slot_name,
      formatJstShort(booking.start_at).split(' ')[0] ?? startedAt.slice(0, 10),
      formatJstTime(booking.start_at),
      booking.representative_name,
      booking.phone,
      booking.party_size,
      ...Array.from({ length: companionColumns }, (_, index) => companions[index] ?? ''),
      booking.checked_in_count,
      booking.status === 'confirmed' ? '予約済み' : 'キャンセル',
      booking.source === 'admin' ? '管理者代理' : 'LINE',
      formatJstIsoLike(booking.created_at),
      booking.cancelled_at ? formatJstIsoLike(booking.cancelled_at) : '',
    ]);
  });

  return `${BOM}${[csvRow(header), ...rows].join('\r\n')}\r\n`;
}

/** ダウンロード用のファイル名（ASCIIに寄せる）。 */
export function csvFileName(prefix: string, id: number): string {
  const safe = prefix.replace(/[^A-Za-z0-9._-]/g, '') || 'roster';
  return `${safe}-${id}.csv`;
}
