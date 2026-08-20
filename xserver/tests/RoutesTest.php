<?php

declare(strict_types=1);

/**
 * ルーティングと画面。
 * URL・POST先・name属性が Workers 版と一致していることを確認する。
 */

use App\Auth\Session;

describe('公開ルート', function (): void {
    test('/ が公開中の予約ページを一覧する', function (): void {
        resetRequestState();
        $app = makeApp();
        $pageId = Fixtures::page($app);
        Fixtures::slot($app, $pageId);
        Fixtures::page($app, ['slug' => 'draft-page', 'title' => '下書きページ', 'status' => 'draft']);

        $response = request($app, 'GET', '/');

        assertSame(200, $response->status);
        assertContains('らっこ号 池袋便', $response->body);
        assertNotContains('下書きページ', $response->body, '下書きは公開トップに出さない');
        assertContains('/reserve/rakko-ikebukuro', $response->body);
    });

    test('/healthz が ok を返す', function (): void {
        resetRequestState();
        $app = makeApp();
        $response = request($app, 'GET', '/healthz');
        assertSame(200, $response->status);
        assertContains('"ok":true', $response->body);
    });

    test('存在しないURLは404', function (): void {
        resetRequestState();
        $app = makeApp();
        $response = request($app, 'GET', '/no-such-page');
        assertSame(404, $response->status);
        assertContains('ページが見つかりません', $response->body);
    });

    test('/reserve/{slug} が予約フォームを表示する', function (): void {
        resetRequestState();
        $app = makeApp();
        $pageId = Fixtures::page($app, ['requires_line_login' => false]);
        $slotId = Fixtures::slot($app, $pageId);

        $response = request($app, 'GET', '/reserve/rakko-ikebukuro');

        assertSame(200, $response->status);
        assertContains('action="/reserve/rakko-ikebukuro/book"', $response->body, 'POST先が同じ');
        assertContains('name="slot_selected" value="' . $slotId . '"', $response->body);
        assertContains('name="party_size_' . $slotId . '"', $response->body);
        assertContains('name="companion_' . $slotId . '[]"', $response->body);
        assertContains('name="representative_name"', $response->body);
        assertContains('name="phone"', $response->body);
        assertContains('name="agreed"', $response->body);
        assertContains('name="csrf_token"', $response->body);
    });

    test('下書きページは未ログインには404', function (): void {
        resetRequestState();
        $app = makeApp();
        Fixtures::page($app, ['slug' => 'secret', 'title' => '非公開', 'status' => 'draft']);

        $response = request($app, 'GET', '/reserve/secret');
        assertSame(404, $response->status);
    });

    test('max_party_size=1 の枠では人数選択UIを出さず hidden で1を送る', function (): void {
        resetRequestState();
        $app = makeApp();
        $pageId = Fixtures::page($app, ['requires_line_login' => false]);
        $slotId = Fixtures::slot($app, $pageId, ['max_party_size' => 1]);

        $response = request($app, 'GET', '/reserve/rakko-ikebukuro');

        assertContains(
            '<input type="hidden" name="party_size_' . $slotId . '" value="1">',
            $response->body
        );
        assertNotContains('type="radio" id="party_size_' . $slotId . '_2"', $response->body);
    });

    test('旧URL /trips/{slug}/book はログインへ誘導する', function (): void {
        resetRequestState();
        $app = makeApp();
        $response = request($app, 'GET', '/trips/ikebukuro-20260821-outbound/book');
        assertSame(303, $response->status);
        assertContains('/login', $response->headers['Location']);
    });
});

describe('予約POST', function (): void {
    test('CSRFトークンが無いPOSTは拒否される', function (): void {
        resetRequestState();
        $app = makeApp();
        $pageId = Fixtures::page($app, ['requires_line_login' => false]);
        $slotId = Fixtures::slot($app, $pageId);

        $response = request($app, 'POST', '/reserve/rakko-ikebukuro/book', [
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => '1',
            'party_size_' . $slotId => '1',
        ], [], ['slot_selected' => [(string) $slotId]]);

        assertSame(303, $response->status);
        assertContains('msg=csrf', $response->headers['Location']);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId), '予約は作られないこと');
    });

    test('ログイン必須ページに未ログインでPOSTするとログインへ誘導される', function (): void {
        resetRequestState();
        $app = makeApp();
        $pageId = Fixtures::page($app);
        $slotId = Fixtures::slot($app, $pageId);

        $response = request($app, 'POST', '/reserve/rakko-ikebukuro/book', [
            'csrf_token' => 'x',
        ], [], ['slot_selected' => [(string) $slotId]]);

        assertSame(303, $response->status);
        assertContains('/login', $response->headers['Location']);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId));
    });

    test('複数枠のチェックボックスがすべてPOSTとして解釈される', function (): void {
        resetRequestState();
        $app = makeApp();
        $pageId = Fixtures::page($app, ['requires_line_login' => false]);
        $a = Fixtures::slot($app, $pageId, ['name' => '行き']);
        $b = Fixtures::slot($app, $pageId, [
            'name' => '帰り',
            'start_at' => '2099-08-21 23:10:00',
            'sort_order' => 2,
        ]);

        $csrf = $app->session->csrfToken();
        $response = request($app, 'POST', '/reserve/rakko-ikebukuro/book', [
            'csrf_token' => $csrf,
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => '1',
            'party_size_' . $a => '2',
            'party_size_' . $b => '3',
            'companion_' . $a => ['花子'],
            'companion_' . $b => ['花子', '次郎'],
        ], [], ['slot_selected' => [(string) $a, (string) $b]]);

        assertSame(303, $response->status, '成功時は予約詳細へリダイレクトする');
        assertContains('/bookings/', $response->headers['Location']);
        assertContains('completed=1', $response->headers['Location']);
        assertSame(2, $app->slots->sumConfirmedSeats($a));
        assertSame(3, $app->slots->sumConfirmedSeats($b));
    });

    test('満席のときは400で選択内容を保持したまま再表示する', function (): void {
        resetRequestState();
        $app = makeApp();
        $pageId = Fixtures::page($app, ['requires_line_login' => false]);
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

        $csrf = $app->session->csrfToken();
        $response = request($app, 'POST', '/reserve/rakko-ikebukuro/book', [
            'csrf_token' => $csrf,
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => '1',
            'party_size_' . $slotId => '1',
        ], [], ['slot_selected' => [(string) $slotId]]);

        assertSame(400, $response->status);
        assertContains('満席', $response->body);
        assertContains('山田太郎', $response->body, '入力値が保持されること');
    });
});

describe('マイ予約・予約詳細', function (): void {
    test('未ログインではログインへ誘導される', function (): void {
        resetRequestState();
        $app = makeApp();
        $response = request($app, 'GET', '/my-bookings');
        assertSame(303, $response->status);
        assertContains('/login', $response->headers['Location']);
        assertContains('msg=login_required', $response->headers['Location']);
    });

    test('ログイン中は自分の予約だけが並ぶ', function (): void {
        resetRequestState();
        $app = makeApp();
        $pageId = Fixtures::page($app);
        $slotId = Fixtures::slot($app, $pageId);
        $mine = Fixtures::user($app, 'U-mine', '自分');
        $other = Fixtures::user($app, 'U-other', '他人');

        $mineBooking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => $mine,
            'source' => 'line',
            'representative_name' => '自分 太郎',
            'phone' => '090-0000-0001',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);
        $otherBooking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => $other,
            'source' => 'line',
            'representative_name' => '他人 次郎',
            'phone' => '090-0000-0002',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);

        $app->session->startUserSession($mine);
        $response = request($app, 'GET', '/my-bookings');

        assertSame(200, $response->status);
        assertContains('予約ID #' . $mineBooking['booking_id'], $response->body);
        assertNotContains('予約ID #' . $otherBooking['booking_id'], $response->body, '他人の予約は見せない');
        assertContains('自分さん', $response->body, 'ヘッダーに自分の名前が出ること');
    });

    test('他人の予約詳細は404', function (): void {
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
        assertSame(404, $response->status);
    });

    test('キャンセルは2段階（詳細ページから確認して実行）', function (): void {
        resetRequestState();
        $app = makeApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app));
        $userId = Fixtures::user($app);

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
        $bookingId = $booking['booking_id'];

        $app->session->startUserSession($userId);

        $detail = request($app, 'GET', '/bookings/' . $bookingId);
        assertSame(200, $detail->status);
        assertContains('action="/bookings/' . $bookingId . '/cancel"', $detail->body);

        $csrf = $app->session->csrfToken();
        $cancel = request($app, 'POST', '/bookings/' . $bookingId . '/cancel', [
            'csrf_token' => $csrf,
        ]);

        assertSame(303, $cancel->status);
        assertContains('msg=cancelled', $cancel->headers['Location']);
        assertSame(0, $app->slots->sumConfirmedSeats($slotId));
    });
});

describe('セッションとCSRF', function (): void {
    test('署名付きCookieは改ざんを検出する', function (): void {
        resetRequestState();
        $app = makeApp();
        $session = $app->session;

        $token = $session->sign(['uid' => 1]);
        assertNotNull($session->verify($token));
        assertNull($session->verify($token . 'x'), '署名が壊れていれば拒否する');
        assertNull($session->verify('not-a-token'));
    });

    test('別のSESSION_SECRETで署名されたCookieは通らない', function (): void {
        resetRequestState();
        $a = makeApp();
        $b = makeApp(['SESSION_SECRET' => str_repeat('z', 48)]);

        $token = $a->session->sign(['uid' => 42]);
        assertNotNull($a->session->verify($token));
        assertNull($b->session->verify($token));
    });

    test('CSRFトークンは同一リクエスト内で発行・検証できる', function (): void {
        resetRequestState();
        $app = makeApp();
        $token = $app->session->csrfToken();

        assertTrue($app->session->verifyCsrf($token));
        assertFalse($app->session->verifyCsrf('wrong-token'));
        assertFalse($app->session->verifyCsrf(null));
        assertFalse($app->session->verifyCsrf(''));
    });

    test('期限内のセッションCookieは有効', function (): void {
        resetRequestState();
        $app = makeApp();
        $userId = Fixtures::user($app);

        $app->session->startUserSession($userId);
        assertSame($userId, $app->session->userId());

        // 発行から29日後でもまだ有効
        $_COOKIE[App\Auth\Session::USER_COOKIE] = $app->session->sign([
            'uid' => $userId,
            'iat' => time() - (App\Auth\Session::USER_MAX_AGE - 86400),
        ]);
        assertSame($userId, $app->session->userId());
    });

    test('USER_MAX_AGE（30日）を過ぎた署名済みCookieは無効', function (): void {
        resetRequestState();
        $app = makeApp();
        $userId = Fixtures::user($app);

        // 署名は正しいが発行から30日を超えている
        $_COOKIE[App\Auth\Session::USER_COOKIE] = $app->session->sign([
            'uid' => $userId,
            'iat' => time() - (App\Auth\Session::USER_MAX_AGE + 60),
        ]);

        assertNull($app->session->userId(), '期限切れは無効扱いにすること');
    });

    test('iat の無い署名済みCookieは無効', function (): void {
        resetRequestState();
        $app = makeApp();

        // 署名は正しいが発行時刻が入っていない（期限を判定できない）
        $_COOKIE[App\Auth\Session::USER_COOKIE] = $app->session->sign(['uid' => 1]);
        assertNull($app->session->userId());

        $_COOKIE[App\Auth\Session::USER_COOKIE] = $app->session->sign(['uid' => 1, 'iat' => 'いつか']);
        assertNull($app->session->userId(), 'iat が数値でなければ無効');
    });

    test('期限切れセッションではマイ予約がログインへ誘導される', function (): void {
        resetRequestState();
        $app = makeApp();
        $userId = Fixtures::user($app);
        $_COOKIE[App\Auth\Session::USER_COOKIE] = $app->session->sign([
            'uid' => $userId,
            'iat' => time() - (App\Auth\Session::USER_MAX_AGE + 60),
        ]);

        $response = request($app, 'GET', '/my-bookings');
        assertSame(303, $response->status);
        assertContains('/login', $response->headers['Location']);
    });

    test('管理者セッションは8時間で期限切れになる', function (): void {
        resetRequestState();
        $app = makeApp();

        $app->session->startAdminSession('admin');
        assertSame('admin', $app->session->adminUser());

        $_COOKIE[App\Auth\Session::ADMIN_COOKIE] = $app->session->sign([
            'admin' => 'admin',
            'iat' => time() - (App\Auth\Session::ADMIN_MAX_AGE + 60),
        ]);
        assertNull($app->session->adminUser());
    });

    test('オープンリダイレクトは弾かれる', function (): void {
        assertSame('/', Session::safeRedirectPath('https://evil.example.com'));
        assertSame('/', Session::safeRedirectPath('//evil.example.com'));
        assertSame('/', Session::safeRedirectPath(null));
        assertSame('/reserve/rakko-ikebukuro', Session::safeRedirectPath('/reserve/rakko-ikebukuro'));
    });

    test('PKCE の code_challenge が S256 で作られる', function (): void {
        $verifier = Session::createCodeVerifier();
        $challenge = Session::createCodeChallenge($verifier);

        assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $challenge
        );
        assertNotContains('=', $challenge, 'base64urlはパディングを含まない');
    });
});
