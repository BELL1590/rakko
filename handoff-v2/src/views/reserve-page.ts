/**
 * 公開予約ページ `/reserve/:slug`。
 *
 * 1ページに複数の予約枠を並べ、利用者は「予約する枠」と「枠ごとの人数」を選んで
 * 代表者情報を1回だけ入力し、まとめて確定する。
 * 枠ごとに人数が違ってもよいため、同行者欄は枠ごとに用意する。
 *
 * D1 / D2 の変更点:
 * - 枠カードの赤緑交互配色を廃止（ブランド赤に統一。選択状態は枠線・リング・チェックで表す）
 * - 下部固定CTA + 選択中サマリー（何を何名選んだかを常に見せる）
 * - 送信前の確認セクション（同一フォーム内。ルート・POST先の追加なし）
 * - max_party_size === 1 の枠では人数選択UIを出さず hidden で 1 を送る
 * - 受付開始前 / 受付中 / 受付終了 / 受付停止中 / 満席 を slotState() で正確に区別し、
 *   booking_open_at / booking_close_at を公開UIに反映する
 * - 同行者欄にサーバー側で hidden を付けない（JS無効でも2名以上を入力・POSTできる）
 */

import { esc, when } from '../lib/html';
import { layout, noticeCard, priceInfoCard } from './layout';
import {
  slotRoute,
  slotSeats,
  slotState,
  slotStateLabel,
  slotTiming,
  slotWhen,
} from './slot-parts';
import { formatJstLong } from '../lib/time';
import type { ReservationPageRow, SlotWithAvailability } from '../db/types';

export interface SlotFormValue {
  selected: boolean;
  partySize: number;
  companionNames: string[];
}

export interface ReservePageValues {
  representativeName: string;
  phone: string;
  agreed: boolean;
  /** slotId -> 入力値 */
  slots: Record<number, SlotFormValue>;
}

export function emptyReserveValues(): ReservePageValues {
  return { representativeName: '', phone: '', agreed: false, slots: {} };
}

/** 枠の場所表記（バスは区間、それ以外は会場）。確認セクションとサマリーで使う。 */
function slotWhereText(slot: SlotWithAvailability): string {
  if (slot.origin && slot.destination) return `${slot.origin} → ${slot.destination}`;
  return slot.location ?? slot.origin ?? '';
}

function partyRadios(slot: SlotWithAvailability, value: SlotFormValue): string {
  // 1予約1名しか受け付けない枠では人数選択そのものが不要。
  // POSTされる値の形は変えないため hidden で 1 を送る。
  if (slot.max_party_size <= 1) {
    return `<input type="hidden" name="party_size_${slot.id}" value="1">
      <p class="slot-single-party">この枠はお1人ずつのご予約です。</p>`;
  }

  const maxSelectable = Math.min(slot.max_party_size, slot.remaining_seats);
  const checkedSize =
    value.partySize >= 1 && value.partySize <= maxSelectable ? value.partySize : 1;

  const options = Array.from({ length: slot.max_party_size }, (_, i) => i + 1)
    .map((n) => {
      const disabled = n > maxSelectable;
      const checked = n === checkedSize && !disabled;
      const id = `party_size_${slot.id}_${n}`;
      return `<label class="party__opt" for="${id}">
        <input type="radio" id="${id}" name="party_size_${slot.id}" value="${n}"
          ${checked ? 'checked ' : ''}${disabled ? 'disabled ' : ''}>
        <span>${n}名</span>
      </label>`;
    })
    .join('');

  const hint =
    maxSelectable < slot.max_party_size
      ? `代表者を含めた人数です。残り${slot.remaining_seats}席のため、${maxSelectable + 1}名以上は選択できません。`
      : `代表者を含めた人数です（最大${slot.max_party_size}名）。`;

  return `<div class="field">
      <label id="party_label_${slot.id}">ご予約人数<span class="req">必須</span></label>
      <div class="party${slot.max_party_size > 4 ? ' party--wide' : ''}" role="group"
        aria-labelledby="party_label_${slot.id}">${options}</div>
      <p class="hint">${hint}</p>
    </div>`;
}

/**
 * 同行者氏名の入力欄。
 *
 * hidden を初期状態で付けない（＝JS無効でも入力できる）ことが重要。
 * JSが有効な場合は syncBlock() が人数に応じて不要な欄を hidden + disabled にする。
 * JS無効時は最大人数分の欄がすべて表示され、上から必要な数だけ記入してもらう。
 * 空欄はサーバー側で無視されるため、2名以上の予約もJS無効で成立する。
 */
function companionFields(slot: SlotWithAvailability, value: SlotFormValue): string {
  if (slot.max_party_size <= 1) return '';

  const fields = Array.from({ length: slot.max_party_size - 1 }, (_, i) => {
    const index = i + 1;
    const id = `companion_${slot.id}_${index}`;
    return `<div class="field companion-field" data-companion-index="${index}">
      <label for="${id}">同行者${index}のお名前</label>
      <input type="text" id="${id}" name="companion_${slot.id}"
        value="${esc(value.companionNames[i] ?? '')}" maxlength="50" autocomplete="off">
    </div>`;
  }).join('');

  return `<div class="companion-group" data-companion-group>
      <p class="companion-group__lead">同行者のお名前</p>
      <p class="hint" data-companion-hint>選んだ人数に応じて、上から順にご記入ください（代表者は除きます）。</p>
      ${fields}
    </div>`;
}

function slotBlock(
  slot: SlotWithAvailability,
  value: SlotFormValue,
  nowUtc: string,
): string {
  const state = slotState(slot, nowUtc);
  // 状態ごとにカードの見え方を変える。赤緑交互は使わない。
  const stateClass =
    state === 'open'
      ? ''
      : state === 'before_open'
        ? ' is-waiting'
        : ' is-full';
  const bookable = state === 'open';
  const cardClass = `card trip-card slot-card${stateClass}${
    value.selected ? ' is-selected' : ''
  }`;

  const picker = bookable
    ? `<div class="slot-pick">
      <label class="checkbox-field slot-toggle" for="slot_${slot.id}">
        <input type="checkbox" id="slot_${slot.id}" name="slot_selected" value="${slot.id}"
          data-slot-toggle${value.selected ? ' checked' : ''}>
        <span><strong>この枠を予約する</strong></span>
      </label>
      <div class="slot-fields" data-slot-fields>
        ${partyRadios(slot, value)}
        ${companionFields(slot, value)}
      </div>
    </div>`
    // 予約できない理由は slotSeats() / slotTiming() が一度だけ表示する（二重表示を防ぐ）
    : '';

  return `<article class="${cardClass}" data-slot-block
  data-slot-name="${esc(slot.name)}"
  data-slot-when="${esc(formatJstLong(slot.start_at))}"
  data-slot-where="${esc(slotWhereText(slot))}">
  <div class="trip-card__head">
    <span class="trip-card__dir">${esc(slot.name)}</span>
    <span class="trip-card__state">${esc(slotStateLabel(slot, nowUtc))}</span>
  </div>
  <div class="trip-card__body">
    ${slotWhen(slot)}
    ${when(Boolean(slot.description), `<p class="trip-meta">${esc(slot.description)}</p>`)}
    ${slotRoute(slot)}
    ${slotSeats(slot, nowUtc)}
    ${slotTiming(slot, nowUtc)}
    ${picker}
  </div>
</article>`;
}

export function reservePage(params: {
  page: ReservationPageRow;
  slots: SlotWithAvailability[];
  values: ReservePageValues;
  csrfToken: string;
  userName: string | null;
  isLineFriend: number | null;
  loggedIn: boolean;
  loginUrl: string;
  /** 省略時は現在時刻。テストから固定したい場合のみ渡す。 */
  nowUtc?: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const { page, slots, values } = params;
  const nowUtc = params.nowUtc ?? new Date().toISOString();
  const visibleSlots = slots.filter((slot) => slot.is_visible);
  const bookableSlots = visibleSlots.filter(
    (slot) => slotState(slot, nowUtc) === 'open',
  );

  const slotCards = visibleSlots
    .map((slot) =>
      slotBlock(
        slot,
        values.slots[slot.id] ?? { selected: false, partySize: 1, companionNames: [] },
        nowUtc,
      ),
    )
    .join('\n');

  const friendNotice =
    params.isLineFriend === 0
      ? `<div class="notice" style="margin-bottom:16px">
        現在、草加健康センター公式アカウントの友だち追加が確認できていません。<br>
        <strong>LINEリマインドを受け取るには公式アカウントの友だち追加が必要です。</strong>
        友だち追加をしなくてもご予約は完了できます。
      </div>`
      : '';

  const multiHint =
    page.allow_multi_slot_booking === 1 && bookableSlots.length > 1
      ? `<div class="notice" style="margin-bottom:16px">
        予約したい枠にチェックを入れて、それぞれの人数を選んでください。
        <strong>最大${page.max_slots_per_checkout}枠までまとめて予約できます。</strong>
        枠ごとに人数が違っても構いません。
      </div>`
      : '';

  /**
   * 送信前の確認セクション。
   * JSが無効な環境ではこのセクションがそのまま開いた状態で、
   * 中の送信ボタンから既存の POST 先へ普通に送信できる（＝従来の挙動）。
   * JSが有効な場合は下部固定CTAから開く2段階にする。
   */
  const confirmPanel = `<section class="confirm-panel" id="reserve-confirm" data-confirm-panel>
  <h3>ご予約内容の確認</h3>
  <p class="confirm-lead">まだ予約は確定していません。内容をご確認のうえ、下のボタンで確定してください。</p>

  <div data-confirm-slots></div>

  <ul class="summary-list" data-confirm-rep>
    <li><span class="k">代表者</span><span class="v" data-confirm-name>—</span></li>
    <li><span class="k">電話番号</span><span class="v" data-confirm-phone>—</span></li>
    <li><span class="k">予約件数</span><span class="v" data-confirm-count>—</span></li>
  </ul>

  <button class="btn" type="submit" id="submit-button">選択した予約をまとめて確定する</button>
  <p class="hint center" style="margin-top:8px">送信は1回だけ押してください。</p>
  <button class="btn btn-secondary" type="button" id="confirm-dismiss" hidden
    style="margin-top:10px">内容を変更する</button>
</section>`;

  const stickyCta = `<div class="sticky-cta" id="sticky-cta" hidden data-sticky-cta>
  <div class="sticky-cta__summary" data-sticky-summary hidden>
    <div class="sticky-cta__head">
      <span data-sticky-count>選択中 0件</span>
      <span class="sticky-cta__total" data-sticky-total>合計 0名</span>
    </div>
    <ul class="sticky-cta__list" data-sticky-list></ul>
  </div>
  <button class="btn" type="button" id="open-confirm" data-open-confirm disabled>予約する枠を選んでください</button>
  <p class="sticky-cta__hint" data-sticky-hint></p>
</div>`;

  const formBody = `
${slotCards}

<h2>代表者のご入力</h2>
<div class="card">
  <div class="field">
    <label for="representative_name">代表者氏名<span class="req">必須</span></label>
    <input type="text" id="representative_name" name="representative_name"
      value="${esc(values.representativeName)}" maxlength="50" required autocomplete="name">
    <p class="hint">当日受付でお呼びするお名前です。LINEの表示名とは別に入力してください。</p>
  </div>

  <div class="field">
    <label for="phone">電話番号<span class="req">必須</span></label>
    <input type="tel" id="phone" name="phone" value="${esc(values.phone)}"
      inputmode="tel" maxlength="20" required autocomplete="tel">
    <p class="hint">当日ご連絡できる番号（ハイフンあり・なしどちらも可）</p>
  </div>

  <div class="field" style="margin-bottom:0">
    <div class="checkbox-field">
      <input type="checkbox" id="agreed" name="agreed" value="1" required${
        values.agreed ? ' checked' : ''
      }>
      <label for="agreed">注意事項を確認し、内容に同意します<span class="req">必須</span></label>
    </div>
  </div>
</div>

${confirmPanel}
${stickyCta}`;

  const content = `
<section class="hero" style="margin:-16px -16px 16px">
  <h1>${esc(page.title)}</h1>
  ${when(
    Boolean(page.description),
    `<p>${esc(page.description).replace(/\n/g, '<br>')}</p>`,
  )}
</section>

${friendNotice}
${multiHint}

<h2>予約する枠を選ぶ</h2>
${
  visibleSlots.length === 0
    ? '<div class="card"><p class="muted" style="margin:0">現在ご案内できる枠はありません。</p></div>'
    : ''
}

${
  params.loggedIn
    ? `<form method="post" action="/reserve/${esc(page.slug)}/book" id="reserve-form">
  <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
  ${formBody}
</form>`
    : `${slotCards}
<div class="card center">
  <p style="margin-top:0">ご予約にはLINEログインが必要です。</p>
  <a class="btn btn-line" href="${esc(params.loginUrl)}">LINEでログインして予約する</a>
</div>`
}

${when(page.page_type === 'bus', `<h2>料金のご案内</h2>${priceInfoCard()}`)}

<h2>注意事項</h2>
${noticeCard()}

<p class="center"><a class="btn btn-secondary" href="/my-bookings">マイ予約を確認する</a></p>
`;

  const script = `<script>
(function () {
  var form = document.getElementById('reserve-form');
  if (!form) return;

  var blocks = Array.prototype.slice.call(form.querySelectorAll('[data-slot-block]'));
  var panel = form.querySelector('[data-confirm-panel]');
  var sticky = form.querySelector('[data-sticky-cta]');
  var openBtn = form.querySelector('[data-open-confirm]');
  var dismissBtn = document.getElementById('confirm-dismiss');
  var nameInput = document.getElementById('representative_name');
  var phoneInput = document.getElementById('phone');
  var agreedInput = document.getElementById('agreed');

  // JSが有効なときだけ段階的にUIを絞る。
  // サーバー側は同行者欄を hidden にしていないので、JS無効なら
  // 「全枠の人数ラジオ＋最大人数分の同行者欄＋確認セクション」が
  // すべて見える従来のフォーム1枚として成立する。
  if (panel) panel.hidden = true;
  if (sticky) sticky.hidden = false;
  if (dismissBtn) dismissBtn.hidden = false;

  function partySize(block) {
    var checked = block.querySelector('input[type="radio"]:checked');
    if (checked) return parseInt(checked.value, 10) || 1;
    var hidden = block.querySelector('input[type="hidden"][name^="party_size_"]');
    return hidden ? parseInt(hidden.value, 10) || 1 : 1;
  }

  function isSelected(block) {
    var toggle = block.querySelector('[data-slot-toggle]');
    return !!(toggle && toggle.checked);
  }

  function companionInputs(block) {
    return Array.prototype.slice.call(
      block.querySelectorAll('.companion-field:not([hidden]) input:not([disabled])'),
    );
  }

  function syncBlock(block) {
    var fields = block.querySelector('[data-slot-fields]');
    if (!fields) return;
    var selected = isSelected(block);
    fields.hidden = !selected;
    block.classList.toggle('is-selected', selected);

    var size = partySize(block);
    Array.prototype.slice.call(block.querySelectorAll('.companion-field')).forEach(
      function (field, i) {
        var visible = selected && i < size - 1;
        field.hidden = !visible;
        var input = field.querySelector('input');
        if (input) {
          input.required = visible;
          input.disabled = !visible;
        }
      },
    );
  }

  function selectedBlocks() {
    return blocks.filter(isSelected);
  }

  function missingLabels() {
    var missing = [];
    var picked = selectedBlocks();
    if (picked.length === 0) missing.push('枠を選択');
    if (nameInput && !nameInput.value.trim()) missing.push('代表者氏名');
    if (phoneInput && !phoneInput.value.trim()) missing.push('電話番号');
    var compMissing = picked.some(function (block) {
      return companionInputs(block).some(function (input) { return !input.value.trim(); });
    });
    if (compMissing) missing.push('同行者のお名前');
    if (agreedInput && !agreedInput.checked) missing.push('注意事項の同意');
    return missing;
  }

  function syncSticky() {
    if (!sticky || !openBtn) return;
    var picked = selectedBlocks();
    var total = picked.reduce(function (sum, block) { return sum + partySize(block); }, 0);

    var summary = sticky.querySelector('[data-sticky-summary]');
    var list = sticky.querySelector('[data-sticky-list]');
    if (summary && list) {
      summary.hidden = picked.length === 0;
      list.innerHTML = '';
      picked.forEach(function (block) {
        var li = document.createElement('li');
        var name = document.createElement('span');
        name.className = 's-name';
        name.textContent = block.getAttribute('data-slot-name') || '';
        var when = document.createElement('span');
        when.className = 's-when';
        when.textContent = block.getAttribute('data-slot-when') || '';
        var size = document.createElement('span');
        size.className = 's-size';
        size.textContent = partySize(block) + '名';
        li.appendChild(name);
        li.appendChild(when);
        li.appendChild(size);
        list.appendChild(li);
      });
      var countEl = sticky.querySelector('[data-sticky-count]');
      var totalEl = sticky.querySelector('[data-sticky-total]');
      if (countEl) countEl.textContent = '選択中 ' + picked.length + '件';
      if (totalEl) totalEl.textContent = '合計 ' + total + '名';
    }

    var missing = missingLabels();
    openBtn.disabled = missing.length > 0;
    openBtn.textContent = picked.length
      ? '選択した' + picked.length + '件の内容を確認する'
      : '予約する枠を選んでください';

    var hint = sticky.querySelector('[data-sticky-hint]');
    if (hint) hint.textContent = missing.length ? '未入力：' + missing.join(' / ') : '';
  }

  function fillConfirm() {
    if (!panel) return;
    var picked = selectedBlocks();
    var host = panel.querySelector('[data-confirm-slots]');
    if (host) {
      host.innerHTML = '';
      picked.forEach(function (block) {
        var size = partySize(block);
        var comps = companionInputs(block)
          .map(function (input) { return input.value.trim(); })
          .filter(function (v) { return v; });

        var card = document.createElement('div');
        card.className = 'confirm-slot';

        var head = document.createElement('div');
        head.className = 'confirm-slot__head';
        var hName = document.createElement('span');
        hName.className = 'confirm-slot__name';
        hName.textContent = block.getAttribute('data-slot-name') || '';
        var hSize = document.createElement('span');
        hSize.className = 'confirm-slot__size';
        hSize.textContent = size + '名';
        head.appendChild(hName);
        head.appendChild(hSize);

        var body = document.createElement('div');
        body.className = 'confirm-slot__body';
        var pWhen = document.createElement('p');
        pWhen.className = 'confirm-slot__when';
        pWhen.textContent = block.getAttribute('data-slot-when') || '';
        body.appendChild(pWhen);
        var whereText = block.getAttribute('data-slot-where');
        if (whereText) {
          var pWhere = document.createElement('p');
          pWhere.className = 'confirm-slot__where';
          pWhere.textContent = whereText;
          body.appendChild(pWhere);
        }
        var pComp = document.createElement('p');
        pComp.className = 'confirm-slot__comp';
        pComp.textContent = '同行者：' + (comps.length ? comps.join('、') : '—');
        body.appendChild(pComp);

        card.appendChild(head);
        card.appendChild(body);
        host.appendChild(card);
      });
    }

    var total = picked.reduce(function (sum, block) { return sum + partySize(block); }, 0);
    var nameEl = panel.querySelector('[data-confirm-name]');
    var phoneEl = panel.querySelector('[data-confirm-phone]');
    var countEl = panel.querySelector('[data-confirm-count]');
    if (nameEl) nameEl.textContent = (nameInput && nameInput.value.trim()) || '—';
    if (phoneEl) phoneEl.textContent = (phoneInput && phoneInput.value.trim()) || '—';
    if (countEl) countEl.textContent = picked.length + '件 / 計' + total + '名';
  }

  blocks.forEach(function (block) {
    var toggle = block.querySelector('[data-slot-toggle]');
    if (toggle) {
      toggle.addEventListener('change', function () { syncBlock(block); syncSticky(); });
    }
    Array.prototype.slice.call(block.querySelectorAll('input[type="radio"]')).forEach(
      function (radio) {
        radio.addEventListener('change', function () { syncBlock(block); syncSticky(); });
      },
    );
    Array.prototype.slice.call(block.querySelectorAll('.companion-field input')).forEach(
      function (input) {
        input.addEventListener('input', function () {
          input.dataset.touched = '1';
          copyCompanions(block);
          syncSticky();
        });
      },
    );
  });

  function copyCompanions(source) {
    var values = companionInputs(source).map(function (input) { return input.value; });
    var size = partySize(source);
    blocks.forEach(function (block) {
      if (block === source || !isSelected(block)) return;
      if (partySize(block) !== size) return;
      companionInputs(block).forEach(function (input, i) {
        if (input.dataset.touched === '1') return;
        if (values[i] !== undefined) input.value = values[i];
      });
    });
  }

  [nameInput, phoneInput].forEach(function (input) {
    if (input) input.addEventListener('input', syncSticky);
  });
  if (agreedInput) agreedInput.addEventListener('change', syncSticky);

  if (openBtn && panel) {
    openBtn.addEventListener('click', function () {
      if (missingLabels().length > 0) return;
      fillConfirm();
      panel.hidden = false;
      if (sticky) sticky.hidden = true;
      panel.setAttribute('tabindex', '-1');
      panel.focus();
    });
  }

  if (dismissBtn && panel) {
    dismissBtn.addEventListener('click', function () {
      panel.hidden = true;
      if (sticky) sticky.hidden = false;
      syncSticky();
    });
  }

  blocks.forEach(syncBlock);
  syncSticky();

  var submitting = false;
  form.addEventListener('submit', function (event) {
    if (selectedBlocks().length === 0) {
      event.preventDefault();
      window.alert('予約する枠を1つ以上選択してください。');
      return;
    }
    if (submitting) {
      event.preventDefault();
      return;
    }
    submitting = true;
    var button = document.getElementById('submit-button');
    if (button) {
      button.disabled = true;
      button.textContent = '送信中…';
    }
  });
})();
</script>`;

  return layout(
    {
      title: `${page.title} | 予約`,
      userName: params.userName,
      alert: params.alert ?? null,
      bodyEnd: params.loggedIn ? script : '',
    },
    content,
  );
}
