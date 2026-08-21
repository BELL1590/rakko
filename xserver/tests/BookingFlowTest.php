<?php

declare(strict_types=1);

/**
 * 公開予約導線のE2E相当テスト。
 *
 * 「画面が200を返す」ではなく、
 * 公開ページ → LINEログイン導線 → 予約入力 → POST → 予約確定 → 完了画面
 * までを実際にルーター経由で通し、DBの状態まで確認する。
 *
 * LINE Platform への実通信は行わない（実credentialが無いため）。
 * 認可URLの生成とアプリ側の導線までを検証対象とする。
 */

use App\Auth\Session;
use App\Support\Uuid;

/** 予約可能な公開ページと枠を1つ用意する。 */
function flowFixture(App\App $app, array $pageOverrides = [], array $slotOverrides = []): array
{
    $pageId = Fixtures::page($app, array_merge([
        'slug' => 'flow-event',
        'title' => 'フロー検証イベント',
        'status' => 'published',
    ], $pageOverrides));

    $slotId = Fixtures::slot($app, $pageId, array_merge([
        'name' => '行き',
        'capacity' => 10,
        'max_party_size' => 4,
    ], $slotOverrides));

    return [$pageId, $slotId];
}

/**
 * 予約フォームのPOST本文を組み立てる。
 * 実際のフォームが送る name 属性と同じ形にする。
 */
function bookingPost(string $csrf, int $slotId, array $overrides = []): array
{
    return array_merge([
        'csrf_token' => $csrf,
        'representative_name' => '山田太郎',
        'phone' => '090-1234-5678',
        'agreed' => '1',
        'party_size_' . $slotId => '2',
        'companion_' . $slotId => ['山田花子'],
    ], $overrides);
}

describe('公開予約導線（E2E相当）', function (): void {
    test('1. 公開予約ページがGETできる', function (): void {
        resetRequestState();
        $app = makeApp();
        [, $slotId] = flowFixture($app);

        $response = request($app, 'GET', '/reserve/flow-event');

        assertSame(200, $response->status);
        assertContains('フロー検証イベント', $response->body);
        assertContains('行き', $response->body);
    });

    test('2. 未ログインならLINEログイン導線が表示される', function (): void {
        resetRequestState();
        $app = makeApp();
        flowFixture($app);

        $response = request($app, 'GET', '/reserve/flow-event');

        assertSame(200, $response->status);
        assertContains('ご予約にはLINEログインが必要です。', $response->body);
        assertContains('LINEでログインして予約する', $response->body);
        assertContains('/login?redirect_to=', $response->body);
        // 未ログインには予約フォームを出さない
        assertNotContains('id="reserve-form"', $response->body);
    });

    test('3. /login がLINEログインボタンを出し、認可URLを生成できる', function (): void {
        resetRequestState();
        $app = makeApp();

        $login = request($app, 'GET', '/login', [], ['redirect_to' => '/reserve/flow-event']);
        assertSame(200, $login->status);
        assertContains('action="/auth/line/start"', $login->body);
        assertContains('name="redirect_to" value="/reserve/flow-event"', $login->body);

        // POST /auth/line/start → LINE認可へ302
        $csrf = $app->session->csrfToken();
        $start = request($app, 'POST', '/auth/line/start', [
            'csrf_token' => $csrf,
            'redirect_to' => '/reserve/flow-event',
        ]);

        assertSame(302, $start->status);
        $authorizeUrl = $start->headers['Location'];
        assertContains('https://access.line.me/oauth2/v2.1/authorize', $authorizeUrl);
        assertContains('response_type=code', $authorizeUrl);
        assertContains('code_challenge_method=S256', $authorizeUrl);
        assertContains('scope=openid%20profile', $authorizeUrl);
        assertContains('%2Fauth%2Fline%2Fcallback', $authorizeUrl);
        assertNotContains('bot_prompt', $authorizeUrl, '任意パラメータは既定で付けない');
    });

    test('4. ログイン済みなら予約フォームが表示される', function (): void {
        resetRequestState();
        $app = makeApp();
        [, $slotId] = flowFixture($app);
        $app->session->startUserSession(Fixtures::user($app, 'U-flow', '予約 太郎'));

        $response = request($app, 'GET', '/reserve/flow-event');

        assertSame(200, $response->status);
        assertContains('id="reserve-form"', $response->body);
        assertContains('action="/reserve/flow-event/book"', $response->body);
        // 5〜10: 入力に必要なフィールドが揃っている
        assertContains('name="slot_selected" value="' . $slotId . '"', $response->body);
        assertContains('name="party_size_' . $slotId . '"', $response->body);
        assertContains('name="companion_' . $slotId . '[]"', $response->body);
        assertContains('name="representative_name"', $response->body);
        assertContains('name="phone"', $response->body);
        assertContains('name="agreed"', $response->body);
        assertContains('name="csrf_token"', $response->body);
    });

    test('注意事項と同意チェックが同意より前に並ぶ', function (): void {
        resetRequestState();
        $app = makeApp();
        flowFixture($app, ['notice_text' => "会場は2階です。\n上履きをご持参ください。"]);
        $app->session->startUserSession(Fixtures::user($app, 'U-flow'));

        $body = request($app, 'GET', '/reserve/flow-event')->body;

        $noticePos = strpos($body, '<li>会場は2階です。</li>');
        $agreePos = strpos($body, 'id="agreed"');
        assertTrue($noticePos !== false, '注意事項が表示されること');
        assertTrue($agreePos !== false, '同意チェックがあること');
        assertTrue($noticePos < $agreePos, '注意事項は同意チェックより前に出すこと');
        assertContains('上記の注意事項を確認し、内容に同意します', $body);
    });

    test('11〜14. 予約POSTでbookingが確定し、完了画面へリダイレクトする', function (): void {
        resetRequestState();
        $app = makeApp();
        [, $slotId] = flowFixture($app);
        $userId = Fixtures::user($app, 'U-flow', '予約 太郎');
        $app->session->startUserSession($userId);

        $before = $app->slots->findSlot($slotId);
        assertSame(0, (int) $before['reserved_seats']);

        $csrf = $app->session->csrfToken();
        $response = request(
            $app,
            'POST',
            '/reserve/flow-event/book',
            bookingPost($csrf, $slotId),
            [],
            ['slot_selected' => [(string) $slotId]]
        );

        // 14. 完了ページへリダイレクト
        assertSame(303, $response->status);
        $location = $response->headers['Location'];
        assertContains('/bookings/', $location);
        assertContains('completed=1', $location);

        // 12. bookingがconfirmedで作られている
        $bookings = $app->bookings->listBySlot($slotId, null);
        assertSame(1, count($bookings));
        $booking = $bookings[0];
        assertSame('confirmed', $booking['status']);
        assertSame('山田太郎', $booking['representative_name']);
        assertSame('090-1234-5678', $booking['phone']);
        assertSame(2, (int) $booking['party_size']);
        assertSame($userId, (int) $booking['user_id']);
        assertSame('line', $booking['source']);
        assertSame(['山田花子'], json_decode((string) $booking['companion_names_json'], true));

        // 13. reserved_seats が人数分増える
        $after = $app->slots->findSlot($slotId);
        assertSame(2, (int) $after['reserved_seats']);
        assertSame(2, (int) $after['booked_seats']);
        assertSame(8, (int) $after['remaining_seats']);

        // 完了画面が実際に開ける
        $detail = request($app, 'GET', $location === null ? '' : parse_url($location, PHP_URL_PATH), [], ['completed' => '1']);
        assertSame(200, $detail->status);
        assertContains('ご予約が完了しました', $detail->body);
        assertContains('山田太郎', $detail->body);
    });

    test('同意チェックを外すと予約は確定しない', function (): void {
        resetRequestState();
        $app = makeApp();
        [, $slotId] = flowFixture($app);
        $app->session->startUserSession(Fixtures::user($app, 'U-flow'));

        $csrf = $app->session->csrfToken();
        $post = bookingPost($csrf, $slotId);
        unset($post['agreed']);

        $response = request($app, 'POST', '/reserve/flow-event/book', $post, [], [
            'slot_selected' => [(string) $slotId],
        ]);

        assertSame(400, $response->status);
        assertContains('同意', $response->body);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId));
    });

    test('15. 同じユーザーの同じ枠への二重予約は拒否される', function (): void {
        resetRequestState();
        $app = makeApp();
        [, $slotId] = flowFixture($app);
        $app->session->startUserSession(Fixtures::user($app, 'U-flow'));

        $csrf = $app->session->csrfToken();
        $first = request($app, 'POST', '/reserve/flow-event/book', bookingPost($csrf, $slotId), [], [
            'slot_selected' => [(string) $slotId],
        ]);
        assertSame(303, $first->status);

        $second = request($app, 'POST', '/reserve/flow-event/book', bookingPost($csrf, $slotId), [], [
            'slot_selected' => [(string) $slotId],
        ]);

        assertSame(400, $second->status);
        assertSame(2, $app->slots->sumConfirmedSeats($slotId), '2件目は席を消費しない');
    });

    test('16. 定員を超える予約は拒否される', function (): void {
        resetRequestState();
        $app = makeApp();
        [, $slotId] = flowFixture($app, [], ['capacity' => 3]);

        // 先客が2席使う
        $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => null,
            'source' => 'admin',
            'representative_name' => '先客',
            'phone' => '0489361126',
            'party_size' => 2,
            'companion_names' => ['同行者'],
            'agreed' => true,
        ]);

        $app->session->startUserSession(Fixtures::user($app, 'U-flow'));
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/reserve/flow-event/book', bookingPost($csrf, $slotId), [], [
            'slot_selected' => [(string) $slotId],
        ]);

        assertSame(400, $response->status);
        assertContains('満席', $response->body);
        assertSame(2, $app->slots->sumConfirmedSeats($slotId), '定員を超えないこと');
        assertSame(2, (int) $app->slots->findSlot($slotId)['reserved_seats']);
    });

    test('17. キャンセルすると席数が戻り、再予約できる', function (): void {
        resetRequestState();
        $app = makeApp();
        [, $slotId] = flowFixture($app);
        $userId = Fixtures::user($app, 'U-flow');
        $app->session->startUserSession($userId);

        $csrf = $app->session->csrfToken();
        $booked = request($app, 'POST', '/reserve/flow-event/book', bookingPost($csrf, $slotId), [], [
            'slot_selected' => [(string) $slotId],
        ]);
        assertSame(303, $booked->status);
        assertSame(2, (int) $app->slots->findSlot($slotId)['reserved_seats']);

        $bookingId = (int) $app->bookings->listBySlot($slotId, null)[0]['id'];

        // 詳細画面からキャンセル
        $detail = request($app, 'GET', '/bookings/' . $bookingId);
        assertSame(200, $detail->status);
        assertContains('action="/bookings/' . $bookingId . '/cancel"', $detail->body);

        $cancel = request($app, 'POST', '/bookings/' . $bookingId . '/cancel', [
            'csrf_token' => $csrf,
        ]);
        assertSame(303, $cancel->status);
        assertContains('msg=cancelled', $cancel->headers['Location']);

        // 席が戻る
        assertSame(0, (int) $app->slots->findSlot($slotId)['reserved_seats']);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId));

        // 再予約できる
        $again = request($app, 'POST', '/reserve/flow-event/book', bookingPost($csrf, $slotId), [], [
            'slot_selected' => [(string) $slotId],
        ]);
        assertSame(303, $again->status);
        assertSame(2, $app->slots->sumConfirmedSeats($slotId));
    });

    test('予約完了後にマイ予約へ反映される', function (): void {
        resetRequestState();
        $app = makeApp();
        [, $slotId] = flowFixture($app);
        $app->session->startUserSession(Fixtures::user($app, 'U-flow', '予約 太郎'));

        $csrf = $app->session->csrfToken();
        request($app, 'POST', '/reserve/flow-event/book', bookingPost($csrf, $slotId), [], [
            'slot_selected' => [(string) $slotId],
        ]);

        $mine = request($app, 'GET', '/my-bookings');
        assertSame(200, $mine->status);
        $bookingId = (int) $app->bookings->listBySlot($slotId, null)[0]['id'];
        assertContains('予約ID #' . $bookingId, $mine->body);
    });

    test('複数枠をまとめて予約できる', function (): void {
        resetRequestState();
        $app = makeApp();
        [$pageId, $outbound] = flowFixture($app);
        $return = Fixtures::slot($app, $pageId, [
            'name' => '帰り',
            'start_at' => '2099-08-21 23:10:00',
            'sort_order' => 2,
        ]);
        $app->session->startUserSession(Fixtures::user($app, 'U-flow'));

        $csrf = $app->session->csrfToken();
        $response = request($app, 'POST', '/reserve/flow-event/book', [
            'csrf_token' => $csrf,
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => '1',
            'party_size_' . $outbound => '2',
            'party_size_' . $return => '3',
            'companion_' . $outbound => ['花子'],
            'companion_' . $return => ['花子', '次郎'],
        ], [], ['slot_selected' => [(string) $outbound, (string) $return]]);

        assertSame(303, $response->status);
        assertSame(2, $app->slots->sumConfirmedSeats($outbound));
        assertSame(3, $app->slots->sumConfirmedSeats($return));

        $bookings = $app->bookings->listBySlot($outbound, null);
        assertNotNull($bookings[0]['booking_group_id'], 'まとめ予約はグループIDを持つ');
    });

    test('CSRFトークンが無ければ予約は作られない', function (): void {
        resetRequestState();
        $app = makeApp();
        [, $slotId] = flowFixture($app);
        $app->session->startUserSession(Fixtures::user($app, 'U-flow'));

        $post = bookingPost('', $slotId);
        unset($post['csrf_token']);

        $response = request($app, 'POST', '/reserve/flow-event/book', $post, [], [
            'slot_selected' => [(string) $slotId],
        ]);

        assertSame(303, $response->status);
        assertContains('msg=csrf', $response->headers['Location']);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId));
    });
});
