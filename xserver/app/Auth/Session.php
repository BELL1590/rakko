<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\Config;

/**
 * 署名付きCookieによるセッション管理。
 * Workers版 src/services/session.ts の移植。
 *
 * PHPのネイティブセッション（ファイル）ではなくHMAC署名Cookieを使う。
 * 共用サーバーでセッション保存ディレクトリの権限問題を持ち込まないため、
 * かつ Workers版と同じ挙動（ステートレス・改ざん検知）を保つため。
 */
final class Session
{
    public const USER_COOKIE = 'rk_session';
    public const ADMIN_COOKIE = 'rk_admin';
    public const OAUTH_COOKIE = 'rk_oauth';
    public const CSRF_COOKIE = 'rk_csrf';

    public const USER_MAX_AGE = 60 * 60 * 24 * 30; // 30日
    public const ADMIN_MAX_AGE = 60 * 60 * 8;      // 8時間
    private const OAUTH_MAX_AGE = 60 * 10;          // 10分

    /** @var array<string, string> 送出予定のCookie（テストから検証できるよう保持） */
    private array $queued = [];

    public function __construct(private readonly Config $config)
    {
    }

    // -----------------------------------------------------------------
    // 署名ユーティリティ
    // -----------------------------------------------------------------

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($padded, true);
        return $decoded === false ? '' : $decoded;
    }

    public function hmac(string $message): string
    {
        return self::base64UrlEncode(
            hash_hmac('sha256', $message, $this->config->sessionSecret(), true)
        );
    }

    /** 値をJSON化し `payload.signature` 形式へ署名する。 */
    public function sign(mixed $payload): string
    {
        $encoded = self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $encoded . '.' . $this->hmac($encoded);
    }

    /**
     * 署名付き文字列を検証して復号する。改ざん時は null。
     *
     * @return array<string, mixed>|null
     */
    public function verify(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $separator = strrpos($token, '.');
        if ($separator === false || $separator === 0) {
            return null;
        }
        $encoded = substr($token, 0, $separator);
        $signature = substr($token, $separator + 1);

        // タイミング安全な比較
        if (!hash_equals($this->hmac($encoded), $signature)) {
            return null;
        }
        $decoded = json_decode(self::base64UrlDecode($encoded), true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return self::base64UrlEncode(random_bytes($bytes));
    }

    // -----------------------------------------------------------------
    // PKCE
    // -----------------------------------------------------------------

    public static function createCodeVerifier(): string
    {
        return self::randomToken(32);
    }

    public static function createCodeChallenge(string $verifier): string
    {
        return self::base64UrlEncode(hash('sha256', $verifier, true));
    }

    // -----------------------------------------------------------------
    // Cookie
    // -----------------------------------------------------------------

    private function writeCookie(string $name, string $value, int $maxAge, string $path = '/', string $sameSite = 'Lax'): void
    {
        $this->queued[$name] = $value;
        // 同一リクエスト内で発行→読み出しできるようにする（CLI/テストでも同じ挙動）
        if ($value === '' || $maxAge <= 0) {
            unset($_COOKIE[$name]);
        } else {
            $_COOKIE[$name] = $value;
        }
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires' => $maxAge > 0 ? time() + $maxAge : 1,
            'path' => $path,
            'httponly' => true,
            'secure' => $this->config->isProduction(),
            'samesite' => $sameSite,
        ]);
    }

    private function readCookie(string $name): ?string
    {
        $value = $_COOKIE[$name] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** テスト用。setcookie() を経由せずに書き込んだ値を確認する。 */
    public function queuedCookie(string $name): ?string
    {
        return $this->queued[$name] ?? null;
    }

    public function startUserSession(int $userId): void
    {
        $this->writeCookie(
            self::USER_COOKIE,
            $this->sign(['uid' => $userId, 'iat' => time()]),
            self::USER_MAX_AGE
        );
    }

    public function userId(): ?int
    {
        $session = $this->verify($this->readCookie(self::USER_COOKIE));
        if ($session === null || !isset($session['uid']) || !is_int($session['uid'])) {
            return null;
        }
        // 署名は無期限に有効なので、発行時刻を見て自前で期限を切る。
        // これが無いと、盗まれたCookieが30日経っても使えてしまう。
        if (!isset($session['iat']) || !is_int($session['iat'])) {
            return null;
        }
        if (time() - $session['iat'] > self::USER_MAX_AGE) {
            return null;
        }
        return $session['uid'];
    }

    public function clearUserSession(): void
    {
        unset($_COOKIE[self::USER_COOKIE]);
        $this->writeCookie(self::USER_COOKIE, '', 0);
    }

    public function startAdminSession(string $username): void
    {
        $this->writeCookie(
            self::ADMIN_COOKIE,
            $this->sign(['admin' => $username, 'iat' => time()]),
            self::ADMIN_MAX_AGE,
            '/admin',
            'Strict'
        );
    }

    public function adminUser(): ?string
    {
        $session = $this->verify($this->readCookie(self::ADMIN_COOKIE));
        if ($session === null || !isset($session['admin']) || !is_string($session['admin'])) {
            return null;
        }
        if (!isset($session['iat']) || !is_int($session['iat'])) {
            return null;
        }
        if (time() - $session['iat'] > self::ADMIN_MAX_AGE) {
            return null;
        }
        // 認証情報が変わった場合に既存セッションを無効化する
        $configured = $this->config->str('ADMIN_USERNAME');
        if ($configured !== '' && !hash_equals($configured, $session['admin'])) {
            return null;
        }
        return $session['admin'];
    }

    public function clearAdminSession(): void
    {
        unset($_COOKIE[self::ADMIN_COOKIE]);
        $this->writeCookie(self::ADMIN_COOKIE, '', 0, '/admin', 'Strict');
    }

    /** @param array<string, string> $value */
    public function putOAuthState(array $value): void
    {
        $value['iat'] = time();
        $this->writeCookie(self::OAUTH_COOKIE, $this->sign($value), self::OAUTH_MAX_AGE);
    }

    /**
     * OAuth stateを取り出して破棄する（ワンタイム）。
     *
     * @return array<string, mixed>|null
     */
    public function takeOAuthState(): ?array
    {
        $session = $this->verify($this->readCookie(self::OAUTH_COOKIE));
        $this->writeCookie(self::OAUTH_COOKIE, '', 0);
        unset($_COOKIE[self::OAUTH_COOKIE]);

        if ($session === null || !isset($session['state']) || !is_string($session['state'])) {
            return null;
        }
        if (!isset($session['iat']) || !is_int($session['iat'])) {
            return null;
        }
        if (time() - $session['iat'] > self::OAUTH_MAX_AGE) {
            return null;
        }
        return $session;
    }

    // -----------------------------------------------------------------
    // CSRF（double submit cookie）
    // -----------------------------------------------------------------

    public function csrfToken(): string
    {
        $existing = $this->verify($this->readCookie(self::CSRF_COOKIE));
        if ($existing !== null && isset($existing['t']) && is_string($existing['t'])) {
            return $existing['t'];
        }

        $token = self::randomToken(24);
        $signed = $this->sign(['t' => $token]);
        $this->writeCookie(self::CSRF_COOKIE, $signed, self::USER_MAX_AGE);
        // 同一リクエスト内で発行→検証できるようにする
        $_COOKIE[self::CSRF_COOKIE] = $signed;
        return $token;
    }

    public function verifyCsrf(?string $submitted): bool
    {
        if ($submitted === null || $submitted === '') {
            return false;
        }
        $stored = $this->verify($this->readCookie(self::CSRF_COOKIE));
        if ($stored === null || !isset($stored['t']) || !is_string($stored['t'])) {
            return false;
        }
        return hash_equals($stored['t'], $submitted);
    }

    /** ログイン後の戻り先として安全なパスだけを許可する（オープンリダイレクト防止）。 */
    public static function safeRedirectPath(?string $value, string $fallback = '/'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (!str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return $fallback;
        }
        return $value;
    }
}
