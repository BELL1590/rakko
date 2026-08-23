<?php

declare(strict_types=1);

/**
 * スマートフォンでのLINEアプリ起動（auto login）に関わる構成の回帰テスト。
 *
 * LINEの auto login は、こちら側で何かを有効化するものではなく
 * 「標準の v2.1 認可エンドポイントへ、無効化パラメータを付けずに、
 *   利用者の操作を起点として遷移する」ことで成立する。
 * ここではその前提が将来壊されないよう固定する。
 *
 * 実機でLINEアプリが起動するかはOS・ブラウザ依存のため、ここでは検証できない。
 * 検証しているのは「アプリ起動を妨げる構成になっていないこと」まで。
 */

use App\Auth\LineLogin;
use App\Http\SecurityHeaders;
use App\Support\Config;

/** 認可URLを1本組み立てる。 */
function authorizeUrlForAutoLogin(array $extra = []): string
{
    $login = new LineLogin(new Config(array_merge([
        'APP_URL' => 'https://reserve.example.com',
        'LINE_LOGIN_CHANNEL_ID' => '1234567890',
        'LINE_LOGIN_CHANNEL_SECRET' => 'line-login-secret',
    ], $extra)), new FakeHttpClient());

    return $login->buildAuthorizeUrl('the-state', 'the-nonce', 'the-challenge');
}

describe('LINEアプリ起動（auto login）の前提', function (): void {
    test('auto loginを無効化するパラメータを付けない', function (): void {
        $url = authorizeUrlForAutoLogin();

        // これらを付けるとLINEアプリでの自動ログインが行われなくなる
        assertNotContains('disable_auto_login', $url);
        assertNotContains('disable_ios_auto_login', $url);

        // 設定でbot_promptを足しても、無効化パラメータは増えない
        $withPrompt = authorizeUrlForAutoLogin(['LINE_LOGIN_BOT_PROMPT' => 'aggressive']);
        assertNotContains('disable_auto_login', $withPrompt);
        assertNotContains('disable_ios_auto_login', $withPrompt);
    });

    test('ソース上どこにも無効化パラメータを持たない', function (): void {
        $root = dirname(__DIR__);
        foreach (['app/Auth/LineLogin.php', 'app/Controllers/AuthController.php'] as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            assertNotContains('disable_auto_login', $source, $file);
            assertNotContains('disable_ios_auto_login', $source, $file);
        }
    });

    test('LINE公式のWebログイン用エンドポイントを使っている', function (): void {
        $url = authorizeUrlForAutoLogin();

        // access.line.me の v2.1 認可エンドポイントが
        // Universal Link / App Link の対象。独自のスキームには置き換えない
        assertTrue(
            str_starts_with($url, 'https://access.line.me/oauth2/v2.1/authorize?'),
            'v2.1のWebログインエンドポイントであること: ' . $url
        );
        assertNotContains('line://', $url, '独自スキームへ差し替えないこと');
        assertNotContains('lineapp://', $url);
    });

    test('OIDC/PKCEのパラメータは従来どおり揃っている', function (): void {
        $url = authorizeUrlForAutoLogin();

        assertContains('response_type=code', $url);
        assertContains('client_id=1234567890', $url);
        assertContains('scope=openid%20profile', $url);
        assertContains('state=the-state', $url);
        assertContains('nonce=the-nonce', $url);
        assertContains('code_challenge=the-challenge', $url);
        assertContains('code_challenge_method=S256', $url);
        assertContains(
            'redirect_uri=https%3A%2F%2Freserve.example.com%2Fauth%2Fline%2Fcallback',
            $url
        );
    });

    test('CSPが認可先への遷移を許可している', function (): void {
        // form-action が 'self' だけだと、POST後の302がブラウザにブロックされ
        // そもそもLINEへ到達できない
        $policy = SecurityHeaders::contentSecurityPolicy();
        assertContains('https://access.line.me', $policy);
        assertTrue(
            str_starts_with(
                'https://access.line.me/oauth2/v2.1/authorize',
                SecurityHeaders::LINE_AUTHORIZE_ORIGIN
            ),
            '遷移先がform-actionの許可先に含まれること'
        );
    });
});

describe('ログイン導線のHTML', function (): void {
    test('LINEログインは利用者の操作起点の遷移（JSで横取りしない）', function (): void {
        resetRequestState();
        $app = makeApp();

        $body = request($app, 'GET', '/login')->body;

        // 素のフォーム送信であること。JSでの自動遷移やXHRにするとアプリが起動しない
        assertContains('<form method="post" action="/auth/line/start"', $body);
        assertContains('type="submit"', $body);
        assertNotContains('<script', $body, 'ログイン画面にJSを載せない');
        assertNotContains('target="_blank"', $body, 'LINEログインは同一タブで遷移する');
        assertNotContains('XMLHttpRequest', $body);
        assertNotContains('fetch(', $body);
    });

    test('LINEアプリが開かない場合の案内を出す', function (): void {
        resetRequestState();
        $app = makeApp();

        $body = request($app, 'GET', '/login')->body;

        assertContains('LINEアプリが開かない場合', $body);
        assertContains('Chrome / Safari', $body);
        assertContains('アプリ内ブラウザ', $body);
    });

    test('LINEアプリが使えなくてもWebログインで予約を継続できる', function (): void {
        resetRequestState();
        $app = makeApp();

        // 認可URLは通常のHTTPS。アプリが無ければLINEのWebログイン画面が開く
        $csrf = $app->session->csrfToken();
        $response = request($app, 'POST', '/auth/line/start', [
            'csrf_token' => $csrf,
            'redirect_to' => '/reserve/rakko-ikebukuro',
        ]);

        assertSame(302, $response->status);
        $location = $response->headers['Location'];
        assertTrue(str_starts_with($location, 'https://'), 'HTTPSのWeb URLであること');
        assertContains('access.line.me', $location);

        // ログイン後は元のページへ戻る
        $state = $app->session->takeOAuthState();
        assertNotNull($state);
        assertSame('/reserve/rakko-ikebukuro', $state['redirect_to']);
    });

    test('ログイン画面の友だち追加の案内が予約条件と矛盾しない', function (): void {
        resetRequestState();
        $app = makeApp();

        $body = request($app, 'GET', '/login')->body;

        assertContains('予約専用LINE公式アカウントの友だち追加が必要です', $body);
        assertNotContains(
            '友だち追加をしなくてもご予約自体は可能です',
            $body,
            '友だち追加は必須なので、任意と読める案内を残さない'
        );
    });
});
