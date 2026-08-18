/**
 * 予約完了 / 予約詳細ページ。
 *
 * 一括予約の完了時は、同じグループの予約をまとめて表示する。
 * キャンセルは誤操作防止のため2段階（同一ページ内で確認セクションを開くだけで、
 * ルートもPOST先も変えない）。JSが無効な場合は確認セクションが最初から開き、
 * 従来の confirm() が働く。キャンセルは枠単位。
 */

import { esc, when } from '../lib/html';
import { formatJstLong } from '../lib/time';
import { layout } from './layout';
import { slotRoute, slotWhen } from './slot-parts';
import { parseCompanionNames, type BookingWithSlot } from '../db/types';

function summaryCard(booking: BookingWithSlot, isReturn: boolean): string {
  const companions = parseCompanionNames(booking.companion_names_json);
  const cancelled = booking.status === 'cancelled';

  return `<section class="card trip-card${isReturn ? ' is-return' : ''}">
  <div class="trip-card__head">
    <span class="trip-card__dir">${esc(booking.slot_name)}</span>
    <span class="trip-card__state">${cancelled ? 'キャンセル済み' : '予約済み'}</span>
  </div>
  <div class="trip-card__body">
    ${slotWhen(booking)}
    ${slotRoute(booking)}
    <ul class="summary-list">
      <li><span class="k">予約ID</span><span class="v">#${booking.id}</span></li>
      <li><span class="k">予約ページ</span><span class="v">${esc(booking.page_title)}</span></li>
      <li><span class="k">代表者</span><span class="v">${esc(booking.representative_name)}</span></li>
      <li><span class="k">ご予約人数</span><span class="v">${booking.party_size}名</span></li>
      ${when(
        companions.length > 0,
        `<li><span class="k">同行者</span><span class="v">${esc(companions.join('、'))}</span></li>`,
      )}
      <li><span class="k">ステータス</span><span class="v">${
        cancelled
          ? '<span class="badge badge-cancelled">キャンセル済み</span>'
          : '<span class="badge badge-confirmed">予約済み</span>'
      }</span></li>
    </ul>
  </div>
</section>`;
}

export function bookingDetailPage(params: {
  booking: BookingWithSlot;
  /** 一括予約の場合、同じグループの他の予約 */
  groupBookings?: BookingWithSlot[];
  csrfToken: string;
  userName: string | null;
  justCompleted: boolean;
  notificationNote?: string | null;
  nowUtc: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const { booking } = params;
  const cancelled = booking.status === 'cancelled';
  const started = booking.start_at <= params.nowUtc;
  const canCancel = !cancelled && !started;
  const heading = params.justCompleted ? 'ご予約が完了しました' : 'ご予約の詳細';
  const others = (params.groupBookings ?? []).filter((entry) => entry.id !== booking.id);

  const cancelForm = `<form method="post" action="/bookings/${booking.id}/cancel" style="margin:0"
      id="cancel-form" onsubmit="return confirm('この予約をキャンセルします。よろしいですか？');">
      <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
      <button class="btn btn-danger" type="submit">キャンセルを確定する</button>
    </form>`;

  const cancelSection = canCancel
    ? `<div class="btn-stack" id="cancel-trigger-wrap" hidden>
    <button class="btn btn-danger-outline" type="button" id="cancel-trigger">この予約をキャンセルする</button>
  </div>

  <section class="cancel-panel" id="cancel-panel" style="margin-bottom:16px">
    <p class="cancel-lead">この予約をキャンセルしますか？</p>
    <ul class="summary-list" style="margin-bottom:12px">
      <li><span class="k">予約ID</span><span class="v">#${booking.id}</span></li>
      <li><span class="k">予約枠</span><span class="v">${esc(booking.page_title)}／${esc(booking.slot_name)}</span></li>
      <li><span class="k">日時</span><span class="v">${esc(formatJstLong(booking.start_at))}</span></li>
      <li><span class="k">ご予約人数</span><span class="v">${booking.party_size}名</span></li>
    </ul>
    <p class="muted" style="margin-top:0">キャンセルすると座席は他のお客様へ開放されます。取り消しはできません。
    ${when(
      others.length > 0,
      'まとめて予約した他の枠はキャンセルされません（枠ごとにお手続きください）。',
    )}</p>
    <div class="btn-stack">
      <button class="btn btn-secondary" type="button" id="cancel-dismiss" hidden>やめる（予約を続ける）</button>
      ${cancelForm}
    </div>
  </section>`
    : '';

  const content = `
${when(
  params.justCompleted,
  `<div class="alert alert-success done-head" role="status">
    <span class="done-mark" aria-hidden="true">✓</span>
    <span>
      <strong style="display:block;font-size:1.15rem">ご予約が完了しました</strong>
      当日お気をつけてお越しください。
    </span>
  </div>`,
)}

<h2>${heading}</h2>

${summaryCard(booking, false)}

${when(
  others.length > 0,
  `<h2>同時に予約した枠</h2>
   ${others.map((entry) => summaryCard(entry, true)).join('\n')}
   <p class="muted">キャンセルは枠ごとに、それぞれの詳細ページからお手続きいただけます。</p>`,
)}

${when(
  Boolean(params.notificationNote),
  `<div class="notice" style="margin-bottom:16px">${esc(params.notificationNote)}</div>`,
)}

<div class="btn-row" style="margin-bottom:16px">
  <a class="btn btn-secondary" href="/my-bookings">マイ予約へ</a>
  <a class="btn btn-secondary" href="/reserve/${esc(booking.page_slug)}">予約ページへ</a>
</div>

${cancelSection}
`;

  const script = canCancel
    ? `<script>
(function () {
  var panel = document.getElementById('cancel-panel');
  var wrap = document.getElementById('cancel-trigger-wrap');
  var trigger = document.getElementById('cancel-trigger');
  var dismiss = document.getElementById('cancel-dismiss');
  var form = document.getElementById('cancel-form');
  if (!panel || !wrap || !trigger || !dismiss) return;

  // JSが動く環境では「キャンセルする」→ 確認セクションの2段階にする。
  // 確認セクション自体が確認UIなので、confirm() は外す。
  if (form) form.removeAttribute('onsubmit');
  panel.hidden = true;
  wrap.hidden = false;
  dismiss.hidden = false;

  trigger.addEventListener('click', function () {
    panel.hidden = false;
    wrap.hidden = true;
    panel.setAttribute('tabindex', '-1');
    panel.focus();
  });
  dismiss.addEventListener('click', function () {
    panel.hidden = true;
    wrap.hidden = false;
    trigger.focus();
  });
})();
</script>`
    : '';

  return layout(
    {
      title: `${heading} | ${booking.page_title}`,
      userName: params.userName,
      alert: params.alert ?? null,
      bodyEnd: script,
    },
    content,
  );
}
