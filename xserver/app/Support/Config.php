<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 設定の読み込みと環境判定。
 * Workers版 src/env.ts と同じ役割（DEMO_MODE の安全弁を含む）。
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * config.local.php を読む。無ければ config.example.php にフォールバックし、
     * 環境変数（RAKKO_*）で上書きできるようにする（CI/テスト用）。
     */
    public static function load(?string $dir = null): self
    {
        $dir ??= \dirname(__DIR__, 2) . '/config';
        $local = $dir . '/config.local.php';
        $example = $dir . '/config.example.php';

        $values = [];
        if (is_file($local)) {
            /** @var array<string, mixed> $values */
            $values = require $local;
        } elseif (is_file($example)) {
            /** @var array<string, mixed> $values */
            $values = require $example;
        }

        foreach ($values as $key => $_) {
            $env = getenv('RAKKO_' . $key);
            if ($env !== false) {
                $values[$key] = $env;
            }
        }

        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? $default;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }

    public function isProduction(): bool
    {
        return strtolower($this->str('APP_ENV', 'development')) === 'production';
    }

    /** production では DEMO_MODE を無効にする。 */
    public function isDemoMode(): bool
    {
        return $this->bool('DEMO_MODE') && !$this->isProduction();
    }

    /**
     * production で DEMO_MODE が有効なら起動を許さない。
     * Workers版 assertDemoModeSafety() と同じ安全弁。
     *
     * @throws ConfigError
     */
    public function assertDemoModeSafety(): void
    {
        if ($this->isProduction() && $this->bool('DEMO_MODE')) {
            throw new ConfigError(
                'DEMO_MODE must be disabled in production. Set DEMO_MODE=false and redeploy.'
            );
        }
    }

    /** 署名鍵の最低長。短い鍵はCookie偽造の総当たりを現実的にするため許さない。 */
    public const MIN_SESSION_SECRET_LENGTH = 32;

    /** 署名鍵。production では未設定・短すぎる値を許さない。 */
    public function sessionSecret(): string
    {
        $secret = $this->str('SESSION_SECRET');

        if ($this->isProduction()) {
            if ($secret === '') {
                throw new ConfigError('SESSION_SECRET is required in production.');
            }
            if (strlen($secret) < self::MIN_SESSION_SECRET_LENGTH) {
                throw new ConfigError(sprintf(
                    'SESSION_SECRET must be at least %d characters in production.',
                    self::MIN_SESSION_SECRET_LENGTH
                ));
            }
            return $secret;
        }

        return $secret !== '' ? $secret : 'dev-only-insecure-session-secret';
    }

    /** 末尾スラッシュなしの公開URL。 */
    public function baseUrl(): string
    {
        $url = rtrim($this->str('APP_URL'), '/');
        if ($url !== '') {
            return $url;
        }
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return ($https ? 'https://' : 'http://') . $host;
    }

    public function hasLineLogin(): bool
    {
        return $this->str('LINE_LOGIN_CHANNEL_ID') !== ''
            && $this->str('LINE_LOGIN_CHANNEL_SECRET') !== '';
    }

    public function hasLineMessaging(): bool
    {
        return $this->str('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN') !== '';
    }

    public function adminConfigured(): bool
    {
        return $this->str('ADMIN_USERNAME') !== '' && $this->str('ADMIN_PASSWORD_HASH') !== '';
    }
}
