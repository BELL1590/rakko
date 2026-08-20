<?php

declare(strict_types=1);

/**
 * フロントコントローラ。XSERVER（Apache + mod_rewrite）配下で全リクエストを受ける。
 *
 * アプリ本体（app/, config/）はドキュメントルートの外に置く前提のため、
 * 相対参照は必ずこのファイルからの相対パスで解決する。
 */

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Support\ConfigError;

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

// セキュリティヘッダ（Workers版 secureHeaders 相当）
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
    . "style-src 'self'; img-src 'self' https://profile.line-scdn.net data:; "
    . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'"
);

try {
    $boot = rakko_boot();
    $app = new App($boot['config'], $boot['db']);
    $response = $app->handle(Request::fromGlobals());
} catch (ConfigError $e) {
    $response = Response::text('Configuration error: ' . $e->getMessage(), 500);
} catch (\Throwable $e) {
    error_log('[fatal] ' . $e::class . ': ' . $e->getMessage());
    $response = Response::text('Internal Server Error', 500);
}

$response->send();
