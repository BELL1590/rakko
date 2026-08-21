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

        // デモでは友だち状態は不明扱い
        $user = $this->users->upsertByLineId($lineUserId, $displayName, null, null);
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
