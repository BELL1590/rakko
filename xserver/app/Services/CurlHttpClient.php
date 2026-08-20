<?php

declare(strict_types=1);

namespace App\Services;

/** 本番用のcURL実装。 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(private int $timeoutSeconds = 10)
    {
    }

    public function post(string $url, string $body, array $headers): array
    {
        return $this->send($url, 'POST', $body, $headers);
    }

    public function get(string $url, array $headers): array
    {
        return $this->send($url, 'GET', null, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function send(string $url, string $method, ?string $body, array $headers): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => ''];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            // 証明書検証は必ず有効のまま
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'status' => $status,
            'body' => is_string($response) ? $response : '',
        ];
    }
}
