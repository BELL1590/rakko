(function () {
  // 枠カード（data-slot-block）は未ログイン時にも表示される
  // （ログインを促す前に、どんな枠があるか見せるため）。
  // そのため人数選択に応じた同行者欄の表示切り替えは、
  // #reserve-form の有無に関係なく常に動かす必要がある。
  // document から探すことで、フォームの外にある枠カードにも対応する。
  var blocks = Array.prototype.slice.call(document.querySelectorAll('[data-slot-block]'));

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

  // onSlotChange は枠選択・人数・同行者の変更のたびに呼ばれる。
  // #reserve-form があるログイン済みフローでは sticky CTA の再同期に差し替える
  // （下の form 分岐内で上書きする）。フォームが無い場合は syncBlock だけで十分。
  var onSlotChange = function () {};

  blocks.forEach(function (block) {
    var toggle = block.querySelector('[data-slot-toggle]');
    if (toggle) {
      toggle.addEventListener('change', function () { syncBlock(block); onSlotChange(); });
    }
    Array.prototype.slice.call(block.querySelectorAll('input[type="radio"]')).forEach(
      function (radio) {
        radio.addEventListener('change', function () { syncBlock(block); onSlotChange(); });
      },
    );
    Array.prototype.slice.call(block.querySelectorAll('.companion-field input')).forEach(
      function (input) {
        input.addEventListener('input', function () {
          input.dataset.touched = '1';
          copyCompanions(block);
          onSlotChange();
        });
      },
    );
  });

  // 人数選択に応じた同行者欄の表示は、ログイン状態に関係なく初期表示から揃える。
  blocks.forEach(syncBlock);

  // ここから先（sticky CTA・確認パネル・代表者入力・送信）は
  // 予約フォームがあるとき、つまりログイン済みで実際に送信できるときだけ動かす。
  // 未ログイン時は #reserve-form 自体が存在しないため、ここで抜ける。
  var form = document.getElementById('reserve-form');
  if (!form) return;

  var panel = form.querySelector('[data-confirm-panel]');
  // sticky CTA は position:fixed の下部固定バーで、</form> の外に描画されている。
  // form.querySelector で探すと必ず null になり、
  // 「確認パネルは hidden、CTA も出ない」＝送信手段ゼロになるため document から探す。
  var sticky = document.querySelector('[data-sticky-cta]');
  var openBtn = document.querySelector('[data-open-confirm]');
  var dismissBtn = document.getElementById('confirm-dismiss');
  var nameInput = document.getElementById('representative_name');
  var phoneInput = document.getElementById('phone');
  var agreedInput = document.getElementById('agreed');

  // JSが有効なときだけ段階的にUIを絞る。
  // サーバー側は同行者欄を hidden にしていないので、JS無効なら
  // 「全枠の人数ラジオ＋最大人数分の同行者欄＋確認セクション」が
  // すべて見える従来のフォーム1枚として成立する。
  //
  // 確認パネルを隠してよいのは、代わりの導線（sticky CTA と開くボタン）が
  // 揃っているときだけ。片方でも欠けたら隠さない。
  // 隠したうえで代替導線も出せないと、送信ボタンへ到達する手段が無くなる。
  var hasStickyPath = !!(sticky && openBtn);
  if (panel && hasStickyPath) panel.hidden = true;
  if (hasStickyPath) sticky.hidden = false;
  if (dismissBtn && hasStickyPath) dismissBtn.hidden = false;

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

  // 枠選択・人数・同行者の変更で sticky CTA を再同期する（フォームがある場合のみ）
  onSlotChange = syncSticky;

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
