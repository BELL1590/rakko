/**
 * LINE Messaging API（push message）クライアントと、通知本文の組み立て。
 */

import { formatJstLong, formatJstTime } from '../lib/time';
import type { Direction } from '../db/types';

const PUSH_ENDPOINT = 'https://api.line.me/v2/bot/message/push';

export type PushResult =
  | { ok: true }
  | { ok: false; retryable: boolean; error: string };

/**
 * push message を送る。
 *
 * 注意: HTTP 200 は「LINEプラットフォームが受け付けた」だけであり、
 * ユーザーへ届いたことの保証ではない。呼び出し側は `requested` として記録する。
 */
export async function pushTextMessage(params: {
  accessToken: string;
  to: string;
  text: string;
  fetchImpl?: typeof fetch;
}): Promise<PushResult> {
  const doFetch = params.fetchImpl ?? fetch;
  try {
    const response = await doFetch(PUSH_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${params.accessToken}`,
      },
      body: JSON.stringify({
        to: params.to,
        messages: [{ type: 'text', text: params.text }],
      }),
    });

    if (response.ok) return { ok: true };

    // 本文にトークンは含めない。ステータスとAPIのmessageのみ保持する。
    let message = '';
    try {
      const data = (await response.json()) as { message?: string };
      message = data.message ?? '';
    } catch {
      message = '';
    }
    // 4xx はリトライしても直らない（友だち未追加・ブロック等を含む）
    const retryable = response.status >= 500 || response.status === 429;
    return {
      ok: false,
      retryable,
      error: `HTTP ${response.status}${message ? `: ${message}` : ''}`.slice(0, 300),
    };
  } catch (error) {
    return {
      ok: false,
      retryable: true,
      error: `network error: ${(error as Error).message}`.slice(0, 300),
    };
  }
}

export interface TripMessageContext {
  direction: Direction;
  origin: string;
  destination: string;
  depart_at: string;
}

export function directionLabel(direction: Direction): string {
  return direction === 'outbound' ? '行き' : '帰り';
}

/** 予約完了通知の本文。 */
export function buildBookingConfirmationText(
  trip: TripMessageContext,
  partySize: number,
): string {
  return [
    '🚌 らっこ号 池袋便',
    '予約が完了しました。',
    '',
    `【${directionLabel(trip.direction)}】`,
    `${formatJstLong(trip.depart_at)}`,
    `${trip.origin} → ${trip.destination}`,
    '',
    `予約人数：${partySize}名`,
    '',
    '予約内容は下記ページから確認できます。',
  ].join('\n');
}

/** 乗車前リマインドの本文。 */
export function buildReminderText(trip: TripMessageContext, partySize: number): string {
  const time = formatJstTime(trip.depart_at);
  if (trip.direction === 'outbound') {
    return [
      '🚌 らっこ号 池袋便のお知らせ',
      `本日${time} ${trip.origin}出発です。`,
      '出発15分前までに集合場所へお越しください。',
      '',
      `予約人数：${partySize}名`,
    ].join('\n');
  }
  return [
    '🚌 らっこ号 帰り便のお知らせ',
    `本日${time} ${trip.origin}出発です。`,
    'お乗り遅れのないようご注意ください。',
    '',
    `予約人数：${partySize}名`,
  ].join('\n');
}
