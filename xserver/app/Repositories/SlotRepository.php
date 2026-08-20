<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Db;
use App\Support\Time;

/**
 * 予約ページ / 予約枠の参照・更新。
 * Workers版 src/db/queries.ts の該当部分の移植。
 */
final class SlotRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // -----------------------------------------------------------------
    // 予約ページ
    // -----------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function findPageById(int $id): ?array
    {
        return $this->db->first('SELECT * FROM reservation_pages WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findPageBySlug(string $slug): ?array
    {
        return $this->db->first('SELECT * FROM reservation_pages WHERE slug = ?', [$slug]);
    }

    /** @return list<array<string, mixed>> 枠数・予約人数つき */
    public function listPagesWithStats(): array
    {
        return $this->db->all(
            'SELECT p.*,
               (SELECT COUNT(*) FROM reservation_slots s WHERE s.reservation_page_id = p.id) AS slot_count,
               COALESCE((SELECT SUM(b.party_size) FROM bookings b
                          JOIN reservation_slots s2 ON s2.id = b.reservation_slot_id
                         WHERE s2.reservation_page_id = p.id AND b.status = \'confirmed\'), 0) AS booked_seats,
               COALESCE((SELECT SUM(s3.capacity) FROM reservation_slots s3
                         WHERE s3.reservation_page_id = p.id), 0) AS capacity_total
             FROM reservation_pages p
             ORDER BY (p.status = \'archived\') ASC, p.created_at DESC, p.id DESC'
        );
    }

    /** @return list<array<string, mixed>> */
    public function listPublishedPages(): array
    {
        return array_values(array_filter(
            $this->listPagesWithStats(),
            static fn (array $page): bool => $page['status'] === 'published'
        ));
    }

    /** @param array<string, mixed> $input */
    public function createPage(array $input, ?string $now = null): int
    {
        $now ??= Time::nowUtc();
        return $this->db->insert(
            'INSERT INTO reservation_pages
               (slug, title, description, status, page_type, allow_multi_slot_booking,
                requires_line_login, max_slots_per_checkout, checkin_label, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $input['slug'], $input['title'], $input['description'], $input['status'],
                $input['page_type'], (int) $input['allow_multi_slot_booking'],
                (int) $input['requires_line_login'], (int) $input['max_slots_per_checkout'],
                $input['checkin_label'], $now, $now,
            ]
        );
    }

    /** @param array<string, mixed> $input */
    public function updatePage(int $pageId, array $input, ?string $now = null): void
    {
        $now ??= Time::nowUtc();
        $this->db->run(
            'UPDATE reservation_pages
                SET slug = ?, title = ?, description = ?, status = ?, page_type = ?,
                    allow_multi_slot_booking = ?, requires_line_login = ?,
                    max_slots_per_checkout = ?, checkin_label = ?, updated_at = ?
              WHERE id = ?',
            [
                $input['slug'], $input['title'], $input['description'], $input['status'],
                $input['page_type'], (int) $input['allow_multi_slot_booking'],
                (int) $input['requires_line_login'], (int) $input['max_slots_per_checkout'],
                $input['checkin_label'], $now, $pageId,
            ]
        );
    }

    public function updatePageStatus(int $pageId, string $status, ?string $now = null): void
    {
        $now ??= Time::nowUtc();
        $this->db->run(
            'UPDATE reservation_pages SET status = ?, updated_at = ? WHERE id = ?',
            [$status, $now, $pageId]
        );
    }

    // -----------------------------------------------------------------
    // 予約枠
    // -----------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function listSlotsByPage(int $pageId, ?string $now = null): array
    {
        $now ??= Time::nowUtc();
        $rows = $this->db->all(
            'SELECT * FROM reservation_slots
              WHERE reservation_page_id = ?
              ORDER BY sort_order ASC, start_at ASC, id ASC',
            [$pageId]
        );
        return array_map(fn (array $row): array => $this->decorate($row, $now), $rows);
    }

    /** @return array<string, mixed>|null */
    public function findSlot(int $slotId, ?string $now = null): ?array
    {
        $now ??= Time::nowUtc();
        $row = $this->db->first('SELECT * FROM reservation_slots WHERE id = ?', [$slotId]);
        return $row === null ? null : $this->decorate($row, $now);
    }

    /** 予約枠 + ページ情報。 @return array<string, mixed>|null */
    public function findSlotWithPage(int $slotId, ?string $now = null): ?array
    {
        $now ??= Time::nowUtc();
        $row = $this->db->first(
            'SELECT s.*, p.slug AS page_slug, p.title AS page_title, p.status AS page_status,
                    p.page_type, p.checkin_label, p.max_slots_per_checkout,
                    p.allow_multi_slot_booking, p.requires_line_login
               FROM reservation_slots s
               JOIN reservation_pages p ON p.id = s.reservation_page_id
              WHERE s.id = ?',
            [$slotId]
        );
        return $row === null ? null : $this->decorate($row, $now);
    }

    /** 旧 `/trips/:slug` 互換のための解決。 @return array<string, mixed>|null */
    public function findSlotByLegacyTripSlug(string $tripSlug, ?string $now = null): ?array
    {
        // D1版の trips.slug は 'ikebukuro-20260821-outbound' のような固定値。
        // XSERVER版では枠名（行き/帰り）へ対応付ける。
        $map = [
            'ikebukuro-20260821-outbound' => '行き',
            'ikebukuro-20260822-return' => '帰り',
        ];
        $name = $map[$tripSlug] ?? null;
        if ($name === null) {
            return null;
        }
        $row = $this->db->first(
            'SELECT s.id FROM reservation_slots s
               JOIN reservation_pages p ON p.id = s.reservation_page_id
              WHERE p.slug = ? AND s.name = ?',
            ['rakko-ikebukuro', $name]
        );
        return $row === null ? null : $this->findSlotWithPage((int) $row['id'], $now);
    }

    /** @param array<string, mixed> $input */
    public function createSlot(int $pageId, array $input, ?string $now = null): int
    {
        $now ??= Time::nowUtc();
        return $this->db->insert(
            'INSERT INTO reservation_slots
               (reservation_page_id, name, description, start_at, end_at, origin, destination,
                location, capacity, max_party_size, booking_open_at, booking_close_at,
                reminder_at, booking_status, sort_order, reserved_seats, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
            [
                $pageId, $input['name'], $input['description'], $input['start_at'], $input['end_at'],
                $input['origin'], $input['destination'], $input['location'],
                (int) $input['capacity'], (int) $input['max_party_size'],
                $input['booking_open_at'], $input['booking_close_at'], $input['reminder_at'],
                $input['booking_status'], (int) $input['sort_order'], $now, $now,
            ]
        );
    }

    /** @param array<string, mixed> $input */
    public function updateSlot(int $slotId, array $input, ?string $now = null): void
    {
        $now ??= Time::nowUtc();
        $this->db->run(
            'UPDATE reservation_slots
                SET name = ?, description = ?, start_at = ?, end_at = ?, origin = ?,
                    destination = ?, location = ?, capacity = ?, max_party_size = ?,
                    booking_open_at = ?, booking_close_at = ?, reminder_at = ?,
                    booking_status = ?, sort_order = ?, updated_at = ?
              WHERE id = ?',
            [
                $input['name'], $input['description'], $input['start_at'], $input['end_at'],
                $input['origin'], $input['destination'], $input['location'],
                (int) $input['capacity'], (int) $input['max_party_size'],
                $input['booking_open_at'], $input['booking_close_at'], $input['reminder_at'],
                $input['booking_status'], (int) $input['sort_order'], $now, $slotId,
            ]
        );
    }

    /** 確定予約の合計人数（カウンタではなく実データから数える。整合性確認用）。 */
    public function sumConfirmedSeats(int $slotId): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(SUM(party_size), 0) FROM bookings
              WHERE reservation_slot_id = ? AND status = \'confirmed\'',
            [$slotId]
        );
    }

    /**
     * 残席・状態を付与する。Workers版 decorateSlot() と同じ計算。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function decorate(array $row, string $now): array
    {
        $capacity = (int) $row['capacity'];
        $booked = (int) $row['reserved_seats'];
        $remaining = max(0, $capacity - $booked);
        $started = (string) $row['start_at'] <= $now;
        $withinWindow =
            ($row['booking_open_at'] === null || (string) $row['booking_open_at'] <= $now)
            && ($row['booking_close_at'] === null || (string) $row['booking_close_at'] > $now);

        $row['booked_seats'] = $booked;
        $row['remaining_seats'] = $remaining;
        $row['is_full'] = $remaining <= 0;
        $row['is_bookable'] = $row['booking_status'] === 'open'
            && $remaining > 0 && !$started && $withinWindow;
        $row['is_visible'] = $row['booking_status'] !== 'hidden';

        return $row;
    }
}
