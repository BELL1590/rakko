<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 時刻ユーティリティ。Workers版 src/lib/time.ts の移植。
 *
 * - DBはUTC（'YYYY-MM-DD HH:MM:SS'）で保存する
 * - 表示は必ず Asia/Tokyo へ変換する
 * - サーバーのタイムゾーン設定に依存しない（明示的に UTC / Asia/Tokyo を指定）
 */
final class Time
{
    public const JST = 'Asia/Tokyo';

    private const WEEKDAY_JA = ['日', '月', '火', '水', '木', '金', '土'];

    public static function utc(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }

    public static function jst(): \DateTimeZone
    {
        return new \DateTimeZone(self::JST);
    }

    /** 現在時刻（UTC, 'Y-m-d H:i:s'）。 */
    public static function nowUtc(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', self::utc());
        return $now->setTimezone(self::utc())->format('Y-m-d H:i:s');
    }

    /**
     * DBのUTC文字列（'Y-m-d H:i:s' / ISO8601 どちらでも）を DateTimeImmutable へ。
     * 不正値は null。
     */
    public static function parseUtc(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = str_replace('T', ' ', trim($value));
        $normalized = rtrim($normalized, 'Z');
        try {
            $date = new \DateTimeImmutable($normalized, self::utc());
        } catch (\Exception) {
            return null;
        }
        return $date;
    }

    private static function toJst(?string $utc): ?\DateTimeImmutable
    {
        $date = self::parseUtc($utc);
        return $date?->setTimezone(self::jst());
    }

    /** 例: `8月21日（金）20:00` */
    public static function formatJstLong(?string $utc): string
    {
        $jst = self::toJst($utc);
        if (!$jst) {
            return '';
        }
        $weekday = self::WEEKDAY_JA[(int) $jst->format('w')];
        return sprintf(
            '%d月%d日（%s）%s',
            (int) $jst->format('n'),
            (int) $jst->format('j'),
            $weekday,
            $jst->format('H:i')
        );
    }

    /** 例: `8/21 20:00` */
    public static function formatJstShort(?string $utc): string
    {
        $jst = self::toJst($utc);
        return $jst ? sprintf('%d/%d %s', (int) $jst->format('n'), (int) $jst->format('j'), $jst->format('H:i')) : '';
    }

    /** 例: `2026-08-21 20:00` */
    public static function formatJstIsoLike(?string $utc): string
    {
        $jst = self::toJst($utc);
        return $jst ? $jst->format('Y-m-d H:i') : '';
    }

    /** 例: `20:00` */
    public static function formatJstTime(?string $utc): string
    {
        $jst = self::toJst($utc);
        return $jst ? $jst->format('H:i') : '';
    }

    /** `<input type="datetime-local">` 用（JST表記）。 */
    public static function toJstDatetimeLocal(?string $utc): string
    {
        $jst = self::toJst($utc);
        return $jst ? $jst->format('Y-m-d\TH:i') : '';
    }

    /** JSTの `YYYY-MM-DDTHH:MM` をUTC保存値へ。不正な入力は null。 */
    public static function fromJstDatetimeLocal(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/', $value, $m)) {
            return null;
        }
        try {
            $jst = new \DateTimeImmutable(
                sprintf('%s-%s-%s %s:%s:00', $m[1], $m[2], $m[3], $m[4], $m[5]),
                self::jst()
            );
        } catch (\Exception) {
            return null;
        }
        return $jst->setTimezone(self::utc())->format('Y-m-d H:i:s');
    }

    /** JSTでの日付（'Y-m-d'）。管理ダッシュボードの「本日」判定に使う。 */
    public static function jstDate(?string $utc): string
    {
        $jst = self::toJst($utc);
        return $jst ? $jst->format('Y-m-d') : '';
    }
}
