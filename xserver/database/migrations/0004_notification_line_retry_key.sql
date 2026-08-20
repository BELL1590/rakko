-- LINE Messaging API のネットワーク境界での二重送信を防ぐための retry key を保存する。
--
-- sending + claim_token でアプリ/DB側の同時実行競合は解消済みだが、
-- 「LINEがpushを受理した直後にPHPプロセスが落ち、DBへ requested を書けなかった」
-- 場合には、stale再取得後の再試行で同じ通知がもう一度配信されうる。
--
-- Push Message の X-Line-Retry-Key を使うと、LINE側が同じキーのリクエストを
-- 重複と判定して 409 を返すため、ネットワーク境界を越えた冪等性が得られる。
--
-- claim_token は「送信権の排他」用で claim のたびに変わるのに対し、
-- line_retry_key は「同じ通知の同じ内容の再送」を表すため、
-- 一度発行したら 5xx / timeout / stale再取得をまたいで保持し続ける。

ALTER TABLE notifications
  ADD COLUMN line_retry_key CHAR(36) NULL AFTER claim_token;
