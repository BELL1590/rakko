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
use App\Http\SecurityHeaders;
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

$request = Request::fromGlobals();

// セキュリティヘッダ（Workers版 secureHeaders 相当）。
// 内容は App\Http\SecurityHeaders に定義し、テストから検証できるようにしている。
// LIFFブートストラップ画面だけ、LIFF SDKとLINE APIのオリジンを追加で許可する。
SecurityHeaders::send(SecurityHeaders::needsLiff($request->path));

try {
    $boot = rakko_boot();
    $app = new App($boot['config'], $boot['db']);
    $response = $app->handle($request);
} catch (ConfigError $e) {
    $response = Response::text('Configuration error: ' . $e->getMessage(), 500);
} catch (\Throwable $e) {
    error_log('[fatal] ' . $e::class . ': ' . $e->getMessage());
    $response = Response::text('Internal Server Error', 500);
}

$response->send();
