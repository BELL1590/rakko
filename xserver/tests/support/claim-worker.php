<?php

declare(strict_types=1);

/**
 * NotificationConcurrencyTest から別プロセスとして起動され、
 * 同じ booking / notification_type の送信権を同時に取りに行く。
 *
 * 引数: <booking_id> <type> <api_log_path>
 * 出力: CLAIMED / SKIPPED / ERR:<message>
 *
 * 送信権を取れたときだけ「Messaging API 相当の処理」として
 * api_log_path へ1行追記する。テスト側はこの行数で二重送信を検出する。
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database\Connection;
use App\Database\Db;
use App\Repositories\NotificationRepository;
use App\Services\ReminderService;
use App\Support\Config;

$bookingId = (int) ($argv[1] ?? 0);
$type = (string) ($argv[2] ?? 'reminder');
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

    // 全ワーカーが同時に claim するよう、秒の変わり目まで待つ
    usleep((int) (1_000_000 - (int) (microtime(true) * 1_000_000) % 1_000_000));

    $token = $notifications->claim($bookingId, $type, ReminderService::MAX_ATTEMPTS);
    if ($token === null) {
        echo "SKIPPED\n";
        exit(0);
    }

    // ここに到達できるのは送信権を取った1プロセスだけであるべき
    file_put_contents($apiLog, getmypid() . "\n", FILE_APPEND | LOCK_EX);
    $notifications->finish($bookingId, $type, $token, 'requested', null);

    echo "CLAIMED\n";
    exit(0);
} catch (\Throwable $e) {
    echo 'ERR:' . $e->getMessage() . "\n";
    exit(1);
}
