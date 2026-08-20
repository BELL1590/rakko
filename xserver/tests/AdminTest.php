<?php

declare(strict_types=1);

/** 管理画面: 認証・設定変更・代理予約・受付操作・状態表示。 */

describe('管理画面の認可', function (): void {
    test('未ログインのGETはログイン画面へ、POSTは401', function (): void {
        resetRequestState();
        $app = makeApp();

        $get = request($app, 'GET', '/admin');
        assertSame(303, $get->status);
        assertContains('/admin/login', $get->headers['Location']);

        $post = request($app, 'POST', '/admin/reminders/run');
        assertSame(401, $post->status, 'POSTはリダイレクトせず拒否する');
    });

    test('正しいユーザー名・パスワードでログインできる', function (): void {
        resetRequestState();
        $app = makeApp();
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/admin/login', [
            'csrf_token' => $csrf,
            'username' => 'admin',
            'password' => 'admin-password',
        ]);

        assertSame(303, $response->status);
        assertSame('/admin', $response->headers['Location']);
        assertNotNull($app->session->adminUser());
    });

    test('パスワードが違えばログインできない', function (): void {
        resetRequestState();
        $app = makeApp();
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/admin/login', [
            'csrf_token' => $csrf,
            'username' => 'admin',
            'password' => 'wrong-password',
        ]);

        assertContains('msg=admin_login_failed', $response->headers['Location']);
        assertNull($app->session->adminUser());
    });

    test('CSRFトークンの無いログインは拒否される', function (): void {
        resetRequestState();
        $app = makeApp();

        $response = request($app, 'POST', '/admin/login', [
            'username' => 'admin',
            'password' => 'admin-password',
        ]);

        assertContains('msg=csrf', $response->headers['Location']);
        assertNull($app->session->adminUser());
    });

    test('利用者用セッションでは管理画面に入れない', function (): void {
        resetRequestState();
        $app = makeApp();
        $app->session->startUserSession(Fixtures::user($app));

        $response = request($app, 'GET', '/admin');
        assertSame(303, $response->status);
        assertContains('/admin/login', $response->headers['Location']);
    });
});

describe('管理画面の操作', function (): void {
    test('ダッシュボードに予約枠の状況が出る', function (): void {
        $app = adminApp();
        $pageId = Fixtures::page($app);
        Fixtures::slot($app, $pageId, ['capacity' => 40]);

        $response = request($app, 'GET', '/admin');
        assertSame(200, $response->status);
        assertContains('らっこ号 池袋便', $response->body);
        assertContains('本日の予約人数', $response->body);
        assertContains('残席わずか', $response->body);
    });

    test('予約ページを作成・編集できる', function (): void {
        $app = adminApp();
        $csrf = $app->session->csrfToken();

        $create = request($app, 'POST', '/admin/reservations', [
            'csrf_token' => $csrf,
            'slug' => 'new-event',
            'title' => '新しいイベント',
            'description' => '説明',
            'status' => 'published',
            'page_type' => 'event',
            'requires_line_login' => '1',
            'allow_multi_slot_booking' => '1',
            'max_slots_per_checkout' => '3',
            'checkin_label' => '来場',
        ]);
        assertSame(303, $create->status);
        assertContains('msg=page_created', $create->headers['Location']);

        $page = $app->slots->findPageBySlug('new-event');
        assertNotNull($page);
        assertSame('新しいイベント', $page['title']);
        assertSame(3, (int) $page['max_slots_per_checkout']);
        assertSame('来場', $page['checkin_label']);

        $update = request($app, 'POST', '/admin/reservations/' . (int) $page['id'], [
            'csrf_token' => $csrf,
            'slug' => 'new-event',
            'title' => '改題したイベント',
            'description' => '',
            'status' => 'closed',
            'page_type' => 'event',
            'max_slots_per_checkout' => '1',
            'checkin_label' => '受付',
        ]);
        assertSame(303, $update->status);

        $updated = $app->slots->findPageById((int) $page['id']);
        assertSame('改題したイベント', $updated['title']);
        assertSame('closed', $updated['status']);
        assertSame(0, (int) $updated['allow_multi_slot_booking'], 'チェックを外せば0になる');
        assertSame(0, (int) $updated['requires_line_login']);
    });

    test('slug が重複するページは作れない', function (): void {
        $app = adminApp();
        Fixtures::page($app);
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/admin/reservations', [
            'csrf_token' => $csrf,
            'slug' => 'rakko-ikebukuro',
            'title' => '重複',
            'status' => 'draft',
            'page_type' => 'other',
            'max_slots_per_checkout' => '4',
        ]);

        assertContains('msg=slug_taken', $response->headers['Location']);
    });

    test('不正な slug は拒否される', function (): void {
        $app = adminApp();
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/admin/reservations', [
            'csrf_token' => $csrf,
            'slug' => 'Bad Slug!',
            'title' => 'だめ',
            'status' => 'draft',
            'page_type' => 'other',
            'max_slots_per_checkout' => '4',
        ]);

        assertContains('msg=slug_invalid', $response->headers['Location']);
    });

    test('予約ページを枠ごと複製できる（下書きとして）', function (): void {
        $app = adminApp();
        $pageId = Fixtures::page($app);
        Fixtures::slot($app, $pageId, ['name' => '行き']);
        Fixtures::slot($app, $pageId, ['name' => '帰り', 'sort_order' => 2]);
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/admin/reservations/' . $pageId . '/duplicate', [
            'csrf_token' => $csrf,
        ]);
        assertContains('msg=page_duplicated', $response->headers['Location']);

        $copy = $app->slots->findPageBySlug('rakko-ikebukuro-copy');
        assertNotNull($copy);
        assertSame('draft', $copy['status'], '複製は下書きから始める');
        assertSame(2, count($app->slots->listSlotsByPage((int) $copy['id'])), '枠も複製される');
    });

    test('予約枠を追加・編集できる', function (): void {
        $app = adminApp();
        $pageId = Fixtures::page($app);
        $csrf = $app->session->csrfToken();

        $create = request($app, 'POST', '/admin/reservations/' . $pageId . '/slots', [
            'csrf_token' => $csrf,
            'name' => '行き',
            'description' => '',
            'start_at' => '2099-08-21T20:00',
            'origin' => '池袋西口',
            'destination' => '草加健康センター',
            'capacity' => '24',
            'max_party_size' => '4',
            'booking_status' => 'open',
            'sort_order' => '1',
        ]);
        assertContains('msg=slot_created', $create->headers['Location']);

        $slots = $app->slots->listSlotsByPage($pageId);
        assertSame(1, count($slots));
        $slot = $slots[0];
        assertSame('行き', $slot['name']);
        assertSame(24, (int) $slot['capacity']);
        // JST 20:00 は UTC 11:00 として保存される
        assertSame('2099-08-21 11:00:00', $slot['start_at']);

        $update = request($app, 'POST', '/admin/slots/' . (int) $slot['id'], [
            'csrf_token' => $csrf,
            'name' => '行き（変更）',
            'start_at' => '2099-08-21T20:30',
            'capacity' => '30',
            'max_party_size' => '2',
            'booking_status' => 'closed',
            'sort_order' => '5',
            'reminder_at' => '2099-08-21T17:00',
        ]);
        assertContains('msg=saved', $update->headers['Location']);

        $updated = $app->slots->findSlot((int) $slot['id']);
        assertSame('行き（変更）', $updated['name']);
        assertSame(30, (int) $updated['capacity']);
        assertSame('closed', $updated['booking_status']);
        assertSame('2099-08-21 08:00:00', $updated['reminder_at'], 'リマインドもUTCで保存される');
    });

    test('確定予約より小さい定員には変更できない', function (): void {
        $app = adminApp();
        $pageId = Fixtures::page($app);
        $slotId = Fixtures::slot($app, $pageId, ['capacity' => 10]);
        $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => null,
            'source' => 'admin',
            'representative_name' => '先客',
            'phone' => '0489361126',
            'party_size' => 4,
            'companion_names' => ['a', 'b', 'c'],
            'agreed' => true,
        ]);
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/admin/slots/' . $slotId, [
            'csrf_token' => $csrf,
            'name' => '行き',
            'start_at' => '2099-08-21T20:00',
            'capacity' => '2',
            'max_party_size' => '4',
            'booking_status' => 'open',
            'sort_order' => '1',
        ]);

        assertContains('msg=capacity_too_small', $response->headers['Location']);
        assertSame(10, (int) $app->slots->findSlot($slotId)['capacity'], '定員は変わらない');
    });

    test('代理予約を登録でき、同行者は読点で分割される', function (): void {
        $app = adminApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app));
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/admin/slots/' . $slotId . '/bookings', [
            'csrf_token' => $csrf,
            'representative_name' => '電話 太郎',
            'phone' => '0489361126',
            'party_size' => '3',
            'companion_names_text' => '山田花子、佐藤次郎',
        ]);

        assertContains('msg=booking_created', $response->headers['Location']);
        $bookings = $app->bookings->listBySlot($slotId, null);
        assertSame(1, count($bookings));
        assertSame('admin', $bookings[0]['source']);
        assertSame(
            ['山田花子', '佐藤次郎'],
            json_decode((string) $bookings[0]['companion_names_json'], true)
        );
    });

    test('受付人数を −/＋/全員 で操作できる', function (): void {
        $app = adminApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app));
        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => null,
            'source' => 'admin',
            'representative_name' => '予約者',
            'phone' => '0489361126',
            'party_size' => 3,
            'companion_names' => ['a', 'b'],
            'agreed' => true,
        ]);
        $bookingId = $booking['booking_id'];
        $csrf = $app->session->csrfToken();

        $post = static fn (string $op) => request($app, 'POST', '/admin/bookings/' . $bookingId . '/checkin', [
            'csrf_token' => $csrf,
            'slot_id' => (string) $slotId,
            'op' => $op,
        ]);

        $post('inc');
        assertSame(1, (int) $app->bookings->find($bookingId)['checked_in_count']);

        $post('dec');
        $post('dec');
        assertSame(0, (int) $app->bookings->find($bookingId)['checked_in_count'], '0未満にはならない');

        $post('all');
        assertSame(3, (int) $app->bookings->find($bookingId)['checked_in_count']);

        $post('inc');
        assertSame(3, (int) $app->bookings->find($bookingId)['checked_in_count'], '人数を超えない');

        $back = $post('inc');
        assertSame('/admin/slots/' . $slotId . '?msg=saved', $back->headers['Location']);
    });

    test('管理者は予約をキャンセルでき、残席が戻る', function (): void {
        $app = adminApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app), ['capacity' => 10]);
        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => null,
            'source' => 'admin',
            'representative_name' => '予約者',
            'phone' => '0489361126',
            'party_size' => 2,
            'companion_names' => ['a'],
            'agreed' => true,
        ]);
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/admin/bookings/' . $booking['booking_id'] . '/cancel', [
            'csrf_token' => $csrf,
            'slot_id' => (string) $slotId,
        ]);

        assertContains('msg=cancelled', $response->headers['Location']);
        assertSame(0, (int) $app->slots->findSlot($slotId)['booked_seats']);
    });

    test('名簿は氏名・電話番号・予約IDで検索できる', function (): void {
        $app = adminApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app));
        foreach ([['山田太郎', '090-1111-1111'], ['佐藤花子', '080-2222-2222']] as [$name, $phone]) {
            $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => null,
                'source' => 'admin',
                'representative_name' => $name,
                'phone' => $phone,
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ]);
        }

        assertSame(1, count($app->bookings->listBySlot($slotId, '山田')));
        assertSame(1, count($app->bookings->listBySlot($slotId, '080-2222')));
        assertSame(2, count($app->bookings->listBySlot($slotId, null)));
    });

    test('旧URL /admin/trips/{slug} は新しい枠ページへ転送する', function (): void {
        $app = adminApp();
        $pageId = Fixtures::page($app);
        $slotId = Fixtures::slot($app, $pageId, ['name' => '行き']);

        $response = request($app, 'GET', '/admin/trips/ikebukuro-20260821-outbound');
        assertSame(303, $response->status);
        assertSame('/admin/slots/' . $slotId, $response->headers['Location']);
    });
});

describe('管理画面の状態表示', function (): void {
    /** ダッシュボードのバッジは公開側と同じ判定を使う。 */
    $badgeFor = static function (array $overrides): string {
        $app = adminApp();
        $pageId = Fixtures::page($app);
        Fixtures::slot($app, $pageId, $overrides);
        return request($app, 'GET', '/admin')->body;
    };

    test('受付中の枠は「受付中」と表示される', function () use ($badgeFor): void {
        assertContains('badge-open">受付中', $badgeFor(['booking_status' => 'open']));
    });

    test('受付開始前の枠は「受付開始前」と表示される', function () use ($badgeFor): void {
        $body = $badgeFor(['booking_open_at' => '2099-12-31 00:00:00']);
        assertContains('受付開始前', $body);
    });

    test('受付停止中の枠は「受付停止中」と表示される', function () use ($badgeFor): void {
        assertContains('受付停止中', $badgeFor(['booking_status' => 'closed']));
    });

    test('満席の枠は「満席」と表示される', function (): void {
        $app = adminApp();
        $pageId = Fixtures::page($app);
        $slotId = Fixtures::slot($app, $pageId, ['capacity' => 1]);
        $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => null,
            'source' => 'admin',
            'representative_name' => '先客',
            'phone' => '0489361126',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);

        assertContains('badge-full">満席', request($app, 'GET', '/admin')->body);
    });

    test('締切を過ぎた枠は「受付終了」と表示される', function () use ($badgeFor): void {
        assertContains('受付終了', $badgeFor(['booking_close_at' => '2026-01-01 00:00:00']));
    });
});
