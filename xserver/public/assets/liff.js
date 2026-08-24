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
  // 遷移先へ飛ばした直後にこのページへ戻ってきた＝セッションが確立できていない。
  // そのまま再度飛ばすとループになるので、直近の遷移を覚えておく。
  var REDIRECT_KEY_PREFIX = 'rakko.liff.redirect:';
  var REDIRECT_LOOP_WINDOW_MS = 10000;

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

  /** 直前にこの行き先へ飛ばしていたら true（＝戻されてきた）。 */
  function bouncedBackFrom(target) {
    try {
      var raw = window.sessionStorage.getItem(REDIRECT_KEY_PREFIX + target);
      if (!raw) return false;
      return Date.now() - parseInt(raw, 10) < REDIRECT_LOOP_WINDOW_MS;
    } catch (e) {
      return false;
    }
  }

  function rememberRedirect(target) {
    try {
      window.sessionStorage.setItem(REDIRECT_KEY_PREFIX + target, String(Date.now()));
    } catch (e) {
      // 記録できなくても遷移自体は行う
    }
  }

  function forgetRedirect(target) {
    try {
      window.sessionStorage.removeItem(REDIRECT_KEY_PREFIX + target);
    } catch (e) {
      // 実害なし
    }
  }

  if (typeof liff === 'undefined' || !liffId) {
    fallback('LINEログインへお進みください。');
    return;
  }

  // 直前にこのページから遷移先へ飛ばしたのに戻ってきた＝
  // セッションを確立できていない（Cookieが保存されない等）。
  // もう一度同じことをしてもループするだけなので、通常のログインへ倒す。
  if (bouncedBackFrom(redirectTo)) {
    forgetRedirect(redirectTo);
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

      // 1回目: サーバーで本人確認 → セッション発行 → 友だち状態もサーバーが取得
      return postSession(idToken, accessToken)
        .then(function (result) {
          if (!result || result.ok !== true) {
            fallback('LINEログインへお進みください。');
            return;
          }

          // サーバーが「友だち」と判定していればそのまま進む
          if (result.is_line_friend === true) {
            go(result.redirect_to || redirectTo);
            return;
          }

          // 未追加。LIFF内なら友だち追加を促し、その結果をサーバーで取り直す。
          // requestFriendship() の戻り値からは実際に追加したか判定できないため、
          // getFriendship() で確認し、さらにサーバー側で再取得する。
          if (!liff.isInClient()) {
            // 外部ブラウザでは友だち追加を促せない。
            // 予約ページ側の友だち追加案内へ進める。
            go(result.redirect_to || redirectTo);
            return;
          }

          return promptAndResyncFriendship(idToken, accessToken).then(function (resynced) {
            go((resynced && resynced.redirect_to) || result.redirect_to || redirectTo);
          });
        })
        .catch(function () {
          fallback('LINEログインへお進みください。');
        });
    })
    .catch(function () {
      // LIFF初期化失敗（LIFF外・設定不備・ネットワーク等）
      fallback('LINEログインへお進みください。');
    });

  /**
   * raw token をサーバーへ送ってセッションを作る／友だち状態を取り直す。
   * 送るのは raw token だけ。userId や friendFlag は送らない（送っても使われない）。
   */
  function postSession(idToken, accessToken) {
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
    }).then(function (response) {
      return response.json().catch(function () { return null; });
    });
  }

  /**
   * 友だち追加を promptし、追加されたならサーバー側で友だち状態を取り直す。
   *
   * requestFriendship() の Promise は resolve しても、
   * 利用者が実際に追加したかは戻り値から判定できない（LINE公式仕様）。
   * そのため getFriendship() で確認し、
   * さらに「サーバーがLINEへ問い合わせた結果」だけを正とする。
   * クライアントの判定でDBを true にはしない。
   *
   * @return Promise<object|null> 再同期したサーバー応答（しなかった場合は null）
   */
  function promptAndResyncFriendship(idToken, accessToken) {
    if (typeof liff.requestFriendship !== 'function'
      || typeof liff.getFriendship !== 'function') {
      return Promise.resolve(null);
    }

    say('予約専用LINE公式アカウントの友だち追加をお願いします…');

    return liff.requestFriendship()
      .catch(function () {
        // 拒否・非対応。状態確認へ進む
      })
      .then(function () {
        // 追加されたかは戻り値では分からないので、必ず確認する
        return liff.getFriendship();
      })
      .then(function (friendship) {
        if (!friendship || friendship.friendFlag !== true) {
          // 追加されていない。予約ページの友だち追加案内へ進める
          return null;
        }
        // クライアントの判定はここまで。
        // 実際にDBを更新するのは、サーバーがLINEへ問い合わせ直した結果だけ。
        say('友だち追加を確認しています…');
        return postSession(idToken, accessToken);
      })
      .catch(function () {
        return null;
      });
  }

  function go(path) {
    // サーバーが safeRedirectPath を通した値だが、念のため同一オリジン内に限定する
    var target = typeof path === 'string' && path.charAt(0) === '/' && path.charAt(1) !== '/'
      ? path
      : '/';
    rememberRedirect(target);
    window.location.replace(target);
  }
})();
