<?php

declare(strict_types=1);

namespace App\Http;

/** リクエストの薄いラッパ。スーパーグローバルへの直接アクセスをここへ閉じ込める。 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, list<string>> $repeated 同名スカラーの繰り返し（slot_selected など）
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $repeated = []
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        // 末尾スラッシュは正規化（/admin/ → /admin）
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return new self($method, $path, $_GET, $_POST, self::parseRepeated());
    }

    /**
     * PHP は同名スカラーのPOST（`slot_selected` を複数チェック）を最後の1件に潰す。
     * Workers版と name 属性を揃えたままにするため、生ボディを自前で読んで
     * 「同名で送られた全ての値」を保持する。
     *
     * @return array<string, list<string>>
     */
    private static function parseRepeated(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return [];
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        return self::parseRepeatedBody($raw);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function parseRepeatedBody(string $raw): array
    {
        $out = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $key = $eq === false ? $pair : substr($pair, 0, $eq);
            $value = $eq === false ? '' : substr($pair, $eq + 1);
            $key = urldecode($key);
            $out[$key][] = urldecode($value);
        }
        return $out;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        return $default;
    }

    public function str(string $key, string $default = ''): string
    {
        return $this->input($key) ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->post);
    }

    /**
     * 同名の複数値（companion_12[] のような配列POST）を取り出す。
     *
     * @return list<string>
     */
    public function all(string $key): array
    {
        $value = $this->post[$key] ?? null;
        if (is_array($value)) {
            return array_values(array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $value));
        }
        if (isset($this->repeated[$key])) {
            return $this->repeated[$key];
        }
        if (is_string($value)) {
            return [$value];
        }
        return [];
    }

    /** @return array<string, mixed> */
    public function allPost(): array
    {
        return $this->post;
    }
}
