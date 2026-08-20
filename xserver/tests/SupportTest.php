<?php

declare(strict_types=1);

/** 予約枠の状態判定・時刻・設定安全装置・LINE Login の検証。 */

use App\Auth\LineLogin;
use App\Support\Config;
use App\Support\ConfigError;
use App\Support\Time;
use App\Views\Layout;
use App\Views\SlotParts;

/** 状態判定用の枠データ。 */
function stateSlot(array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'name' => '行き',
        'start_at' => '2099-08-21 11:00:00',
        'end_at' => null,
        'capacity' => 40,
        'max_party_size' => 4,
        'booking_open_at' => null,
        'booking_close_at' => null,
        'booking_status' => 'open',
        'booked_seats' => 0,
        'remaining_seats' => 40,
        'is_full' => false,
        'is_bookable' => true,
        'is_visible' => true,
    ], $overrides);
}

describe('予約枠の5状態', function (): void {
    $now = '2026-08-01 00:00:00';

    test('受付中', function () use ($now): void {
        assertSame('open', SlotParts::state(stateSlot(), $now));
        assertSame('受付中', SlotParts::stateLabel(stateSlot(), $now));
    });

    test('受付開始前', function () use ($now): void {
        $slot = stateSlot(['booking_open_at' => '2026-09-01 00:00:00', 'is_bookable' => false]);
        assertSame('before_open', SlotParts::state($slot, $now));
        assertSame('受付開始前', SlotParts::stateLabel($slot, $now));
    });

    test('受付終了（締切超過）', function () use ($now): void {
        $slot = stateSlot(['booking_close_at' => '2026-07-01 00:00:00', 'is_bookable' => false]);
        assertSame('closed_time', SlotParts::state($slot, $now));
        assertSame('受付終了', SlotParts::stateLabel($slot, $now));
    });

    test('受付停止中', function () use ($now): void {
        $slot = stateSlot(['booking_status' => 'closed', 'is_bookable' => false]);
        assertSame('suspended', SlotParts::state($slot, $now));
        assertSame('受付停止中', SlotParts::stateLabel($slot, $now));
    });

    test('満席', function () use ($now): void {
        $slot = stateSlot([
            'booked_seats' => 40,
            'remaining_seats' => 0,
            'is_full' => true,
            'is_bookable' => false,
        ]);
        assertSame('full', SlotParts::state($slot, $now));
        assertSame('満席', SlotParts::stateLabel($slot, $now));
    });

    test('受付停止は満席より優先される（運用側の意思を尊重する）', function () use ($now): void {
        $slot = stateSlot([
            'booking_status' => 'closed',
            'is_full' => true,
            'remaining_seats' => 0,
            'is_bookable' => false,
        ]);
        assertSame('suspended', SlotParts::state($slot, $now));
    });
});

describe('残席バッジ', function (): void {
    test('しきい値は定員の25%（最大6）', function (): void {
        assertSame(6, Layout::fewSeatsThreshold(40));
        assertSame(2, Layout::fewSeatsThreshold(6), '定員6名の貸切枠を常時「残りわずか」にしない');
        assertSame(1, Layout::fewSeatsThreshold(1));
        assertSame(6, Layout::fewSeatsThreshold(null));
    });

    test('残席0は「わずか」ではなく満席として扱う', function (): void {
        assertFalse(Layout::isFewSeats(0, 40));
        assertTrue(Layout::isFewSeats(3, 40));
        assertFalse(Layout::isFewSeats(20, 40));
    });

    test('バッジ文言は満席・残りわずか・空席ありの3種', function (): void {
        assertContains('満席', Layout::seatBadge(true, 0, 40));
        assertContains('残りわずか', Layout::seatBadge(false, 2, 40));
        assertContains('空席あり', Layout::seatBadge(false, 30, 40));
    });
});

describe('時刻の扱い', function (): void {
    test('保存はUTC・表示はJST', function (): void {
        assertSame('8月21日（金）20:00', Time::formatJstLong('2026-08-21 11:00:00'));
        assertSame('20:00', Time::formatJstTime('2026-08-21 11:00:00'));
        assertSame('2026-08-21 20:00', Time::formatJstIsoLike('2026-08-21 11:00:00'));
        assertSame('8/21 20:00', Time::formatJstShort('2026-08-21 11:00:00'));
    });

    test('datetime-local との相互変換で値が保たれる', function (): void {
        $utc = '2026-08-21 11:00:00';
        $local = Time::toJstDatetimeLocal($utc);

        assertSame('2026-08-21T20:00', $local);
        assertSame($utc, Time::fromJstDatetimeLocal($local));
    });

    test('日付をまたぐJST変換も正しい', function (): void {
        // JST 2026-08-22 08:10 = UTC 2026-08-21 23:10
        assertSame('2026-08-22T08:10', Time::toJstDatetimeLocal('2026-08-21 23:10:00'));
        assertSame('2026-08-22', Time::jstDate('2026-08-21 23:10:00'));
    });

    test('不正な値は空文字/nullになる', function (): void {
        assertSame('', Time::formatJstLong(null));
        assertSame('', Time::formatJstLong('not-a-date'));
        assertNull(Time::fromJstDatetimeLocal(''));
        assertNull(Time::fromJstDatetimeLocal('おかしな値'));
    });

    test('nowUtc は保存形式（Y-m-d H:i:s）で返る', function (): void {
        $now = Time::nowUtc();
        assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now), $now);
    });
});

describe('設定の安全装置', function (): void {
    test('production で DEMO_MODE が有効なら起動を拒否する', function (): void {
        $config = new Config([
            'APP_ENV' => 'production',
            'DEMO_MODE' => true,
            'SESSION_SECRET' => str_repeat('x', 48),
        ]);

        assertThrows(
            ConfigError::class,
            static fn () => $config->assertDemoModeSafety(),
            'production + DEMO_MODE は拒否されること'
        );
    });

    test('production 以外なら DEMO_MODE を許可する', function (): void {
        $config = new Config([
            'APP_ENV' => 'staging',
            'DEMO_MODE' => true,
            'SESSION_SECRET' => str_repeat('x', 48),
        ]);

        $config->assertDemoModeSafety();
        assertTrue($config->isDemoMode());
    });

    test('production では SESSION_SECRET の未設定・短すぎる値を拒否する', function (): void {
        foreach (['', 'short'] as $secret) {
            $config = new Config(['APP_ENV' => 'production', 'SESSION_SECRET' => $secret]);
            assertThrows(
                ConfigError::class,
                static fn () => $config->sessionSecret(),
                '"' . $secret . '" は拒否されること'
            );
        }

        $ok = new Config(['APP_ENV' => 'production', 'SESSION_SECRET' => str_repeat('a', 48)]);
        assertSame(str_repeat('a', 48), $ok->sessionSecret());
    });

    test('production 以外では開発用の既定鍵にフォールバックする', function (): void {
        $config = new Config(['APP_ENV' => 'local', 'SESSION_SECRET' => '']);
        assertSame('dev-only-insecure-session-secret', $config->sessionSecret());
    });

    test('production では DEMO_MODE でもリクエストを処理しない', function (): void {
        resetRequestState();
        $app = makeApp(['APP_ENV' => 'production', 'DEMO_MODE' => true]);

        $response = request($app, 'GET', '/');
        assertSame(500, $response->status);
        assertContains('Configuration error', $response->body);
    });

    test('LINE設定の有無を判定できる', function (): void {
        $configured = new Config([
            'LINE_LOGIN_CHANNEL_ID' => '123',
            'LINE_LOGIN_CHANNEL_SECRET' => 'secret',
            'LINE_MESSAGING_CHANNEL_ACCESS_TOKEN' => 'token',
        ]);
        assertTrue($configured->hasLineLogin());
        assertTrue($configured->hasLineMessaging());

        $empty = new Config([]);
        assertFalse($empty->hasLineLogin());
        assertFalse($empty->hasLineMessaging());
    });
});

describe('LINE Login', function (): void {
    /** テスト用の id_token（HS256）を組み立てる。 */
    $makeIdToken = static function (array $claims, string $secret): string {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ), '+/', '-_'), '=');

        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode($claims);
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header . '.' . $payload, $secret, true)
        ), '+/', '-_'), '=');

        return $header . '.' . $payload . '.' . $signature;
    };

    /** 有効な claims の雛形。 */
    $claims = static fn (array $overrides = []): array => array_merge([
        'iss' => 'https://access.line.me',
        'sub' => 'U-line-abc',
        'aud' => '1234567890',
        'exp' => time() + 600,
        'iat' => time(),
        'nonce' => 'test-nonce',
        'name' => 'テスト太郎',
    ], $overrides);

    /** LineLogin を組み立てる。 */
    $makeLogin = static function (FakeHttpClient $http): LineLogin {
        return new LineLogin(new Config([
            'APP_URL' => 'https://reserve.example.com',
            'LINE_LOGIN_CHANNEL_ID' => '1234567890',
            'LINE_LOGIN_CHANNEL_SECRET' => 'line-login-secret',
        ]), $http);
    };

    test('認可URLに state・nonce・PKCE(S256)が含まれる', function () use ($makeLogin): void {
        $url = $makeLogin(new FakeHttpClient())
            ->buildAuthorizeUrl('the-state', 'the-nonce', 'the-challenge');

        assertContains('response_type=code', $url);
        assertContains('client_id=1234567890', $url);
        assertContains('state=the-state', $url);
        assertContains('nonce=the-nonce', $url);
        assertContains('code_challenge=the-challenge', $url);
        assertContains('code_challenge_method=S256', $url);
        assertContains('scope=openid%20profile', $url);
        assertContains('bot_prompt=aggressive', $url);
        assertContains('redirect_uri=https%3A%2F%2Freserve.example.com%2Fauth%2Fline%2Fcallback', $url);
    });

    test('正しい id_token を検証できる', function () use ($makeLogin, $makeIdToken, $claims): void {
        $login = $makeLogin(new FakeHttpClient());
        $token = $makeIdToken($claims(), 'line-login-secret');

        $verified = $login->verifyIdToken($token, 'test-nonce');
        assertSame('U-line-abc', $verified['sub']);
        assertSame('テスト太郎', $verified['name']);
    });

    test('署名・iss・aud・exp・nonce のいずれかが不正なら拒否する', function () use ($makeLogin, $makeIdToken, $claims): void {
        $login = $makeLogin(new FakeHttpClient());

        $cases = [
            '署名が違う' => [$makeIdToken($claims(), 'wrong-secret'), 'test-nonce'],
            'iss が違う' => [$makeIdToken($claims(['iss' => 'https://evil.example']), 'line-login-secret'), 'test-nonce'],
            'aud が違う' => [$makeIdToken($claims(['aud' => '9999999999']), 'line-login-secret'), 'test-nonce'],
            '期限切れ' => [$makeIdToken($claims(['exp' => time() - 10]), 'line-login-secret'), 'test-nonce'],
            'nonce が違う' => [$makeIdToken($claims(), 'line-login-secret'), 'other-nonce'],
        ];

        foreach ($cases as $label => [$token, $nonce]) {
            assertThrows(
                \RuntimeException::class,
                static fn () => $login->verifyIdToken($token, $nonce),
                $label . ' は拒否されること'
            );
        }
    });

    test('friendship status API の結果を bool で返す', function () use ($makeLogin): void {
        $friend = new FakeHttpClient(200, '{"friendFlag":true}');
        assertTrue($makeLogin($friend)->fetchFriendshipStatus('access-token'));

        $notFriend = new FakeHttpClient(200, '{"friendFlag":false}');
        assertFalse($makeLogin($notFriend)->fetchFriendshipStatus('access-token'));

        // 取得できなければ「不明」（既存値を消さない）
        $error = new FakeHttpClient(403, '{}');
        assertNull($makeLogin($error)->fetchFriendshipStatus('access-token'));
    });

    test('state が一致しないコールバックはログインさせない', function (): void {
        resetRequestState();
        $app = makeApp();
        $app->session->putOAuthState([
            'state' => 'expected-state',
            'nonce' => 'n',
            'code_verifier' => 'v',
            'redirect_to' => '/',
        ]);

        $response = request($app, 'GET', '/auth/line/callback', [], [
            'state' => 'attacker-state',
            'code' => 'the-code',
        ]);

        assertSame(303, $response->status);
        assertContains('msg=session_expired', $response->headers['Location']);
        assertNull($app->session->userId(), 'ログインしていないこと');
    });

    test('OAuth state はワンタイム（使い切ったら消える）', function (): void {
        resetRequestState();
        $app = makeApp();
        $app->session->putOAuthState([
            'state' => 's',
            'nonce' => 'n',
            'code_verifier' => 'v',
            'redirect_to' => '/',
        ]);

        assertNotNull($app->session->takeOAuthState());
        assertNull($app->session->takeOAuthState(), '2回目は取り出せない');
    });
});

describe('デモログイン', function (): void {
    test('DEMO_MODE が無効なら疑似ログインできない', function (): void {
        resetRequestState();
        $app = makeApp(['DEMO_MODE' => false]);
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/auth/demo/login', ['csrf_token' => $csrf]);
        assertContains('msg=demo_disabled', $response->headers['Location']);
        assertNull($app->session->userId());
    });

    test('DEMO_MODE が有効なら疑似ログインできる', function (): void {
        resetRequestState();
        $app = makeApp(['DEMO_MODE' => true, 'APP_ENV' => 'test']);
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/auth/demo/login', [
            'csrf_token' => $csrf,
            'demo_user_id' => 'demo-user-001',
            'demo_display_name' => 'デモ太郎',
            'redirect_to' => '/my-bookings',
        ]);

        assertSame(303, $response->status);
        assertSame('/my-bookings', $response->headers['Location']);
        assertNotNull($app->session->userId());
    });
});
