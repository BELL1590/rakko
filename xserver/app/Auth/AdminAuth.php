<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\Config;

/**
 * 管理画面の認証。
 * パスワードは password_hash() のハッシュのみを設定に置き、平文は保持しない。
 */
final class AdminAuth
{
    public function __construct(private Config $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config->adminConfigured();
    }

    public function verify(string $username, string $password): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $expectedUser = $this->config->str('ADMIN_USERNAME');
        $hash = $this->config->str('ADMIN_PASSWORD_HASH');

        // ユーザー名の比較もタイミング安全に行う
        $userOk = hash_equals($expectedUser, $username);
        $passOk = password_verify($password, $hash);

        return $userOk && $passOk;
    }
}
