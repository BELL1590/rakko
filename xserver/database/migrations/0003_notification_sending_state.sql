-- 通知の送信権取得を原子的にするための in-flight 状態 `sending` と、
-- 送信権の持ち主を識別する `claim_token` を追加する。
--
-- 従来は claim() が status を変えずに attempt_count だけ増やしていたため、
-- 通知行が pending のまま残り、2つのプロセス（Cron と管理画面の手動実行など）が
-- 同時に claim すると両方の UPDATE が `status IN ('pending','failed')` に一致し、
-- Messaging API を二重に呼ぶ可能性があった。
--
-- 以後の状態遷移:
--   pending / failed        -> sending   （claim。勝った1プロセスだけが送信する）
--   sending                 -> requested / failed / skipped
--                                        （finish。claim_token が一致する場合のみ）
--   sending が長時間放置     -> sending   （送信中にプロセスが落ちた場合の再取得。
--                                          token が変わるため、後から目を覚ました
--                                          元のプロセスの finish は無視される）

ALTER TABLE notifications
  MODIFY COLUMN status
    ENUM('pending','sending','requested','failed','skipped')
    NOT NULL DEFAULT 'pending',
  ADD COLUMN claim_token CHAR(32) NULL AFTER status;
