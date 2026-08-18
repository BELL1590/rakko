-- Phase 2: 汎用予約システム化
-- 「らっこ号 池袋便」専用の trips を、予約ページ(reservation_pages) と
-- 予約枠(reservation_slots) へ一般化する。
-- 既存テーブル(trips / bookings / notifications / users)はこの migration では変更しない。

CREATE TABLE reservation_pages (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  slug        TEXT NOT NULL UNIQUE,
  title       TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  status      TEXT NOT NULL DEFAULT 'draft'
                CHECK (status IN ('draft', 'published', 'closed', 'archived')),
  -- 表示の既定値を決めるためのヒント。業務ロジックはこの値に依存させない。
  page_type   TEXT NOT NULL DEFAULT 'other'
                CHECK (page_type IN ('bus', 'event', 'time_slot', 'other')),
  allow_multi_slot_booking INTEGER NOT NULL DEFAULT 1 CHECK (allow_multi_slot_booking IN (0, 1)),
  requires_line_login      INTEGER NOT NULL DEFAULT 1 CHECK (requires_line_login IN (0, 1)),
  max_slots_per_checkout   INTEGER NOT NULL DEFAULT 4 CHECK (max_slots_per_checkout >= 1),
  -- 受付確認のUI文言（乗車 / 受付 / 来場 など）。内部名は checked_in_count のまま。
  checkin_label TEXT NOT NULL DEFAULT '受付',
  created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE reservation_slots (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  reservation_page_id INTEGER NOT NULL REFERENCES reservation_pages(id),
  name                TEXT NOT NULL,
  description         TEXT NOT NULL DEFAULT '',
  -- 日時はすべてUTCの ISO8601 文字列で保存する（表示は Asia/Tokyo）
  start_at            TEXT NOT NULL,
  end_at              TEXT,
  -- バス用。イベント/貸切では location のみ使う。
  origin              TEXT,
  destination         TEXT,
  location            TEXT,
  capacity            INTEGER NOT NULL CHECK (capacity >= 0),
  max_party_size      INTEGER NOT NULL DEFAULT 4
                        CHECK (max_party_size >= 1 AND max_party_size <= 20),
  booking_open_at     TEXT,
  booking_close_at    TEXT,
  reminder_at         TEXT,
  booking_status      TEXT NOT NULL DEFAULT 'open'
                        CHECK (booking_status IN ('open', 'closed', 'hidden')),
  sort_order          INTEGER NOT NULL DEFAULT 0,
  -- 旧 trips からの移行元。段階移行のためだけに保持する。
  legacy_trip_id      INTEGER REFERENCES trips(id),
  created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  updated_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE INDEX ix_reservation_slots_page ON reservation_slots (reservation_page_id, sort_order);
CREATE UNIQUE INDEX ux_reservation_slots_legacy_trip
  ON reservation_slots (legacy_trip_id) WHERE legacy_trip_id IS NOT NULL;
