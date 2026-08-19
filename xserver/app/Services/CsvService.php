<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Time;

/**
 * 名簿CSVの生成。Workers版 src/services/csv.ts の移植。
 *
 * - UTF-8 BOM 付き（Excelで日本語が文字化けしない）
 * - CSVインジェクション対策: 数式として解釈されうる先頭文字を無害化する
 */
final class CsvService
{
    public const BOM = "\xEF\xBB\xBF";

    /**
     * Excel/Sheets が数式として評価しうる文字で始まるセルを無害化する。
     * 例: `=1+1`, `+HYPERLINK(...)`, `-2+3`, `@SUM(...)`、および先頭のタブ/CR。
     */
    public static function sanitizeCell(string $value): string
    {
        if ($value === '') {
            return '';
        }
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    public static function cell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;
        return '"' . str_replace('"', '""', self::sanitizeCell($text)) . '"';
    }

    /** @param list<mixed> $cells */
    public static function row(array $cells): string
    {
        return implode(',', array_map([self::class, 'cell'], $cells));
    }

    /**
     * 名簿CSVを組み立てる。予約枠単位・ページ全体のどちらでも同じ列構成。
     *
     * @param list<array<string, mixed>> $bookings
     */
    public static function buildRoster(array $bookings): string
    {
        $companionColumns = 3;
        foreach ($bookings as $booking) {
            $companionColumns = max($companionColumns, count(self::companions($booking)));
        }

        $header = [
            '予約番号', '予約ページ名', '予約枠名', '日付', '開始時刻',
            '代表者氏名', '電話番号', '予約人数',
        ];
        for ($i = 1; $i <= $companionColumns; $i++) {
            $header[] = '同行者' . $i;
        }
        $header[] = '受付済人数';
        $header[] = '予約状態';
        $header[] = '予約元';
        $header[] = '予約日時';
        $header[] = 'キャンセル日時';

        $lines = [self::row($header)];

        foreach ($bookings as $booking) {
            $companions = self::companions($booking);
            $startAt = (string) $booking['start_at'];
            $short = Time::formatJstShort($startAt);
            $date = explode(' ', $short)[0] ?? '';

            $cells = [
                (int) $booking['id'],
                (string) $booking['page_title'],
                (string) $booking['slot_name'],
                $date,
                Time::formatJstTime($startAt),
                (string) $booking['representative_name'],
                (string) $booking['phone'],
                (int) $booking['party_size'],
            ];
            for ($i = 0; $i < $companionColumns; $i++) {
                $cells[] = $companions[$i] ?? '';
            }
            $cells[] = (int) $booking['checked_in_count'];
            $cells[] = $booking['status'] === 'confirmed' ? '予約済み' : 'キャンセル';
            $cells[] = $booking['source'] === 'admin' ? '管理者代理' : 'LINE';
            $cells[] = Time::formatJstIsoLike((string) $booking['created_at']);
            $cells[] = $booking['cancelled_at'] !== null
                ? Time::formatJstIsoLike((string) $booking['cancelled_at'])
                : '';

            $lines[] = self::row($cells);
        }

        return self::BOM . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param array<string, mixed> $booking
     * @return list<string>
     */
    public static function companions(array $booking): array
    {
        $decoded = json_decode((string) ($booking['companion_names_json'] ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $decoded),
            static fn (string $v): bool => $v !== ''
        ));
    }

    /** ダウンロード用のファイル名（ASCIIに寄せる）。 */
    public static function fileName(string $prefix, int $id): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $prefix) ?: 'roster';
        return sprintf('%s-%d.csv', $safe, $id);
    }
}
