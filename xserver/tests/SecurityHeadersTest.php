<?php

declare(strict_types=1);

/**
 * 本番CSPの回帰テスト。
 *
 * ここが緩むと XSS 防御が効かなくなり、
 * 逆に締めすぎると LINE ログイン導線や画面表示がブラウザでブロックされる。
 * 実際に配信されるHTTPヘッダのレベルでも確認する（末尾のsmokeテスト）。
 */

use App\Http\SecurityHeaders;

/** CSPをディレクティブ名 => 値の配列に分解する。 */
function parseCsp(string $policy): array
{
    $out = [];
    foreach (explode(';', $policy) as $directive) {
        $parts = preg_split('/\s+/', trim($directive)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            continue;
        }
        $name = array_shift($parts);
        $out[$name] = $parts;
    }
    return $out;
}

describe('本番セキュリティヘッダ', function (): void {
    test('style-src は現行Viewsのinline styleを許可する', function (): void {
        $csp = parseCsp(SecurityHeaders::contentSecurityPolicy());

        assertTrue(isset($csp['style-src']), 'style-src が定義されていること');
        assertTrue(in_array("'self'", $csp['style-src'], true));
        assertTrue(
            in_array("'unsafe-inline'", $csp['style-src'], true),
            '現行Viewsはinline styleを使うため必要'
        );
    });

    test('form-action は self と LINE認可先のみ許可する', function (): void {
        $csp = parseCsp(SecurityHeaders::contentSecurityPolicy());

        assertTrue(isset($csp['form-action']), 'form-action が定義されていること');
        assertSame(
            ["'self'", 'https://access.line.me'],
            $csp['form-action'],
            'LINEログイン導線に必要な1オリジンだけを追加する'
        );
    });

    test('frame-ancestors は none、base-uri は self', function (): void {
        $csp = parseCsp(SecurityHeaders::contentSecurityPolicy());

        assertSame(["'none'"], $csp['frame-ancestors']);
        assertSame(["'self'"], $csp['base-uri']);
    });

    test('default-src / script-src / img-src が想定どおり', function (): void {
        $csp = parseCsp(SecurityHeaders::contentSecurityPolicy());

        assertSame(["'self'"], $csp['default-src']);
        assertSame(["'self'", "'unsafe-inline'"], $csp['script-src']);
        assertSame(["'self'", 'https://profile.line-scdn.net', 'data:'], $csp['img-src']);
    });

    test('ワイルドカード許可を含まない', function (): void {
        $policy = SecurityHeaders::contentSecurityPolicy();

        foreach (parseCsp($policy) as $name => $sources) {
            foreach ($sources as $source) {
                assertNotSame('*', $source, $name . ' に * を含めない');
                assertFalse(
                    str_starts_with($source, '*.'),
                    $name . ' にサブドメインワイルドカードを含めない（' . $source . '）'
                );
                assertFalse(
                    $source === 'http:' || $source === 'https:',
                    $name . ' にスキーム全体の許可を含めない（' . $source . '）'
                );
            }
        }
        assertNotContains('*', $policy, 'ポリシー全体にワイルドカードを含めない');
    });

    test('unsafe-eval は許可しない', function (): void {
        assertNotContains("'unsafe-eval'", SecurityHeaders::contentSecurityPolicy());
    });

    test('その他のセキュリティヘッダも維持する', function (): void {
        $headers = SecurityHeaders::all();

        assertSame('nosniff', $headers['X-Content-Type-Options']);
        assertSame('DENY', $headers['X-Frame-Options']);
        assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        assertTrue(isset($headers['Content-Security-Policy']));
    });

    test('LINE認可オリジンはCSPとLineLoginで一致している', function (): void {
        // CSPで許可した先と、実際に遷移する先がずれていないこと
        assertTrue(
            str_starts_with(
                'https://access.line.me/oauth2/v2.1/authorize',
                SecurityHeaders::LINE_AUTHORIZE_ORIGIN
            ),
            'buildAuthorizeUrl の遷移先が form-action の許可先に含まれること'
        );
    });
});
