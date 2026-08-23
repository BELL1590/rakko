<?php

declare(strict_types=1);

/**
 * ConcurrencyTest から別プロセスとして起動され、同じ枠へ同時に予約を投げる。
 * 引数: <slot_id> <worker_index>
 * 出力: OK / FULL / ERR:<code>
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database\Connection;
use App\Database\Db;
use App\Repositories\BookingRepository;
use App\Repositories\SlotRepository;
use App\Repositories\UserRepository;
use App\Services\BookingService;
use App\Support\Config;

$slotId = (int) ($argv[1] ?? 0);
$index = (int) ($argv[2] ?? 0);

$config = new Config([
    'APP_URL' => 'https://reserve.example.com',
    'APP_ENV' => 'test',
    'SESSION_SECRET' => str_repeat('t', 48),
    'DB_HOST' => getenv('RAKKO_DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => (int) (getenv('RAKKO_DB_PORT') ?: 3306),
    'DB_NAME' => getenv('RAKKO_DB_NAME') ?: 'rakko_test',
    'DB_USER' => getenv('RAKKO_DB_USER') ?: 'rakko',
    'DB_PASSWORD' => getenv('RAKKO_DB_PASSWORD') ?: 'rakko_test_pw',
    'DEMO_MODE' => false,
]);

try {
    $db = new Db((new Connection($config))->pdo());
    $service = new BookingService($db, new SlotRepository($db), new BookingRepository($db), new UserRepository($db));

    // 全ワーカーが同時に走り出すよう、秒の変わり目まで待つ
    usleep((int) ((1_000_000 - (int) (microtime(true) * 1_000_000) % 1_000_000)));

    $result = $service->createBooking([
        'slot_id' => $slotId,
        'user_id' => null,
        'source' => 'admin',
        'representative_name' => 'レース' . $index,
        'phone' => '0489361126',
        'party_size' => 2,
        'companion_names' => ['同行者'],
        'agreed' => true,
    ]);

    echo $result['ok'] === true ? "OK\n" : ('ERR:' . $result['code'] . "\n");
} catch (\Throwable $e) {
    echo 'ERR:EXCEPTION ' . $e->getMessage() . "\n";
}
