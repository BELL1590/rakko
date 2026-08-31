<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Db;
use App\Support\Time;

/** LINEユーザーの保存・参照。 */
final class UserRepository
{
    /** 予約に使えるLINE友だち確認の有効期間（秒）。 */
    public const FRIEND_STATUS_MAX_AGE_SECONDS = 300;

    public function __construct(private Db $db)
    {
    }

    /**
     * 予約・画面表示用のユーザー取得。
     *
     * is_line_friend=1 でも最終確認から5分を超えていれば、
     * その場では「不明(null)」として扱い、長寿命Cookieだけで予約を通さない。
     * DB上の履歴値自体は保持し、次回LIFF/OAuthで再確認できた時点で更新する。
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->first(
            'SELECT id, line_user_id, line_display_name, line_picture_url,
                    CASE
                      WHEN is_line_friend = 1
                       AND friend_status_checked_at IS NOT NULL
                       AND friend_status_checked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)
                       AND friend_status_checked_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND)
                        THEN 1
                      WHEN is_line_friend = 1 THEN NULL
                      ELSE is_line_friend
                    END AS is_line_friend,
                    friend_status_checked_at, created_at, updated_at
               FROM users
              WHERE id = ?',
            [self::FRIEND_STATUS_MAX_AGE_SECONDS, $id]
        );
    }

    /**
     * 認証処理用の生の保存値を返す。
     * upsert直後の結果確認では鮮度マスクを掛けない。
     *
     * @return array<string, mixed>|null
     */
    public function findByLineUserId(string $lineUserId): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE line_user_id = ?', [$lineUserId]);
    }

    /**
     * LINEユーザーを作成 or 更新する。
     *
     * 友だち状態が取得できなかった `null` は fail closed で扱う。
     * 過去の true を残すと「以前は友だちだったが今回は確認不能」でも予約できてしまうため、
     * true → null は NULL に落とす。一方、既知の false は安全側なので false → null では保持する。
     * true/false をLINE Platformから取得できたときだけ friend_status_checked_at を更新する。
     *
     * @return array<string, mixed>
     */
    public function upsertByLineId(
        string $lineUserId,
        string $displayName,
        ?string $pictureUrl,
        ?bool $isFriend,
        ?string $now = null
    ): array {
        $now ??= Time::nowUtc();
        $friend = $isFriend === null ? null : ($isFriend ? 1 : 0);
        $checkedAt = $isFriend === null ? null : $now;

        $this->db->run(
            'INSERT INTO users
               (line_user_id, line_display_name, line_picture_url, is_line_friend,
                friend_status_checked_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               line_display_name = VALUES(line_display_name),
               line_picture_url  = VALUES(line_picture_url),
               is_line_friend    = CASE
                   WHEN VALUES(is_line_friend) IS NULL AND users.is_line_friend = 1 THEN NULL
                   ELSE COALESCE(VALUES(is_line_friend), users.is_line_friend)
               END,
               friend_status_checked_at = CASE
                   WHEN VALUES(is_line_friend) IS NULL THEN NULL
                   ELSE VALUES(friend_status_checked_at)
               END,
               updated_at        = VALUES(updated_at)',
            [$lineUserId, $displayName, $pictureUrl, $friend, $checkedAt, $now, $now]
        );

        $user = $this->findByLineUserId($lineUserId);
        if ($user === null) {
            throw new \RuntimeException('failed to upsert user');
        }
        return $user;
    }

    public function updateFriendStatus(int $userId, ?bool $isFriend, ?string $now = null): void
    {
        $now ??= Time::nowUtc();
        $checkedAt = $isFriend === null ? null : $now;
        $this->db->run(
            'UPDATE users
                SET is_line_friend = ?, friend_status_checked_at = ?, updated_at = ?
              WHERE id = ?',
            [$isFriend === null ? null : ($isFriend ? 1 : 0), $checkedAt, $now, $userId]
        );
    }
}
