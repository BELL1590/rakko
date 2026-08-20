<?php

declare(strict_types=1);

namespace App\Http;

/**
 * 最小のルーター。`/reserve/{slug}` のような波括弧プレースホルダに対応する。
 * URLはWorkers版と同一のまま維持する（front controller で全リクエストを受ける）。
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'GET', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'POST', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(Request $request): ?Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            $params = $this->match($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }
            /** @var Response $response */
            $response = ($route['handler'])($request, $params);
            return $response;
        }
        return null;
    }

    /**
     * @return array<string, string>|null マッチしたパラメータ（マッチしなければ null）
     */
    private function match(string $pattern, string $path): ?array
    {
        $patternSegments = explode('/', trim($pattern, '/'));
        $pathSegments = explode('/', trim($path, '/'));

        if (count($patternSegments) !== count($pathSegments)) {
            return null;
        }

        $params = [];
        foreach ($patternSegments as $index => $segment) {
            $actual = rawurldecode($pathSegments[$index]);
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $params[substr($segment, 1, -1)] = $actual;
                continue;
            }
            if ($segment !== $actual) {
                return null;
            }
        }
        return $params;
    }
}
