import { esc, when } from '../lib/html';
import { formatJstLong, formatJstTime } from '../lib/time';
import { layout, splitPlace } from './layout';
import { parseCompanionNames, type BookingWithTrip } from '../db/types';

/**
 * 予約完了 / 予約詳細ページ。
 *
 * キャンセルは誤操作防止のため、詳細を再表示してから確定する2段階にする。
 * ルートもPOST先も変更しない（同一ページ内で確認セクションを開くだけ）。
 * JSが無効な場合は確認セクションが最初から開いた状態で、従来の confirm() が働く。
 */
export function bookingDetailPage(params: {
  booking: BookingWithTrip;
  csrfToken: string;
  userName: string | null;
  justCompleted: boolean;
  notificationNote?: string | null;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const { booking } = params;
  const isReturn = booking.direction === 'return';
  const label = isReturn ? '帰り' : '行き';
  const companions = parseCompanionNames(booking.companion_names_json);
  const cancelled = booking.status === 'cancelled';
  const departed = booking.depart_at <= new Date().toISOString();
  const canCancel = !cancelled && !departed;

  const heading = params.justCompleted ? 'ご予約が完了しました' : 'ご予約の詳細';

  const long = formatJstLong(booking.depart_at);
  const time = formatJstTime(booking.depart_at);
  const date = long.endsWith(time) ? long.slice(0, long.length - time.length) : long;
  const from = splitPlace(booking.origin);
  const to = splitPlace(booking.destination);

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
      <li><span class="k">日時</span><span class="v">${esc(long)}</span></li>
      <li><span class="k">区間</span><span class="v">${esc(booking.origin)} → ${esc(booking.destination)}</span></li>
      <li><span class="k">ご予約人数</span><span class="v">${booking.party_size}名</span></li>
      ${when(
        companions.length > 0,
        `<li><span class="k">同行者</span><span class="v">${esc(companions.join('、'))}</span></li>`,
      )}
    </ul>
    <p class="muted" style="margin-top:0">キャンセルすると座席は他のお客様へ開放されます。取り消しはできません。再度ご乗車される場合は、あらためて予約が必要です。</p>
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

<section class="card trip-card${isReturn ? ' is-return' : ''}">
  <div class="trip-card__head">
    <span class="trip-card__dir">${label}</span>
    <span class="trip-card__state">${
      cancelled ? 'キャンセル済み' : '予約済み'
    }</span>
  </div>
  <div class="trip-card__body">
    <p class="trip-when">
      <span class="trip-date">${esc(date)}</span>
      <span class="trip-time">${esc(time)}</span>
    </p>
    <div class="route">
      <span class="route__col from">
        <span class="route__label">出発・集合</span>
        <span class="route__place">${esc(from.main)}</span>
        ${from.sub ? `<span class="route__sub">${esc(from.sub)}</span>` : ''}
      </span>
      <span class="route__arrow" aria-hidden="true">▶</span>
      <span class="route__col to">
        <span class="route__label">到着</span>
        <span class="route__place">${esc(to.main)}</span>
        ${to.sub ? `<span class="route__sub">${esc(to.sub)}</span>` : ''}
      </span>
    </div>

    <ul class="summary-list">
      <li><span class="k">予約ID</span><span class="v">#${booking.id}</span></li>
      <li><span class="k">乗車場所</span><span class="v">${esc(booking.origin)}</span></li>
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
</section>

${when(
  Boolean(params.notificationNote),
  `<div class="notice" style="margin-bottom:16px">${esc(params.notificationNote)}</div>`,
)}

<div class="btn-row" style="margin-bottom:16px">
  <a class="btn btn-secondary" href="/my-bookings">マイ予約へ</a>
  <a class="btn btn-secondary" href="/">トップへ戻る</a>
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
      title: `${heading} | らっこ号 池袋便`,
      userName: params.userName,
      alert: params.alert ?? null,
      bodyEnd: script,
    },
    content,
  );
}
