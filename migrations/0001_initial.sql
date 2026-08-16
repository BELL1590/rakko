-- らっこ号 池袋便 予約システム 初期スキーマ
-- 日時はすべてUTCの ISO8601 文字列 (YYYY-MM-DDTHH:MM:SSZ) で保存する。

CREATE TABLE users (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  line_user_id      TEXT NOT NULL UNIQUE,
  line_display_name TEXT NOT NULL DEFAULT '',
  line_picture_url  TEXT,
  -- 1 = 友だち, 0 = 未追加/ブロック, NULL = 取得できていない
  is_line_friend    INTEGER,
  created_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  updated_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE trips (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  slug            TEXT NOT NULL UNIQUE,
  direction       TEXT NOT NULL CHECK (direction IN ('outbound', 'return')),
  origin          TEXT NOT NULL,
  destination     TEXT NOT NULL,
  depart_at       TEXT NOT NULL,
  reminder_at     TEXT NOT NULL,
  capacity        INTEGER NOT NULL CHECK (capacity >= 0),
  booking_status  TEXT NOT NULL DEFAULT 'open' CHECK (booking_status IN ('open', 'closed')),
  booking_open_at  TEXT,
  booking_close_at TEXT,
  created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE bookings (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  trip_id              INTEGER NOT NULL REFERENCES trips(id),
  -- 管理者代理予約では NULL
  user_id              INTEGER REFERENCES users(id),
  source               TEXT NOT NULL CHECK (source IN ('line', 'admin')),
  representative_name  TEXT NOT NULL,
  phone                TEXT NOT NULL,
  party_size           INTEGER NOT NULL CHECK (party_size >= 1 AND party_size <= 4),
  companion_names_json TEXT NOT NULL DEFAULT '[]',
  status               TEXT NOT NULL DEFAULT 'confirmed' CHECK (status IN ('confirmed', 'cancelled')),
  checked_in_count     INTEGER NOT NULL DEFAULT 0
                         CHECK (checked_in_count >= 0 AND checked_in_count <= party_size),
  cancelled_at         TEXT,
  created_at           TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  updated_at           TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- 同一LINEユーザーが同一便を二重にconfirmedで持てない。
-- cancelled は対象外なので、キャンセル後の再予約は可能。
CREATE UNIQUE INDEX ux_bookings_user_trip_confirmed
  ON bookings (user_id, trip_id)
  WHERE status = 'confirmed' AND user_id IS NOT NULL;

CREATE INDEX ix_bookings_trip_status ON bookings (trip_id, status);
CREATE INDEX ix_bookings_user ON bookings (user_id);

-- ---------------------------------------------------------------------------
-- オーバーブッキング防止の最終防衛線。
-- アプリ側でも条件付きINSERTで防いでいるが、DBレベルでも必ず拒否する。
-- ---------------------------------------------------------------------------
CREATE TRIGGER trg_bookings_capacity_insert
BEFORE INSERT ON bookings
WHEN NEW.status = 'confirmed'
BEGIN
  SELECT RAISE(ABORT, 'CAPACITY_EXCEEDED')
  WHERE (
    SELECT COALESCE(SUM(b.party_size), 0)
    FROM bookings b
    WHERE b.trip_id = NEW.trip_id AND b.status = 'confirmed'
  ) + NEW.party_size > (SELECT t.capacity FROM trips t WHERE t.id = NEW.trip_id);
END;

CREATE TRIGGER trg_bookings_capacity_update
BEFORE UPDATE ON bookings
WHEN NEW.status = 'confirmed'
BEGIN
  SELECT RAISE(ABORT, 'CAPACITY_EXCEEDED')
  WHERE (
    SELECT COALESCE(SUM(b.party_size), 0)
    FROM bookings b
    WHERE b.trip_id = NEW.trip_id AND b.status = 'confirmed' AND b.id <> NEW.id
  ) + NEW.party_size > (SELECT t.capacity FROM trips t WHERE t.id = NEW.trip_id);
END;

CREATE TABLE notifications (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  booking_id        INTEGER NOT NULL REFERENCES bookings(id),
  notification_type TEXT NOT NULL CHECK (notification_type IN ('booking_confirmation', 'reminder')),
  -- requested = Messaging APIが成功応答を返した（ユーザー到達の保証ではない）
  status            TEXT NOT NULL DEFAULT 'pending'
                      CHECK (status IN ('pending', 'requested', 'failed', 'skipped')),
  attempt_count     INTEGER NOT NULL DEFAULT 0,
  last_error        TEXT,
  requested_at      TEXT,
  created_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  updated_at        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  UNIQUE (booking_id, notification_type)
);

CREATE INDEX ix_notifications_status ON notifications (status, notification_type);
