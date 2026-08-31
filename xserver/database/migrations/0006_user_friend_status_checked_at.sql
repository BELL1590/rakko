-- LINE友だち状態の鮮度を管理する。
-- is_line_friend=1 でも確認が古ければ予約を通さないため、
-- LINE Platformで最後に確認できた時刻を保存する。
-- 既存行は NULL のままにして、次回LINE/LIFF認証時の再確認を必須にする。

ALTER TABLE users
  ADD COLUMN friend_status_checked_at DATETIME NULL AFTER is_line_friend;
