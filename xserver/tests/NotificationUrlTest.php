<?php

declare(strict_types=1);

/**
 * LINE通知に予約詳細URLが入ることの検証。
 *
 * 本番実機で「予約内容は下記ページから確認できます。」の後にURLが無く、
 * 利用者が通知から予約詳細へ辿れない状態だった。
 */

use App\Services\LineMessenger;

describe('予約完了通知の予約詳細URL', function (): void {
    test('絶対URLが本文末尾に入る', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp(['APP_URL' => 'https://reserve.example.com'], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app));

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => Fixtures::user($app, 'U-url-001'),
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 2,
            'companion_names' => ['花子'],
            'agreed' => true,
        ]);
        assertTrue($booking['ok']);

        assertSame('requested', $app->reminders->sendBookingConfirmation([$booking['booking_id']]));

        $texts = pushedTexts($http);
        assertSame(1, count($texts));
        $text = $texts[0];

        assertContains('予約内容はこちらから確認できます。', $text);
        assertContains(
            'https://reserve.example.com/bookings/' . $booking['booking_id'],
            $text,
            '正しい予約IDの絶対URLが入ること'
        );
        // 末尾がURLで終わる（LINEが自動リンクにできる形）
        $lines = explode("\n", $text);
        assertSame(
            'https://reserve.example.com/bookings/' . $booking['booking_id'],
            trim((string) end($lines))
        );
    });

    test('completed=1 は付けない（恒久リンクのため）', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp(['APP_URL' => 'https://reserve.example.com'], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app));

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => Fixtures::user($app, 'U-url-002'),
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);
        $app->reminders->sendBookingConfirmation([$booking['booking_id']]);

        $text = pushedTexts($http)[0];
        assertNotContains('completed=1', $text);
        assertNotContains('?', $text, 'クエリを付けないこと');
    });

    test('APP_URLを使い、ドメインをハードコードしていない', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        // 本番とは別のドメインを設定しても、そのドメインが使われること
        $app = makeApp(['APP_URL' => 'https://another-host.example.jp'], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app));

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => Fixtures::user($app, 'U-url-003'),
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);
        $app->reminders->sendBookingConfirmation([$booking['booking_id']]);

        $text = pushedTexts($http)[0];
        assertContains('https://another-host.example.jp/bookings/', $text);
        assertNotContains('reserve.yunoizumi.com', $text, '本番ドメインが埋め込まれていないこと');

        // ソース上も本番ドメインを持たない
        $messenger = (string) file_get_contents(dirname(__DIR__) . '/app/Services/LineMessenger.php');
        assertNotContains('yunoizumi', $messenger);
        assertNotContains('reserve.example', $messenger);
    });

    test('一括予約では先頭の予約IDのURLを使う', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp(['APP_URL' => 'https://reserve.example.com'], $http);
        $pageId = Fixtures::page($app);
        $outbound = Fixtures::slot($app, $pageId, ['name' => '行き']);
        $return = Fixtures::slot($app, $pageId, [
            'name' => '帰り',
            'start_at' => '2099-08-21 23:10:00',
            'sort_order' => 2,
        ]);

        $result = $app->booking->createGroupBooking([
            'page_id' => $pageId,
            'user_id' => Fixtures::user($app, 'U-url-group'),
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => true,
            'items' => [
                ['slot_id' => $outbound, 'party_size' => 2, 'companion_names' => ['花子']],
                ['slot_id' => $return, 'party_size' => 2, 'companion_names' => ['花子']],
            ],
        ]);
        assertTrue($result['ok']);
        assertSame(2, count($result['booking_ids']));

        $app->reminders->sendBookingConfirmation($result['booking_ids']);

        $texts = pushedTexts($http);
        assertSame(1, count($texts), '一括予約は1通にまとめる');
        $text = $texts[0];

        $first = min($result['booking_ids']);
        assertContains('https://reserve.example.com/bookings/' . $first, $text);
        // 2件分の枠情報は入るが、URLは1本だけ
        assertContains('【行き】', $text);
        assertContains('【帰り】', $text);
        assertSame(1, substr_count($text, '/bookings/'), 'URLは1本だけにする');
    });

    test('URL以外の既存文言は維持されている', function (): void {
        $text = LineMessenger::buildBookingConfirmationText(
            'らっこ号 池袋便',
            'bus',
            [[
                'slot_name' => '行き',
                'start_at' => '2026-08-21 11:00:00',
                'origin' => '池袋西口',
                'destination' => '草加健康センター',
                'location' => null,
                'party_size' => 2,
            ]],
            'https://reserve.example.com/bookings/12'
        );

        assertContains('🚌 らっこ号 池袋便', $text);
        assertContains('予約が完了しました。', $text);
        assertContains('【行き】', $text);
        assertContains('8月21日（金）20:00', $text);
        assertContains('池袋西口 → 草加健康センター', $text);
        assertContains('予約人数：2名', $text);
    });

    test('URLを渡せない場合でも案内文は残る', function (): void {
        $text = LineMessenger::buildBookingConfirmationText('イベント', 'event', [[
            'slot_name' => '第1回',
            'start_at' => '2026-08-21 11:00:00',
            'origin' => null,
            'destination' => null,
            'location' => '大広間',
            'party_size' => 1,
        ]], null);

        assertContains('予約内容は「マイ予約」から確認できます。', $text);
        assertNotContains('/bookings/', $text);
    });

    test('bookingDetailUrl はベースURLの末尾スラッシュを吸収する', function (): void {
        assertSame(
            'https://reserve.example.com/bookings/7',
            LineMessenger::bookingDetailUrl('https://reserve.example.com', 7)
        );
        assertSame(
            'https://reserve.example.com/bookings/7',
            LineMessenger::bookingDetailUrl('https://reserve.example.com/', 7)
        );
    });
});

describe('リマインド通知の予約詳細URL', function (): void {
    test('リマインドにも予約詳細URLが入る', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp(['APP_URL' => 'https://reserve.example.com'], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app), [
            'start_at' => '2099-08-21 11:00:00',
            'reminder_at' => '2099-08-21 08:00:00',
        ]);

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => Fixtures::user($app, 'U-remind-url'),
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 2,
            'companion_names' => ['花子'],
            'agreed' => true,
        ]);

        $summary = $app->reminders->processDueReminders('2099-08-21 08:01:00');
        assertSame(1, $summary['requested']);

        $text = pushedTexts($http)[0];
        assertContains('予約内容を確認する:', $text);
        assertContains(
            'https://reserve.example.com/bookings/' . $booking['booking_id'],
            $text
        );
        assertNotContains('completed=1', $text);

        // リマインドの既存文言も維持
        assertContains('のお知らせ', $text);
        assertContains('予約人数：2名', $text);
    });

    test('リマインド本文はURLなしでも従来どおり組み立てられる', function (): void {
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
        assertNotContains('予約内容を確認する:', $text);
    });
});

describe('通知URLを開いたときの認証', function (): void {
    test('未ログインで予約詳細URLを開くとLINEログインへ誘導され、元URLへ戻れる', function (): void {
        resetRequestState();
        $app = makeApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app));
        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => Fixtures::user($app, 'U-owner-url'),
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);
        $bookingId = $booking['booking_id'];

        // 未ログイン（LINEから開いた直後）
        resetRequestState();
        $response = request($app, 'GET', '/bookings/' . $bookingId);

        assertSame(303, $response->status);
        $location = $response->headers['Location'];
        assertContains('/login', $location);
        assertContains('redirect_to=', $location);
        assertContains(rawurlencode('/bookings/' . $bookingId), $location, '元URLへ戻れること');
    });

    test('通知URLを知っていても他人の予約は見られない', function (): void {
        resetRequestState();
        $app = makeApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app));
        $owner = Fixtures::user($app, 'U-owner');
        $other = Fixtures::user($app, 'U-other');

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => $owner,
            'source' => 'line',
            'representative_name' => '所有者',
            'phone' => '090-0000-0001',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);

        $app->session->startUserSession($other);
        $response = request($app, 'GET', '/bookings/' . $booking['booking_id']);
        assertSame(404, $response->status, '所有者以外には存在を明かさない');

        $app->session->startUserSession($owner);
        $ownerView = request($app, 'GET', '/bookings/' . $booking['booking_id']);
        assertSame(200, $ownerView->status);
        assertContains('所有者', $ownerView->body);
    });
});
