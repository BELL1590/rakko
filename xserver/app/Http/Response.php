<?php

declare(strict_types=1);

namespace App\Http;

/**
 * レスポンス。テストから中身を検証できるよう値オブジェクトにしている。
 *
 * PHP 8.0 互換のため `readonly` を外している（8.1で追加された機能）。
 * これらのプロパティはコンストラクタでのみ設定し、以後書き換えない約束で扱う。
 * 型宣言は残しているため、誤った型の代入は 8.0 でも TypeError になる。
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = []
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            $status,
            (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public static function csv(string $body, string $fileName): self
    {
        return new self(200, $body, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $fileName),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** 実際にHTTPへ送出する。 */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $this->body;
    }
}
