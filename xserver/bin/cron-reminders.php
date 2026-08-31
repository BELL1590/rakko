<?php

declare(strict_types=1);

/**
 * XSERVER Cron から5分毎に呼ぶ通知バッチ。
 * Cloudflare Workers 版の Cron Trigger（handleScheduled）相当のリマインドに加え、
 * 一時障害で失敗した予約完了通知も安全に再試行する。
 *
 *   毎5分: 分フィールド "0,5,10,...,55" / 時・日・月・曜日はすべて "*"
 *   実行コマンド: /usr/bin/php8.0 /home/<account>/<domain>/app-root/bin/cron-reminders.php
 *
 * 終了コード: 0 = 正常、1 = 設定/接続エラー。
 * 個人情報は出力しない（件数のみ記録する）。
 */

use App\App;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI only.\n";
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

try {
    $boot = rakko_boot();
    // production で DEMO_MODE が有効なら何も送らない
    $boot['config']->assertDemoModeSafety();

    $app = new App($boot['config'], $boot['db']);
    $confirmations = $app->reminders->processFailedBookingConfirmations();
    $reminders = $app->reminders->processDueReminders();

    printf(
        "[cron] %s confirmations checked=%d requested=%d failed=%d skipped=%d already=%d; reminders checked=%d requested=%d failed=%d skipped=%d already=%d\n",
        gmdate('Y-m-d H:i:s'),
        $confirmations['checked'],
        $confirmations['requested'],
        $confirmations['failed'],
        $confirmations['skipped'],
        $confirmations['already'],
        $reminders['checked'],
        $reminders['requested'],
        $reminders['failed'],
        $reminders['skipped'],
        $reminders['already'],
    );
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[cron] ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
