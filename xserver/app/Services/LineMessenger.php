<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\Time;
use App\Support\Uuid;

/**
 * LINE Messaging API（push message）と通知本文の組み立て。
 * Workers版 src/services/line-message.ts の移植。
 */
final class LineMessenger
{
    private const PUSH_ENDPOINT = 'https://api.line.me/v2/bot/message/push';

    /**
     * LINE Platform が retry key を保持する期間は24時間。
     * それを過ぎると同じキーでも重複と判定されず、そのまま2通目として配信される。
     * 判定の境目ぎりぎりを攻めても得るものが無いため、1時間の安全マージンを引く。
     */
    public const RETRY_KEY_TTL_SECONDS = 23 * 60 * 60;

    public function __construct(
        private Config $config,
        private HttpClient $http
    ) {
    }

    /**
     * push message を送る。
     *
     * 注意: HTTP 200 は「LINEプラットフォームが受け付けた」だけであり、
     * ユーザーへ届いたことの保証ではない。呼び出し側は requested として記録する。
     *
     * $retryKey（X-Line-Retry-Key）は必須。初回送信から必ず付ける。
     * LINEが受理した直後にこちらのプロセスが落ちてDBへ requested を書けなくても、
     * 同じキーで再送すればLINE側が重複と判定して 409 を返すため、
     * ネットワーク境界を越えて二重配信を防げる。
     *
     * キーが不正な形式なら、ヘッダ無しで送る（＝重複防止が効かない状態で送る）
     * のではなく、Messaging API を呼ぶ前に失敗させる。
     *
     * @param string $retryKey UUID形式
     * @return array{ok: true, deduplicated: bool}|array{ok: false, retryable: bool, error: string}
     */
    public function push(string $to, string $text, string $retryKey): array
    {
        if (!Uuid::isValid($retryKey)) {
            // 二重配信のリスクを負ってまで送る理由が無いので、ここで打ち切る。
            // 再試行しても直らない実装バグなので retryable=false。
            return [
                'ok' => false,
                'retryable' => false,
                'error' => 'invalid X-Line-Retry-Key (must be a UUID); push was not sent',
            ];
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->config->str('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'),
            'X-Line-Retry-Key' => $retryKey,
        ];

        $response = $this->http->post(
            self::PUSH_ENDPOINT,
            (string) json_encode([
                'to' => $to,
                'messages' => [['type' => 'text', 'text' => $text]],
            ], JSON_UNESCAPED_UNICODE),
            $headers
        );

        $status = $response['status'];
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'deduplicated' => false];
        }

        // 409 = 同じ retry key のリクエストを既に受理済み。
        // 前回の送信は成功していたということなので、再送せず成功として扱う。
        if ($status === 409) {
            return ['ok' => true, 'deduplicated' => true];
        }

        // 本文にトークンは含めない。ステータスとAPIのmessageのみ保持する。
        $message = '';
        $decoded = json_decode($response['body'], true);
        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
            $message = $decoded['message'];
        }

        // 4xx はリトライしても直らない（友だち未追加・ブロック等を含む）
        $retryable = $status >= 500 || $status === 429 || $status === 0;

        return [
            'ok' => false,
            'retryable' => $retryable,
            'error' => mb_substr(
                sprintf('HTTP %d%s', $status, $message !== '' ? ': ' . $message : ''),
                0,
                300
            ),
        ];
    }

    private static function icon(string $pageType): string
    {
        return $pageType === 'bus' ? '🚌' : '🔔';
    }

    /** 「池袋西口 → 草加健康センター」または「会場：〇〇」の1行。 */
    public static function routeLine(?string $origin, ?string $destination, ?string $location): string
    {
        if ($origin !== null && $origin !== '' && $destination !== null && $destination !== '') {
            return $origin . ' → ' . $destination;
        }
        if ($location !== null && $location !== '') {
            return '会場：' . $location;
        }
        if ($origin !== null && $origin !== '') {
            return '集合：' . $origin;
        }
        return '';
    }

    /**
     * 予約完了通知の本文。一括予約のときは1通に全枠をまとめる。
     *
     * @param list<array<string, mixed>> $bookings
     */
    public static function buildBookingConfirmationText(
        string $pageTitle,
        string $pageType,
        array $bookings
    ): string {
        $lines = [self::icon($pageType) . ' ' . $pageTitle, '予約が完了しました。'];

        foreach ($bookings as $booking) {
            $route = self::routeLine(
                $booking['origin'] ?? null,
                $booking['destination'] ?? null,
                $booking['location'] ?? null
            );
            $lines[] = '';
            $lines[] = '【' . (string) $booking['slot_name'] . '】';
            $lines[] = Time::formatJstLong((string) $booking['start_at']);
            if ($route !== '') {
                $lines[] = $route;
            }
            $lines[] = '予約人数：' . (int) $booking['party_size'] . '名';
        }

        $lines[] = '';
        $lines[] = '予約内容は下記ページから確認できます。';
        return implode("\n", $lines);
    }

    /** 開始前リマインドの本文。 */
    public static function buildReminderText(
        string $pageTitle,
        string $pageType,
        string $slotName,
        string $startAt,
        ?string $origin,
        ?string $location,
        int $partySize
    ): string {
        $time = Time::formatJstTime($startAt);
        $lines = [sprintf('%s %s「%s」のお知らせ', self::icon($pageType), $pageTitle, $slotName)];

        if ($origin !== null && $origin !== '') {
            $lines[] = sprintf('本日%s %s出発です。', $time, $origin);
            $lines[] = '出発15分前までに集合場所へお越しください。';
        } else {
            $lines[] = sprintf('本日%s 開始です。', $time);
            if ($location !== null && $location !== '') {
                $lines[] = '会場：' . $location;
            }
            $lines[] = 'お時間に余裕をもってお越しください。';
        }

        $lines[] = '';
        $lines[] = '予約人数：' . $partySize . '名';
        return implode("\n", $lines);
    }
}
