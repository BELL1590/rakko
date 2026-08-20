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

    test('注意事項はHTMLとして解釈せずescapeする', function (): void {
        $html = Layout::noticeCard('<script>alert("xss")</script>');
        assertContains('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
        assertNotContains('<script>', $html);
    });

    test('注意事項が空欄なら従来の共通注意事項へfallbackする', function (): void {
        $null = Layout::noticeCard(null);
        $blank = Layout::noticeCard(" \n ");

        assertContains('開始時刻の15分前までに集合場所へお越しください。', $null);
        assertContains('交通状況により到着時刻が前後する場合があります。', $blank);
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
