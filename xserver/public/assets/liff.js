(function () {
  var root = document.getElementById('liff-bootstrap');
  if (!root) return;

  var statusEl = document.getElementById('liff-status');
  var fallbackEl = document.getElementById('liff-fallback');
  var liffId = root.getAttribute('data-liff-id');
  var redirectTo = root.getAttribute('data-redirect-to') || '/';
  var csrfToken = root.getAttribute('data-csrf-token') || '';

  // 無限リダイレクト防止。
  // liff.login() はこのページへ戻ってくるので、1リクエストにつき1回までに制限する。
  // sessionStorage が使えない環境（プライベートモード等）ではログインを試みない。
  var LOGIN_FLAG = 'rakko.liff.login.attempted';

  function say(message) {
    if (statusEl) statusEl.textContent = message;
  }

  /** LIFFで進めないときは既存のLINEログインへ倒す。 */
  function fallback(message) {
    say(message);
    if (fallbackEl) fallbackEl.hidden = false;
  }

  function attemptedLogin() {
    try {
      return window.sessionStorage.getItem(LOGIN_FLAG) === '1';
    } catch (e) {
      // sessionStorageが使えないと再訪の判別ができない。
      // 「試行済み」とみなしてループを避ける。
      return true;
    }
  }

  function markLoginAttempted() {
    try {
      window.sessionStorage.setItem(LOGIN_FLAG, '1');
      return true;
    } catch (e) {
      return false;
    }
  }

  function clearLoginAttempt() {
    try {
      window.sessionStorage.removeItem(LOGIN_FLAG);
    } catch (e) {
      // 消せなくても実害はない
    }
  }

  if (typeof liff === 'undefined' || !liffId) {
    fallback('LINEログインへお進みください。');
    return;
  }

  liff
    .init({ liffId: liffId, withLoginOnExternalBrowser: true })
    .then(function () {
      if (!liff.isLoggedIn()) {
        if (attemptedLogin()) {
          // 一度ログインへ飛ばしても戻ってこられなかった。
          // もう一度飛ばすとループになるので、通常のログインへ倒す。
          clearLoginAttempt();
          fallback('LINEログインへお進みください。');
          return;
        }
        if (!markLoginAttempted()) {
          fallback('LINEログインへお進みください。');
          return;
        }
        liff.login({ redirectUri: window.location.href });
        return;
      }

      clearLoginAttempt();

      // サーバーへ渡すのは raw token のみ。
      // userId や displayName、getDecodedIDToken() の中身は送らない（送っても使われない）。
      var idToken = liff.getIDToken();
      if (!idToken) {
        fallback('LINEログインへお進みください。');
        return;
      }
      var accessToken = null;
      try {
        accessToken = liff.getAccessToken();
      } catch (e) {
        accessToken = null;
      }

      say('ログイン処理中…');

      var body = 'csrf_token=' + encodeURIComponent(csrfToken)
        + '&id_token=' + encodeURIComponent(idToken)
        + '&redirect_to=' + encodeURIComponent(redirectTo);
      if (accessToken) {
        body += '&access_token=' + encodeURIComponent(accessToken);
      }

      return fetch('/auth/liff/session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
        credentials: 'same-origin',
      })
        .then(function (response) {
          return response.json().catch(function () { return null; });
        })
        .then(function (result) {
          if (!result || result.ok !== true) {
            fallback('LINEログインへお進みください。');
            return;
          }

          // 友だち未追加なら、LIFF内で追加を促せる場合は促す。
          // ここでの判定は表示のためだけで、予約可否はサーバーが決める。
          if (result.is_line_friend !== true && liff.isInClient()) {
            requestFriendship().then(function () {
              go(result.redirect_to || redirectTo);
            });
            return;
          }

          go(result.redirect_to || redirectTo);
        })
        .catch(function () {
          fallback('LINEログインへお進みください。');
        });
    })
    .catch(function () {
      // LIFF初期化失敗（LIFF外・設定不備・ネットワーク等）
      fallback('LINEログインへお進みください。');
    });

  /** 友だち追加を促す。使えない環境では何もしない。 */
  function requestFriendship() {
    if (typeof liff.requestFriendship !== 'function') {
      return Promise.resolve();
    }
    say('予約専用LINE公式アカウントの友だち追加をお願いします…');
    return liff.requestFriendship().catch(function () {
      // 拒否・非対応でも予約画面へは進める（未追加なら案内が出る）
    });
  }

  function go(path) {
    // サーバーが safeRedirectPath を通した値だが、念のため同一オリジン内に限定する
    var target = typeof path === 'string' && path.charAt(0) === '/' && path.charAt(1) !== '/'
      ? path
      : '/';
    window.location.replace(target);
  }
})();
