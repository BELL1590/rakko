<?php

declare(strict_types=1);

/** LINE通知の本文・送信・リマインドの重複防止と再試行。 */

use App\Services\LineMessenger;
use App\Services\ReminderService;

describe('LINEメッセージの本文', function (): void {
    test('予約完了通知に枠名・日時・経路・人数が入る', function (): void {
        $text = LineMessenger::buildBookingConfirmationText('らっこ号 池袋便', 'bus', [[
            'slot_name' => '行き',
            'start_at' => '2026-08-21 11:00:00',
            'origin' => '池袋西口',
            'destination' => '草加健康センター',
            'location' => null,
            'party_size' => 2,
        ]]);

        assertContains('らっこ号 池袋便', $text);
        assertContains('【行き】', $text);
        assertContains('20:00', $text, 'JSTで表示すること');
        assertContains('池袋西口 → 草加健康センター', $text);
        assertContains('予約人数：2名', $text);
    });

    test('一括予約は1通に複数枠がまとまる', function (): void {
        $text = LineMessenger::buildBookingConfirmationText('らっこ号 池袋便', 'bus', [
            [
                'slot_name' => '行き',
                'start_at' => '2026-08-21 11:00:00',
                'origin' => '池袋西口',
                'destination' => '草加健康センター',
                'location' => null,
                'party_size' => 2,
            ],
            [
                'slot_name' => '帰り',
                'start_at' => '2026-08-21 23:10:00',
                'origin' => '草加健康センター',
                'destination' => '池袋西口',
                'location' => null,
                'party_size' => 3,
            ],
        ]);

        assertContains('【行き】', $text);
        assertContains('【帰り】', $text);
        assertContains('予約人数：2名', $text);
        assertContains('予約人数：3名', $text);
    });

    test('バスのリマインドは出発地と集合案内を含む', function (): void {
        $text = LineMessenger::buildReminderText(
            'らっこ号 池袋便',
            'bus',
            '行き',
            '2026-08-21 11:00:00',
            '池袋西口',
            null,
            2
        );

        assertContains('らっこ号 池袋便「行き」のお知らせ', $text);
        assertContains('本日20:00 池袋西口出発です。', $text);
        assertContains('出発15分前', $text);
        assertContains('予約人数：2名', $text);
    });

    test('会場型のリマインドは会場名を含む', function (): void {
        $text = LineMessenger::buildReminderText(
            '健康講座',
            'event',
            '第1回',
            '2026-08-21 01:00:00',
            null,
            '草加健康センター 大広間',
            1
        );

        assertContains('本日10:00 開始です。', $text);
        assertContains('会場：草加健康センター 大広間', $text);
        assertNotContains('出発', $text);
    });

    test('経路表記は出発地・到着地・会場の有無で切り替わる', function (): void {
        assertSame('A → B', LineMessenger::routeLine('A', 'B', null));
        assertSame('会場：C', LineMessenger::routeLine(null, null, 'C'));
        assertSame('集合：D', LineMessenger::routeLine('D', null, null));
        assertSame('', LineMessenger::routeLine(null, null, null));
    });
});

describe('通知の送信', function (): void {
    test('LINE予約は予約完了通知が送られ requested になる', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp([], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app));
        $userId = Fixtures::user($app, 'U-line-001');

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 2,
            'companion_names' => ['花子'],
            'agreed' => true,
        ]);

        $outcome = $app->reminders->sendBookingConfirmation([$booking['booking_id']]);

        assertSame('requested', $outcome);
        assertSame(1, count($http->calls), 'push APIを1回呼ぶこと');
        assertContains('U-line-001', $http->calls[0]['body']);
        assertContains('Bearer line-messaging-token', $http->calls[0]['headers']['Authorization']);

        $notification = $app->notifications->find($booking['booking_id'], 'booking_confirmation');
        assertSame('requested', $notification['status']);
    });

    test('管理者代理予約には通知を送らない', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp([], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app));

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

        $outcome = $app->reminders->sendBookingConfirmation([$booking['booking_id']]);

        assertSame('skipped', $outcome);
        assertSame(0, count($http->calls), 'LINE APIを呼ばないこと');
    });

    test('同じ通知は二度送られない（冪等）', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp([], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app));
        $userId = Fixtures::user($app, 'U-line-002');

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);

        assertSame('requested', $app->reminders->sendBookingConfirmation([$booking['booking_id']]));
        assertSame('already', $app->reminders->sendBookingConfirmation([$booking['booking_id']]));
        assertSame(1, count($http->calls), '2回目はAPIを呼ばない');
    });

    test('5xx は failed として記録し、後から再試行できる', function (): void {
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 500, 'body' => 'server error'],
            ['status' => 200, 'body' => '{}'],
        ];
        resetRequestState();
        $app = makeApp([], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app));
        $userId = Fixtures::user($app, 'U-line-003');

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);

        assertSame('failed', $app->reminders->sendBookingConfirmation([$booking['booking_id']]));
        $failed = $app->notifications->find($booking['booking_id'], 'booking_confirmation');
        assertSame('failed', $failed['status']);
        assertSame(1, (int) $failed['attempt_count']);

        assertSame('requested', $app->reminders->sendBookingConfirmation([$booking['booking_id']]), '再試行できる');
        assertSame(2, (int) $app->notifications->find($booking['booking_id'], 'booking_confirmation')['attempt_count']);
    });

    test('4xx は再試行しても変わらないため skipped で確定する', function (): void {
        $http = new FakeHttpClient(400, '{"message":"invalid"}');
        resetRequestState();
        $app = makeApp([], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app));
        $userId = Fixtures::user($app, 'U-line-004');

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);

        assertSame('skipped', $app->reminders->sendBookingConfirmation([$booking['booking_id']]));
        assertSame('skipped', $app->notifications->find($booking['booking_id'], 'booking_confirmation')['status']);
    });

    test('アクセストークン未設定なら送信をスキップする', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp(['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN' => ''], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app));
        $userId = Fixtures::user($app, 'U-line-005');

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);

        assertSame('skipped', $app->reminders->sendBookingConfirmation([$booking['booking_id']]));
        assertSame(0, count($http->calls));
    });
});

describe('リマインドバッチ', function (): void {
    /** リマインド対象の予約を1件作る。 */
    $prepare = static function (FakeHttpClient $http, array $slotOverrides = []): array {
        resetRequestState();
        $app = makeApp([], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app), array_merge([
            'start_at' => '2099-08-21 11:00:00',
            'reminder_at' => '2099-08-21 08:00:00',
        ], $slotOverrides));
        $userId = Fixtures::user($app, 'U-reminder-001');

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 2,
            'companion_names' => ['花子'],
            'agreed' => true,
        ]);

        return [$app, $slotId, $booking['booking_id']];
    };

    test('reminder_at を過ぎたら送信対象になる', function () use ($prepare): void {
        $http = new FakeHttpClient(200, '{}');
        [$app] = $prepare($http);

        $before = $app->reminders->processDueReminders('2099-08-21 07:59:00');
        assertSame(0, $before['checked'], '時刻前は対象にならない');

        $after = $app->reminders->processDueReminders('2099-08-21 08:01:00');
        assertSame(1, $after['checked']);
        assertSame(1, $after['requested']);
        assertContains('お知らせ', $http->calls[0]['body']);
    });

    test('開始後の枠にはリマインドを送らない', function () use ($prepare): void {
        $http = new FakeHttpClient(200, '{}');
        [$app] = $prepare($http);

        $summary = $app->reminders->processDueReminders('2099-08-21 12:00:00');
        assertSame(0, $summary['checked'], '出発済みの枠は対象外');
        assertSame(0, count($http->calls));
    });

    test('リマインドは1予約につき1回だけ送られる', function () use ($prepare): void {
        $http = new FakeHttpClient(200, '{}');
        [$app] = $prepare($http);

        $first = $app->reminders->processDueReminders('2099-08-21 08:01:00');
        assertSame(1, $first['requested']);

        $second = $app->reminders->processDueReminders('2099-08-21 08:06:00');
        assertSame(0, $second['checked'], '送信済みは再抽出されない');
        assertSame(1, count($http->calls));
    });

    test('失敗したリマインドは上限まで再試行し、それ以降は諦める', function () use ($prepare): void {
        $http = new FakeHttpClient(500, 'server error');
        [$app] = $prepare($http);

        for ($attempt = 1; $attempt <= ReminderService::MAX_ATTEMPTS; $attempt++) {
            $summary = $app->reminders->processDueReminders('2099-08-21 08:0' . $attempt . ':00');
            assertSame(1, $summary['failed'], $attempt . '回目は再試行されること');
        }

        $giveUp = $app->reminders->processDueReminders('2099-08-21 08:59:00');
        assertSame(0, $giveUp['checked'], '上限を超えたら対象から外れる');
        assertSame(ReminderService::MAX_ATTEMPTS, count($http->calls));
    });

    test('reminder_at 未設定の枠は送信対象にならない', function () use ($prepare): void {
        $http = new FakeHttpClient(200, '{}');
        [$app] = $prepare($http, ['reminder_at' => null]);

        $summary = $app->reminders->processDueReminders('2099-08-21 10:00:00');
        assertSame(0, $summary['checked']);
    });

    test('キャンセル済みの予約にはリマインドを送らない', function () use ($prepare): void {
        $http = new FakeHttpClient(200, '{}');
        [$app, , $bookingId] = $prepare($http);
        $app->booking->cancelBooking($bookingId, null, true);

        $summary = $app->reminders->processDueReminders('2099-08-21 08:01:00');
        assertSame(0, $summary['checked']);
        assertSame(0, count($http->calls));
    });

    test('管理画面からリマインドを手動実行できる', function (): void {
        $http = new FakeHttpClient(200, '{}');
        $app = adminApp([], $http);
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/admin/reminders/run', ['csrf_token' => $csrf]);
        assertSame(303, $response->status);
        assertContains('msg=reminder_done', $response->headers['Location']);
    });
});
