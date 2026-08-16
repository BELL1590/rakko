import { esc } from '../lib/html';
import { formatJstLong } from '../lib/time';
import { layout, noticeCard } from './layout';
import type { TripWithAvailability } from '../db/types';
import { MAX_PARTY_SIZE } from '../services/booking-service';

export interface BookingFormValues {
  representativeName: string;
  phone: string;
  partySize: number;
  companionNames: string[];
  agreed: boolean;
}

/**
 * 予約フォーム。
 * - 選べる人数は残席で制限する（サーバー側でも同じ判定を行う）
 * - 人数に応じて同行者欄を動的に表示
 * - 送信ボタンの二重押下を抑止（本体はサーバー側の重複防止）
 */
export function bookingFormPage(params: {
  trip: TripWithAvailability;
  values: BookingFormValues;
  csrfToken: string;
  userName: string | null;
  friendPromptUrl?: string | null;
  isLineFriend: number | null;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const { trip, values } = params;
  const label = trip.direction === 'outbound' ? '行き' : '帰り';
  const maxSelectable = Math.min(MAX_PARTY_SIZE, trip.remaining_seats);

  const partyOptions = Array.from({ length: MAX_PARTY_SIZE }, (_, i) => i + 1)
    .map((n) => {
      const disabled = n > maxSelectable;
      const selected = n === values.partySize && !disabled;
      return `<option value="${n}"${disabled ? ' disabled' : ''}${selected ? ' selected' : ''}>${n}名${
        disabled ? '（残席不足）' : ''
      }</option>`;
    })
    .join('');

  const companionFields = Array.from({ length: MAX_PARTY_SIZE - 1 }, (_, i) => {
    const index = i + 1;
    const value = values.companionNames[i] ?? '';
    return `<div class="field companion-field" data-companion-index="${index}" hidden>
      <label for="companion_${index}">同行者${index}のお名前<span class="req">必須</span></label>
      <input type="text" id="companion_${index}" name="companion_names" value="${esc(value)}"
        maxlength="50" autocomplete="off">
    </div>`;
  }).join('');

  const friendNotice =
    params.isLineFriend === 0
      ? `<div class="notice" style="margin-bottom:16px">
        現在、草加健康センター公式アカウントの友だち追加が確認できていません。<br>
        <strong>LINEリマインドを受け取るには公式アカウントの友だち追加が必要です。</strong>
        友だち追加をしなくてもご予約は完了できます。
      </div>`
      : '';

  const content = `
<h2>${label}便のご予約</h2>

<section class="card trip-card">
  <span class="trip-badge${trip.direction === 'return' ? ' is-return' : ''}">${label}</span>
  <p class="trip-datetime">${esc(formatJstLong(trip.depart_at))}</p>
  <p class="trip-route">${esc(trip.origin)} → ${esc(trip.destination)}</p>
  <p class="seats">残り <span class="seats-num">${trip.remaining_seats}</span> 席 / ${trip.capacity}席</p>
</section>

${friendNotice}

<form method="post" action="/trips/${esc(trip.slug)}/book" id="booking-form" class="card">
  <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">

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
    <label for="party_size">ご予約人数<span class="req">必須</span></label>
    <select id="party_size" name="party_size" required>${partyOptions}</select>
    <p class="hint">代表者を含めた人数です（1〜4名）。</p>
  </div>

  ${companionFields}

  <div class="field checkbox-field">
    <input type="checkbox" id="agreed" name="agreed" value="1" required${values.agreed ? ' checked' : ''}>
    <label for="agreed">注意事項に同意します<span class="req">必須</span></label>
  </div>

  <button class="btn" type="submit" id="submit-button">この内容で予約する</button>
  <p class="hint center" style="margin-top:8px">送信は1回だけ押してください。</p>
</form>

${noticeCard()}

<p class="center"><a href="/">トップへ戻る</a></p>
`;

  const script = `<script>
(function () {
  var form = document.getElementById('booking-form');
  if (!form) return;
  var partySelect = document.getElementById('party_size');
  var companionFields = Array.prototype.slice.call(
    form.querySelectorAll('.companion-field')
  );

  function syncCompanions() {
    var size = parseInt(partySelect.value, 10) || 1;
    companionFields.forEach(function (field, i) {
      var visible = i < size - 1;
      field.hidden = !visible;
      var input = field.querySelector('input');
      if (input) {
        input.required = visible;
        input.disabled = !visible;
      }
    });
  }

  partySelect.addEventListener('change', syncCompanions);
  syncCompanions();

  var submitting = false;
  form.addEventListener('submit', function (event) {
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
      title: `${label}便のご予約 | らっこ号 池袋便`,
      userName: params.userName,
      alert: params.alert ?? null,
      bodyEnd: script,
    },
    content,
  );
}
