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

// ビルトインサーバー（php -S ... public/index.php）は全リクエストをこのファイルへ渡すため、
// /assets/* のような実ファイルを自分で返す必要がある。
// 本番の Apache では .htaccess が実ファイルを先に配信するので、この分岐は通らない。
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $file = is_string($requested) ? __DIR__ . '/' . ltrim($requested, '/') : '';
    if ($file !== '' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require_once $root . '/app/bootstrap.php';

// セキュリティヘッダ（Workers版 secureHeaders 相当）。
// 現行Viewsはinline styleを使用するため style-src ではunsafe-inlineを許可する。
// LINE Loginは同一originのPOSTから access.line.me へ302遷移するため、form-actionに認可先だけを明示許可する。
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' https://profile.line-scdn.net data:; "
    . "form-action 'self' https://access.line.me; frame-ancestors 'none'; base-uri 'self'"
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
