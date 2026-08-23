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

    /**
     * LINE Login の `bot_prompt`（公式アカウント友だち追加の促し）。
     *
     * 任意機能なので既定は「送らない」。
     * リンク設定が未完了の状態でこれを送ると authorize が 400 になり、
     * 予約導線そのものが止まるため。
     *
     * 「LINE Loginチャネルと予約専用LINE公式アカウントのリンク」自体は
     * friendship/v1/status による友だち判定に必要な必須前提であり、
     * 任意なのは bot_prompt を送るかどうかだけ。
     *
     * 未設定・空文字は null（送らない）。
     * 'normal' / 'aggressive' のみ許可し、それ以外は設定ミスとして拒否する
     * （黙って無視すると、設定したつもりで効いていない状態に気づけない）。
     *
     * @return 'normal'|'aggressive'|null
     */
    public function lineLoginBotPrompt(): ?string
    {
        $value = strtolower(trim($this->str('LINE_LOGIN_BOT_PROMPT')));
        if ($value === '') {
            return null;
        }
        if ($value !== 'normal' && $value !== 'aggressive') {
            throw new ConfigError(sprintf(
                'LINE_LOGIN_BOT_PROMPT must be empty, "normal" or "aggressive" (got "%s").',
                $value
            ));
        }
        return $value;
    }

    /**
     * 予約専用LINE公式アカウントの友だち追加URL。未設定なら null。
     * https:// 以外は誤設定として扱い、リンクにしない。
     *
     * ここで案内するアカウントは、LINE Loginチャネルにリンクした
     * 予約専用LINE公式アカウントと同一でなければならない。
     * 別アカウントを案内すると、友だち追加しても
     * friendship/v1/status の friendFlag が true にならず予約できない。
     */
    public function lineFriendUrl(): ?string
    {
        $url = trim($this->str('LINE_FRIEND_URL'));
        if ($url === '' || !str_starts_with($url, 'https://')) {
            return null;
        }
        return $url;
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
