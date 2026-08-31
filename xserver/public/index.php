<?php

declare(strict_types=1);

/**
 * フロントコントローラ。XSERVER（Apache + mod_rewrite）配下で全リクエストを受ける。
 *
 * app-root の解決順:
 *   1. サーバー管理下の環境変数 RAKKO_APP_ROOT
 *   2. リポジトリ標準配置: dirname(__DIR__)
 *   3. XSERVER独立ドキュメントルート配置: public_html の兄弟 app-root/
 *   4. XSERVERサブドメイン配置: <domain>/public_html/<subdomain>/ の2階層上にある app-root/
 *
 * 本番固有パスをGit管理中のこのファイルへ直接書き込まない。
 */

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Http\SecurityHeaders;
use App\Support\ConfigError;

$configuredRoot = getenv('RAKKO_APP_ROOT');
$candidates = [];
if (is_string($configuredRoot) && trim($configuredRoot) !== '') {
    $candidates[] = rtrim(trim($configuredRoot), "/\\");
}
$candidates[] = dirname(__DIR__);
$candidates[] = dirname(__DIR__) . '/app-root';
$candidates[] = dirname(dirname(__DIR__)) . '/app-root';

$root = null;
$bootstrap = null;
foreach (array_values(array_unique($candidates)) as $candidate) {
    $candidateBootstrap = $candidate . '/app/bootstrap.php';
    if (is_file($candidateBootstrap)) {
        $root = $candidate;
        $bootstrap = $candidateBootstrap;
        break;
    }
}

// ユーザー入力（Host/URI/GET/POST/Cookie）からapp-rootを決めない。
// 設定ミス時は内部パスをレスポンスへ出さず、安全に停止する。
// ループ後にもbootstrapを再確認し、解決後の前提を明示的に固定する。
if ($root === null || $bootstrap === null || !is_file($bootstrap)) {
    error_log('[bootstrap] app root was not found; check RAKKO_APP_ROOT or app-root placement');
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
