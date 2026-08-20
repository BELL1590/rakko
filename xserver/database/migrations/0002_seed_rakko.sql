-- 「らっこ号 池袋便」の初期データ。
-- D1版 0002_seed_trips.sql / 0004_migrate_trips_to_slots.sql と同じ内容を
-- 予約ページ + 予約枠の形で投入する。
--
-- JST = UTC+9 なのでUTCへ換算して保存する。
--   行き: 2026-08-21 20:00 JST = 2026-08-21 11:00 UTC / リマインド 17:00 JST = 08:00 UTC
--   帰り: 2026-08-22 08:10 JST = 2026-08-21 23:10 UTC / リマインド 07:00 JST = 22:00 UTC
-- 定員は仮値40席。本番は管理画面から変更する。

INSERT INTO reservation_pages
  (slug, title, description, status, page_type,
   allow_multi_slot_booking, requires_line_login, max_slots_per_checkout, checkin_label,
   created_at, updated_at)
SELECT
  'rakko-ikebukuro',
  'らっこ号 池袋便',
  '池袋西口から草加健康センターまで直行する送迎バスです。行き・帰りそれぞれご予約いただけます。',
  'published', 'bus', 1, 1, 4, '乗車',
  UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM reservation_pages WHERE slug = 'rakko-ikebukuro');

INSERT INTO reservation_slots
  (reservation_page_id, name, description, start_at, origin, destination, location,
   capacity, max_party_size, reminder_at, booking_status, sort_order, reserved_seats,
   created_at, updated_at)
SELECT p.id, '行き', '', '2026-08-21 11:00:00',
       '池袋西口 マクドナルド前辺り', '草加健康センター', '池袋西口 マクドナルド前辺り',
       40, 4, '2026-08-21 08:00:00', 'open', 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM reservation_pages p
WHERE p.slug = 'rakko-ikebukuro'
  AND NOT EXISTS (
    SELECT 1 FROM reservation_slots s WHERE s.reservation_page_id = p.id AND s.name = '行き'
  );

INSERT INTO reservation_slots
  (reservation_page_id, name, description, start_at, origin, destination, location,
   capacity, max_party_size, reminder_at, booking_status, sort_order, reserved_seats,
   created_at, updated_at)
SELECT p.id, '帰り', '', '2026-08-21 23:10:00',
       '草加健康センター', '池袋西口', '草加健康センター',
       40, 4, '2026-08-21 22:00:00', 'open', 2, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM reservation_pages p
WHERE p.slug = 'rakko-ikebukuro'
  AND NOT EXISTS (
    SELECT 1 FROM reservation_slots s WHERE s.reservation_page_id = p.id AND s.name = '帰り'
  );
