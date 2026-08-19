<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Db;
use App\Support\Time;

/**
 * 通知ログ。
 * UNIQUE(booking_id, notification_type) により二重送信をDB制約で防ぐ。
 */
final class NotificationRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * 通知の送信権を確保する。
     *
     * @return bool true なら送信してよい（このリクエストが権利を取った）
     */
    public function claim(int $bookingId, string $type, int $maxAttempts, ?string $now = null): bool
    {
        $now ??= Time::nowUtc();

        // 既にあれば無視される（UNIQUE制約）
        $this->db->run(
            'INSERT IGNORE INTO notifications
               (booking_id, notification_type, status, attempt_count, created_at, updated_at)
             VALUES (?, ?, \'pending\', 0, ?, ?)',
            [$bookingId, $type, $now, $now]
        );

        $changed = $this->db->run(
            'UPDATE notifications
                SET attempt_count = attempt_count + 1, updated_at = ?
              WHERE booking_id = ? AND notification_type = ?
                AND status IN (\'pending\', \'failed\')
                AND attempt_count < ?',
            [$now, $bookingId, $type, $maxAttempts]
        );

        return $changed > 0;
    }

    public function finish(
        int $bookingId,
        string $type,
        string $status,
        ?string $lastError,
        ?string $now = null
    ): void {
        $now ??= Time::nowUtc();
        $this->db->run(
            'UPDATE notifications
                SET status = ?,
                    last_error = ?,
                    requested_at = CASE WHEN ? = \'requested\' THEN ? ELSE requested_at END,
                    updated_at = ?
              WHERE booking_id = ? AND notification_type = ?',
            [$status, $lastError, $status, $now, $now, $bookingId, $type]
        );
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
     * リマインド送信対象。
     * - reminder_at を過ぎた枠（NULLなら送らない）
     * - 開始前（開始済みの枠には送らない）
     * - confirmed の予約のみ
     * - 未送信、または failed かつ試行回数が上限未満
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
                AND (n.id IS NULL OR (n.status = \'failed\' AND n.attempt_count < ?))
              ORDER BY b.id ASC',
            [$now, $now, $maxAttempts]
        );
    }
}
