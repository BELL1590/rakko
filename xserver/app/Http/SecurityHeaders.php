<?php

declare(strict_types=1);

namespace App\Http;

/**
 * 本番で送るセキュリティヘッダ（Workers版 secureHeaders 相当）。
 *
 * public/index.php に直書きするとテストから検証できないため、ここへ切り出す。
 * 値そのものは従来と同一。
 */
final class SecurityHeaders
{
    /** LINE Login の認可エンドポイント。form-action で明示的に許可する先。 */
    public const LINE_AUTHORIZE_ORIGIN = 'https://access.line.me';

    /** LINEプロフィール画像の配信元。 */
    public const LINE_PROFILE_ORIGIN = 'https://profile.line-scdn.net';

    /** LIFF SDK v2 の配信元。 */
    public const LIFF_SDK_ORIGIN = 'https://static.line-scdn.net';

    /** LIFF SDK が通信するLINEのAPIオリジン。 */
    public const LINE_API_ORIGIN = 'https://api.line.me';

    /**
     * Content-Security-Policy の値。
     *
     * - `style-src` に 'unsafe-inline' を含めるのは、現行Viewsが
     *   inline style を多用しており、外すと表示が崩れるため。
     *   Workers版と同じ見た目を保つ前提での判断。
     * - `form-action` に access.line.me を足すのは、`/auth/line/start` の
     *   POST から LINE 認可へ 302 で遷移するため。'self' だけだと
     *   ブラウザがこの遷移をブロックし、ログインできない。
     * - ワイルドカードは使わない。許可先は必要なオリジンのみ列挙する。
     */
    public static function contentSecurityPolicy(bool $withLiff = false): string
    {
        // LIFF SDK は外部スクリプトで、LINEのAPIへXHRする。
        // これを全ページで許可すると必要以上に緩くなるので、
        // LIFFブートストラップ画面のときだけ、必要なオリジンを足す。
        $scriptSrc = "script-src 'self' 'unsafe-inline'";
        $connectSrc = "connect-src 'self'";
        if ($withLiff) {
            $scriptSrc .= ' ' . self::LIFF_SDK_ORIGIN;
            $connectSrc .= ' ' . self::LINE_API_ORIGIN . ' ' . self::LINE_AUTHORIZE_ORIGIN;
        }

        return implode('; ', [
            "default-src 'self'",
            $scriptSrc,
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' " . self::LINE_PROFILE_ORIGIN . ' data:',
            $connectSrc,
            "form-action 'self' " . self::LINE_AUTHORIZE_ORIGIN,
            "frame-ancestors 'none'",
            "base-uri 'self'",
        ]);
    }

    /** このパスでLIFF用の緩和が必要か。 */
    public static function needsLiff(string $path): bool
    {
        return $path === '/liff' || str_starts_with($path, '/liff/reserve/');
    }

    /**
     * 送出するヘッダの一覧。
     *
     * @return array<string, string>
     */
    public static function all(bool $withLiff = false): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => self::contentSecurityPolicy($withLiff),
        ];
    }

    /** 実際にHTTPへ送出する。 */
    public static function send(bool $withLiff = false): void
    {
        foreach (self::all($withLiff) as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
