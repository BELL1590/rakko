<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Db;
use App\Support\Time;
use App\Support\Uuid;

/**
 * 通知ログ。
 * UNIQUE(booking_id, notification_type) により1予約1通知に限定し、
 * さらに `sending` 状態への原子的な遷移で同時送信を1プロセスに絞る。
 */
final class NotificationRepository
{
    /**
     * `sending` のまま放置された通知を再取得できるようになるまでの秒数。
     * 送信中にプロセスが落ちると `sending` で残るため、
     * これを過ぎたものは「落ちた」とみなして再試行の対象に戻す。
     * push の HTTP タイムアウト（10秒）より十分に長くとる。
     */
    public const STALE_SENDING_SECONDS = 600;

    public function __construct(private Db $db)
    {
    }

    /**
     * 通知1件の送信権を確保する。
     * 複数件送信と同じ all-or-nothing 実装を使う。
     *
     * @return array{token: string, retry_key: string, attempt: int, first_attempt_at: string}|null
     */
    public function claim(int $bookingId, string $type, int $maxAttempts, ?string $now = null): ?array
    {
        $claims = $this->claimMany([$bookingId], $type, $maxAttempts, $now);
        return $claims[$bookingId] ?? null;
    }

    /**
     * 複数通知を all-or-nothing で原子的に claim する。
     *
     * 一括予約を1通にまとめて送る場合、予約ごとに順番に claim すると
     * 2つのCronが同時実行された際に「Aが行き、Bが帰り」を別々にclaimできる。
     * それを防ぐため、全通知行を1トランザクションで booking_id 昇順に FOR UPDATE し、
     * **全件がclaim可能な場合だけ**全件を sending へ進める。
     * 1件でも requested/skipped/上限到達/非stale sending なら0件claimする。
     *
     * 通知行がまだ無い予約は claim トランザクションの前に INSERT IGNORE で作る。
     * 行作成自体は冪等で送信権ではないためautocommitとし、同一未作成行へ多数の
     * プロセスが同時INSERTしてトランザクション同士がdeadlockすることを避ける。
     * 送信権のall-or-nothing判定と更新だけをFOR UPDATEのトランザクション内で行う。
     *
     * @param list<int> $bookingIds
     * @return array<int, array{token: string, retry_key: string, attempt: int, first_attempt_at: string}>|null
     *         全件claim成功なら booking_id => claim、1件でも不可なら null
     */
    public function claimMany(
        array $bookingIds,
        string $type,
        int $maxAttempts,
        ?string $now = null
    ): ?array {
        $now ??= Time::nowUtc();
        $ids = array_values(array_unique(array_map('intval', $bookingIds)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return [];
        }

        $staleBefore = $this->staleCutoff($now);

        // 行作成は送信権の取得ではない。autocommitで先に確定することで、
        // 多数プロセスが同じ未作成行を同一トランザクション内でINSERTして
        // deadlockすることを避ける。途中で落ちてもpending行が残るだけで安全。
        foreach ($ids as $bookingId) {
            $this->db->run(
                'INSERT IGNORE INTO notifications
                   (booking_id, notification_type, status, attempt_count,
                    line_retry_key, created_at, updated_at)
                 VALUES (?, ?, \'pending\', 0, ?, ?, ?)',
                [$bookingId, $type, Uuid::v4(), $now, $now]
            );
        }

        return $this->db->transaction(function (Db $db) use ($ids, $type, $maxAttempts, $now, $staleBefore): ?array {
            // ここからが送信権。全対象行を同じ順序でロックして全件可否を一括判定する。
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = $db->all(
                'SELECT booking_id, status, attempt_count, line_retry_key, created_at, updated_at
                   FROM notifications
                  WHERE notification_type = ?
                    AND booking_id IN (' . $placeholders . ')
                  ORDER BY booking_id ASC
                  FOR UPDATE',
                array_merge([$type], $ids)
            );

            if (count($rows) !== count($ids)) {
                return null;
            }

            /** @var array<int, array<string, mixed>> $byId */
            $byId = [];
            foreach ($rows as $row) {
                $byId[(int) $row['booking_id']] = $row;
            }

            foreach ($ids as $bookingId) {
                $row = $byId[$bookingId] ?? null;
                if ($row === null || (int) $row['attempt_count'] >= $maxAttempts) {
                    return null;
                }

                $status = (string) $row['status'];
                $retryable = in_array($status, ['pending', 'failed'], true)
                    || ($status === 'sending' && (string) $row['updated_at'] < $staleBefore);
                if (!$retryable) {
                    return null;
                }
            }

            $claims = [];
            foreach ($ids as $bookingId) {
                $row = $byId[$bookingId];
                $token = bin2hex(random_bytes(16));
                $newRetryKey = Uuid::v4();
                $storedRetryKey = is_string($row['line_retry_key'] ?? null)
                    && trim((string) $row['line_retry_key']) !== ''
                    ? (string) $row['line_retry_key']
                    : $newRetryKey;

                $db->run(
                    'UPDATE notifications
                        SET status = \'sending\',
                            claim_token = ?,
                            line_retry_key = COALESCE(NULLIF(line_retry_key, \'\'), ?),
                            attempt_count = attempt_count + 1,
                            updated_at = ?
                      WHERE booking_id = ? AND notification_type = ?',
                    [$token, $newRetryKey, $now, $bookingId, $type]
                );

                $claims[$bookingId] = [
                    'token' => $token,
                    'retry_key' => $storedRetryKey,
                    'attempt' => (int) $row['attempt_count'] + 1,
                    'first_attempt_at' => (string) $row['created_at'],
                ];
            }

            return $claims;
        });
    }

    /**
     * 送信結果を確定する。
     * `sending` かつ claim_token が一致する場合だけ遷移させ、
     * 送信権を持たないプロセスが状態を書き換えられないようにする。
     * （放置された sending を別プロセスが再取得したあと、
     *   元のプロセスが目を覚まして finish しても無視される）
     */
    public function finish(
        int $bookingId,
        string $type,
        string $token,
        string $status,
        ?string $lastError,
        ?string $now = null
    ): void {
        $now ??= Time::nowUtc();
        $this->db->run(
            'UPDATE notifications
                SET status = ?,
                    claim_token = NULL,
                    last_error = ?,
                    requested_at = CASE WHEN ? = \'requested\' THEN ? ELSE requested_at END,
                    updated_at = ?
              WHERE booking_id = ? AND notification_type = ?
                AND status = \'sending\'
                AND claim_token = ?',
            [$status, $lastError, $status, $now, $now, $bookingId, $type, $token]
        );
    }

    /** `sending` を「落ちた」とみなす境界時刻。 */
    private function staleCutoff(string $now): string
    {
        $parsed = Time::parseUtc($now);
        $base = $parsed?->getTimestamp() ?? time();
        return gmdate('Y-m-d H:i:s', $base - self::STALE_SENDING_SECONDS);
    }

    /** @return array<string, mixed>|null */
    public function find(int $bookingId, string $type): ?array
    {
        return $this->db->first(
            'SELECT * FROM notifications WHERE booking_id = ? AND notification_type = ?',
            [$bookingId, $type]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listForSlot(int $slotId): array
    {
        return $this->db->all(
            'SELECT n.* FROM notifications n
               JOIN bookings b ON b.id = n.booking_id
              WHERE b.reservation_slot_id = ?
              ORDER BY n.id DESC',
            [$slotId]
        );
    }

    /**
     * 予約完了通知の再試行候補。
     *
     * 通知行が無い予約も候補に含める。これにより、予約COMMIT後に
     * 初回通知処理が通知行作成前で落ちてもCronから復旧できる。
     * 一括予約はグループ全件がconfirmedかつ送信可能な状態のときだけ候補にする。
     * 実際の排他は claimMany() が FOR UPDATE 下で再検証するため、
     * 候補取得後に競合しても部分送信にはならない。
     *
     * @return list<array{booking_id: int|string, booking_group_id: ?string}>
     */
    public function listRetryableBookingConfirmationTargets(string $now, int $maxAttempts): array
    {
        $staleBefore = $this->staleCutoff($now);

        return $this->db->all(
            'SELECT b.id AS booking_id, b.booking_group_id
               FROM bookings b
               LEFT JOIN notifications n
                 ON n.booking_id = b.id AND n.notification_type = \'booking_confirmation\'
              WHERE b.status = \'confirmed\'
                AND b.source <> \'admin\'
                AND b.user_id IS NOT NULL
                AND (
                    n.id IS NULL
                    OR (
                        n.attempt_count < ?
                        AND (n.status IN (\'pending\', \'failed\')
                             OR (n.status = \'sending\' AND n.updated_at < ?))
                    )
                )
                AND (
                    b.booking_group_id IS NULL
                    OR (
                        NOT EXISTS (
                            SELECT 1
                              FROM bookings bg
                             WHERE bg.booking_group_id = b.booking_group_id
                               AND (
                                   bg.status <> \'confirmed\'
                                   OR bg.source = \'admin\'
                                   OR bg.user_id IS NULL
                               )
                        )
                        AND NOT EXISTS (
                            SELECT 1
                              FROM bookings bg
                              LEFT JOIN notifications ng
                                ON ng.booking_id = bg.id
                               AND ng.notification_type = \'booking_confirmation\'
                             WHERE bg.booking_group_id = b.booking_group_id
                               AND ng.id IS NOT NULL
                               AND (
                                   ng.status IN (\'requested\', \'skipped\')
                                   OR ng.attempt_count >= ?
                                   OR (ng.status = \'sending\' AND ng.updated_at >= ?)
                               )
                        )
                    )
                )
              ORDER BY COALESCE(b.booking_group_id, CONCAT(\'single:\', b.id)), b.id',
            [$maxAttempts, $staleBefore, $maxAttempts, $staleBefore]
        );
    }

    /**
     * リマインド送信対象。
     * - reminder_at を過ぎた枠（NULLなら送らない）
     * - 開始前（開始済みの枠には送らない）
     * - confirmed の予約のみ
     * - 未送信、または failed かつ試行回数が上限未満
     * - 送信中（sending）は対象外。ただし長時間放置されたものは
     *   プロセスが落ちたとみなして再度対象にする
     *
     * @return list<array<string, mixed>>
     */
    public function listDueReminderTargets(string $now, int $maxAttempts): array
    {
        return $this->db->all(
            'SELECT b.id AS booking_id, b.reservation_slot_id, b.party_size, b.representative_name,
                    u.line_user_id, u.is_line_friend,
                    s.name AS slot_name, s.origin, s.destination, s.location, s.start_at,
                    p.title AS page_title, p.page_type
               FROM bookings b
               JOIN reservation_slots s ON s.id = b.reservation_slot_id
               JOIN reservation_pages p ON p.id = s.reservation_page_id
               LEFT JOIN users u ON u.id = b.user_id
               LEFT JOIN notifications n
                 ON n.booking_id = b.id AND n.notification_type = \'reminder\'
              WHERE b.status = \'confirmed\'
                AND s.reminder_at IS NOT NULL
                AND s.reminder_at <= ?
                AND s.start_at > ?
                AND (n.id IS NULL
                     OR (n.attempt_count < ?
                         AND (n.status IN (\'pending\', \'failed\')
                              OR (n.status = \'sending\' AND n.updated_at < ?))))
              ORDER BY b.id ASC',
            [$now, $now, $maxAttempts, $this->staleCutoff($now)]
        );
    }
}
