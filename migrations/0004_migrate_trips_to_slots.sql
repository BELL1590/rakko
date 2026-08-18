-- Phase 2: 既存の trips / bookings を予約枠モデルへ移行する。
-- 既存データは削除しない。bookings は列追加のためテーブル再構築するが、
-- id を含めて全行をコピーする（notifications.booking_id との対応も保たれる）。

PRAGMA defer_foreign_keys = true;

-- 1. 池袋便の予約ページを作成
INSERT INTO reservation_pages
  (slug, title, description, status, page_type,
   allow_multi_slot_booking, requires_line_login, max_slots_per_checkout, checkin_label)
SELECT
  'rakko-ikebukuro',
  'らっこ号 池袋便',
  '池袋西口から草加健康センターまで直行する送迎バスです。行き・帰りそれぞれご予約いただけます。',
  'published',
  'bus',
  1, 1, 4, '乗車'
WHERE EXISTS (SELECT 1 FROM trips)
  AND NOT EXISTS (SELECT 1 FROM reservation_pages WHERE slug = 'rakko-ikebukuro');

-- 2. 既存の便を予約枠へ移行（行き / 帰り）
INSERT INTO reservation_slots
  (reservation_page_id, name, description, start_at, end_at,
   origin, destination, location, capacity, max_party_size,
   booking_open_at, booking_close_at, reminder_at, booking_status, sort_order,
   legacy_trip_id, created_at, updated_at)
SELECT
  (SELECT id FROM reservation_pages WHERE slug = 'rakko-ikebukuro'),
  CASE t.direction WHEN 'outbound' THEN '行き' ELSE '帰り' END,
  '',
  t.depart_at,
  NULL,
  t.origin,
  t.destination,
  t.origin,
  t.capacity,
  4,
  t.booking_open_at,
  t.booking_close_at,
  t.reminder_at,
  t.booking_status,
  CASE t.direction WHEN 'outbound' THEN 1 ELSE 2 END,
  t.id,
  t.created_at,
  t.updated_at
FROM trips t
WHERE NOT EXISTS (SELECT 1 FROM reservation_slots s WHERE s.legacy_trip_id = t.id);

-- 3. bookings を予約枠ベースへ再構築
CREATE TABLE bookings_new (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  reservation_slot_id  INTEGER NOT NULL REFERENCES reservation_slots(id),
  -- 旧モデルの参照。段階移行のため残す（新規予約では NULL のことがある）。
  trip_id              INTEGER REFERENCES trips(id),
  -- 同一ページの複数枠をまとめて予約したときの一括予約ID
  booking_group_id     TEXT,
  user_id              INTEGER REFERENCES users(id),
  source               TEXT NOT NULL CHECK (source IN ('line', 'admin')),
  representative_name  TEXT NOT NULL,
  phone                TEXT NOT NULL,
  -- 上限は予約枠ごとの max_party_size で判定する（トリガーで担保）
  party_size           INTEGER NOT NULL CHECK (party_size >= 1 AND party_size <= 20),
  companion_names_json TEXT NOT NULL DEFAULT '[]',
  status               TEXT NOT NULL DEFAULT 'confirmed' CHECK (status IN ('confirmed', 'cancelled')),
  checked_in_count     INTEGER NOT NULL DEFAULT 0
                         CHECK (checked_in_count >= 0 AND checked_in_count <= party_size),
  cancelled_at         TEXT,
  created_at           TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  updated_at           TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

INSERT INTO bookings_new
  (id, reservation_slot_id, trip_id, booking_group_id, user_id, source,
   representative_name, phone, party_size, companion_names_json, status,
   checked_in_count, cancelled_at, created_at, updated_at)
SELECT
  b.id,
  (SELECT s.id FROM reservation_slots s WHERE s.legacy_trip_id = b.trip_id),
  b.trip_id,
  NULL,
  b.user_id,
  b.source,
  b.representative_name,
  b.phone,
  b.party_size,
  b.companion_names_json,
  b.status,
  b.checked_in_count,
  b.cancelled_at,
  b.created_at,
  b.updated_at
FROM bookings b;

DROP TABLE bookings;

ALTER TABLE bookings_new RENAME TO bookings;

-- 4. インデックス（旧テーブルと同じ役割を予約枠単位で再定義）
--    同一ユーザーが同一枠を confirmed で二重に持てない。cancelled は対象外。
CREATE UNIQUE INDEX ux_bookings_user_slot_confirmed
  ON bookings (user_id, reservation_slot_id)
  WHERE status = 'confirmed' AND user_id IS NOT NULL;

CREATE INDEX ix_bookings_slot_status ON bookings (reservation_slot_id, status);
CREATE INDEX ix_bookings_user ON bookings (user_id);
CREATE INDEX ix_bookings_group ON bookings (booking_group_id);

-- 5. オーバーブッキング防止の最終防衛線を予約枠単位で再定義
CREATE TRIGGER trg_bookings_capacity_insert
BEFORE INSERT ON bookings
WHEN NEW.status = 'confirmed'
BEGIN
  SELECT RAISE(ABORT, 'CAPACITY_EXCEEDED')
  WHERE (
    SELECT COALESCE(SUM(b.party_size), 0)
    FROM bookings b
    WHERE b.reservation_slot_id = NEW.reservation_slot_id AND b.status = 'confirmed'
  ) + NEW.party_size > (
    SELECT s.capacity FROM reservation_slots s WHERE s.id = NEW.reservation_slot_id
  );
END;

CREATE TRIGGER trg_bookings_capacity_update
BEFORE UPDATE ON bookings
WHEN NEW.status = 'confirmed'
BEGIN
  SELECT RAISE(ABORT, 'CAPACITY_EXCEEDED')
  WHERE (
    SELECT COALESCE(SUM(b.party_size), 0)
    FROM bookings b
    WHERE b.reservation_slot_id = NEW.reservation_slot_id
      AND b.status = 'confirmed' AND b.id <> NEW.id
  ) + NEW.party_size > (
    SELECT s.capacity FROM reservation_slots s WHERE s.id = NEW.reservation_slot_id
  );
END;

-- 1予約あたりの人数上限も予約枠の設定で拒否する
CREATE TRIGGER trg_bookings_max_party_size_insert
BEFORE INSERT ON bookings
WHEN NEW.status = 'confirmed'
BEGIN
  SELECT RAISE(ABORT, 'PARTY_SIZE_EXCEEDED')
  WHERE NEW.party_size > (
    SELECT s.max_party_size FROM reservation_slots s WHERE s.id = NEW.reservation_slot_id
  );
END;
