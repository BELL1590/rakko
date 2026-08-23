<?php

declare(strict_types=1);

/**
 * 同時アクセス時のオーバーブッキング防止。
 *
 * D1(SQLite) は単一ライターのため直列化されるが、MySQL は複数接続が本当に並行する。
 * ここでは PDO 接続を複数張って行ロック（SELECT ... FOR UPDATE）が効いていることを、
 * 「素の SELECT → INSERT」と比較して確認する。
 *
 * 併せて、別プロセスから同時に予約を投げる本番相当のレースも検証する。
 */

use App\Database\Connection;
use App\Database\Db;
use App\Repositories\BookingRepository;
use App\Repositories\SlotRepository;
use App\Repositories\UserRepository;
use App\Services\BookingService;
use App\Support\Config;

/** 同じ設定で独立したDB接続を張る（＝別セッション・別トランザクション）。 */
function makeIsolatedService(Config $config): array
{
    $db = new Db((new Connection($config))->pdo());
    $slots = new SlotRepository($db);
    $bookings = new BookingRepository($db);
    return [$db, new BookingService($db, $slots, $bookings, new UserRepository($db))];
}

describe('同時アクセス時の定員保護', function (): void {
    test('行ロックにより、2つの接続が同時に最後の1席を取り合っても1件しか通らない', function (): void {
        $app = makeApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app), ['capacity' => 1]);
        Fixtures::user($app, 'U-race-a', 'レースA');
        Fixtures::user($app, 'U-race-b', 'レースB');

        [$dbA, $serviceA] = makeIsolatedService($app->config);
        [$dbB, $serviceB] = makeIsolatedService($app->config);

        // 接続Aが枠をロックしたまま保持する
        $dbA->pdo()->beginTransaction();
        $dbA->pdo()
            ->prepare('SELECT * FROM reservation_slots WHERE id = ? FOR UPDATE')
            ->execute([$slotId]);
        $dbA->pdo()->exec('UPDATE reservation_slots SET reserved_seats = reserved_seats + 1 WHERE id = ' . $slotId);

        // 接続Bは短いロック待ちで諦めるよう設定し、ブロックされることを確認する
        $dbB->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            $dbB->pdo()->beginTransaction();
            $dbB->pdo()
                ->prepare('SELECT * FROM reservation_slots WHERE id = ? FOR UPDATE')
                ->execute([$slotId]);
            $dbB->pdo()->rollBack();
        } catch (\PDOException $e) {
            $blocked = true;
            if ($dbB->pdo()->inTransaction()) {
                $dbB->pdo()->rollBack();
            }
        }
        assertTrue($blocked, 'FOR UPDATE が他接続をブロックすること（行ロックが効いている）');

        $dbA->pdo()->rollBack();

        // ロックが外れれば通常どおり1件だけ通る
        $first = $serviceA->createBooking([
            'slot_id' => $slotId,
            'user_id' => (int) $app->users->findByLineUserId('U-race-a')['id'],
            'source' => 'line',
            'representative_name' => 'レースA',
            'phone' => '090-0000-0001',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);
        $second = $serviceB->createBooking([
            'slot_id' => $slotId,
            'user_id' => (int) $app->users->findByLineUserId('U-race-b')['id'],
            'source' => 'line',
            'representative_name' => 'レースB',
            'phone' => '090-0000-0002',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);

        assertTrue($first['ok']);
        assertFalse($second['ok']);
        assertSame('FULL', $second['code']);
        assertSame(1, $app->slots->sumConfirmedSeats($slotId));
    });

    test('DBのCHECK制約が最後の砦として定員超過を拒む', function (): void {
        $app = makeApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app), ['capacity' => 2]);

        // アプリの検証を迂回してカウンタだけ増やそうとしても、DB側で弾かれる
        $error = assertThrows(
            \PDOException::class,
            static function () use ($app, $slotId): void {
                $app->db->run(
                    'UPDATE reservation_slots SET reserved_seats = ? WHERE id = ?',
                    [3, $slotId]
                );
            },
            'reserved_seats > capacity は CHECK 制約で拒否されること'
        );
        assertContains('23000', $error->getCode() . '', 'DB制約違反であること');

        assertSame(0, (int) $app->slots->findSlot($slotId)['booked_seats']);
    });

    test('DBのUNIQUE制約が最後の砦として二重予約を拒む', function (): void {
        $app = makeApp();
        $slotId = Fixtures::slot($app, Fixtures::page($app), ['capacity' => 10]);
        $userId = Fixtures::user($app);

        $params = [
            'booking_group_id' => null,
            'reservation_slot_id' => $slotId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 1,
            'companion_names_json' => '[]',
        ];

        $app->bookings->insert($params);
        assertThrows(
            \PDOException::class,
            static fn () => $app->bookings->insert($params),
            '同一ユーザー×同一枠の confirmed は UNIQUE 制約で拒否されること'
        );
    });

    test('別プロセスから同時に予約しても定員を超えない', function (): void {
        $app = makeApp();
        $pageId = Fixtures::page($app);
        // 定員10に対して 8並列 × 2名 = 16名分の要求を同時に投げる
        $slotId = Fixtures::slot($app, $pageId, ['capacity' => 10]);

        $workers = 8;
        $script = dirname(__DIR__) . '/tests/support/race-worker.php';
        assertTrue(is_file($script), 'ワーカースクリプトが存在すること');

        $procs = [];
        for ($i = 0; $i < $workers; $i++) {
            $cmd = sprintf(
                '%s %s %d %d 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($script),
                $slotId,
                $i
            );
            $procs[$i] = popen($cmd, 'r');
        }

        $okCount = 0;
        $fullCount = 0;
        foreach ($procs as $handle) {
            $output = trim((string) stream_get_contents($handle));
            pclose($handle);
            if (str_contains($output, 'OK')) {
                $okCount++;
            } elseif (str_contains($output, 'FULL')) {
                $fullCount++;
            }
        }

        $seats = $app->slots->sumConfirmedSeats($slotId);
        $slot = $app->slots->findSlot($slotId);

        assertTrue($okCount > 0, '少なくとも1件は成功すること（実際: ' . $okCount . '）');
        assertSame(5, $okCount, '定員10 ÷ 2名 = 5件だけ通ること');
        assertSame(3, $fullCount, '残りは FULL で拒否されること');
        assertSame(10, $seats, '確定席数が定員を超えないこと');
        assertSame(10, (int) $slot['booked_seats'], 'カウンタも定員どおりであること');
        assertSame([], $app->booking->findCounterMismatches(), 'カウンタと実データが一致すること');
    });

    test('一括予約の途中で満席になっても、部分的な予約は残らない', function (): void {
        $app = makeApp();
        $pageId = Fixtures::page($app);
        $roomy = Fixtures::slot($app, $pageId, ['name' => '行き', 'capacity' => 10]);
        $tight = Fixtures::slot($app, $pageId, [
            'name' => '帰り',
            'capacity' => 2,
            'start_at' => '2099-08-21 23:10:00',
            'sort_order' => 2,
        ]);

        // 先に別ユーザーが「帰り」を埋める
        $app->booking->createBooking([
            'slot_id' => $tight,
            'user_id' => Fixtures::user($app, 'U-first'),
            'source' => 'line',
            'representative_name' => '先客',
            'phone' => '090-0000-0001',
            'party_size' => 2,
            'companion_names' => ['同行者'],
            'agreed' => true,
        ]);

        $result = $app->booking->createGroupBooking([
            'page_id' => $pageId,
            'user_id' => Fixtures::user($app, 'U-second'),
            'source' => 'line',
            'representative_name' => '後客',
            'phone' => '090-0000-0002',
            'agreed' => true,
            'items' => [
                ['slot_id' => $roomy, 'party_size' => 2, 'companion_names' => ['同行者']],
                ['slot_id' => $tight, 'party_size' => 1, 'companion_names' => []],
            ],
        ]);

        assertFalse($result['ok']);
        assertSame('FULL', $result['code']);
        assertSame(0, $app->slots->sumConfirmedSeats($roomy), '行きの予約も残らないこと');
        assertSame(2, $app->slots->sumConfirmedSeats($tight), '先客の予約は影響を受けないこと');
        assertSame([], $app->booking->findCounterMismatches());
    });
});
