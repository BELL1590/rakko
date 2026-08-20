<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Db;
use App\Support\Time;

/** 予約の参照・更新。Workers版 src/db/queries.ts の bookings 部分の移植。 */
final class BookingRepository
{
    private const COLUMNS = 'b.id, b.reservation_slot_id, b.booking_group_id, b.user_id, b.source,
        b.representative_name, b.phone, b.party_size, b.companion_names_json, b.status,
        b.checked_in_count, b.cancelled_at, b.created_at, b.updated_at';

    private const JOIN_COLUMNS = 's.name AS slot_name, s.description AS slot_description,
        s.start_at, s.end_at, s.origin, s.destination, s.location, s.max_party_size,
        s.reminder_at, s.booking_close_at, s.booking_open_at, s.booking_status,
        s.capacity, s.reserved_seats, s.reservation_page_id,
        p.slug AS page_slug, p.title AS page_title, p.page_type, p.checkin_label';

    private const FROM = ' FROM bookings b
        JOIN reservation_slots s ON s.id = b.reservation_slot_id
        JOIN reservation_pages p ON p.id = s.reservation_page_id ';

    public function __construct(private Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $bookingId): ?array
    {
        return $this->db->first(
            'SELECT ' . self::COLUMNS . ', ' . self::JOIN_COLUMNS . self::FROM . ' WHERE b.id = ?',
            [$bookingId]
        );
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        return $this->db->all(
            'SELECT ' . self::COLUMNS . ', ' . self::JOIN_COLUMNS . self::FROM .
            " WHERE b.id IN ($placeholders) ORDER BY s.sort_order ASC, s.start_at ASC",
            array_map('intval', $ids)
        );
    }

    /** @return list<array<string, mixed>> */
    public function listByGroup(string $groupId): array
    {
        return $this->db->all(
            'SELECT ' . self::COLUMNS . ', ' . self::JOIN_COLUMNS . self::FROM .
            ' WHERE b.booking_group_id = ? ORDER BY s.sort_order ASC, s.start_at ASC',
            [$groupId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listByUser(int $userId): array
    {
        return $this->db->all(
            'SELECT ' . self::COLUMNS . ', ' . self::JOIN_COLUMNS . self::FROM .
            ' WHERE b.user_id = ? ORDER BY (b.status = \'cancelled\') ASC, s.start_at ASC',
            [$userId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listBySlot(int $slotId, ?string $search): array
    {
        $term = $search !== null && trim($search) !== '' ? trim($search) : null;
        $like = $term === null ? null : '%' . $term . '%';
        return $this->db->all(
            'SELECT ' . self::COLUMNS . ', ' . self::JOIN_COLUMNS . self::FROM .
            ' WHERE b.reservation_slot_id = ?
                AND (? IS NULL
                     OR b.representative_name LIKE ?
                     OR b.phone LIKE ?
                     OR b.companion_names_json LIKE ?
                     OR CAST(b.id AS CHAR) = ?)
              ORDER BY b.created_at DESC, b.id DESC',
            [$slotId, $like, $like, $like, $like, $term ?? '']
        );
    }

    /** ページ配下の全予約（ページ全体CSV用）。 @return list<array<string, mixed>> */
    public function listByPage(int $pageId, bool $includeCancelled): array
    {
        return $this->db->all(
            'SELECT ' . self::COLUMNS . ', ' . self::JOIN_COLUMNS . self::FROM .
            ' WHERE s.reservation_page_id = ? AND (? = 1 OR b.status = \'confirmed\')
              ORDER BY s.sort_order ASC, s.start_at ASC, b.id ASC',
            [$pageId, $includeCancelled ? 1 : 0]
        );
    }

    /** 予約枠の名簿（CSV用）。 @return list<array<string, mixed>> */
    public function listRosterBySlot(int $slotId, bool $includeCancelled): array
    {
        return $this->db->all(
            'SELECT ' . self::COLUMNS . ', ' . self::JOIN_COLUMNS . self::FROM .
            ' WHERE b.reservation_slot_id = ? AND (? = 1 OR b.status = \'confirmed\')
              ORDER BY b.id ASC',
            [$slotId, $includeCancelled ? 1 : 0]
        );
    }

    /**
     * 同一ユーザーが既に確定予約を持っている枠のID一覧。
     *
     * @param list<int> $slotIds
     * @return list<int>
     */
    public function confirmedSlotIdsForUser(int $userId, array $slotIds): array
    {
        if ($slotIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($slotIds), '?'));
        $rows = $this->db->all(
            "SELECT reservation_slot_id FROM bookings
              WHERE user_id = ? AND status = 'confirmed'
                AND reservation_slot_id IN ($placeholders)",
            array_merge([$userId], array_map('intval', $slotIds))
        );
        return array_map(static fn (array $row): int => (int) $row['reservation_slot_id'], $rows);
    }

    /** @param array<string, mixed> $params */
    public function insert(array $params, ?string $now = null): int
    {
        $now ??= Time::nowUtc();
        return $this->db->insert(
            'INSERT INTO bookings
               (reservation_slot_id, booking_group_id, user_id, source, representative_name,
                phone, party_size, companion_names_json, status, checked_in_count,
                created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'confirmed\', 0, ?, ?)',
            [
                (int) $params['reservation_slot_id'],
                $params['booking_group_id'],
                $params['user_id'],
                $params['source'],
                $params['representative_name'],
                $params['phone'],
                (int) $params['party_size'],
                $params['companion_names_json'],
                $now,
                $now,
            ]
        );
    }

    /**
     * 予約をキャンセルする。所有者チェックはSQLのWHEREへ含める。
     *
     * @return int 更新行数（0なら対象なし＝他人の予約 or すでにキャンセル済み）
     */
    public function cancel(int $bookingId, ?int $userId, bool $requireOwner, ?string $now = null): int
    {
        $now ??= Time::nowUtc();
        return $this->db->run(
            'UPDATE bookings
                SET status = \'cancelled\', cancelled_at = ?, updated_at = ?
              WHERE id = ?
                AND status = \'confirmed\'
                AND (? = 0 OR (user_id IS NOT NULL AND user_id = ?))',
            [$now, $now, $bookingId, $requireOwner ? 1 : 0, $userId]
        );
    }

    public function updateCheckedInCount(int $bookingId, int $count, ?string $now = null): int
    {
        $now ??= Time::nowUtc();
        return $this->db->run(
            'UPDATE bookings
                SET checked_in_count = ?, updated_at = ?
              WHERE id = ? AND status = \'confirmed\' AND ? >= 0 AND ? <= party_size',
            [$count, $now, $bookingId, $count, $count]
        );
    }
}
