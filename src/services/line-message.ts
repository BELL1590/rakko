/**
 * LINE Messaging API（push message）クライアントと、通知本文の組み立て。
 * 予約ページ種別（バス / イベント / 時間枠）に依存しすぎない汎用の文面にする。
 */

import { formatJstLong, formatJstTime } from '../lib/time';
import type { PageType } from '../db/types';

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

export interface SlotMessageContext {
  name: string;
  start_at: string;
  origin: string | null;
  destination: string | null;
  location: string | null;
}

function pageIcon(pageType: PageType): string {
  return pageType === 'bus' ? '🚌' : '🔔';
}

/** 「池袋西口 → 草加健康センター」または「会場：〇〇」の1行。 */
export function routeLine(slot: SlotMessageContext): string {
  if (slot.origin && slot.destination) return `${slot.origin} → ${slot.destination}`;
  if (slot.location) return `会場：${slot.location}`;
  if (slot.origin) return `集合：${slot.origin}`;
  return '';
}

/**
 * 予約完了通知の本文。
 * 一括予約のときは1通に全枠をまとめる。
 */
export function buildBookingConfirmationText(params: {
  pageTitle: string;
  pageType: PageType;
  items: { slot: SlotMessageContext; partySize: number }[];
}): string {
  const lines: string[] = [
    `${pageIcon(params.pageType)} ${params.pageTitle}`,
    '予約が完了しました。',
  ];

  for (const item of params.items) {
    const route = routeLine(item.slot);
    lines.push('');
    lines.push(`【${item.slot.name}】`);
    lines.push(formatJstLong(item.slot.start_at));
    if (route) lines.push(route);
    lines.push(`予約人数：${item.partySize}名`);
  }

  lines.push('');
  lines.push('予約内容は下記ページから確認できます。');
  return lines.join('\n');
}

/** 開始前リマインドの本文。 */
export function buildReminderText(params: {
  pageTitle: string;
  pageType: PageType;
  slot: SlotMessageContext;
  partySize: number;
}): string {
  const time = formatJstTime(params.slot.start_at);
  const lines: string[] = [
    `${pageIcon(params.pageType)} ${params.pageTitle}「${params.slot.name}」のお知らせ`,
  ];

  if (params.slot.origin) {
    lines.push(`本日${time} ${params.slot.origin}出発です。`);
    lines.push('出発15分前までに集合場所へお越しください。');
  } else {
    lines.push(`本日${time} 開始です。`);
    if (params.slot.location) lines.push(`会場：${params.slot.location}`);
    lines.push('お時間に余裕をもってお越しください。');
  }

  lines.push('');
  lines.push(`予約人数：${params.partySize}名`);
  return lines.join('\n');
}
