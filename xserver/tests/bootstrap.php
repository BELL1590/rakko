<?php

declare(strict_types=1);

/**
 * XSERVER版の最小テストランナー。
 *
 * 共有ホスティングでは Composer / PHPUnit を前提にできないため、
 * 依存ゼロ・PHP標準のみで動くランナーを同梱する。
 *
 *   php tests/run.php            全テスト
 *   php tests/run.php booking    ファイル名に "booking" を含むテストのみ
 *
 * DB接続は環境変数 RAKKO_DB_* で上書きする（config.local.php を汚さない）。
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\App;
use App\Database\Connection;
use App\Database\Db;
use App\Database\Migrator;
use App\Http\Request;
use App\Http\Response;
use App\Services\HttpClient;
use App\Support\Config;

final class TestStats
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var list<string> */
    public static array $failures = [];
    public static string $suite = '';
}

/** テスト1件。例外・アサーション失敗を捕まえて次へ進む。 */
function test(string $name, callable $body): void
{
    try {
        $body();
        TestStats::$passed++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } catch (\Throwable $e) {
        TestStats::$failed++;
        $label = TestStats::$suite . ' > ' . $name;
        TestStats::$failures[] = $label . "\n      " . $e->getMessage();
        echo "  \033[31m✗\033[0m {$name}\n      " . $e->getMessage() . "\n";
    }
}

function describe(string $name, callable $body): void
{
    $previous = TestStats::$suite;
    TestStats::$suite = $previous === '' ? $name : $previous . ' > ' . $name;
    echo "\n" . TestStats::$suite . "\n";
    $body();
    TestStats::$suite = $previous;
}

/**
 * アサーション失敗。必ず例外を投げるので呼び出し元へは戻らない。
 *
 * PHP 8.0 には `never` 戻り値型が無い（8.1で追加）。
 * 8.0 では `: never` は「クラス never を返す」と解釈され、
 * 常に throw するため偶然動いてしまうだけなので、型は書かずに
 * 静的解析向けの `@return never` で意図を示す。
 *
 * @return never
 */
function fail(string $message)
{
    throw new \AssertionError($message);
}

function assertTrue(mixed $value, string $message = 'expected true'): void
{
    if ($value !== true) {
        fail($message . ' (got ' . var_export($value, true) . ')');
    }
}

function assertFalse(mixed $value, string $message = 'expected false'): void
{
    if ($value !== false) {
        fail($message . ' (got ' . var_export($value, true) . ')');
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = 'values differ'): void
{
    if ($expected !== $actual) {
        fail(sprintf(
            "%s\n      expected: %s\n      actual:   %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertNotSame(mixed $unexpected, mixed $actual, string $message = 'values should differ'): void
{
    if ($unexpected === $actual) {
        fail($message . ' (both ' . var_export($actual, true) . ')');
    }
}

function assertNull(mixed $value, string $message = 'expected null'): void
{
    if ($value !== null) {
        fail($message . ' (got ' . var_export($value, true) . ')');
    }
}

function assertNotNull(mixed $value, string $message = 'expected not null'): void
{
    if ($value === null) {
        fail($message);
    }
}

function assertContains(string $needle, string $haystack, string $message = 'substring not found'): void
{
    if (!str_contains($haystack, $needle)) {
        fail($message . ': ' . $needle);
    }
}

function assertNotContains(string $needle, string $haystack, string $message = 'unexpected substring'): void
{
    if (str_contains($haystack, $needle)) {
        fail($message . ': ' . $needle);
    }
}

function assertThrows(string $class, callable $body, string $message = 'expected exception'): \Throwable
{
    try {
        $body();
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            fail($message . ': got ' . $e::class . ' (' . $e->getMessage() . ')');
        }
        return $e;
    }
    fail($message . ': ' . $class . ' was not thrown');
}

// ---------------------------------------------------------------------------
// テスト用の環境
// ---------------------------------------------------------------------------

/** テストからLINE APIの応答を差し替えるためのHTTPクライアント。 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, body: string, headers: array<string, string>}> */
    public array $calls = [];

    /** @var list<array{status: int, body: string}> */
    public array $responses = [];

    public function __construct(public int $defaultStatus = 200, public string $defaultBody = '{}')
    {
    }

    /** @param array<string, string> $headers */
    public function post(string $url, string $body, array $headers): array
    {
        $this->calls[] = ['method' => 'POST', 'url' => $url, 'body' => $body, 'headers' => $headers];
        return $this->next();
    }

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers): array
    {
        $this->calls[] = ['method' => 'GET', 'url' => $url, 'body' => '', 'headers' => $headers];
        return $this->next();
    }

    /** @return array{status: int, body: string} */
    private function next(): array
    {
        return array_shift($this->responses) ?? ['status' => $this->defaultStatus, 'body' => $this->defaultBody];
    }
}

/**
 * テストDBを作り直し、マイグレーションを適用したアプリを返す。
 * 各テストが完全に独立するよう、呼ぶたびに全テーブルを空にする。
 *
 * @param array<string, mixed> $overrides config の上書き
 */
function makeApp(array $overrides = [], ?FakeHttpClient $http = null): App
{
    static $migrated = false;

    $root = dirname(__DIR__);
    $base = [
        'APP_URL' => 'https://reserve.example.com',
        'APP_ENV' => 'test',
        'SESSION_SECRET' => str_repeat('t', 48),
        'DB_HOST' => getenv('RAKKO_DB_HOST') ?: '127.0.0.1',
        'DB_PORT' => (int) (getenv('RAKKO_DB_PORT') ?: 3306),
        'DB_NAME' => getenv('RAKKO_DB_NAME') ?: 'rakko_test',
        'DB_USER' => getenv('RAKKO_DB_USER') ?: 'rakko',
        'DB_PASSWORD' => getenv('RAKKO_DB_PASSWORD') ?: 'rakko_test_pw',
        'LINE_LOGIN_CHANNEL_ID' => '1234567890',
        'LINE_LOGIN_CHANNEL_SECRET' => 'line-login-secret',
        'LINE_MESSAGING_CHANNEL_ACCESS_TOKEN' => 'line-messaging-token',
        'ADMIN_USERNAME' => 'admin',
        'ADMIN_PASSWORD_HASH' => password_hash('admin-password', PASSWORD_DEFAULT),
        'DEMO_MODE' => false,
    ];

    $config = new Config(array_merge($base, $overrides));
    $db = new Db((new Connection($config))->pdo());

    if (!$migrated) {
        // 前回実行の残骸を消してから作り直す
        dropAllTables($db);
        (new Migrator($db, $root . '/database/migrations'))->migrate();
        $migrated = true;
    }

    truncateAll($db);

    return new App($config, $db, $http ?? new FakeHttpClient());
}

function dropAllTables(Db $db): void
{
    $pdo = $db->pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function truncateAll(Db $db): void
{
    $pdo = $db->pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['notifications', 'bookings', 'reservation_slots', 'reservation_pages', 'users'] as $table) {
        $pdo->exec('TRUNCATE TABLE `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/** Cookie を含むリクエスト状態をリセットする。 */
function resetRequestState(): void
{
    $_COOKIE = [];
    $_GET = [];
    $_POST = [];
    $_SERVER['QUERY_STRING'] = '';
}

/**
 * ルーター経由でリクエストを1件処理する。
 *
 * @param array<string, mixed> $post
 * @param array<string, mixed> $query
 */
function request(
    App $app,
    string $method,
    string $path,
    array $post = [],
    array $query = [],
    array $repeated = [],
): Response {
    $_GET = $query;
    $_POST = $post;
    $_SERVER['QUERY_STRING'] = http_build_query($query);
    return $app->handle(new Request($method, $path, $query, $post, $repeated));
}

/** 管理者としてログイン済みのアプリを作る。 */
function adminApp(array $overrides = [], ?FakeHttpClient $http = null): App
{
    resetRequestState();
    $app = makeApp($overrides, $http);
    $app->session->startAdminSession('admin');
    return $app;
}

/** 予約ページと枠をまとめて作る簡易ファクトリ。 */
final class Fixtures
{
    /** @param array<string, mixed> $overrides */
    public static function page(App $app, array $overrides = []): int
    {
        return $app->slots->createPage(array_merge([
            'slug' => 'rakko-ikebukuro',
            'title' => 'らっこ号 池袋便',
            'description' => 'テスト用の予約ページ',
            'status' => 'published',
            'page_type' => 'bus',
            'allow_multi_slot_booking' => true,
            'requires_line_login' => true,
            'max_slots_per_checkout' => 4,
            'checkin_label' => '乗車',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    public static function slot(App $app, int $pageId, array $overrides = []): int
    {
        return $app->slots->createSlot($pageId, array_merge([
            'name' => '行き',
            'description' => '',
            'start_at' => '2099-08-21 11:00:00',
            'end_at' => null,
            'origin' => '池袋西口 マクドナルド前辺り',
            'destination' => '草加健康センター',
            'location' => null,
            'capacity' => 40,
            'max_party_size' => 4,
            'booking_open_at' => null,
            'booking_close_at' => null,
            'reminder_at' => null,
            'booking_status' => 'open',
            'sort_order' => 1,
        ], $overrides));
    }

    public static function user(App $app, string $lineId = 'U-test-0001', string $name = 'テスト太郎'): int
    {
        return (int) $app->users->upsertByLineId($lineId, $name, null, true)['id'];
    }
}
