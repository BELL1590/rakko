<?php

declare(strict_types=1);

/**
 * フロントコントローラ。XSERVER（Apache + mod_rewrite）配下で全リクエストを受ける。
 *
 * ローカル/同居配置では dirname(__DIR__) を app-root として使う。
 * 本番のようにドキュメントルートとアプリ本体を分離する場合は、
 * サーバー管理下の環境変数 RAKKO_APP_ROOT に app-root の絶対パスを設定する。
 * Git管理中のこのファイルを本番だけ手修正してはならない。
 */

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Http\SecurityHeaders;
use App\Support\ConfigError;

$configuredRoot = getenv('RAKKO_APP_ROOT');
$root = is_string($configuredRoot) && trim($configuredRoot) !== ''
    ? rtrim(trim($configuredRoot), "/\\")
    : dirname(__DIR__);
$bootstrap = $root . '/app/bootstrap.php';

// ユーザー入力（Host/URI/GET/POST/Cookie）からapp-rootを決めない。
// 設定ミス時は内部パスをレスポンスへ出さず、安全に停止する。
if (!is_file($bootstrap)) {
    error_log('[bootstrap] RAKKO_APP_ROOT does not contain app/bootstrap.php');
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Application configuration error.';
    exit(1);
}

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

require_once $bootstrap;

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
