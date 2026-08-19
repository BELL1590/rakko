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
