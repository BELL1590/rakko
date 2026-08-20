<?php

declare(strict_types=1);

namespace App\Support;

/** HTML生成の最小ユーティリティ。出力は必ずここを通してエスケープする。 */
final class Html
{
    public static function esc(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 条件付き出力。false のときは空文字。 */
    public static function when(mixed $condition, string $html): string
    {
        return $condition ? $html : '';
    }

    /** 属性値としてのHTML片（既にエスケープ済みの想定）。 */
    public static function attr(string $value): string
    {
        return self::esc($value);
    }
}
