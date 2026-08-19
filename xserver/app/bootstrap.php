<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use App\Database\Connection;
use App\Database\Db;
use App\Support\Config;

/**
 * 設定とDB接続を組み立てる。
 * Web（public/index.php）と CLI（bin/*.php）の両方から使う。
 *
 * @return array{config: Config, db: Db}
 */
function rakko_boot(?string $configDir = null): array
{
    $config = Config::load($configDir);
    $db = new Db((new Connection($config))->pdo());

    return ['config' => $config, 'db' => $db];
}
