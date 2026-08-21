<?php

declare(strict_types=1);

use App\Views\Layout;

describe('イベント別注意事項', function (): void {
    test('0005 migrationでreservation_pages.notice_textが追加される', function (): void {
        $app = makeApp();
        $column = $app->db->first("SHOW COLUMNS FROM reservation_pages LIKE 'notice_text'");
        assertNotNull($column);
        assertSame('notice_text', $column['Field']);
    });

    test('管理画面で注意事項を保存して再編集できる', function (): void {
        $app = adminApp();
        $csrf = $app->session->csrfToken();
        $notice = "受付10分前までにお越しください。\n飲食物の持ち込みはできません。";

        $response = request($app, 'POST', '/admin/reservations', [
            'csrf_token' => $csrf,
            'title' => 'テストイベント',
            'slug' => 'event-notice-test',
            'description' => '説明',
            'notice_text' => $notice,
            'status' => 'published',
            'page_type' => 'event',
            'max_slots_per_checkout' => '1',
            'checkin_label' => '受付',
        ]);

        assertSame(303, $response->status);
        $page = $app->slots->findPageBySlug('event-notice-test');
        assertNotNull($page);
        assertSame($notice, $page['notice_text']);

        $edit = request($app, 'GET', '/admin/reservations/' . (int) $page['id']);
        assertSame(200, $edit->status);
        assertContains('name="notice_text"', $edit->body);
        assertContains('受付10分前までにお越しください。', $edit->body);
    });

    test('公開予約ページはイベント固有の注意事項を1行1項目で表示する', function (): void {
        $app = makeApp();
        $pageId = Fixtures::page($app, [
            'slug' => 'custom-notice',
            'page_type' => 'event',
            'requires_line_login' => false,
            'notice_text' => "集合は5分前です。\nタオルをご持参ください。",
        ]);
        Fixtures::slot($app, $pageId, ['origin' => null, 'destination' => null, 'location' => 'イベント会場']);

        $response = request($app, 'GET', '/reserve/custom-notice');
        assertSame(200, $response->status);
        assertContains('<li>集合は5分前です。</li>', $response->body);
        assertContains('<li>タオルをご持参ください。</li>', $response->body);
        assertNotContains('交通状況により到着時刻が前後する場合があります。', $response->body);
    });

    test('注意事項はHTMLとして解釈せずescapeする（公開ページ経由）', function (): void {
        $app = makeApp();
        $pageId = Fixtures::page($app, [
            'slug' => 'xss-notice',
            'requires_line_login' => false,
            'notice_text' => "<script>alert('xss')</script>\n"
                . '<img src=x onerror="alert(1)">' . "\n"
                . '<b>太字にはしない</b>',
        ]);
        Fixtures::slot($app, $pageId);

        $response = request($app, 'GET', '/reserve/xss-notice');
        assertSame(200, $response->status);

        // 生タグとして出力されないこと
        // （エスケープ後の本文には「onerror=」という文字列自体は残るので、
        //   タグとして成立していないことを見る）
        assertNotContains('<script>alert', $response->body);
        assertNotContains('<img', $response->body);
        assertNotContains('<b>太字にはしない</b>', $response->body);

        // エスケープ済みの文字列として見えること
        assertContains('&lt;script&gt;', $response->body);
        assertContains('&lt;img src=x onerror=', $response->body);
        assertContains('&lt;b&gt;太字にはしない&lt;/b&gt;', $response->body);
    });

    test('注意事項の各行は<li>1項目として出力される', function (): void {
        $app = makeApp();
        $pageId = Fixtures::page($app, [
            'slug' => 'multiline-notice',
            'requires_line_login' => false,
            // 空行・前後の空白は無視する
            'notice_text' => "  1行目  \n\n2行目\n\n\n  3行目",
        ]);
        Fixtures::slot($app, $pageId);

        $body = request($app, 'GET', '/reserve/multiline-notice')->body;

        assertContains('<li>1行目</li>', $body);
        assertContains('<li>2行目</li>', $body);
        assertContains('<li>3行目</li>', $body);
        assertNotContains('<li></li>', $body, '空行は項目にしない');
    });

    test('注意事項が空欄なら従来の共通注意事項へfallbackする（公開ページ経由）', function (): void {
        $app = makeApp();

        // NULL のケース
        $nullPage = Fixtures::page($app, [
            'slug' => 'notice-null',
            'requires_line_login' => false,
            'notice_text' => null,
        ]);
        Fixtures::slot($app, $nullPage);

        // 空白のみのケース
        $blankPage = Fixtures::page($app, [
            'slug' => 'notice-blank',
            'requires_line_login' => false,
            'notice_text' => " \n ",
        ]);
        Fixtures::slot($app, $blankPage);

        foreach (['notice-null', 'notice-blank'] as $slug) {
            $body = request($app, 'GET', '/reserve/' . $slug)->body;
            assertContains('ご利用にあたっての注意事項', $body, $slug);
            assertContains('キャンセルは「マイ予約」からお願いします。', $body, $slug . ' は共通文言へfallback');
        }
    });

    test('注意事項の単体挙動（改行分割とfallback）', function (): void {
        $html = Layout::noticeCard("A\nB");
        assertContains('<li>A</li>', $html);
        assertContains('<li>B</li>', $html);

        assertContains('キャンセルは「マイ予約」からお願いします。', Layout::noticeCard(null));
        assertContains('キャンセルは「マイ予約」からお願いします。', Layout::noticeCard(' '));
    });

    test('予約ページ複製でも注意事項を引き継ぐ', function (): void {
        $app = adminApp();
        $pageId = Fixtures::page($app, [
            'slug' => 'notice-copy-source',
            'notice_text' => "イベント専用注意1\nイベント専用注意2",
        ]);
        Fixtures::slot($app, $pageId);
        $csrf = $app->session->csrfToken();

        $response = request($app, 'POST', '/admin/reservations/' . $pageId . '/duplicate', [
            'csrf_token' => $csrf,
        ]);
        assertSame(303, $response->status);

        $copy = $app->slots->findPageBySlug('notice-copy-source-copy');
        assertNotNull($copy);
        assertSame("イベント専用注意1\nイベント専用注意2", $copy['notice_text']);
    });
});
