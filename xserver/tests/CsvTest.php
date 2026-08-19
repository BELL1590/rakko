<?php

declare(strict_types=1);

/** 名簿CSV: 列構成・BOM・日本語・数式インジェクション無害化。 */

use App\Services\CsvService;

describe('名簿CSV', function (): void {
    test('UTF-8 BOM 付きで出力される（Excelの文字化け防止）', function (): void {
        $csv = CsvService::buildRoster([]);
        assertSame("\xEF\xBB\xBF", substr($csv, 0, 3), '先頭がBOMであること');
    });

    test('必要な列が日本語ヘッダーで並ぶ', function (): void {
        $csv = CsvService::buildRoster([]);
        $header = explode("\r\n", substr($csv, 3))[0];

        foreach ([
            '予約番号', '予約ページ名', '予約枠名', '日付', '開始時刻',
            '代表者氏名', '電話番号', '予約人数', '同行者1', '同行者2', '同行者3',
            '受付済人数', '予約状態', '予約元', '予約日時', 'キャンセル日時',
        ] as $column) {
            assertContains($column, $header, '列 ' . $column . ' があること');
        }
    });

    test('同行者の列数は最大人数に合わせて増える', function (): void {
        $csv = CsvService::buildRoster([[
            'id' => 1,
            'page_title' => 'らっこ号 池袋便',
            'slot_name' => '行き',
            'start_at' => '2026-08-21 11:00:00',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 5,
            'companion_names_json' => json_encode(['a', 'b', 'c', 'd'], JSON_UNESCAPED_UNICODE),
            'checked_in_count' => 0,
            'status' => 'confirmed',
            'source' => 'line',
            'created_at' => '2026-08-01 00:00:00',
            'cancelled_at' => null,
        ]]);

        assertContains('同行者4', $csv, '4人目の列が増えること');
    });

    test('日本語・時刻がJSTで出力される', function (): void {
        $csv = CsvService::buildRoster([[
            'id' => 12,
            'page_title' => 'らっこ号 池袋便',
            'slot_name' => '行き',
            // UTC 11:00 = JST 20:00
            'start_at' => '2026-08-21 11:00:00',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 2,
            'companion_names_json' => json_encode(['山田花子'], JSON_UNESCAPED_UNICODE),
            'checked_in_count' => 1,
            'status' => 'confirmed',
            'source' => 'line',
            'created_at' => '2026-08-01 03:00:00',
            'cancelled_at' => null,
        ]]);

        assertContains('らっこ号 池袋便', $csv);
        assertContains('山田花子', $csv);
        assertContains('20:00', $csv, 'JSTの時刻で出ること');
        assertContains('8/21', $csv, '日付列はJSTの月日');
        assertContains('予約済み', $csv);
        assertContains('LINE', $csv);
    });

    test('キャンセル済みは状態とキャンセル日時が入る', function (): void {
        $csv = CsvService::buildRoster([[
            'id' => 13,
            'page_title' => 'ページ',
            'slot_name' => '枠',
            'start_at' => '2026-08-21 11:00:00',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 1,
            'companion_names_json' => '[]',
            'checked_in_count' => 0,
            'status' => 'cancelled',
            'source' => 'admin',
            'created_at' => '2026-08-01 03:00:00',
            'cancelled_at' => '2026-08-02 03:00:00',
        ]]);

        assertContains('キャンセル', $csv);
        assertContains('管理者代理', $csv);
        assertContains('2026-08-02', $csv);
    });

    describe('数式インジェクションの無害化', function (): void {
        test('= + - @ タブ 復帰 で始まるセルは先頭にアポストロフィを付ける', function (): void {
            foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $dangerous) {
                $sanitized = CsvService::sanitizeCell($dangerous);
                assertSame("'" . $dangerous, $sanitized, $dangerous . ' が無害化されること');
            }
        });

        test('通常の値はそのまま', function (): void {
            foreach (['山田太郎', '090-1234-5678', '1', ''] as $safe) {
                assertSame($safe, CsvService::sanitizeCell($safe));
            }
        });

        test('名簿に紛れ込んだ数式も無害化される', function (): void {
            $csv = CsvService::buildRoster([[
                'id' => 1,
                'page_title' => 'ページ',
                'slot_name' => '枠',
                'start_at' => '2026-08-21 11:00:00',
                'representative_name' => '=cmd|\'/c calc\'!A1',
                'phone' => '090-1234-5678',
                'party_size' => 1,
                'companion_names_json' => '[]',
                'checked_in_count' => 0,
                'status' => 'confirmed',
                'source' => 'line',
                'created_at' => '2026-08-01 03:00:00',
                'cancelled_at' => null,
            ]]);

            assertNotContains(',=cmd', $csv, '生の数式がそのまま出ないこと');
            assertContains("'=cmd", $csv);
        });

        test('全セルを引用符で囲み、内側の引用符は二重化する', function (): void {
            assertSame('"a,b"', CsvService::cell('a,b'));
            assertSame('"a""b"', CsvService::cell('a"b'));
            assertSame("\"a\nb\"", CsvService::cell("a\nb"));
            assertSame('"abc"', CsvService::cell('abc'));
        });
    });

    test('ダウンロード名はASCIIに寄せる', function (): void {
        assertSame('slot-12.csv', CsvService::fileName('slot', 12));
        assertSame('event-3.csv', CsvService::fileName('event', 3));
        assertSame('roster-1.csv', CsvService::fileName('名簿', 1), '非ASCIIは既定名にする');
    });
});

describe('名簿CSVのダウンロード', function (): void {
    test('枠単位のCSVが確定予約のみを含む', function (): void {
        $app = adminApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app));

        $keep = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => null,
            'source' => 'admin',
            'representative_name' => '残る人',
            'phone' => '0489361126',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);
        $drop = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => null,
            'source' => 'admin',
            'representative_name' => '消える人',
            'phone' => '0489361127',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);
        $app->booking->cancelBooking($drop['booking_id'], null, true);

        $response = request($app, 'GET', '/admin/reservation-slots/' . $slotId . '/roster.csv');
        assertSame(200, $response->status);
        assertContains('text/csv', $response->headers['Content-Type']);
        assertContains('slot-' . $slotId . '.csv', $response->headers['Content-Disposition']);
        assertContains('残る人', $response->body);
        assertNotContains('消える人', $response->body);

        $withCancelled = request(
            $app,
            'GET',
            '/admin/reservation-slots/' . $slotId . '/roster.csv',
            [],
            ['include' => 'cancelled']
        );
        assertContains('消える人', $withCancelled->body, '?include=cancelled で含められる');
    });

    test('ページ単位のCSVは全枠を含む', function (): void {
        $app = adminApp();
        $pageId = Fixtures::page($app);
        $a = Fixtures::slot($app, $pageId, ['name' => '行き']);
        $b = Fixtures::slot($app, $pageId, ['name' => '帰り', 'sort_order' => 2]);

        foreach ([[$a, '行きの人'], [$b, '帰りの人']] as [$slotId, $name]) {
            $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => null,
                'source' => 'admin',
                'representative_name' => $name,
                'phone' => '0489361126',
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ]);
        }

        $response = request($app, 'GET', '/admin/reservations/' . $pageId . '/roster.csv');
        assertSame(200, $response->status);
        assertContains('行きの人', $response->body);
        assertContains('帰りの人', $response->body);
        assertContains('event-' . $pageId . '.csv', $response->headers['Content-Disposition']);
    });

    test('未ログインではCSVを取得できない', function (): void {
        resetRequestState();
        $app = makeApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app));

        $response = request($app, 'GET', '/admin/reservation-slots/' . $slotId . '/roster.csv');
        assertSame(303, $response->status);
        assertContains('/admin/login', $response->headers['Location']);
    });
});
