<?php

declare(strict_types=1);

/**
 * LIFF経由のログイン。
 *
 * 目的は「新しい端末・ブラウザでも、LINEアプリのログイン状態から
 * その端末用の rk_session を発行できるようにする」こと。
 *
 * 認証の境界はサーバー側の raw ID token 検証ただ一点。
 * 画面から送られてくる userId / displayName / friendFlag は一切信用しない。
 */

use App\Auth\Session;
use App\Http\SecurityHeaders;
use App\Support\Config;

/** LINEの検証エンドポイントが返す claims を模した応答を作る。 */
function liffVerifyResponse(array $overrides = []): array
{
    return [
        'status' => 200,
        'body' => (string) json_encode(array_merge([
            'iss' => 'https://access.line.me',
            'sub' => 'U-liff-user-0001',
            'aud' => '1234567890',
            'exp' => time() + 3600,
            'iat' => time(),
            'name' => 'LIFF 太郎',
            'picture' => 'https://profile.line-scdn.net/abc',
        ], $overrides), JSON_UNESCAPED_UNICODE),
    ];
}

/** friendship/v1/status の応答。 */
function liffFriendshipResponse(bool $friend): array
{
    return ['status' => 200, 'body' => (string) json_encode(['friendFlag' => $friend])];
}

describe('LIFFログイン（サーバー側検証）', function (): void {
    test('正しいID tokenでセッションが発行される', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse(), liffFriendshipResponse(true)];
        $app = makeApp([], $http);

        $csrf = $app->session->csrfToken();
        $response = request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'raw.id.token',
            'access_token' => 'raw-access-token',
            'redirect_to' => '/reserve/rakko-ikebukuro',
        ]);

        assertSame(200, $response->status);
        $json = json_decode($response->body, true);
        assertTrue($json['ok']);
        assertSame('/reserve/rakko-ikebukuro', $json['redirect_to']);

        // rk_session がこの端末向けに発行されている
        assertNotNull($app->session->userId(), 'セッションが作られること');

        $user = $app->users->findByLineUserId('U-liff-user-0001');
        assertNotNull($user);
        assertSame('LIFF 太郎', $user['line_display_name']);
        assertSame(1, (int) $user['is_line_friend']);
    });

    test('raw tokenをLINEの検証エンドポイントへ送っている', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse(), liffFriendshipResponse(true)];
        $app = makeApp([], $http);

        $csrf = $app->session->csrfToken();
        request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'raw.id.token',
        ]);

        $verify = $http->calls[0];
        assertSame('https://api.line.me/oauth2/v2.1/verify', $verify['url']);
        assertContains('id_token=raw.id.token', $verify['body']);
        assertContains('client_id=1234567890', $verify['body'], 'audをLINE側にも検証させる');
    });

    test('CSRFトークンが無ければ拒否する', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse()];
        $app = makeApp([], $http);

        $response = request($app, 'POST', '/auth/liff/session', [
            'id_token' => 'raw.id.token',
        ]);

        assertSame(400, $response->status);
        assertNull($app->session->userId());
        assertSame(0, count($http->calls), 'LINEへ問い合わせもしないこと');
    });

    test('id_tokenが無ければ拒否する', function (): void {
        resetRequestState();
        $app = makeApp();
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/auth/liff/session', ['csrf_token' => $csrf]);

        assertSame(400, $response->status);
        assertNull($app->session->userId());
    });

    test('LINE側が検証に失敗したらセッションを作らない', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [['status' => 400, 'body' => '{"error":"invalid_request"}']];
        $app = makeApp([], $http);

        $csrf = $app->session->csrfToken();
        $response = request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'forged.token',
        ]);

        assertSame(401, $response->status);
        assertNull($app->session->userId(), 'セッションを発行しないこと');
    });

    test('audience（チャネル）が違うトークンは拒否する', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse(['aud' => '9999999999'])];
        $app = makeApp([], $http);

        $csrf = $app->session->csrfToken();
        $response = request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'other.channel.token',
        ]);

        assertSame(401, $response->status);
        assertNull($app->session->userId());
    });

    test('期限切れトークンは拒否する', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse(['exp' => time() - 60])];
        $app = makeApp([], $http);

        $csrf = $app->session->csrfToken();
        $response = request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'expired.token',
        ]);

        assertSame(401, $response->status);
        assertNull($app->session->userId());
    });

    test('issuerが違うトークンは拒否する', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse(['iss' => 'https://evil.example.com'])];
        $app = makeApp([], $http);

        $csrf = $app->session->csrfToken();
        $response = request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'evil.token',
        ]);

        assertSame(401, $response->status);
        assertNull($app->session->userId());
    });

    test('subが無いトークンは拒否する', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse(['sub' => ''])];
        $app = makeApp([], $http);

        $csrf = $app->session->csrfToken();
        $response = request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'no.sub.token',
        ]);

        assertSame(401, $response->status);
        assertNull($app->session->userId());
    });

    test('クライアントが名乗るuserIdは使わない（検証済みsubを使う）', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        // LINEが返す本物のsubは U-liff-user-0001
        $http->responses = [liffVerifyResponse(), liffFriendshipResponse(true)];
        $app = makeApp([], $http);

        // 攻撃者が別人のIDを名乗る
        $victim = (int) $app->users->upsertByLineId('U-victim', '被害者', null, true)['id'];

        $csrf = $app->session->csrfToken();
        request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'raw.id.token',
            'userId' => 'U-victim',
            'line_user_id' => 'U-victim',
            'sub' => 'U-victim',
            'displayName' => '被害者',
        ]);

        $sessionUserId = $app->session->userId();
        assertNotNull($sessionUserId);
        assertNotSame($victim, $sessionUserId, '名乗ったIDでログインさせないこと');

        $actual = $app->users->findById($sessionUserId);
        assertSame('U-liff-user-0001', $actual['line_user_id'], '検証済みsubのユーザーになること');
        assertSame('LIFF 太郎', $actual['line_display_name'], '名乗った表示名を使わないこと');
    });

    test('既存ユーザーは同じLINE subで再利用される', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse(), liffFriendshipResponse(true)];
        $app = makeApp([], $http);

        $existing = (int) $app->users->upsertByLineId('U-liff-user-0001', '旧表示名', null, true)['id'];

        $csrf = $app->session->csrfToken();
        request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'raw.id.token',
            'access_token' => 'raw-access-token',
        ]);

        assertSame($existing, $app->session->userId(), '同じユーザーを使い回すこと');
        assertSame(
            1,
            (int) $app->db->scalar('SELECT COUNT(*) FROM users WHERE line_user_id = ?', ['U-liff-user-0001']),
            'ユーザーが増えないこと'
        );
    });

    test('新しい端末（rk_session無し）からでもセッションを作れる', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse(), liffFriendshipResponse(true)];
        $app = makeApp([], $http);

        // 端末Aで作られた既存ユーザー
        $app->users->upsertByLineId('U-liff-user-0001', 'LIFF 太郎', null, true);

        // 端末B: Cookieが一切無い状態
        resetRequestState();
        assertNull($app->session->userId(), '新端末にはセッションが無い');

        $csrf = $app->session->csrfToken();
        $response = request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'raw.id.token',
            'access_token' => 'raw-access-token',
        ]);

        assertSame(200, $response->status);
        assertNotNull($app->session->userId(), '新端末にセッションが発行されること');
    });

    test('アクセストークン・IDトークンを永続化しない', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse(), liffFriendshipResponse(true)];
        $app = makeApp([], $http);

        $csrf = $app->session->csrfToken();
        request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'raw.id.token.value',
            'access_token' => 'raw-access-token-value',
        ]);

        // usersテーブルのどの列にもトークンが入っていない
        $row = $app->db->first('SELECT * FROM users WHERE line_user_id = ?', ['U-liff-user-0001']);
        assertNotNull($row);
        foreach ($row as $column => $value) {
            if (!is_string($value)) {
                continue;
            }
            assertNotContains('raw.id.token.value', $value, $column . ' にIDトークンを保存しない');
            assertNotContains('raw-access-token-value', $value, $column . ' にアクセストークンを保存しない');
        }

        // セッションCookieにもトークンを載せない
        $cookie = $app->session->queuedCookie(Session::USER_COOKIE);
        assertNotNull($cookie);
        assertNotContains('raw.id.token.value', $cookie);
        assertNotContains('raw-access-token-value', $cookie);
    });
});

describe('LIFFと友だち必須の整合', function (): void {
    test('friendFlag=true なら予約できる', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse(), liffFriendshipResponse(true)];
        $app = makeApp([], $http);

        $pageId = Fixtures::page($app, ['slug' => 'liff-page']);
        $slotId = Fixtures::slot($app, $pageId);

        $csrf = $app->session->csrfToken();
        request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'raw.id.token',
            'access_token' => 'raw-access-token',
        ]);

        $body = request($app, 'GET', '/reserve/liff-page')->body;
        assertContains('id="reserve-form"', $body, '予約フォームが出ること');
    });

    test('friendFlag=false では予約できない', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse(), liffFriendshipResponse(false)];
        $app = makeApp([], $http);

        $pageId = Fixtures::page($app, ['slug' => 'liff-page']);
        $slotId = Fixtures::slot($app, $pageId);

        $csrf = $app->session->csrfToken();
        request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'raw.id.token',
            'access_token' => 'raw-access-token',
        ]);

        $body = request($app, 'GET', '/reserve/liff-page')->body;
        assertContains('予約専用LINE公式アカウントの友だち追加が必要です', $body);
        assertNotContains('id="reserve-form"', $body);

        // 画面を迂回してPOSTしても通らない
        $post = request($app, 'POST', '/reserve/liff-page/book', [
            'csrf_token' => $csrf,
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => '1',
            'party_size_' . $slotId => '1',
        ], [], ['slot_selected' => [(string) $slotId]]);

        assertSame(400, $post->status);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId));
    });

    test('access_tokenが無ければ友だち状態は不明のまま（fail closed）', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [liffVerifyResponse()];
        $app = makeApp([], $http);

        Fixtures::slot($app, Fixtures::page($app, ['slug' => 'liff-page']));

        $csrf = $app->session->csrfToken();
        request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'raw.id.token',
        ]);

        $user = $app->users->findByLineUserId('U-liff-user-0001');
        assertNull($user['is_line_friend'], '不明のままにする');

        $body = request($app, 'GET', '/reserve/liff-page')->body;
        assertContains('予約専用LINE公式アカウントの友だち追加が必要です', $body);
    });

    test('クライアントがfriendFlag=trueを偽装しても予約できない', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        // LINEに聞くと未追加
        $http->responses = [liffVerifyResponse(), liffFriendshipResponse(false)];
        $app = makeApp([], $http);

        $pageId = Fixtures::page($app, ['slug' => 'liff-page']);
        $slotId = Fixtures::slot($app, $pageId);

        $csrf = $app->session->csrfToken();
        // 画面が friendFlag=true を名乗る
        request($app, 'POST', '/auth/liff/session', [
            'csrf_token' => $csrf,
            'id_token' => 'raw.id.token',
            'access_token' => 'raw-access-token',
            'friendFlag' => 'true',
            'is_line_friend' => '1',
        ]);

        $user = $app->users->findByLineUserId('U-liff-user-0001');
        assertSame(0, (int) $user['is_line_friend'], 'LINEの回答を採用すること');

        $post = request($app, 'POST', '/reserve/liff-page/book', [
            'csrf_token' => $csrf,
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => '1',
            'party_size_' . $slotId => '1',
        ], [], ['slot_selected' => [(string) $slotId]]);

        assertSame(400, $post->status);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId));
    });

    test('管理者代理予約はLIFFの影響を受けない', function (): void {
        resetRequestState();
        $app = makeApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app));

        $result = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => null,
            'source' => 'admin',
            'representative_name' => '電話 太郎',
            'phone' => '0489361126',
            'party_size' => 2,
            'companion_names' => ['同行者'],
            'agreed' => true,
        ]);

        assertTrue($result['ok']);
        assertSame(2, $app->slots->sumConfirmedSeats($slotId));
    });
});

describe('LIFF画面とfallback', function (): void {
    test('LIFF未設定なら通常のLINEログインへ案内する', function (): void {
        resetRequestState();
        $app = makeApp(['LINE_LIFF_ID' => '']);

        $response = request($app, 'GET', '/liff');

        assertSame(200, $response->status);
        assertContains('/login', $response->body);
        assertNotContains('static.line-scdn.net', $response->body, 'SDKを読み込まない');
    });

    test('LIFF設定済みならSDKと初期化スクリプトを読み込む', function (): void {
        resetRequestState();
        $app = makeApp(['LINE_LIFF_ID' => '2001234567-AbCdEfGh']);

        $body = request($app, 'GET', '/liff')->body;

        assertContains('https://static.line-scdn.net/liff/edge/2/sdk.js', $body);
        assertContains('/assets/liff.js', $body);
        assertContains('data-liff-id="2001234567-AbCdEfGh"', $body);
        assertContains('data-csrf-token=', $body, 'CSRFトークンを画面へ渡すこと');
    });

    test('JS無効・LIFF失敗でも既存LINEログインへ進める', function (): void {
        resetRequestState();
        $app = makeApp(['LINE_LIFF_ID' => '2001234567-AbCdEfGh']);

        $body = request($app, 'GET', '/liff', [], ['redirect_to' => '/reserve/rakko-ikebukuro'])->body;

        // noscript とフォールバックリンクが最初からHTMLにある
        assertContains('<noscript>', $body);
        assertContains('/login?redirect_to=', $body);
        assertContains('LINEでログイン', $body);
    });

    test('LIFF URLの追加パスで予約slugを維持する', function (): void {
        resetRequestState();
        $app = makeApp(['LINE_LIFF_ID' => '2001234567-AbCdEfGh']);

        $body = request($app, 'GET', '/liff/reserve/rakko-ikebukuro')->body;

        assertContains('data-redirect-to="/reserve/rakko-ikebukuro"', $body);
        assertContains(rawurlencode('/reserve/rakko-ikebukuro'), $body, 'fallbackも同じ行き先');
    });

    test('liff.state からも行き先を復元する', function (): void {
        resetRequestState();
        $app = makeApp(['LINE_LIFF_ID' => '2001234567-AbCdEfGh']);

        $body = request($app, 'GET', '/liff', [], ['liff.state' => '/reserve/rakko-ikebukuro'])->body;

        assertContains('data-redirect-to="/reserve/rakko-ikebukuro"', $body);
    });

    test('外部サイトへのリダイレクトは弾く', function (): void {
        resetRequestState();
        $app = makeApp(['LINE_LIFF_ID' => '2001234567-AbCdEfGh']);

        $body = request($app, 'GET', '/liff', [], ['redirect_to' => 'https://evil.example.com'])->body;

        assertNotContains('evil.example.com', $body);
        assertContains('data-redirect-to="/"', $body);
    });

    test('既存のLINE Login（OAuth/OIDC）は残っている', function (): void {
        resetRequestState();
        $app = makeApp();

        // ログイン画面のフォームも、開始エンドポイントも従来どおり
        $login = request($app, 'GET', '/login');
        assertSame(200, $login->status);
        assertContains('action="/auth/line/start"', $login->body);

        $csrf = $app->session->csrfToken();
        $start = request($app, 'POST', '/auth/line/start', [
            'csrf_token' => $csrf,
            'redirect_to' => '/reserve/rakko-ikebukuro',
        ]);
        assertSame(302, $start->status);
        assertContains('https://access.line.me/oauth2/v2.1/authorize', $start->headers['Location']);
        assertContains('code_challenge_method=S256', $start->headers['Location']);
    });
});

describe('LIFFのCSPと設定', function (): void {
    test('LIFF画面だけSDKとLINE APIを許可する', function (): void {
        $normal = SecurityHeaders::contentSecurityPolicy();
        $liff = SecurityHeaders::contentSecurityPolicy(true);

        // 通常ページは従来どおり絞ったまま
        assertNotContains('static.line-scdn.net', $normal);
        assertNotContains('api.line.me', $normal);
        assertContains("connect-src 'self'", $normal);

        // LIFF画面だけ必要なオリジンを足す
        assertContains("script-src 'self' 'unsafe-inline' https://static.line-scdn.net", $liff);
        assertContains('connect-src \'self\' https://api.line.me https://access.line.me', $liff);

        // どちらもワイルドカードは無い
        foreach ([$normal, $liff] as $policy) {
            assertNotContains('*', $policy);
            assertNotContains("'unsafe-eval'", $policy);
            assertContains("frame-ancestors 'none'", $policy);
            assertContains("base-uri 'self'", $policy);
        }
    });

    test('CSP緩和は /liff だけに適用する', function (): void {
        assertTrue(SecurityHeaders::needsLiff('/liff'));
        assertFalse(SecurityHeaders::needsLiff('/'));
        assertFalse(SecurityHeaders::needsLiff('/reserve/rakko-ikebukuro'));
        assertFalse(SecurityHeaders::needsLiff('/admin'));
    });

    test('LIFF IDの形式を検証する', function (): void {
        $valid = new Config(['LINE_LIFF_ID' => '2001234567-AbCdEfGh']);
        assertSame('2001234567-AbCdEfGh', $valid->liffId());
        assertTrue($valid->hasLiff());

        foreach (['', 'not-a-liff-id', '12345', '<script>', 'javascript:alert(1)'] as $bad) {
            $config = new Config(['LINE_LIFF_ID' => $bad]);
            assertNull($config->liffId(), '"' . $bad . '" は無効');
            assertFalse($config->hasLiff());
        }
    });

    test('共有用LIFF URLを組み立てられる', function (): void {
        $config = new Config(['LINE_LIFF_ID' => '2001234567-AbCdEfGh']);

        assertSame('https://liff.line.me/2001234567-AbCdEfGh', $config->liffUrl());
        assertSame(
            'https://liff.line.me/2001234567-AbCdEfGh/reserve/rakko-ikebukuro',
            $config->liffUrl('/reserve/rakko-ikebukuro')
        );
        assertNull((new Config([]))->liffUrl(), '未設定ならnull');
    });
});
