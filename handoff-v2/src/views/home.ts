/**
 * 公開トップ。公開中の予約ページを一覧する。
 *
 * D4 の変更点:
 * - ページを「受付中 / 受付開始前 / 受付終了」の3グループに分ける。
 *   is_bookable=false をすべて受付終了に丸めない（開始前のページは期待を持たせて上に出す）。
 * - 各ページの枠を1行ずつ並べ、日時と残席をスマホで縦に読めるようにする
 * - 枠の色分けは行わない（ブランド赤を基本に統一）
 */

import { esc, when } from '../lib/html';
import { formatJstLong } from '../lib/time';
import { layout, priceInfoCard, seatBadge } from './layout';
import { slotState } from './slot-parts';
import type { PageWithStats, SlotWithAvailability } from '../db/types';

export interface HomePageEntry {
  page: PageWithStats;
  slots: SlotWithAvailability[];
}

type PageGroup = 'open' | 'before_open' | 'closed';

/**
 * ページ全体の分類。
 * 1枠でも予約できれば「受付中」。
 * 予約できる枠が無く、開始前の枠があれば「受付開始前」。
 * それ以外（満席・受付終了・受付停止のみ）が「受付終了」。
 */
function classifyPage(slots: SlotWithAvailability[], nowUtc: string): PageGroup {
  const states = slots.map((slot) => slotState(slot, nowUtc));
  if (states.includes('open')) return 'open';
  if (states.includes('before_open')) return 'before_open';
  return 'closed';
}

/** 受付開始前のページで「いつから予約できるか」。最も早い開始時刻を採る。 */
function earliestOpenAt(slots: SlotWithAvailability[]): string | null {
  const opens = slots
    .map((slot) => slot.booking_open_at)
    .filter((v): v is string => Boolean(v))
    .sort();
  return opens[0] ?? null;
}

function slotLines(slots: SlotWithAvailability[], nowUtc: string): string {
  return slots
    .map((slot) => {
      const state = slotState(slot, nowUtc);
      const right =
        state === 'open'
          ? `残り${slot.remaining_seats}席`
          : state === 'full'
            ? '満席'
            : state === 'before_open'
              ? '開始前'
              : state === 'suspended'
                ? '停止中'
                : '終了';
      return `<li>
      <span class="slot-line__name">${esc(slot.name)}</span>
      <span class="slot-line__when">${esc(formatJstLong(slot.start_at))}</span>
      <span class="slot-line__seats">${right}</span>
    </li>`;
    })
    .join('');
}

/** 受付中のページカード。 */
function openPageCard(entry: HomePageEntry, nowUtc: string): string {
  const { page } = entry;
  const slots = entry.slots.filter((slot) => slot.is_visible);
  const openSlots = slots.filter((slot) => slotState(slot, nowUtc) === 'open');
  const remaining = openSlots.reduce((sum, slot) => sum + slot.remaining_seats, 0);
  const capacity = openSlots.reduce((sum, slot) => sum + slot.capacity, 0);
  const someUnavailable = slots.length > openSlots.length;
  const first = slots[0];

  // 締切が設定されている枠のうち、最も早いものを案内する
  const nextClose = openSlots
    .map((slot) => slot.booking_close_at)
    .filter((v): v is string => Boolean(v))
    .sort()[0];

  return `<article class="card trip-card slot-card">
  <div class="trip-card__head">
    <span class="trip-card__dir">${esc(page.title)}</span>
    <span class="trip-card__state">受付中</span>
  </div>
  <div class="trip-card__body">
    ${when(Boolean(page.description), `<p class="trip-meta">${esc(page.description)}</p>`)}
    ${when(
      Boolean(first),
      `<p class="trip-when"><span class="trip-date">${esc(
        first ? formatJstLong(first.start_at) : '',
      )}</span></p>`,
    )}
    ${when(slots.length > 0, `<ul class="slot-lines">${slotLines(slots, nowUtc)}</ul>`)}
    <p class="seats">残り <span class="seats-num">${remaining}</span> 席 / ${capacity}席${seatBadge(
      { is_full: remaining === 0, remaining_seats: remaining, capacity },
    )}${
      someUnavailable
        ? '<span class="seat-badge is-few" style="margin-left:6px">一部受付終了</span>'
        : ''
    }</p>
    ${when(
      Boolean(nextClose),
      `<p class="slot-timing">${esc(nextClose ? formatJstLong(nextClose) : '')}まで受付</p>`,
    )}
    <a class="btn" style="margin-top:14px" href="/reserve/${esc(page.slug)}">この予約ページを開く</a>
  </div>
</article>`;
}

/** 受付開始前のページカード。開始日時を主役にする。 */
function beforeOpenPageCard(entry: HomePageEntry, nowUtc: string): string {
  const { page } = entry;
  const slots = entry.slots.filter((slot) => slot.is_visible);
  const openAt = earliestOpenAt(slots);
  const first = slots[0];

  return `<article class="card trip-card slot-card is-waiting">
  <div class="trip-card__head">
    <span class="trip-card__dir">${esc(page.title)}</span>
    <span class="trip-card__state">受付開始前</span>
  </div>
  <div class="trip-card__body">
    ${when(Boolean(page.description), `<p class="trip-meta">${esc(page.description)}</p>`)}
    ${when(
      Boolean(first),
      `<p class="trip-when"><span class="trip-date">${esc(
        first ? formatJstLong(first.start_at) : '',
      )}</span></p>`,
    )}
    ${when(slots.length > 0, `<ul class="slot-lines">${slotLines(slots, nowUtc)}</ul>`)}
    ${
      openAt
        ? `<p class="slot-timing is-waiting"><strong>${esc(
            formatJstLong(openAt),
          )}</strong>から受付開始</p>`
        : '<p class="slot-timing is-waiting">受付開始をお待ちください。</p>'
    }
    <a class="btn btn-secondary" style="margin-top:14px" href="/reserve/${esc(
      page.slug,
    )}">内容を見る</a>
  </div>
</article>`;
}

/** 受付終了・満席のページは畳んで下に出す。 */
function closedPageCard(entry: HomePageEntry, nowUtc: string): string {
  const { page } = entry;
  const slots = entry.slots.filter((slot) => slot.is_visible);
  const states = slots.map((slot) => slotState(slot, nowUtc));
  const allFull = states.length > 0 && states.every((s) => s === 'full');
  const allSuspended = states.length > 0 && states.every((s) => s === 'suspended');
  const first = slots[0];

  return `<article class="page-closed">
  <div class="page-closed__head">
    <span class="page-closed__title">${esc(page.title)}</span>
    <span class="badge badge-closed">${
      allFull ? '満席' : allSuspended ? '受付停止中' : '受付終了'
    }</span>
  </div>
  <p class="page-closed__meta">${
    first ? `${esc(formatJstLong(first.start_at))} ・ ` : ''
  }${slots.length}枠 ・ <a href="/reserve/${esc(page.slug)}">内容を見る</a></p>
</article>`;
}

export function homePage(params: {
  entries: HomePageEntry[];
  userName: string | null;
  /** 省略時は現在時刻。テストから固定したい場合のみ渡す。 */
  nowUtc?: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const nowUtc = params.nowUtc ?? new Date().toISOString();

  const open: HomePageEntry[] = [];
  const beforeOpen: HomePageEntry[] = [];
  const closed: HomePageEntry[] = [];

  for (const entry of params.entries) {
    const slots = entry.slots.filter((slot) => slot.is_visible);
    const group = classifyPage(slots, nowUtc);
    if (group === 'open') open.push(entry);
    else if (group === 'before_open') beforeOpen.push(entry);
    else closed.push(entry);
  }

  // 受付開始前は「早く開くもの」を上に
  beforeOpen.sort((a, b) => {
    const av = earliestOpenAt(a.slots) ?? '';
    const bv = earliestOpenAt(b.slots) ?? '';
    return av.localeCompare(bv);
  });

  const content = `
<section class="hero" style="margin:-16px -16px 16px">
  <h1>草加健康センター<br>予約センター</h1>
  <span class="hero-sub">オンライン予約</span>
  <p>ご利用になる予約ページを選んでください。</p>
</section>

<div class="section-head">
  <h2>受付中の予約</h2>
  ${when(open.length > 0, `<span class="count">${open.length}件</span>`)}
</div>
${
  open.length === 0
    ? '<div class="card"><p class="muted" style="margin:0">現在受付中の予約はありません。</p></div>'
    : open.map((entry) => openPageCard(entry, nowUtc)).join('\n')
}

<div class="notice" style="margin-bottom:16px">
  ご予約にはLINEログインが必要です。予約完了のお知らせと開始前のリマインドをLINEでお送りします。
</div>

<p><a class="btn btn-secondary" href="/my-bookings">マイ予約を確認する</a></p>

${when(
  beforeOpen.length > 0,
  `<div class="section-head">
  <h2 class="is-waiting">受付開始前</h2>
  <span class="count is-waiting">${beforeOpen.length}件</span>
</div>
${beforeOpen.map((entry) => beforeOpenPageCard(entry, nowUtc)).join('\n')}`,
)}

${when(
  closed.length > 0,
  `<div class="section-head">
  <h2 class="is-closed">受付終了</h2>
  <span class="count is-closed">${closed.length}件</span>
</div>
${closed.map((entry) => closedPageCard(entry, nowUtc)).join('\n')}`,
)}

<h2>草加健康センター 館内料金（参考）</h2>
${priceInfoCard()}
`;

  return layout(
    {
      title: '草加健康センター 予約センター',
      userName: params.userName,
      alert: params.alert ?? null,
    },
    content,
  );
}
