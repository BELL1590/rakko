-- 草加健康センター 汎用予約システム — XSERVER MySQL スキーマ
--
-- Cloudflare D1(SQLite) の論理スキーマを MySQL / InnoDB / utf8mb4 へ移植したもの。
-- 日時はすべてUTCの DATETIME で保存する（表示は Asia/Tokyo）。
--
-- D1 との差分と、その理由:
--  * 部分UNIQUEインデックス（WHERE status='confirmed'）は MySQL に無いため、
--    生成カラム dedupe_key + UNIQUE で同等の保護を実装する。
--  * 定員超過を止める BEFORE INSERT トリガーは、MySQL では自テーブルを参照できないため、
--    「予約時のトランザクション + SELECT ... FOR UPDATE」を一次防御とし、
--    reserved_seats カウンタの CHECK 制約をDBレベルの最終防衛線として併用する。

CREATE TABLE users (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  line_user_id      VARCHAR(64)  NOT NULL,
  line_display_name VARCHAR(100) NOT NULL DEFAULT '',
  line_picture_url  VARCHAR(512) NULL,
  -- 1 = 友だち, 0 = 未追加/ブロック, NULL = 取得できていない
  is_line_friend    TINYINT(1)   NULL,
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_users_line_user_id (line_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservation_pages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(60)  NOT NULL,
  title       VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  status      ENUM('draft','published','closed','archived') NOT NULL DEFAULT 'draft',
  -- 表示の既定値を決めるヒント。業務ロジックはこの値に依存させない。
  page_type   ENUM('bus','event','time_slot','other') NOT NULL DEFAULT 'other',
  allow_multi_slot_booking TINYINT(1) NOT NULL DEFAULT 1,
  requires_line_login      TINYINT(1) NOT NULL DEFAULT 1,
  max_slots_per_checkout   TINYINT UNSIGNED NOT NULL DEFAULT 4,
  -- 受付確認のUI文言（乗車 / 受付 / 来場 など）。内部名は checked_in_count のまま。
  checkin_label VARCHAR(20) NOT NULL DEFAULT '受付',
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_reservation_pages_slug (slug),
  CONSTRAINT ck_pages_max_slots CHECK (max_slots_per_checkout >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservation_slots (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_page_id INT UNSIGNED NOT NULL,
  name                VARCHAR(80)  NOT NULL,
  description         VARCHAR(300) NOT NULL DEFAULT '',
  start_at            DATETIME     NOT NULL,
  end_at              DATETIME     NULL,
  -- バス用。イベント/貸切では location のみ使う。
  origin              VARCHAR(150) NULL,
  destination         VARCHAR(150) NULL,
  location            VARCHAR(150) NULL,
  capacity            INT UNSIGNED NOT NULL,
  max_party_size      TINYINT UNSIGNED NOT NULL DEFAULT 4,
  booking_open_at     DATETIME NULL,
  booking_close_at    DATETIME NULL,
  reminder_at         DATETIME NULL,
  booking_status      ENUM('open','closed','hidden') NOT NULL DEFAULT 'open',
  sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- 確定予約の合計人数。予約/キャンセルと同一トランザクションで更新する。
  -- CHECK 制約がオーバーブッキングのDBレベル最終防衛線になる。
  reserved_seats      INT UNSIGNED NOT NULL DEFAULT 0,
  -- 旧 D1 の trips からの移行元。移行時のみ使用。
  legacy_trip_id      INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_reservation_slots_page (reservation_page_id, sort_order),
  KEY ix_reservation_slots_reminder (reminder_at, start_at),
  UNIQUE KEY ux_reservation_slots_legacy_trip (legacy_trip_id),
  CONSTRAINT fk_slots_page FOREIGN KEY (reservation_page_id)
    REFERENCES reservation_pages (id) ON DELETE RESTRICT,
  CONSTRAINT ck_slots_max_party CHECK (max_party_size >= 1 AND max_party_size <= 20),
  -- 予約人数の合計が定員を超えることは、どの経路からでも許さない
  CONSTRAINT ck_slots_reserved CHECK (reserved_seats <= capacity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_slot_id  INT UNSIGNED NOT NULL,
  -- 同一ページの複数枠をまとめて予約したときの一括予約ID
  booking_group_id     CHAR(36) NULL,
  -- 管理者代理予約では NULL
  user_id              INT UNSIGNED NULL,
  source               ENUM('line','admin') NOT NULL,
  representative_name  VARCHAR(100) NOT NULL,
  phone                VARCHAR(30)  NOT NULL,
  party_size           TINYINT UNSIGNED NOT NULL,
  companion_names_json JSON NOT NULL,
  status               ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  checked_in_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  cancelled_at         DATETIME NULL,
  created_at           DATETIME NOT NULL,
  updated_at           DATETIME NOT NULL,
  -- SQLite の部分UNIQUEインデックス相当。
  -- confirmed かつ LINEユーザーの予約だけキーを持ち、それ以外は NULL。
  -- MySQL の UNIQUE は NULL を重複扱いしないため、
  -- キャンセル済み・管理者代理（user_id IS NULL）は重複判定の対象外になる。
  dedupe_key VARCHAR(48) GENERATED ALWAYS AS (
    CASE
      WHEN status = 'confirmed' AND user_id IS NOT NULL
        THEN CONCAT(user_id, ':', reservation_slot_id)
      ELSE NULL
    END
  ) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY ux_bookings_user_slot_confirmed (dedupe_key),
  KEY ix_bookings_slot_status (reservation_slot_id, status),
  KEY ix_bookings_user (user_id),
  KEY ix_bookings_group (booking_group_id),
  CONSTRAINT fk_bookings_slot FOREIGN KEY (reservation_slot_id)
    REFERENCES reservation_slots (id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT ck_bookings_party_size CHECK (party_size >= 1 AND party_size <= 20),
  CONSTRAINT ck_bookings_checked_in CHECK (checked_in_count <= party_size)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id        INT UNSIGNED NOT NULL,
  notification_type ENUM('booking_confirmation','reminder') NOT NULL,
  -- requested = Messaging APIが成功応答を返した（ユーザー到達の保証ではない）
  status            ENUM('pending','requested','failed','skipped') NOT NULL DEFAULT 'pending',
  attempt_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error        VARCHAR(300) NULL,
  requested_at      DATETIME NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  PRIMARY KEY (id),
  -- 同じ予約・同じ種別の通知は1件だけ（二重送信のDB制約）
  UNIQUE KEY ux_notifications_booking_type (booking_id, notification_type),
  KEY ix_notifications_status (status, notification_type),
  CONSTRAINT fk_notifications_booking FOREIGN KEY (booking_id)
    REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 適用済みマイグレーションの記録
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   VARCHAR(200) NOT NULL,
  applied_at DATETIME NOT NULL,
  PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
