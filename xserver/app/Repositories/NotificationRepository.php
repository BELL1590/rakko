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
     * 通知の送信権を確保する。
     *
     * `pending` / `failed`（および放置された `sending`）から `sending` への
     * 遷移を単一のUPDATEで行い、実際に行を更新できたプロセスだけが送信する。
     * MySQL はUPDATEで行ロックを取ったあとにWHERE句を再評価するため、
     * 同時に走った2つ目のUPDATEは `sending` になった行に一致せず 0 行となる。
     *
     * `claim_token` は claim のたびに変わる（送信権の排他用）が、
     * `line_retry_key` は一度発行したら保持し続ける
     * （同じ通知の再送だと LINE 側に伝えるため。COALESCE で上書きしない）。
     *
     * @return array{token: string, retry_key: string, attempt: int, first_attempt_at: string}|null
     *         送信権を取れたら claim_token・LINE retry key・試行回数・初回試行時刻、
     *         取れなければ null
     */
    public function claim(int $bookingId, string $type, int $maxAttempts, ?string $now = null): ?array
    {
        $now ??= Time::nowUtc();
        $staleBefore = $this->staleCutoff($now);
        $token = bin2hex(random_bytes(16));
        $newRetryKey = Uuid::v4();

        // 既にあれば無視される（UNIQUE制約）
        $this->db->run(
            'INSERT IGNORE INTO notifications
               (booking_id, notification_type, status, attempt_count,
                line_retry_key, created_at, updated_at)
             VALUES (?, ?, \'pending\', 0, ?, ?, ?)',
            [$bookingId, $type, $newRetryKey, $now, $now]
        );

        $changed = $this->db->run(
            'UPDATE notifications
                SET status = \'sending\',
                    claim_token = ?,
                    line_retry_key = COALESCE(line_retry_key, ?),
                    attempt_count = attempt_count + 1,
                    updated_at = ?
              WHERE booking_id = ? AND notification_type = ?
                AND attempt_count < ?
                AND (status IN (\'pending\', \'failed\')
                     OR (status = \'sending\' AND updated_at < ?))',
            [$token, $newRetryKey, $now, $bookingId, $type, $maxAttempts, $staleBefore]
        );

        if ($changed === 0) {
            return null;
        }

        // 実際に保存されている値を読み直す
        // （retry key は初回なら今生成したもの、再試行時は最初に発行したもの）
        $row = $this->db->first(
            'SELECT line_retry_key, attempt_count, created_at FROM notifications
              WHERE booking_id = ? AND notification_type = ? AND claim_token = ?',
            [$bookingId, $type, $token]
        );

        return [
            'token' => $token,
            'retry_key' => is_string($row['line_retry_key'] ?? null) ? $row['line_retry_key'] : $newRetryKey,
            'attempt' => (int) ($row['attempt_count'] ?? 1),
            // 行の作成＝1回目のclaim（＝初回送信試行）と同一のリクエスト内。
            // retry key の有効期限判定の基準に使う。
            'first_attempt_at' => (string) ($row['created_at'] ?? $now),
        ];
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
     * 一括予約は初回送信時に複数予約から1つの X-Line-Retry-Key を決定的に
     * 導出しているため、グループの一部だけを再送すると retry key と本文が変わり、
     * LINE側の重複排除が効かなくなる。そのためグループについては以下を満たす場合だけ返す。
     *
     * - 全予約が confirmed
     * - requested / skipped が1件もない
     * - 試行上限到達が1件もない
     * - 現在送信中（staleではない sending）が1件もない
     *
     * `pending` / `failed` / stale `sending` は claim() が安全に再取得できる。
     * 管理者代理予約・ユーザー紐付けなし・キャンセル済みは対象外。
     *
     * @return list<array{booking_id: int|string, booking_group_id: ?string}>
     */
    public function listRetryableBookingConfirmationTargets(string $now, int $maxAttempts): array
    {
        $staleBefore = $this->staleCutoff($now);

        return $this->db->all(
            'SELECT b.id AS booking_id, b.booking_group_id
               FROM bookings b
               JOIN notifications n
                 ON n.booking_id = b.id AND n.notification_type = \'booking_confirmation\'
              WHERE b.status = \'confirmed\'
                AND b.source <> \'admin\'
                AND b.user_id IS NOT NULL
                AND n.attempt_count < ?
                AND (n.status IN (\'pending\', \'failed\')
                     OR (n.status = \'sending\' AND n.updated_at < ?))
                AND (
                    b.booking_group_id IS NULL
                    OR (
                        NOT EXISTS (
                            SELECT 1
                              FROM bookings bg
                             WHERE bg.booking_group_id = b.booking_group_id
                               AND bg.status <> \'confirmed\'
                        )
                        AND NOT EXISTS (
                            SELECT 1
                              FROM bookings bg
                              JOIN notifications ng
                                ON ng.booking_id = bg.id
                               AND ng.notification_type = \'booking_confirmation\'
                             WHERE bg.booking_group_id = b.booking_group_id
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
