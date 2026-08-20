-- 予約ページごとに公開注意事項を保持する。
-- NULL / 空欄はアプリ側で従来の共通注意事項へフォールバックする。

ALTER TABLE reservation_pages
  ADD COLUMN notice_text TEXT NULL AFTER description;
