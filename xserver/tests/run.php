<?php

declare(strict_types=1);

/**
 * テストランナー。tests/*Test.php を読み込んで実行する。
 *
 *   php tests/run.php             全テスト
 *   php tests/run.php booking     ファイル名に "booking" を含むテストのみ
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? null;
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$started = microtime(true);

foreach ($files as $file) {
    if ($filter !== null && !str_contains(basename($file), $filter)) {
        continue;
    }
    require $file;
}

$elapsed = (microtime(true) - $started) * 1000;
$total = TestStats::$passed + TestStats::$failed;

echo "\n" . str_repeat('-', 60) . "\n";
if (TestStats::$failed > 0) {
    echo "\033[31mFAILURES\033[0m\n";
    foreach (TestStats::$failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
    echo "\n";
}
printf(
    "%s  %d passed / %d  (%d failed)  %.0fms\n",
    TestStats::$failed === 0 ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m",
    TestStats::$passed,
    $total,
    TestStats::$failed,
    $elapsed
);

exit(TestStats::$failed === 0 ? 0 : 1);
