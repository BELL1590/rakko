/** 管理画面: 予約ページの一覧・作成・編集と、配下の予約枠の管理。 */

import { esc, when } from '../../lib/html';
import { formatJstIsoLike, formatJstLong, toJstDatetimeLocal } from '../../lib/time';
import { layout } from '../layout';
import type {
  PageWithStats,
  ReservationPageRow,
  SlotWithAvailability,
} from '../../db/types';

const PAGE_STATUS_LABEL: Record<string, string> = {
  draft: '下書き',
  published: '公開中',
  closed: '受付終了',
  archived: 'アーカイブ',
};

const PAGE_TYPE_LABEL: Record<string, string> = {
  bus: 'バス送迎',
  event: 'イベント',
  time_slot: '時間枠（貸切など）',
  other: 'その他',
};

function statusBadge(status: string): string {
  const cls =
    status === 'published' ? 'badge-open' : status === 'draft' ? 'badge-proxy' : 'badge-closed';
  return `<span class="badge ${cls}">${esc(PAGE_STATUS_LABEL[status] ?? status)}</span>`;
}

/** 予約ページ一覧 `/admin/reservations` */
export function adminPagesListPage(params: {
  pages: PageWithStats[];
  baseUrl: string;
  csrfToken: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const cards = params.pages
    .map((page) => {
      const publicUrl = `${params.baseUrl}/reserve/${page.slug}`;
      return `<article class="card admin-card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
    <h3 style="margin:0">${esc(page.title)}</h3>
    ${statusBadge(page.status)}
  </div>
  <p class="muted" style="margin:6px 0 0">${esc(PAGE_TYPE_LABEL[page.page_type] ?? page.page_type)}
    ・ 予約枠 ${page.slot_count}件 ・ 作成 ${esc(formatJstIsoLike(page.created_at))}</p>
  <p class="stat">${page.booked_seats} <small>/ ${page.capacity_total}名</small></p>
  <p class="muted" style="word-break:break-all">公開URL：<a href="/reserve/${esc(page.slug)}">${esc(publicUrl)}</a></p>
  <div class="btn-row" style="margin-top:12px">
    <a class="btn btn-sm btn-secondary" href="/admin/reservations/${page.id}">編集・予約枠</a>
    <form method="post" action="/admin/reservations/${page.id}/duplicate" style="margin:0">
      <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
      <button class="btn btn-sm btn-secondary" type="submit">複製</button>
    </form>
    <form method="post" action="/admin/reservations/${page.id}/status" style="margin:0">
      <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
      ${
        page.status === 'published'
          ? `<button class="btn btn-sm btn-danger-outline" type="submit" name="status" value="closed">受付を停止</button>`
          : `<button class="btn btn-sm" type="submit" name="status" value="published">公開する</button>`
      }
    </form>
  </div>
</article>`;
    })
    .join('\n');

  const content = `
<h2>予約ページ</h2>
<p><a class="btn" href="/admin/reservations/new">新しい予約ページを作成</a></p>
${
  params.pages.length === 0
    ? '<div class="card"><p class="muted" style="margin:0">まだ予約ページがありません。</p></div>'
    : cards
}
`;

  return layout(
    { title: '予約ページ一覧 | 管理画面', admin: true, alert: params.alert ?? null },
    content,
  );
}

/** 予約ページの作成・編集フォーム。 */
export function adminPageFormPage(params: {
  page: ReservationPageRow | null;
  slots: SlotWithAvailability[];
  csrfToken: string;
  baseUrl: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const page = params.page;
  const isNew = page === null;
  const action = isNew ? '/admin/reservations' : `/admin/reservations/${page.id}`;

  const value = {
    slug: page?.slug ?? '',
    title: page?.title ?? '',
    description: page?.description ?? '',
    status: page?.status ?? 'draft',
    page_type: page?.page_type ?? 'other',
    allow_multi: page ? page.allow_multi_slot_booking === 1 : true,
    requires_login: page ? page.requires_line_login === 1 : true,
    max_slots: page?.max_slots_per_checkout ?? 4,
    checkin_label: page?.checkin_label ?? '受付',
  };

  const statusOptions = Object.entries(PAGE_STATUS_LABEL)
    .map(
      ([key, label]) =>
        `<option value="${key}"${value.status === key ? ' selected' : ''}>${esc(label)}</option>`,
    )
    .join('');

  const typeOptions = Object.entries(PAGE_TYPE_LABEL)
    .map(
      ([key, label]) =>
        `<option value="${key}"${value.page_type === key ? ' selected' : ''}>${esc(label)}</option>`,
    )
    .join('');

  const slotRows = params.slots
    .map(
      (slot) => `<article class="book-row">
  <div class="book-row__head">
    <span class="book-row__name">${esc(slot.name)}</span>
    <span class="book-row__size">${slot.booked_seats} / ${slot.capacity}名</span>
    <span class="badge ${
      slot.booking_status === 'open'
        ? 'badge-open'
        : slot.booking_status === 'hidden'
          ? 'badge-proxy'
          : 'badge-closed'
    }" style="margin-left:auto">${
      slot.booking_status === 'open'
        ? '受付中'
        : slot.booking_status === 'hidden'
          ? '非表示'
          : '受付停止'
    }</span>
  </div>
  <p class="book-row__meta">
    ${esc(formatJstLong(slot.start_at))}${slot.end_at ? `〜${esc(formatJstLong(slot.end_at))}` : ''}<br>
    ${esc(slot.origin && slot.destination ? `${slot.origin} → ${slot.destination}` : (slot.location ?? '-'))}<br>
    1予約あたり最大${slot.max_party_size}名 ・ 残り${slot.remaining_seats}席
    ${slot.reminder_at ? ` ・ リマインド ${esc(formatJstLong(slot.reminder_at))}` : ' ・ リマインドなし'}
  </p>
  <div class="btn-row">
    <a class="btn btn-sm btn-secondary" href="/admin/slots/${slot.id}">予約一覧・設定</a>
    <a class="btn btn-sm btn-secondary" href="/admin/reservation-slots/${slot.id}/roster.csv">名簿CSV</a>
  </div>
</article>`,
    )
    .join('\n');

  const slotForm = isNew
    ? ''
    : `<h2>予約枠を追加</h2>
${slotFormFields({
  action: `/admin/reservations/${page.id}/slots`,
  csrfToken: params.csrfToken,
  submitLabel: '予約枠を追加',
  pageType: value.page_type,
  slot: null,
  nextSortOrder: params.slots.length + 1,
})}`;

  const content = `
<p><a href="/admin/reservations">← 予約ページ一覧へ</a></p>
<h2>${isNew ? '新しい予約ページ' : esc(page.title)}</h2>

<form class="card" method="post" action="${action}">
  <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
  <div class="field">
    <label for="title">ページ名<span class="req">必須</span></label>
    <input type="text" id="title" name="title" value="${esc(value.title)}" maxlength="80" required>
  </div>
  <div class="field">
    <label for="slug">slug（公開URL）<span class="req">必須</span></label>
    <input type="text" id="slug" name="slug" value="${esc(value.slug)}" maxlength="60"
      pattern="[a-z0-9-]+" required>
    <p class="hint">半角英小文字・数字・ハイフン。公開URLは ${esc(params.baseUrl)}/reserve/<strong>slug</strong> になります。</p>
  </div>
  <div class="field">
    <label for="description">説明</label>
    <input type="text" id="description" name="description" value="${esc(value.description)}" maxlength="300">
  </div>
  <div class="field">
    <label for="status">公開状態</label>
    <select id="status" name="status">${statusOptions}</select>
  </div>
  <div class="field">
    <label for="page_type">種別</label>
    <select id="page_type" name="page_type">${typeOptions}</select>
    <p class="hint">表示の初期値が変わります。予約ロジックはこの値に依存しません。</p>
  </div>
  <div class="field">
    <label for="checkin_label">受付確認の呼び方</label>
    <input type="text" id="checkin_label" name="checkin_label" value="${esc(value.checkin_label)}" maxlength="10">
    <p class="hint">例：乗車 / 受付 / 来場。管理画面の「〇〇済人数」に使います。</p>
  </div>
  <div class="field checkbox-field">
    <input type="checkbox" id="requires_line_login" name="requires_line_login" value="1"${
      value.requires_login ? ' checked' : ''
    }>
    <label for="requires_line_login">LINEログインを必須にする</label>
  </div>
  <div class="field checkbox-field">
    <input type="checkbox" id="allow_multi_slot_booking" name="allow_multi_slot_booking" value="1"${
      value.allow_multi ? ' checked' : ''
    }>
    <label for="allow_multi_slot_booking">同一ページの複数枠をまとめて予約できるようにする</label>
  </div>
  <div class="field">
    <label for="max_slots_per_checkout">一度に選べる最大枠数</label>
    <input type="number" id="max_slots_per_checkout" name="max_slots_per_checkout"
      value="${value.max_slots}" min="1" max="20" required>
  </div>
  <button class="btn btn-sm" type="submit">${isNew ? '作成する' : '保存する'}</button>
</form>

${
  isNew
    ? '<p class="muted">作成後に予約枠を追加できます。</p>'
    : `<h2>予約枠（${params.slots.length}件）</h2>
<div class="stack">${slotRows || '<p class="muted">まだ予約枠がありません。</p>'}</div>
${when(
  params.slots.length > 0,
  `<p style="margin-top:12px"><a class="btn btn-sm btn-secondary" href="/admin/reservations/${page.id}/roster.csv">このイベントの全予約をCSV</a></p>`,
)}
${slotForm}`
}
`;

  return layout(
    {
      title: `${isNew ? '予約ページ作成' : page.title} | 管理画面`,
      admin: true,
      alert: params.alert ?? null,
    },
    content,
  );
}

/** 予約枠の作成/編集フォーム（作成と編集で同じ項目を使う）。 */
export function slotFormFields(params: {
  action: string;
  csrfToken: string;
  submitLabel: string;
  pageType: string;
  slot: SlotWithAvailability | null;
  nextSortOrder?: number;
}): string {
  const slot = params.slot;
  const isBus = params.pageType === 'bus';

  return `<form class="card" method="post" action="${esc(params.action)}">
  <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
  <div class="field">
    <label for="name">枠名<span class="req">必須</span></label>
    <input type="text" id="name" name="name" value="${esc(slot?.name ?? '')}" maxlength="60" required
      placeholder="${isBus ? '行き / 帰り' : '13:00回 / 10:00〜11:00'}">
  </div>
  <div class="field">
    <label for="description">説明</label>
    <input type="text" id="description" name="description" value="${esc(slot?.description ?? '')}" maxlength="200">
  </div>
  <div class="field">
    <label for="start_at">開始日時（JST）<span class="req">必須</span></label>
    <input type="datetime-local" id="start_at" name="start_at"
      value="${esc(slot ? toJstDatetimeLocal(slot.start_at) : '')}" required>
  </div>
  <div class="field">
    <label for="end_at">終了日時（JST・任意）</label>
    <input type="datetime-local" id="end_at" name="end_at"
      value="${esc(slot?.end_at ? toJstDatetimeLocal(slot.end_at) : '')}">
  </div>
  <div class="field">
    <label for="origin">出発地（バス用・任意）</label>
    <input type="text" id="origin" name="origin" value="${esc(slot?.origin ?? '')}" maxlength="100">
  </div>
  <div class="field">
    <label for="destination">到着地（バス用・任意）</label>
    <input type="text" id="destination" name="destination" value="${esc(slot?.destination ?? '')}" maxlength="100">
  </div>
  <div class="field">
    <label for="location">会場（任意）</label>
    <input type="text" id="location" name="location" value="${esc(slot?.location ?? '')}" maxlength="100">
  </div>
  <div class="field">
    <label for="capacity">定員<span class="req">必須</span></label>
    <input type="number" id="capacity" name="capacity" value="${slot?.capacity ?? 24}" min="0" max="500" required>
    ${when(
      Boolean(slot),
      `<p class="hint">既存の確定予約人数（${slot?.booked_seats ?? 0}名）を下回る値には変更できません。</p>`,
    )}
  </div>
  <div class="field">
    <label for="max_party_size">1予約あたりの最大人数<span class="req">必須</span></label>
    <input type="number" id="max_party_size" name="max_party_size"
      value="${slot?.max_party_size ?? 4}" min="1" max="20" required>
  </div>
  <div class="field">
    <label for="booking_open_at">予約受付開始日時（JST・任意）</label>
    <input type="datetime-local" id="booking_open_at" name="booking_open_at"
      value="${esc(slot?.booking_open_at ? toJstDatetimeLocal(slot.booking_open_at) : '')}">
  </div>
  <div class="field">
    <label for="booking_close_at">予約締切日時（JST・任意）</label>
    <input type="datetime-local" id="booking_close_at" name="booking_close_at"
      value="${esc(slot?.booking_close_at ? toJstDatetimeLocal(slot.booking_close_at) : '')}">
  </div>
  <div class="field">
    <label for="reminder_at">リマインド送信日時（JST・任意）</label>
    <input type="datetime-local" id="reminder_at" name="reminder_at"
      value="${esc(slot?.reminder_at ? toJstDatetimeLocal(slot.reminder_at) : '')}">
    <p class="hint">この時刻を過ぎるとCron（5分毎）がLINEリマインドを送信します。空欄なら送信しません。</p>
  </div>
  <div class="field">
    <label for="booking_status">受付状態</label>
    <select id="booking_status" name="booking_status">
      <option value="open"${slot?.booking_status === 'open' || !slot ? ' selected' : ''}>受付中</option>
      <option value="closed"${slot?.booking_status === 'closed' ? ' selected' : ''}>受付停止</option>
      <option value="hidden"${slot?.booking_status === 'hidden' ? ' selected' : ''}>非表示</option>
    </select>
  </div>
  <div class="field">
    <label for="sort_order">表示順</label>
    <input type="number" id="sort_order" name="sort_order"
      value="${slot?.sort_order ?? params.nextSortOrder ?? 1}" min="0" max="999" required>
  </div>
  <button class="btn btn-sm" type="submit">${esc(params.submitLabel)}</button>
</form>`;
}
