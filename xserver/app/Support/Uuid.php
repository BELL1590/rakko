<?php

declare(strict_types=1);

namespace App\Support;

/**
 * UUID の生成。
 * booking_group_id と LINE の X-Line-Retry-Key で共用する
 * （LINE Messaging API は retry key に UUID 形式を要求する）。
 */
final class Uuid
{
    /** ランダムな UUID v4。 */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::format($bytes);
    }

    /**
     * 与えられた要素から決定的に導出する UUID（v5 相当のビット設定）。
     *
     * 同じ要素の集合からは常に同じ UUID が得られるため、
     * 「同じ内容の再送には同じ retry key、内容が変われば別の key」を
     * 状態を増やさずに満たせる。
     *
     * @param list<string> $parts
     */
    public static function derive(array $parts): string
    {
        sort($parts);
        $bytes = hash('sha256', implode("\0", $parts), true);
        $bytes = substr($bytes, 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::format($bytes);
    }

    /** UUID 形式（8-4-4-4-12）に整形する。 */
    private static function format(string $bytes): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** LINE へ送る前の形式チェック。 */
    public static function isValid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $value
        ) === 1;
    }
}
