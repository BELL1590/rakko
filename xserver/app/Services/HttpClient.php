<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 外部API呼び出しの薄いラッパ（cURL）。
 * テストから差し替えられるよう interface にしている。
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, string $body, array $headers): array;

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $headers): array;
}
