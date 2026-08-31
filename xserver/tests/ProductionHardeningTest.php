<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;

/** @return list<array{method: string, url: string, body: string, headers: array<string, string>}> */
function hardeningPushCalls(FakeHttpClient $http): array
{
    return array_values(array_filter(
        $http->calls,
        static fn (array $call): bool => str_contains($call['url'], '/v2/bot/message/push')
    ));
}

/**
 * @return array{page_id: int, user_id: int, booking_ids: list<int>}
 */
function hardeningCreateBookings(App\App $app, bool $multiple = false): array
{
    $pageId = Fixtures::page($app, ['slug' => $multiple ? 'hardening-group' : 'hardening-single']);
    $slot1 = Fixtures::slot($app, $pageId, [
        'name' => '行き',
        'sort_order' => 1,
        'start_at' => '2099-08-21 11:00:00',
    ]);
    $userId = Fixtures::user($app, $multiple ? 'U-hardening-group' : 'U-hardening-single');

    $items = [[
        'slot_id' => $slot1,
        'party_size' => 1,
        'companion_names' => [],
    ]];

    if ($multiple) {
        $slot2 = Fixtures::slot($app, $pageId, [
            'name' => '帰り',
            'sort_order' => 2,
            'start_at' => '2099-08-21 18:00:00',
        ]);
        $items[] = [
            'slot_id' => $slot2,
            'party_size' => 1,
            'companion_names' => [],
        ];
    }

    $result = $app->booking->createGroupBooking([
        'page_id' => $pageId,
        'user_id' => $userId,
        'source' => 'line',
        'representative_name' => '山田太郎',
        'phone' => '090-1234-5678',
        'agreed' => true,
        'items' => $items,
    ], '2026-08-31 05:00:00');

    assertTrue($result['ok'], 'テスト予約が作成できること');

    return [
        'page_id' => $pageId,
        'user_id' => $userId,
        'booking_ids' => $result['booking_ids'],
    ];
}

describe('Production hardening: LIFF CSP', function (): void {
    test('/liff/reserve/{slug} にもLIFF用CSPを適用する', function (): void {
        assertTrue(SecurityHeaders::needsLiff('/liff'));
        assertTrue(SecurityHeaders::needsLiff('/liff/reserve/example'));
        assertTrue(SecurityHeaders::needsLiff('/liff/reserve/rakko-ikebukuro'));

        foreach (['/', '/reserve/example', '/admin', '/liff-evil'] as $normalPath) {
            assertFalse(SecurityHeaders::needsLiff($normalPath), $normalPath . ' は通常CSPのまま');
        }

        $liff = SecurityHeaders::contentSecurityPolicy(
            SecurityHeaders::needsLiff('/liff/reserve/example')
        );
        assertContains('https://static.line-scdn.net', $liff);
        assertContains('https://api.line.me', $liff);

        $normal = SecurityHeaders::contentSecurityPolicy(
            SecurityHeaders::needsLiff('/reserve/example')
        );
        assertNotContains('https://static.line-scdn.net', $normal);
        assertNotContains('https://api.line.me', $normal);
    });
});

describe('Production hardening: LINE friendship fail closed', function (): void {
    test('以前trueでも今回の友だち状態が不明ならtrueを保持しない', function (): void {
        resetRequestState();
        $app = makeApp();

        $first = $app->users->upsertByLineId('U-stale-friend', 'テスト太郎', null, true);
        assertSame(1, (int) $first['is_line_friend']);

        $second = $app->users->upsertByLineId('U-stale-friend', 'テスト太郎', null, null);
        assertNull($second['is_line_friend'], '過去のtrueを不明時に残さないこと');
    });

    test('true → unknown のあと公開予約は拒否する', function (): void {
        resetRequestState();
        $app = makeApp();
        $pageId = Fixtures::page($app, ['slug' => 'stale-friend-block']);
        $slotId = Fixtures::slot($app, $pageId);

        $user = $app->users->upsertByLineId('U-stale-friend-book', 'テスト太郎', null, true);
        $app->users->upsertByLineId('U-stale-friend-book', 'テスト太郎', null, null);

        $result = $app->booking->createGroupBooking([
            'page_id' => $pageId,
            'user_id' => (int) $user['id'],
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => true,
            'items' => [[
                'slot_id' => $slotId,
                'party_size' => 1,
                'companion_names' => [],
            ]],
        ], '2026-08-31 05:00:00');

        assertFalse($result['ok']);
        assertSame('FRIEND_REQUIRED', $result['code']);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId));
    });

    test('既知のfalseはunknownで安全側のfalseを維持する', function (): void {
        resetRequestState();
        $app = makeApp();

        $app->users->upsertByLineId('U-known-false', 'テスト太郎', null, false);
        $user = $app->users->upsertByLineId('U-known-false', 'テスト太郎', null, null);

        assertSame(0, (int) $user['is_line_friend']);
    });
});

describe('Production hardening: booking confirmation retry', function (): void {
    test('500で失敗した予約完了通知をCron処理で再送してrequestedにする', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 500, 'body' => '{"message":"temporary"}'],
            ['status' => 200, 'body' => '{}'],
        ];
        $app = makeApp([], $http);
        $fixture = hardeningCreateBookings($app);
        $bookingId = $fixture['booking_ids'][0];

        $first = $app->reminders->sendBookingConfirmation([$bookingId], '2026-08-31 05:00:00');
        assertSame('failed', $first);
        assertSame('failed', $app->notifications->find($bookingId, 'booking_confirmation')['status']);

        $firstCall = hardeningPushCalls($http)[0];
        $firstRetryKey = $firstCall['headers']['X-Line-Retry-Key'];

        $summary = $app->reminders->processFailedBookingConfirmations('2026-08-31 05:05:00');
        assertSame(1, $summary['checked']);
        assertSame(1, $summary['requested']);

        $notification = $app->notifications->find($bookingId, 'booking_confirmation');
        assertSame('requested', $notification['status']);
        assertSame(2, (int) $notification['attempt_count']);

        $calls = hardeningPushCalls($http);
        assertSame(2, count($calls));
        assertSame($firstRetryKey, $calls[1]['headers']['X-Line-Retry-Key'], 'retry keyを再利用すること');
    });

    test('409はLINE側で受理済みとしてrequestedに確定する', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 500, 'body' => '{}'],
            ['status' => 409, 'body' => '{}'],
        ];
        $app = makeApp([], $http);
        $fixture = hardeningCreateBookings($app);
        $bookingId = $fixture['booking_ids'][0];

        assertSame(
            'failed',
            $app->reminders->sendBookingConfirmation([$bookingId], '2026-08-31 05:00:00')
        );
        $summary = $app->reminders->processFailedBookingConfirmations('2026-08-31 05:05:00');

        assertSame(1, $summary['requested']);
        assertSame('requested', $app->notifications->find($bookingId, 'booking_confirmation')['status']);
    });

    test('最大3回失敗した通知は4回目をHTTP送信しない', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 500, 'body' => '{}'],
            ['status' => 500, 'body' => '{}'],
            ['status' => 500, 'body' => '{}'],
            ['status' => 200, 'body' => '{}'],
        ];
        $app = makeApp([], $http);
        $fixture = hardeningCreateBookings($app);
        $bookingId = $fixture['booking_ids'][0];

        $app->reminders->sendBookingConfirmation([$bookingId], '2026-08-31 05:00:00');
        $app->reminders->processFailedBookingConfirmations('2026-08-31 05:05:00');
        $app->reminders->processFailedBookingConfirmations('2026-08-31 05:10:00');
        $fourth = $app->reminders->processFailedBookingConfirmations('2026-08-31 05:15:00');

        assertSame(0, $fourth['checked']);
        assertSame(3, count(hardeningPushCalls($http)), '4回目のpushを行わないこと');
        $notification = $app->notifications->find($bookingId, 'booking_confirmation');
        assertSame('failed', $notification['status']);
        assertSame(3, (int) $notification['attempt_count']);
    });

    test('requested済み通知はCronで再送しない', function (): void {
        resetRequestState();
        $http = new FakeHttpClient(200, '{}');
        $app = makeApp([], $http);
        $fixture = hardeningCreateBookings($app);
        $bookingId = $fixture['booking_ids'][0];

        assertSame(
            'requested',
            $app->reminders->sendBookingConfirmation([$bookingId], '2026-08-31 05:00:00')
        );
        $summary = $app->reminders->processFailedBookingConfirmations('2026-08-31 05:05:00');

        assertSame(0, $summary['checked']);
        assertSame(1, count(hardeningPushCalls($http)));
    });

    test('キャンセル済み予約の失敗通知は再送しない', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 500, 'body' => '{}'],
            ['status' => 200, 'body' => '{}'],
        ];
        $app = makeApp([], $http);
        $fixture = hardeningCreateBookings($app);
        $bookingId = $fixture['booking_ids'][0];

        $app->reminders->sendBookingConfirmation([$bookingId], '2026-08-31 05:00:00');
        $cancel = $app->booking->cancelBooking(
            $bookingId,
            $fixture['user_id'],
            false,
            '2026-08-31 05:01:00'
        );
        assertTrue($cancel['ok']);

        $summary = $app->reminders->processFailedBookingConfirmations('2026-08-31 05:05:00');
        assertSame(0, $summary['checked']);
        assertSame(1, count(hardeningPushCalls($http)));
    });

    test('一括予約の再試行は同じ本文と同じretry keyで1通だけ送る', function (): void {
        resetRequestState();
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 500, 'body' => '{}'],
            ['status' => 200, 'body' => '{}'],
        ];
        $app = makeApp([], $http);
        $fixture = hardeningCreateBookings($app, true);

        assertSame(
            'failed',
            $app->reminders->sendBookingConfirmation($fixture['booking_ids'], '2026-08-31 05:00:00')
        );
        $firstCall = hardeningPushCalls($http)[0];

        $summary = $app->reminders->processFailedBookingConfirmations('2026-08-31 05:05:00');
        assertSame(1, $summary['checked'], '一括予約を1グループとして処理すること');
        assertSame(1, $summary['requested']);

        $calls = hardeningPushCalls($http);
        assertSame(2, count($calls));
        assertSame($firstCall['body'], $calls[1]['body'], '再送本文を変えないこと');
        assertSame(
            $firstCall['headers']['X-Line-Retry-Key'],
            $calls[1]['headers']['X-Line-Retry-Key'],
            '一括予約でもretry keyを維持すること'
        );

        foreach ($fixture['booking_ids'] as $bookingId) {
            assertSame(
                'requested',
                $app->notifications->find($bookingId, 'booking_confirmation')['status']
            );
        }
    });
});

describe('Production hardening: app root configuration', function (): void {
    test('public/index.php は本番手修正ではなくRAKKO_APP_ROOTを使う', function (): void {
        $source = file_get_contents(dirname(__DIR__) . '/public/index.php');
        assertTrue(is_string($source));
        assertContains("getenv('RAKKO_APP_ROOT')", $source);
        assertContains("dirname(__DIR__)", $source, '未設定時のローカルfallbackを維持する');
        assertContains('is_file($bootstrap)', $source, 'bootstrap存在確認を行う');
        assertContains('Application configuration error.', $source, '内部パスを出さない失敗応答を持つ');
        assertNotContains("$root = '/home/", $source, '本番固有の絶対パスを埋め込まない');
    });
});
