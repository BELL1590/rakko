<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\LineLogin;
use App\Auth\Session;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;
use App\Support\Config;
use App\Support\Messages;
use App\Views\LiffView;
use App\Views\LoginView;

/**
 * LINE Login（OAuth2 / OIDC + PKCE）と、DEMO_MODE の疑似ログイン。
 * URL・POST先は Workers 版と同一。
 */
final class AuthController
{
    public function __construct(
        private Config $config,
        private Session $session,
        private UserRepository $users,
        private ?LineLogin $line,
    ) {
    }

    public function loginPage(Request $request): Response
    {
        $redirectTo = Session::safeRedirectPath($request->query('redirect_to'), '/');

        return Response::html(LoginView::render(
            $redirectTo,
            $this->config->isDemoMode(),
            $this->config->hasLineLogin(),
            $this->session->csrfToken(),
            Messages::fromCode($request->query('msg')),
        ));
    }

    public function lineStart(Request $request): Response
    {
        if (!$this->session->verifyCsrf($request->input('csrf_token'))) {
            return Response::redirect('/login?msg=csrf');
        }
        if ($this->line === null || !$this->config->hasLineLogin()) {
            return Response::redirect('/login?msg=login_failed');
        }

        $redirectTo = Session::safeRedirectPath($request->str('redirect_to'), '/');
        $state = Session::randomToken(24);
        $nonce = Session::randomToken(24);
        $codeVerifier = Session::createCodeVerifier();
        $codeChallenge = Session::createCodeChallenge($codeVerifier);

        $this->session->putOAuthState([
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'redirect_to' => $redirectTo,
        ]);

        return Response::redirect(
            $this->line->buildAuthorizeUrl($state, $nonce, $codeChallenge),
            302,
        );
    }

    public function lineCallback(Request $request): Response
    {
        $stored = $this->session->takeOAuthState();
        $returnedState = $request->query('state') ?? '';
        $code = $request->query('code') ?? '';
        $error = $request->query('error');

        if ($error !== null || $code === '') {
            return Response::redirect('/login?msg=login_failed');
        }
        // state 不一致は CSRF の可能性があるため必ず拒否する
        if ($stored === null || !hash_equals((string) $stored['state'], $returnedState)) {
            return Response::redirect('/login?msg=session_expired');
        }
        if ($this->line === null || !$this->config->hasLineLogin()) {
            return Response::redirect('/login?msg=login_failed');
        }

        try {
            $token = $this->line->exchangeCode($code, (string) $stored['code_verifier']);
            $accessToken = (string) ($token['access_token'] ?? '');

            $lineUserId = '';
            $displayName = '';
            $pictureUrl = null;

            if (isset($token['id_token']) && is_string($token['id_token']) && $token['id_token'] !== '') {
                $claims = $this->line->verifyIdToken($token['id_token'], (string) $stored['nonce']);
                $lineUserId = (string) $claims['sub'];
                $displayName = (string) ($claims['name'] ?? '');
                $pictureUrl = isset($claims['picture']) ? (string) $claims['picture'] : null;
            } else {
                $profile = $this->line->fetchProfile($accessToken);
                $lineUserId = (string) $profile['userId'];
                $displayName = (string) ($profile['displayName'] ?? '');
                $pictureUrl = isset($profile['pictureUrl']) ? (string) $profile['pictureUrl'] : null;
            }

            if ($displayName === '') {
                $profile = $this->line->fetchProfile($accessToken);
                $displayName = (string) ($profile['displayName'] ?? '');
                $pictureUrl ??= isset($profile['pictureUrl']) ? (string) $profile['pictureUrl'] : null;
            }

            $isFriend = $this->line->fetchFriendshipStatus($accessToken);

            $user = $this->users->upsertByLineId($lineUserId, $displayName, $pictureUrl, $isFriend);
            $this->session->startUserSession((int) $user['id']);

            return Response::redirect(Session::safeRedirectPath((string) $stored['redirect_to'], '/'));
        } catch (\Throwable) {
            // アクセストークン等が混入しないよう、詳細はログに残さない
            return Response::redirect('/login?msg=login_failed');
        }
    }

    /**
     * LIFF のブートストラップ画面。
     *
     * 新しい端末・ブラウザには rk_session が無いため、
     * LINEアプリのログイン状態からWebセッションを作る導線として使う。
     * LIFFが使えない環境では既存のLINE Login（OAuth/OIDC）へ誘導する。
     */
    public function liffPage(Request $request, array $params = []): Response
    {
        // ログイン後の戻り先の決め方（優先順）:
        //   1. LIFF URLの追加パス（例 liff.line.me/{id}/reserve/{slug}）
        //   2. 明示的な redirect_to
        //   3. liff.state（LINEが追加パス／クエリを載せてくる）
        $slug = $params['slug'] ?? null;
        $fromPath = $slug !== null && $slug !== '' ? '/reserve/' . $slug : null;

        $redirectTo = Session::safeRedirectPath(
            $fromPath ?? $request->query('redirect_to') ?? $request->query('liff.state'),
            '/'
        );

        return Response::html(LiffView::render(
            $this->config->liffId(),
            $redirectTo,
            $this->session->csrfToken(),
            $this->config->hasLineLogin(),
            Messages::fromCode($request->query('msg')),
        ));
    }

    /**
     * LIFF から受け取った raw token を検証してセッションを発行する。
     *
     * クライアントが送ってくる userId / displayName / friendFlag は**信用しない**。
     * ID token を LINE Platform で検証し、その `sub` だけを本人の識別子として使う。
     * トークンはDBにもログにも残さない。
     */
    public function liffSession(Request $request): Response
    {
        if (!$this->session->verifyCsrf($request->input('csrf_token'))) {
            return Response::json(['ok' => false, 'error' => 'csrf'], 400);
        }
        if ($this->line === null || !$this->config->hasLineLogin()) {
            return Response::json(['ok' => false, 'error' => 'line_not_configured'], 400);
        }

        $idToken = trim($request->str('id_token'));
        if ($idToken === '') {
            return Response::json(['ok' => false, 'error' => 'id_token_required'], 400);
        }
        $accessToken = trim($request->str('access_token'));

        try {
            // ここが唯一の本人確認。以降はこの claims だけを信じる
            $claims = $this->line->verifyIdTokenViaApi($idToken);
        } catch (\Throwable) {
            // 失敗理由にトークンを含めない
            return Response::json(['ok' => false, 'error' => 'invalid_token'], 401);
        }

        $lineUserId = (string) $claims['sub'];
        $displayName = isset($claims['name']) && is_string($claims['name']) ? $claims['name'] : '';
        $pictureUrl = isset($claims['picture']) && is_string($claims['picture'])
            ? $claims['picture']
            : null;

        // 友だち状態もサーバー側で取り直す。
        // 画面から送られた friendFlag は判断材料にしない。
        // 取得できなければ null のまま（＝未追加扱い。予約はブロックされる）
        $isFriend = null;
        if ($accessToken !== '') {
            try {
                $isFriend = $this->line->fetchFriendshipStatus($accessToken);
            } catch (\Throwable) {
                $isFriend = null;
            }
        }

        if ($displayName === '' && $accessToken !== '') {
            try {
                $profile = $this->line->fetchProfile($accessToken);
                $displayName = (string) ($profile['displayName'] ?? '');
                $pictureUrl ??= isset($profile['pictureUrl']) ? (string) $profile['pictureUrl'] : null;
            } catch (\Throwable) {
                // 表示名が取れなくてもログインは成立させる
            }
        }

        // トークンは保存しない。保存するのはLINEユーザーIDと表示名・友だち状態だけ
        $user = $this->users->upsertByLineId($lineUserId, $displayName, $pictureUrl, $isFriend);
        $this->session->startUserSession((int) $user['id']);

        return Response::json([
            'ok' => true,
            'redirect_to' => Session::safeRedirectPath($request->str('redirect_to'), '/'),
            'is_line_friend' => $isFriend,
        ]);
    }

    /** DEMO_MODE 専用の疑似ログイン。production では絶対に有効化されない。 */
    public function demoLogin(Request $request): Response
    {
        if (!$this->config->isDemoMode()) {
            return Response::redirect('/login?msg=demo_disabled');
        }
        if (!$this->session->verifyCsrf($request->input('csrf_token'))) {
            return Response::redirect('/login?msg=csrf');
        }

        $lineUserId = mb_substr(trim($request->str('demo_user_id')) ?: 'demo-user-001', 0, 64);
        $displayName = mb_substr(trim($request->str('demo_display_name')) ?: 'デモユーザー', 0, 50);
        $redirectTo = Session::safeRedirectPath($request->str('redirect_to'), '/');

        // 公開予約は友だち追加済みを必須にしているため、
        // デモユーザーは「友だち追加済み」として作る。
        // そうしないと DEMO_MODE で予約フローを一切確認できない。
        // production では assertDemoModeSafety() が DEMO_MODE を拒否するので、
        // この抜け道が本番に出ることはない。
        $user = $this->users->upsertByLineId($lineUserId, $displayName, null, true);
        $this->session->startUserSession((int) $user['id']);

        return Response::redirect($redirectTo);
    }

    public function logout(Request $request): Response
    {
        if (!$this->session->verifyCsrf($request->input('csrf_token'))) {
            return Response::redirect('/?msg=csrf');
        }
        $this->session->clearUserSession();
        return Response::redirect('/?msg=logged_out');
    }
}
