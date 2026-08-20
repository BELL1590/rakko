<?php

declare(strict_types=1);

/**
 * マイグレーション実行用CLI。
 *
 *   php bin/migrate.php            未適用のSQLを順に適用する
 *   php bin/migrate.php --status   未適用ファイルの一覧だけ表示する
 *
 * Webからは絶対に実行できないようにする（XSERVERでは bin/ はドキュメントルート外に置く）。
 */

use App\Database\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI only.\n";
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

try {
    $boot = rakko_boot();
    $migrator = new Migrator($boot['db'], $root . '/database/migrations');

    $statusOnly = in_array('--status', $argv, true);
    if ($statusOnly) {
        $pending = $migrator->pendingFiles();
        if ($pending === []) {
            echo "up to date\n";
            exit(0);
        }
        foreach ($pending as $file) {
            echo "pending: {$file}\n";
        }
        exit(0);
    }

    $applied = $migrator->migrate();
    if ($applied === []) {
        echo "up to date\n";
        exit(0);
    }
    foreach ($applied as $file) {
        echo "applied: {$file}\n";
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[migrate] ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
