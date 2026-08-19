<?php

declare(strict_types=1);

/**
 * PSR-4 相当の最小オートローダ。
 * 共用サーバーへそのまま置けるよう、Composer に依存しない。
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
