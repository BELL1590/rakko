/**
 * 公開予約ページ `/reserve/:slug`。
 *
 * 1ページに複数の予約枠を並べ、利用者は「予約する枠」と「枠ごとの人数」を選んで
 * 代表者情報を1回だけ入力し、まとめて確定する。
 * 枠ごとに人数が違ってもよいため、同行者欄は枠ごとに用意する。
 */

import { esc, when } from '../lib/html';
import { layout, noticeCard, priceInfoCard } from './layout';
import { slotRoute, slotSeats, slotStateLabel, slotWhen } from './slot-parts';
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

function partyRadios(slot: SlotWithAvailability, value: SlotFormValue): string {
  const maxSelectable = Math.min(slot.max_party_size, slot.remaining_seats);
  const checkedSize =
    value.partySize >= 1 && value.partySize <= maxSelectable ? value.partySize : 1;

  return Array.from({ length: slot.max_party_size }, (_, i) => i + 1)
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
}

function companionFields(slot: SlotWithAvailability, value: SlotFormValue): string {
  return Array.from({ length: slot.max_party_size - 1 }, (_, i) => {
    const index = i + 1;
    const id = `companion_${slot.id}_${index}`;
    return `<div class="field companion-field" data-companion-index="${index}" hidden>
      <label for="${id}">同行者${index}のお名前<span class="req">必須</span></label>
      <input type="text" id="${id}" name="companion_${slot.id}"
        value="${esc(value.companionNames[i] ?? '')}" maxlength="50" autocomplete="off">
    </div>`;
  }).join('');
}

function slotBlock(
  slot: SlotWithAvailability,
  value: SlotFormValue,
  index: number,
): string {
  const bookable = slot.is_bookable;
  const cardClass = `card trip-card slot-card${index % 2 === 1 ? ' is-return' : ''}${
    bookable ? '' : ' is-full'
  }`;

  const picker = bookable
    ? `<div class="slot-pick">
      <label class="checkbox-field slot-toggle" for="slot_${slot.id}">
        <input type="checkbox" id="slot_${slot.id}" name="slot_selected" value="${slot.id}"
          data-slot-toggle${value.selected ? ' checked' : ''}>
        <span><strong>この枠を予約する</strong></span>
      </label>
      <div class="slot-fields" data-slot-fields>
        <div class="field">
          <label id="party_label_${slot.id}">ご予約人数<span class="req">必須</span></label>
          <div class="party" role="group" aria-labelledby="party_label_${slot.id}">
            ${partyRadios(slot, value)}
          </div>
          <p class="hint">代表者を含めた人数です（最大${slot.max_party_size}名 / 残り${slot.remaining_seats}席）。</p>
        </div>
        ${companionFields(slot, value)}
      </div>
    </div>`
    : `<p class="muted" style="margin:12px 0 0">${
        slot.is_full ? 'この枠は満席です。' : 'この枠は現在受付を停止しています。'
      }</p>`;

  return `<article class="${cardClass}" data-slot-block>
  <div class="trip-card__head">
    <span class="trip-card__dir">${esc(slot.name)}</span>
    <span class="trip-card__state">${esc(slotStateLabel(slot))}</span>
  </div>
  <div class="trip-card__body">
    ${slotWhen(slot)}
    ${when(Boolean(slot.description), `<p class="trip-meta">${esc(slot.description)}</p>`)}
    ${slotRoute(slot)}
    ${slotSeats(slot)}
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
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const { page, slots, values } = params;
  const visibleSlots = slots.filter((slot) => slot.is_visible);
  const bookableSlots = visibleSlots.filter((slot) => slot.is_bookable);

  const slotCards = visibleSlots
    .map((slot, index) =>
      slotBlock(
        slot,
        values.slots[slot.id] ?? { selected: false, partySize: 1, companionNames: [] },
        index,
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

  <div class="field">
    <div class="checkbox-field">
      <input type="checkbox" id="agreed" name="agreed" value="1" required${
        values.agreed ? ' checked' : ''
      }>
      <label for="agreed">注意事項を確認し、内容に同意します<span class="req">必須</span></label>
    </div>
  </div>

  <button class="btn" type="submit" id="submit-button">選択した予約をまとめて確定する</button>
  <p class="hint center" style="margin-top:8px">送信は1回だけ押してください。</p>
</div>`;

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

  function syncBlock(block) {
    var toggle = block.querySelector('[data-slot-toggle]');
    var fields = block.querySelector('[data-slot-fields]');
    if (!toggle || !fields) return;

    var selected = toggle.checked;
    fields.hidden = !selected;
    block.classList.toggle('is-selected', selected);

    var size = 1;
    var radios = Array.prototype.slice.call(fields.querySelectorAll('input[type="radio"]'));
    radios.forEach(function (radio) {
      radio.disabled = radio.disabled && !selected ? radio.disabled : radio.disabled;
      if (radio.checked) size = parseInt(radio.value, 10) || 1;
    });

    var companions = Array.prototype.slice.call(fields.querySelectorAll('.companion-field'));
    companions.forEach(function (field, i) {
      var visible = selected && i < size - 1;
      field.hidden = !visible;
      var input = field.querySelector('input');
      if (input) {
        input.required = visible;
        input.disabled = !visible;
      }
    });
  }

  function syncAll() {
    blocks.forEach(syncBlock);
  }

  blocks.forEach(function (block) {
    var toggle = block.querySelector('[data-slot-toggle]');
    if (toggle) toggle.addEventListener('change', function () { syncBlock(block); });
    Array.prototype.slice.call(block.querySelectorAll('input[type="radio"]')).forEach(
      function (radio) {
        radio.addEventListener('change', function () { syncBlock(block); });
      },
    );
    // 同じ人数の枠には同行者名をそのまま流用できるようにする（未入力の枠のみ）
    Array.prototype.slice.call(block.querySelectorAll('.companion-field input')).forEach(
      function (input) {
        input.addEventListener('input', function () {
          input.dataset.touched = '1';
          copyCompanions(block);
        });
      },
    );
  });

  function companionValues(block) {
    return Array.prototype.slice
      .call(block.querySelectorAll('.companion-field:not([hidden]) input'))
      .map(function (input) { return input.value; });
  }

  function partySize(block) {
    var checked = block.querySelector('input[type="radio"]:checked');
    return checked ? parseInt(checked.value, 10) || 1 : 1;
  }

  function copyCompanions(source) {
    var values = companionValues(source);
    var size = partySize(source);
    blocks.forEach(function (block) {
      if (block === source) return;
      var toggle = block.querySelector('[data-slot-toggle]');
      if (!toggle || !toggle.checked) return;
      if (partySize(block) !== size) return;
      var inputs = Array.prototype.slice.call(
        block.querySelectorAll('.companion-field:not([hidden]) input'),
      );
      inputs.forEach(function (input, i) {
        if (input.dataset.touched === '1') return;
        if (values[i] !== undefined) input.value = values[i];
      });
    });
  }

  syncAll();

  var submitting = false;
  form.addEventListener('submit', function (event) {
    var anySelected = blocks.some(function (block) {
      var toggle = block.querySelector('[data-slot-toggle]');
      return toggle && toggle.checked;
    });
    if (!anySelected) {
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
