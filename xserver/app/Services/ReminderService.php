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
        private Config $config,
        private BookingRepository $bookings,
        private NotificationRepository $notifications,
        private UserRepository $users,
        private LineMessenger $messenger
    ) {
    }

    /**
     * 通知を送信する。
     *
     * 複数予約を1通にまとめる場合は、全予約の送信権をall-or-nothingで確保する。
     * 1件でもclaimできなければ1通も送らず `already` とするため、並行Cronでも
     * 一括予約が「行きだけ」「帰りだけ」に分裂して送信されない。
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

        $claimed = $this->notifications->claimMany(
            $bookingIds,
            $type,
            self::MAX_ATTEMPTS,
            $now
        );
        if ($claimed === null || $claimed === []) {
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

        // LINE側の retry key 管理期間（24時間）を過ぎると、同じキーで送っても
        // 重複と判定されず2通目として配信される。ここまで届かなかった通知を
        // 無理に送り直すより、二重配信を避けて確定させるほうが害が小さい。
        if (self::retryKeyExpired($claimed, $now)) {
            $finishAll(
                'skipped',
                sprintf(
                    'LINE retry key expired (>%dh since first attempt); not resent to avoid duplicate delivery',
                    intdiv(LineMessenger::RETRY_KEY_TTL_SECONDS, 3600)
                )
            );
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
     * retry key が LINE 側の管理期間を過ぎていないか。
     *
     * 判定の基準は「初回送信試行からの経過時間」。
     * まだ1回も送っていない通知（attempt が1＝これが初回）は対象外で、
     * 再送のときだけ期限を見る。
     * 1件でも期限切れが混じっていれば、まとめ送信全体を送らない。
     *
     * @param array<int, array{token: string, retry_key: string, attempt: int, first_attempt_at: string}> $claimed
     */
    private static function retryKeyExpired(array $claimed, string $now): bool
    {
        $nowAt = Time::parseUtc($now);
        if ($nowAt === null) {
            return false;
        }

        foreach ($claimed as $claim) {
            if ($claim['attempt'] <= 1) {
                // 初回送信。retry key はまだLINEに登録されていない
                continue;
            }
            $firstAt = Time::parseUtc($claim['first_attempt_at']);
            if ($firstAt === null) {
                continue;
            }
            if ($nowAt->getTimestamp() - $firstAt->getTimestamp() > LineMessenger::RETRY_KEY_TTL_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * 1回のpushリクエストに付ける X-Line-Retry-Key を決める。
     *
     * 通知1件ならその通知のキーをそのまま使う。
     * 一括予約で複数通知を1通にまとめる場合は、対象キーの集合から決定的に導出する。
     * こうすると「同じ顔ぶれの再送＝同じキー（LINEが重複排除する）」
     * 「顔ぶれが変わった＝本文も変わるので別キー（届く）」が両立する。
     *
     * @param array<int, array{token: string, retry_key: string, attempt: int, first_attempt_at: string}> $claimed
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
            function (array $claimedIds) use ($bookings, $first): string {
                $target = array_values(array_filter(
                    $bookings,
                    static fn (array $b): bool => in_array((int) $b['id'], $claimedIds, true)
                ));
                // 一括予約でも詳細ページは先頭予約のURL1本にする。
                // 予約詳細は booking_group_id で同一グループをまとめて表示するため、
                // 先頭へのリンクからグループ全体を確認できる。
                $detailUrl = $target === []
                    ? null
                    : LineMessenger::bookingDetailUrl(
                        $this->config->baseUrl(),
                        (int) $target[0]['id']
                    );

                return LineMessenger::buildBookingConfirmationText(
                    (string) $first['page_title'],
                    (string) $first['page_type'],
                    $target,
                    $detailUrl
                );
            },
            $now
        );
    }

    /**
     * 失敗した予約完了通知をCronから再試行する。
     *
     * NotificationRepository が「同じ一括予約グループを安全に丸ごと再claimできる」
     * 候補だけを返す。ここでは同じ booking_group_id を1回にまとめ、初回と同じ
     * booking ID集合で sendBookingConfirmation() を呼ぶことで、本文と
     * X-Line-Retry-Key の決定性を維持する。
     * 実際のclaimは dispatch() -> claimMany() がトランザクション内で再検証する。
     *
     * @return array{checked: int, requested: int, failed: int, skipped: int, already: int}
     */
    public function processFailedBookingConfirmations(?string $now = null): array
    {
        $now ??= Time::nowUtc();
        $targets = $this->notifications->listRetryableBookingConfirmationTargets(
            $now,
            self::MAX_ATTEMPTS
        );

        /** @var array<string, list<int>> $groups */
        $groups = [];
        foreach ($targets as $target) {
            $bookingId = (int) $target['booking_id'];
            $groupId = $target['booking_group_id'] !== null
                ? (string) $target['booking_group_id']
                : null;
            $key = $groupId !== null ? 'group:' . $groupId : 'booking:' . $bookingId;

            if (isset($groups[$key])) {
                continue;
            }

            if ($groupId === null) {
                $groups[$key] = [$bookingId];
                continue;
            }

            $groupBookings = array_values(array_filter(
                $this->bookings->listByGroup($groupId),
                static fn (array $booking): bool => $booking['status'] === 'confirmed'
            ));
            $groups[$key] = array_map(
                static fn (array $booking): int => (int) $booking['id'],
                $groupBookings
            );
        }

        $summary = [
            'checked' => count($groups),
            'requested' => 0,
            'failed' => 0,
            'skipped' => 0,
            'already' => 0,
        ];

        foreach ($groups as $bookingIds) {
            if ($bookingIds === []) {
                $summary['skipped']++;
                continue;
            }
            $outcome = $this->sendBookingConfirmation($bookingIds, $now);
            $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
        }

        return $summary;
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
                (int) $target['party_size'],
                LineMessenger::bookingDetailUrl(
                    $this->config->baseUrl(),
                    (int) $target['booking_id']
                )
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
