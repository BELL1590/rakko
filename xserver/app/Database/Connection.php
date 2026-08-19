<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Config;

/**
 * PDO(MySQL) 接続。
 * - 例外モード / 連想配列フェッチ / エミュレート無効（本物のプリペアドステートメント）
 * - セッションのタイムゾーンをUTCに固定し、サーバー設定に依存させない
 */
final class Connection
{
    private ?\PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config->str('DB_HOST', 'localhost'),
            $this->config->int('DB_PORT', 3306),
            $this->config->str('DB_NAME')
        );

        $pdo = new \PDO(
            $dsn,
            $this->config->str('DB_USER'),
            $this->config->str('DB_PASSWORD'),
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                // プレースホルダをDB側で処理させる（SQLインジェクション対策の基本）
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );
        // 日時は常にUTCで読み書きする
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        return $this->pdo = $pdo;
    }
}
