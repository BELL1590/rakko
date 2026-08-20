<?php

declare(strict_types=1);

/**
 * 予約ロジック。
 * 一括予約の原子性・オーバーブッキング防止が最重要のため、ここを最も厚く検証する。
 */

use App\Services\BookingService;

describe('BookingService', function (): void {
    describe('単一枠の予約', function (): void {
        test('通常の予約が確定し、残席が減る', function (): void {
            $app = makeApp();
            $pageId = Fixtures::page($app);
            $slotId = Fixtures::slot($app, $pageId, ['capacity' => 10]);
            $userId = Fixtures::user($app);

            $result = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => $userId,
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 3,
                'companion_names' => ['山田花子', '山田次郎'],
                'agreed' => true,
            ]);

            assertTrue($result['ok'], '予約が確定すること');

            $slot = $app->slots->findSlot($slotId);
            assertSame(3, (int) $slot['booked_seats']);
            assertSame(7, (int) $slot['remaining_seats']);
            assertSame(3, $app->slots->sumConfirmedSeats($slotId), '実データと一致すること');
        });

        test('同意なしは拒否される', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app));

            $result = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => false,
            ]);

            assertFalse($result['ok']);
            assertSame('NOT_AGREED', $result['code']);
        });

        test('電話番号の形式が不正なら拒否される', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app));

            $result = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => 'あいうえお',
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ]);

            assertFalse($result['ok']);
            assertSame('VALIDATION', $result['code']);
        });

        test('枠の max_party_size を超える人数は拒否される', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app), ['max_party_size' => 2]);

            $result = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 3,
                'companion_names' => [],
                'agreed' => true,
            ]);

            assertFalse($result['ok']);
            assertSame('VALIDATION', $result['code']);
        });

        test('同一ユーザーの同一枠への二重予約は拒否される', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app));
            $userId = Fixtures::user($app);

            $base = [
                'slot_id' => $slotId,
                'user_id' => $userId,
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ];

            assertTrue($app->booking->createBooking($base)['ok']);
            $second = $app->booking->createBooking($base);

            assertFalse($second['ok']);
            assertSame('DUPLICATE', $second['code']);
            assertSame(1, $app->slots->sumConfirmedSeats($slotId), '2件目は座席を消費しない');
        });

        test('キャンセル後は同じ枠を予約し直せる', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app));
            $userId = Fixtures::user($app);
            $base = [
                'slot_id' => $slotId,
                'user_id' => $userId,
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 2,
                'companion_names' => ['同行者'],
                'agreed' => true,
            ];

            $first = $app->booking->createBooking($base);
            assertTrue($first['ok']);
            assertTrue($app->booking->cancelBooking($first['booking_id'], $userId, false)['ok']);

            $slot = $app->slots->findSlot($slotId);
            assertSame(0, (int) $slot['booked_seats'], 'キャンセルで残席が戻ること');

            $second = $app->booking->createBooking($base);
            assertTrue($second['ok'], 'キャンセル後は再予約できる');
            assertSame(2, $app->slots->sumConfirmedSeats($slotId));
        });
    });

    describe('受付期間・受付状態', function (): void {
        test('受付開始前は予約できない', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app), [
                'booking_open_at' => '2099-01-01 00:00:00',
            ]);

            $result = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ], '2026-01-01 00:00:00');

            assertFalse($result['ok']);
            assertSame('CLOSED', $result['code']);
        });

        test('締切後は予約できない', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app), [
                'booking_close_at' => '2026-01-01 00:00:00',
            ]);

            $result = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ], '2026-06-01 00:00:00');

            assertFalse($result['ok']);
            assertSame('CLOSED', $result['code']);
        });

        test('受付停止中の枠は予約できない', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app), ['booking_status' => 'closed']);

            $result = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ]);

            assertFalse($result['ok']);
            assertSame('CLOSED', $result['code']);
        });

        test('出発済みの枠は予約できない', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app), [
                'start_at' => '2026-01-01 00:00:00',
            ]);

            $result = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ], '2026-06-01 00:00:00');

            assertFalse($result['ok']);
            assertSame('DEPARTED', $result['code']);
        });
    });

    describe('一括予約（複数枠）', function (): void {
        test('複数枠をまとめて予約すると booking_group_id が共有される', function (): void {
            $app = makeApp();
            $pageId = Fixtures::page($app);
            $outbound = Fixtures::slot($app, $pageId, ['name' => '行き']);
            $return = Fixtures::slot($app, $pageId, [
                'name' => '帰り',
                'start_at' => '2099-08-21 23:10:00',
                'sort_order' => 2,
            ]);

            $result = $app->booking->createGroupBooking([
                'page_id' => $pageId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'agreed' => true,
                'items' => [
                    ['slot_id' => $outbound, 'party_size' => 2, 'companion_names' => ['花子']],
                    ['slot_id' => $return, 'party_size' => 3, 'companion_names' => ['花子', '次郎']],
                ],
            ]);

            assertTrue($result['ok']);
            assertSame(2, count($result['booking_ids']));
            assertNotNull($result['group_id']);

            $group = $app->bookings->listByGroup($result['group_id']);
            assertSame(2, count($group));
            assertSame(2, $app->slots->sumConfirmedSeats($outbound), '枠ごとに人数が異なってよい');
            assertSame(3, $app->slots->sumConfirmedSeats($return));
        });

        test('1枠でも失敗すれば全枠がロールバックされる（all-or-nothing）', function (): void {
            $app = makeApp();
            $pageId = Fixtures::page($app);
            $ok = Fixtures::slot($app, $pageId, ['name' => '行き', 'capacity' => 10]);
            // 残席1しかない枠を2名で予約しようとする
            $tight = Fixtures::slot($app, $pageId, [
                'name' => '帰り',
                'capacity' => 1,
                'start_at' => '2099-08-21 23:10:00',
                'sort_order' => 2,
            ]);

            $result = $app->booking->createGroupBooking([
                'page_id' => $pageId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'agreed' => true,
                'items' => [
                    ['slot_id' => $ok, 'party_size' => 2, 'companion_names' => ['花子']],
                    ['slot_id' => $tight, 'party_size' => 2, 'companion_names' => ['花子']],
                ],
            ]);

            assertFalse($result['ok']);
            assertSame('FULL', $result['code']);
            assertSame(0, $app->slots->sumConfirmedSeats($ok), '成功していた枠も取り消されること');
            assertSame(0, $app->slots->sumConfirmedSeats($tight));
            assertSame(0, (int) $app->slots->findSlot($ok)['booked_seats'], 'カウンタも戻ること');
            assertSame([], $app->booking->findCounterMismatches());
        });

        test('allow_multi_slot_booking=0 のページでは複数枠を選べない', function (): void {
            $app = makeApp();
            $pageId = Fixtures::page($app, ['allow_multi_slot_booking' => false]);
            $a = Fixtures::slot($app, $pageId, ['name' => '行き']);
            $b = Fixtures::slot($app, $pageId, ['name' => '帰り', 'sort_order' => 2]);

            $result = $app->booking->createGroupBooking([
                'page_id' => $pageId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'agreed' => true,
                'items' => [
                    ['slot_id' => $a, 'party_size' => 1, 'companion_names' => []],
                    ['slot_id' => $b, 'party_size' => 1, 'companion_names' => []],
                ],
            ]);

            assertFalse($result['ok']);
            assertSame('TOO_MANY_SLOTS', $result['code']);
        });

        test('max_slots_per_checkout を超える枠数は拒否される', function (): void {
            $app = makeApp();
            $pageId = Fixtures::page($app, ['max_slots_per_checkout' => 1]);
            $a = Fixtures::slot($app, $pageId, ['name' => 'A']);
            $b = Fixtures::slot($app, $pageId, ['name' => 'B', 'sort_order' => 2]);

            $result = $app->booking->createGroupBooking([
                'page_id' => $pageId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'agreed' => true,
                'items' => [
                    ['slot_id' => $a, 'party_size' => 1, 'companion_names' => []],
                    ['slot_id' => $b, 'party_size' => 1, 'companion_names' => []],
                ],
            ]);

            assertFalse($result['ok']);
            assertSame('TOO_MANY_SLOTS', $result['code']);
        });

        test('枠を1つも選ばない場合は拒否される', function (): void {
            $app = makeApp();
            $pageId = Fixtures::page($app);
            Fixtures::slot($app, $pageId);

            $result = $app->booking->createGroupBooking([
                'page_id' => $pageId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'agreed' => true,
                'items' => [],
            ]);

            assertFalse($result['ok']);
            assertSame('NO_SELECTION', $result['code']);
        });

        test('同じ枠を2回選んでも1件として扱う', function (): void {
            $app = makeApp();
            $pageId = Fixtures::page($app);
            $slotId = Fixtures::slot($app, $pageId);

            $result = $app->booking->createGroupBooking([
                'page_id' => $pageId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'agreed' => true,
                'items' => [
                    ['slot_id' => $slotId, 'party_size' => 2, 'companion_names' => ['花子']],
                    ['slot_id' => $slotId, 'party_size' => 2, 'companion_names' => ['花子']],
                ],
            ]);

            assertTrue($result['ok']);
            assertSame(1, count($result['booking_ids']));
            assertNull($result['group_id'], '実質1枠なのでグループIDは付けない');
            assertSame(2, $app->slots->sumConfirmedSeats($slotId));
        });
    });

    describe('定員管理', function (): void {
        test('残席を超える予約は FULL で拒否される', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app), ['capacity' => 3]);

            $first = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app, 'U-a'),
                'source' => 'line',
                'representative_name' => '一人目',
                'phone' => '090-0000-0001',
                'party_size' => 2,
                'companion_names' => ['同行者'],
                'agreed' => true,
            ]);
            assertTrue($first['ok']);

            $second = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app, 'U-b'),
                'source' => 'line',
                'representative_name' => '二人目',
                'phone' => '090-0000-0002',
                'party_size' => 2,
                'companion_names' => ['同行者'],
                'agreed' => true,
            ]);

            assertFalse($second['ok']);
            assertSame('FULL', $second['code']);
            assertSame(2, $app->slots->sumConfirmedSeats($slotId), '定員を超えないこと');
        });

        test('ちょうど定員まで埋められる', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app), ['capacity' => 4]);

            foreach ([1, 2] as $i) {
                $result = $app->booking->createBooking([
                    'slot_id' => $slotId,
                    'user_id' => Fixtures::user($app, 'U-' . $i),
                    'source' => 'line',
                    'representative_name' => '予約者' . $i,
                    'phone' => '090-0000-000' . $i,
                    'party_size' => 2,
                    'companion_names' => ['同行者'],
                    'agreed' => true,
                ]);
                assertTrue($result['ok'], $i . '件目が確定すること');
            }

            $slot = $app->slots->findSlot($slotId);
            assertSame(4, (int) $slot['booked_seats']);
            assertSame(0, (int) $slot['remaining_seats']);
            assertTrue((bool) $slot['is_full'], '満席になること');
        });

        test('定員を確定予約より小さくは変更できない', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app), ['capacity' => 10]);
            $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 4,
                'companion_names' => ['a', 'b', 'c'],
                'agreed' => true,
            ]);

            $tooSmall = $app->booking->updateCapacity($slotId, 3);
            assertFalse($tooSmall['ok']);
            assertSame('CAPACITY_TOO_SMALL', $tooSmall['code']);

            assertTrue($app->booking->updateCapacity($slotId, 4)['ok'], '同数への変更は許可される');
            assertSame(4, (int) $app->slots->findSlot($slotId)['capacity']);
        });
    });

    describe('管理者代理予約', function (): void {
        test('LINEログインなし・同意なしでも登録でき、定員管理は同じ', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app), ['capacity' => 2]);

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
            assertTrue($result['ok']);

            $over = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => null,
                'source' => 'admin',
                'representative_name' => '電話 次郎',
                'phone' => '0489361127',
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ]);
            assertFalse($over['ok'], '代理予約でも定員は超えられない');
            assertSame('FULL', $over['code']);
        });

        test('代理予約は user_id が NULL でも重複判定に引っかからない', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app), ['capacity' => 10]);

            foreach (['一人目', '二人目'] as $name) {
                $result = $app->booking->createBooking([
                    'slot_id' => $slotId,
                    'user_id' => null,
                    'source' => 'admin',
                    'representative_name' => $name,
                    'phone' => '0489361126',
                    'party_size' => 1,
                    'companion_names' => [],
                    'agreed' => true,
                ]);
                assertTrue($result['ok'], $name . 'が登録できること');
            }
            assertSame(2, $app->slots->sumConfirmedSeats($slotId));
        });
    });

    describe('キャンセルと権限', function (): void {
        test('他人の予約はキャンセルできない', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app));
            $owner = Fixtures::user($app, 'U-owner');
            $other = Fixtures::user($app, 'U-other');

            $booking = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => $owner,
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ]);

            $result = $app->booking->cancelBooking($booking['booking_id'], $other, false);
            assertFalse($result['ok']);
            assertSame('FORBIDDEN', $result['code']);
            assertSame(1, $app->slots->sumConfirmedSeats($slotId), '予約は残ること');
        });

        test('他人の予約は詳細も取得できない', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app));
            $owner = Fixtures::user($app, 'U-owner');
            $other = Fixtures::user($app, 'U-other');

            $booking = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => $owner,
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ]);

            assertNotNull($app->booking->findOwnedBooking($booking['booking_id'], $owner));
            assertNull($app->booking->findOwnedBooking($booking['booking_id'], $other));
        });

        test('管理者は所有者でなくてもキャンセルできる', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app));
            $owner = Fixtures::user($app, 'U-owner');

            $booking = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => $owner,
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 2,
                'companion_names' => ['花子'],
                'agreed' => true,
            ]);

            assertTrue($app->booking->cancelBooking($booking['booking_id'], null, true)['ok']);
            assertSame(0, $app->slots->sumConfirmedSeats($slotId));
            assertSame(0, (int) $app->slots->findSlot($slotId)['booked_seats']);
        });

        test('二重キャンセルは失敗し、残席は戻り過ぎない', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app), ['capacity' => 10]);
            $userId = Fixtures::user($app);

            $booking = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => $userId,
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'party_size' => 3,
                'companion_names' => ['a', 'b'],
                'agreed' => true,
            ]);

            assertTrue($app->booking->cancelBooking($booking['booking_id'], $userId, false)['ok']);
            $second = $app->booking->cancelBooking($booking['booking_id'], $userId, false);
            assertFalse($second['ok']);
            assertSame('ALREADY_CANCELLED', $second['code']);
            assertSame(0, (int) $app->slots->findSlot($slotId)['booked_seats']);
        });
    });

    describe('入力の正規化', function (): void {
        test('空の同行者欄（no-JS時のPOST）は取り除かれる', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app));

            $result = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '  山田太郎  ',
                'phone' => ' 090-1234-5678 ',
                'party_size' => 2,
                'companion_names' => ['山田花子', '', '   '],
                'agreed' => true,
            ]);

            assertTrue($result['ok']);
            $booking = $app->bookings->find($result['booking_id']);
            assertSame('山田太郎', $booking['representative_name'], '前後の空白は落とす');
            assertSame('090-1234-5678', $booking['phone']);
            assertSame(['山田花子'], json_decode((string) $booking['companion_names_json'], true));
        });

        test('人数の上限・下限を外れる値は拒否される', function (): void {
            $app = makeApp();
            $slotId = Fixtures::slot($app, Fixtures::page($app));
            $base = [
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app),
                'source' => 'line',
                'representative_name' => '山田太郎',
                'phone' => '090-1234-5678',
                'companion_names' => [],
                'agreed' => true,
            ];

            foreach ([0, -1, BookingService::HARD_MAX_PARTY_SIZE + 1] as $partySize) {
                $result = $app->booking->createBooking($base + ['party_size' => $partySize]);
                assertFalse($result['ok'], $partySize . '名は拒否されること');
                assertSame('VALIDATION', $result['code']);
            }
        });
    });
});
