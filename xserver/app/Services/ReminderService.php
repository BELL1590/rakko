<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BookingRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Support\Config;
use App\Support\Time;
use App\Support\Uuid;

/**
 * 通知（予約完了 / 開始前リマインド）の送信制御。
 * Workers版 src/services/reminder-service.ts の移植。
 *
 * - 二重送信は notifications の UNIQUE(booking_id, notification_type) で防ぐ
 * - Messaging API の成功は requested として扱い、到達保証とはみなさない
 * - 友だち未追加・ブロック等（4xx）は skipped、5xx は最大3回まで再試行
 * - リマインドは予約枠(reservation_slots.reminder_at)単位
 */
final class ReminderService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly Config $config,
        private readonly BookingRepository $bookings,
        private readonly NotificationRepository $notifications,
        private readonly UserRepository $users,
        private readonly LineMessenger $messenger
    ) {
    }

    /**
     * 通知を送信する。複数予約を1通にまとめる場合は、
     * 送信権を確保できた予約だけを対象にする。
     *
     * @param list<int> $bookingIds
     * @param callable(list<int>): string $buildText
     * @return 'requested'|'failed'|'skipped'|'already'
     */
    public function dispatch(
        array $bookingIds,
        string $type,
        ?string $lineUserId,
        callable $buildText,
        ?string $now = null
    ): string {
        $now ??= Time::nowUtc();

        // 送信権を取れた予約だけを対象にする（token を持つ＝このプロセスが送る）
        $claimed = [];
        foreach ($bookingIds as $bookingId) {
            $claim = $this->notifications->claim($bookingId, $type, self::MAX_ATTEMPTS, $now);
            if ($claim !== null) {
                $claimed[$bookingId] = $claim;
            }
        }
        if ($claimed === []) {
            return 'already';
        }

        $finishAll = function (string $status, ?string $error) use ($claimed, $type, $now): void {
            foreach ($claimed as $bookingId => $claim) {
                $this->notifications->finish($bookingId, $type, $claim['token'], $status, $error, $now);
            }
        };

        if ($lineUserId === null || $lineUserId === '') {
            $finishAll('skipped', 'no LINE user (admin proxy booking)');
            return 'skipped';
        }
        if (!$this->config->hasLineMessaging()) {
            $finishAll('skipped', 'messaging channel access token is not configured');
            return 'skipped';
        }

        $result = $this->messenger->push(
            $lineUserId,
            $buildText(array_keys($claimed)),
            self::requestRetryKey($claimed)
        );

        if ($result['ok'] === true) {
            // 409（LINE側で重複と判定）も「前回の送信が受理済み」なので requested 扱い
            $finishAll('requested', null);
            return 'requested';
        }

        // 4xx は再試行しても変わらないため skipped で確定させる
        $status = $result['retryable'] ? 'failed' : 'skipped';
        $finishAll($status, $result['error']);
        return $status;
    }

    /**
     * 1回のpushリクエストに付ける X-Line-Retry-Key を決める。
     *
     * 通知1件ならその通知のキーをそのまま使う。
     * 一括予約で複数通知を1通にまとめる場合は、対象キーの集合から決定的に導出する。
     * こうすると「同じ顔ぶれの再送＝同じキー（LINEが重複排除する）」
     * 「顔ぶれが変わった＝本文も変わるので別キー（届く）」が両立する。
     *
     * @param array<int, array{token: string, retry_key: string}> $claimed
     */
    private static function requestRetryKey(array $claimed): string
    {
        $keys = array_values(array_map(
            static fn (array $claim): string => $claim['retry_key'],
            $claimed
        ));

        return count($keys) === 1 ? $keys[0] : Uuid::derive($keys);
    }

    /**
     * 予約完了通知。一括予約（複数枠）は1通にまとめて送る。
     * 失敗しても確定済みの予約はロールバックしない。
     *
     * @param list<int> $bookingIds
     * @return 'requested'|'failed'|'skipped'|'already'
     */
    public function sendBookingConfirmation(array $bookingIds, ?string $now = null): string
    {
        $bookings = array_values(array_filter(
            $this->bookings->findMany($bookingIds),
            static fn (array $b): bool => $b['status'] === 'confirmed'
        ));
        if ($bookings === []) {
            return 'skipped';
        }

        $first = $bookings[0];
        // 管理者代理予約はLINE通知を送らない
        if ($first['source'] === 'admin' || $first['user_id'] === null) {
            return 'skipped';
        }

        $user = $this->users->findById((int) $first['user_id']);

        return $this->dispatch(
            array_map(static fn (array $b): int => (int) $b['id'], $bookings),
            'booking_confirmation',
            $user['line_user_id'] ?? null,
            static function (array $claimedIds) use ($bookings, $first): string {
                $target = array_values(array_filter(
                    $bookings,
                    static fn (array $b): bool => in_array((int) $b['id'], $claimedIds, true)
                ));
                return LineMessenger::buildBookingConfirmationText(
                    (string) $first['page_title'],
                    (string) $first['page_type'],
                    $target
                );
            },
            $now
        );
    }

    /**
     * reminder_at を過ぎた予約枠の確定予約へリマインドを送る。
     * Cron から5分おきに呼ばれる想定。送信単位は枠。
     *
     * @return array{checked: int, requested: int, failed: int, skipped: int, already: int}
     */
    public function processDueReminders(?string $now = null): array
    {
        $now ??= Time::nowUtc();
        $targets = $this->notifications->listDueReminderTargets($now, self::MAX_ATTEMPTS);

        $summary = [
            'checked' => count($targets),
            'requested' => 0,
            'failed' => 0,
            'skipped' => 0,
            'already' => 0,
        ];

        foreach ($targets as $target) {
            $text = LineMessenger::buildReminderText(
                (string) $target['page_title'],
                (string) $target['page_type'],
                (string) $target['slot_name'],
                (string) $target['start_at'],
                $target['origin'] !== null ? (string) $target['origin'] : null,
                $target['location'] !== null ? (string) $target['location'] : null,
                (int) $target['party_size']
            );

            $outcome = $this->dispatch(
                [(int) $target['booking_id']],
                'reminder',
                $target['line_user_id'] !== null ? (string) $target['line_user_id'] : null,
                static fn (): string => $text,
                $now
            );

            $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
        }

        return $summary;
    }
}
