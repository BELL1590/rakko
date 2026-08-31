<?php

declare(strict_types=1);

/**
 * 複数 booking_id を all-or-nothing で同時claimする競合テスト用ワーカー。
 *
 * 引数: <comma-separated-booking-ids> <type> <api_log_path>
 * 出力: CLAIMED / SKIPPED / ERR:<message>
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database\Connection;
use App\Database\Db;
use App\Repositories\NotificationRepository;
use App\Services\ReminderService;
use App\Support\Config;

$bookingIds = array_values(array_filter(
    array_map('intval', explode(',', (string) ($argv[1] ?? ''))),
    static fn (int $id): bool => $id > 0
));
$type = (string) ($argv[2] ?? 'booking_confirmation');
$apiLog = (string) ($argv[3] ?? '');

$config = new Config([
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
    $notifications = new NotificationRepository($db);

    // 全ワーカーをできるだけ同時に突入させる。
    usleep((int) (1_000_000 - (int) (microtime(true) * 1_000_000) % 1_000_000));

    $claims = $notifications->claimMany(
        $bookingIds,
        $type,
        ReminderService::MAX_ATTEMPTS
    );
    if ($claims === null || $claims === []) {
        echo "SKIPPED\n";
        exit(0);
    }

    // ここに到達できるのはグループ全件を取れた1プロセスだけ。
    file_put_contents($apiLog, getmypid() . "\n", FILE_APPEND | LOCK_EX);
    foreach ($claims as $bookingId => $claim) {
        $notifications->finish((int) $bookingId, $type, $claim['token'], 'requested', null);
    }

    echo "CLAIMED\n";
    exit(0);
} catch (\Throwable $e) {
    echo 'ERR:' . $e->getMessage() . "\n";
    exit(1);
}
