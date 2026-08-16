import { esc } from '../lib/html';
import { formatJstLong, formatJstTime } from '../lib/time';
import { layout, noticeCard, seatBadge, splitPlace } from './layout';
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
 * - 予約対象の便を上部に固定表示（何を予約しているか見失わせない）
 * - 人数は select ではなく大きなラジオボタン（name="party_size" は従来どおり）
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
  const isReturn = trip.direction === 'return';
  const label = isReturn ? '帰り' : '行き';
  const maxSelectable = Math.min(MAX_PARTY_SIZE, trip.remaining_seats);

  // select と同じく「必ず1つは選ばれている」状態にする
  const checkedSize =
    values.partySize >= 1 && values.partySize <= maxSelectable ? values.partySize : 1;

  const partyOptions = Array.from({ length: MAX_PARTY_SIZE }, (_, i) => i + 1)
    .map((n) => {
      const disabled = n > maxSelectable;
      const checked = n === checkedSize && !disabled;
      return `<label class="party__opt" for="party_size_${n}">
        <input type="radio" id="party_size_${n}" name="party_size" value="${n}"
          ${checked ? 'checked ' : ''}${disabled ? 'disabled ' : ''}required>
        <span>${n}名</span>
      </label>`;
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

  const long = formatJstLong(trip.depart_at);
  const time = formatJstTime(trip.depart_at);
  const date = long.endsWith(time) ? long.slice(0, long.length - time.length) : long;
  const from = splitPlace(trip.origin);
  const to = splitPlace(trip.destination);

  const content = `
<h2>${label}便のご予約</h2>

<section class="card trip-card${isReturn ? ' is-return' : ''}">
  <div class="trip-card__head">
    <span class="trip-card__dir">${label}<span class="trip-card__tag">${isReturn ? '草加 発' : '池袋 発'}</span></span>
    <span class="trip-card__state">受付中</span>
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
    <p class="seats">残り <span class="seats-num">${trip.remaining_seats}</span> 席 / ${trip.capacity}席${seatBadge(trip)}</p>
  </div>
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
    <label id="party_size_label" for="party_size_1">ご予約人数<span class="req">必須</span></label>
    <div class="party${isReturn ? ' is-return' : ''}" role="group" aria-labelledby="party_size_label">${partyOptions}</div>
    <p class="hint">${
      maxSelectable < MAX_PARTY_SIZE
        ? `代表者を含めた人数です。残り${trip.remaining_seats}席のため、${maxSelectable + 1}名以上は選択できません。`
        : '代表者を含めた人数です（1〜4名）。'
    }</p>
  </div>

  ${companionFields}

  <div class="field">
    <div class="checkbox-field">
      <input type="checkbox" id="agreed" name="agreed" value="1" required${values.agreed ? ' checked' : ''}>
      <label for="agreed">注意事項を確認し、内容に同意します<span class="req">必須</span></label>
    </div>
  </div>

  <button class="btn${isReturn ? ' btn-return' : ''}" type="submit" id="submit-button">この内容で予約する</button>
  <p class="hint center" style="margin-top:8px">送信は1回だけ押してください。</p>
</form>

${noticeCard()}

<p class="center"><a href="/">トップへ戻る</a></p>
`;

  const script = `<script>
(function () {
  var form = document.getElementById('booking-form');
  if (!form) return;
  var partyRadios = Array.prototype.slice.call(
    form.querySelectorAll('input[name="party_size"]')
  );
  var companionFields = Array.prototype.slice.call(
    form.querySelectorAll('.companion-field')
  );

  function selectedSize() {
    for (var i = 0; i < partyRadios.length; i++) {
      if (partyRadios[i].checked) return parseInt(partyRadios[i].value, 10) || 1;
    }
    return 1;
  }

  function syncCompanions() {
    var size = selectedSize();
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

  partyRadios.forEach(function (radio) {
    radio.addEventListener('change', syncCompanions);
  });
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
