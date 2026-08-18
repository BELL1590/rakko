-- 便の初期データ。JST = UTC+9 なので、UTCへ換算して保存する。
--
-- 行き: 2026-08-21 20:00 JST = 2026-08-21 11:00 UTC
--       リマインド 2026-08-21 17:00 JST = 2026-08-21 08:00 UTC
-- 帰り: 2026-08-22 08:10 JST = 2026-08-21 23:10 UTC
--       リマインド 2026-08-22 07:00 JST = 2026-08-21 22:00 UTC
--
-- 定員は仮値。管理画面から変更できる。

INSERT INTO trips (slug, direction, origin, destination, depart_at, reminder_at, capacity, booking_status)
VALUES
  (
    'ikebukuro-20260821-outbound',
    'outbound',
    '池袋西口 マクドナルド前辺り',
    '草加健康センター',
    '2026-08-21T11:00:00Z',
    '2026-08-21T08:00:00Z',
    40,
    'open'
  ),
  (
    'ikebukuro-20260822-return',
    'return',
    '草加健康センター',
    '池袋西口',
    '2026-08-21T23:10:00Z',
    '2026-08-21T22:00:00Z',
    40,
    'open'
  );
