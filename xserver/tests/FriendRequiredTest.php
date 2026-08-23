<?php

declare(strict_types=1);

/**
 * 予約専用LINE公式アカウントの友だち追加必須化。
 *
 * 予約完了通知・リマインドはpushで送るため、友だちでないと届かない。
 * 「予約はできたのに通知は来ない」を避けるため、公開予約は友だち追加を必須にする。
 * 管理者代理予約はLINEを使わないので対象外。
 */

/** 友だち状態を指定してユーザーを作る。 */
function userWithFriendFlag(App\App $app, string $lineId, ?bool $isFriend): int
{
    return (int) $app->users->upsertByLineId($lineId, 'テスト太郎', null, $isFriend)['id'];
}

/** 予約可能なページと枠。 */
function friendFixture(App\App $app, array $pageOverrides = []): array
{
    $pageId = Fixtures::page($app, array_merge([
        'slug' => 'friend-required',
        'status' => 'published',
    ], $pageOverrides));
    $slotId = Fixtures::slot($app, $pageId, ['capacity' => 10]);
    return [$pageId, $slotId];
}

/** 予約1件分のPOST本文。 */
function friendBookingPost(string $csrf, int $slotId): array
{
    return [
        'csrf_token' => $csrf,
        'representative_name' => '山田太郎',
        'phone' => '090-1234-5678',
        'agreed' => '1',
        'party_size_' . $slotId => '1',
    ];
}

describe('友だち追加の必須化（サーバー側）', function (): void {
    test('friendFlag=true なら予約できる', function (): void {
        resetRequestState();
        $app = makeApp();
        [$pageId, $slotId] = friendFixture($app);
        $userId = userWithFriendFlag($app, 'U-friend-yes', true);

        $result = $app->booking->createGroupBooking([
            'page_id' => $pageId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => true,
            'items' => [['slot_id' => $slotId, 'party_size' => 1, 'companion_names' => []]],
        ]);

        assertTrue($result['ok']);
        assertSame(1, $app->slots->sumConfirmedSeats($slotId));
    });

    test('friendFlag=false では予約が成立しない', function (): void {
        resetRequestState();
        $app = makeApp();
        [$pageId, $slotId] = friendFixture($app);
        $userId = userWithFriendFlag($app, 'U-friend-no', false);

        $result = $app->booking->createGroupBooking([
            'page_id' => $pageId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => true,
            'items' => [['slot_id' => $slotId, 'party_size' => 1, 'companion_names' => []]],
        ]);

        assertFalse($result['ok']);
        assertSame('FRIEND_REQUIRED', $result['code']);
        assertContains('予約専用LINE公式アカウント', $result['message']);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId), '席を消費しないこと');
        assertSame(0, (int) $app->slots->findSlot($slotId)['reserved_seats']);
    });

    test('friendFlag=null（取得できなかった）でも予約が成立しない', function (): void {
        resetRequestState();
        $app = makeApp();
        [$pageId, $slotId] = friendFixture($app);
        $userId = userWithFriendFlag($app, 'U-friend-unknown', null);

        $result = $app->booking->createGroupBooking([
            'page_id' => $pageId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => true,
            'items' => [['slot_id' => $slotId, 'party_size' => 1, 'companion_names' => []]],
        ]);

        assertFalse($result['ok']);
        assertSame('FRIEND_REQUIRED', $result['code']);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId));
    });

    test('user_id が無ければログインが必要', function (): void {
        resetRequestState();
        $app = makeApp();
        [$pageId, $slotId] = friendFixture($app);

        $result = $app->booking->createGroupBooking([
            'page_id' => $pageId,
            'user_id' => null,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => true,
            'items' => [['slot_id' => $slotId, 'party_size' => 1, 'companion_names' => []]],
        ]);

        assertFalse($result['ok']);
        assertSame('LOGIN_REQUIRED', $result['code']);
    });

    test('管理者代理予約は友だち条件の影響を受けない', function (): void {
        resetRequestState();
        $app = makeApp();
        [, $slotId] = friendFixture($app);

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

        assertTrue($result['ok'], '代理予約はLINEを使わないので対象外');
        assertSame(2, $app->slots->sumConfirmedSeats($slotId));
    });

    test('LINEログイン不要ページでは友だち条件を課さない', function (): void {
        resetRequestState();
        $app = makeApp();
        [$pageId, $slotId] = friendFixture($app, [
            'slug' => 'no-login-page',
            'requires_line_login' => false,
        ]);

        $result = $app->booking->createGroupBooking([
            'page_id' => $pageId,
            'user_id' => null,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => true,
            'items' => [['slot_id' => $slotId, 'party_size' => 1, 'companion_names' => []]],
        ]);

        assertTrue($result['ok']);
    });

    test('画面を迂回してPOSTしても友だち条件は突破できない', function (): void {
        resetRequestState();
        $app = makeApp();
        [, $slotId] = friendFixture($app);
        $userId = userWithFriendFlag($app, 'U-bypass', false);
        $app->session->startUserSession($userId);

        $csrf = $app->session->csrfToken();
        $response = request(
            $app,
            'POST',
            '/reserve/friend-required/book',
            friendBookingPost($csrf, $slotId),
            [],
            ['slot_selected' => [(string) $slotId]]
        );

        assertSame(400, $response->status);
        assertContains('予約専用LINE公式アカウント', $response->body);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId));
    });
});

describe('友だち追加の必須化（画面）', function (): void {
    test('未追加なら予約フォームを出さず友だち追加を案内する', function (): void {
        resetRequestState();
        $app = makeApp(['LINE_FRIEND_URL' => 'https://lin.ee/testfriend']);
        friendFixture($app);
        $app->session->startUserSession(userWithFriendFlag($app, 'U-ui-no', false));

        $response = request($app, 'GET', '/reserve/friend-required');

        assertSame(200, $response->status);
        assertContains('予約専用LINE公式アカウントの友だち追加が必要です', $response->body);
        assertContains('https://lin.ee/testfriend', $response->body, '友だち追加リンクを出すこと');
        assertContains('友だち追加を確認して予約に進む', $response->body, '再確認導線があること');
        assertNotContains('id="reserve-form"', $response->body, '予約フォームは出さない');
    });

    test('友だち状態が取得できていない場合も同じ案内を出す', function (): void {
        resetRequestState();
        $app = makeApp();
        friendFixture($app);
        $app->session->startUserSession(userWithFriendFlag($app, 'U-ui-null', null));

        $body = request($app, 'GET', '/reserve/friend-required')->body;

        assertContains('予約専用LINE公式アカウントの友だち追加が必要です', $body);
        assertNotContains('id="reserve-form"', $body);
    });

    test('友だち追加URL未設定でも案内は出る（リンクなし）', function (): void {
        resetRequestState();
        $app = makeApp(['LINE_FRIEND_URL' => '']);
        friendFixture($app);
        $app->session->startUserSession(userWithFriendFlag($app, 'U-ui-nourl', false));

        $body = request($app, 'GET', '/reserve/friend-required')->body;

        assertContains('予約専用LINE公式アカウントの友だち追加が必要です', $body);
        assertContains('LINEアプリで予約専用LINE公式アカウントを検索', $body);
    });

    test('友だち追加済みなら通常どおり予約フォームが出る', function (): void {
        resetRequestState();
        $app = makeApp();
        friendFixture($app);
        $app->session->startUserSession(userWithFriendFlag($app, 'U-ui-yes', true));

        $body = request($app, 'GET', '/reserve/friend-required')->body;

        assertContains('id="reserve-form"', $body);
        assertNotContains('予約専用LINE公式アカウントの友だち追加が必要です', $body);
    });

    test('未ログインなら従来どおりLINEログイン導線を出す', function (): void {
        resetRequestState();
        $app = makeApp();
        friendFixture($app);

        $body = request($app, 'GET', '/reserve/friend-required')->body;

        assertContains('LINEでログインして予約する', $body);
        assertNotContains('id="reserve-form"', $body);
    });

    test('httpsでない友だち追加URLはリンクにしない', function (): void {
        resetRequestState();
        $app = makeApp(['LINE_FRIEND_URL' => 'javascript:alert(1)']);
        friendFixture($app);
        $app->session->startUserSession(userWithFriendFlag($app, 'U-ui-bad', false));

        $body = request($app, 'GET', '/reserve/friend-required')->body;

        assertNotContains('javascript:', $body);
        assertContains('LINEアプリで予約専用LINE公式アカウントを検索', $body);
    });
});

describe('友だち追加済みの予約は通知対象になる', function (): void {
    test('予約確定 → 予約完了通知が送られ、詳細URLが入る', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp(['APP_URL' => 'https://reserve.example.com'], $http);
        [, $slotId] = friendFixture($app);
        $app->session->startUserSession(userWithFriendFlag($app, 'U-flow-friend', true));

        $csrf = $app->session->csrfToken();
        $response = request(
            $app,
            'POST',
            '/reserve/friend-required/book',
            friendBookingPost($csrf, $slotId),
            [],
            ['slot_selected' => [(string) $slotId]]
        );

        assertSame(303, $response->status);
        $bookingId = (int) $app->bookings->listBySlot($slotId, null)[0]['id'];

        $text = pushedTexts($http)[0];
        assertContains('予約が完了しました。', $text);
        assertContains('https://reserve.example.com/bookings/' . $bookingId, $text);
    });

    test('管理者代理予約には通知を送らない（友だち条件と無関係）', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp([], $http);
        [, $slotId] = friendFixture($app);

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => null,
            'source' => 'admin',
            'representative_name' => '電話 太郎',
            'phone' => '0489361126',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);

        assertSame('skipped', $app->reminders->sendBookingConfirmation([$booking['booking_id']]));
        assertSame(0, count($http->calls));
    });
});
