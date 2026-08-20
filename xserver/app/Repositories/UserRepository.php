<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Db;
use App\Support\Time;

/** LINEユーザーの保存・参照。 */
final class UserRepository
{
    public function __construct(private Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByLineUserId(string $lineUserId): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE line_user_id = ?', [$lineUserId]);
    }

    /**
     * LINEユーザーを作成 or 更新する。
     * 友だち状態は取得できたときだけ上書きする（NULLで既存値を消さない）。
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

        $this->db->run(
            'INSERT INTO users
               (line_user_id, line_display_name, line_picture_url, is_line_friend, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               line_display_name = VALUES(line_display_name),
               line_picture_url  = VALUES(line_picture_url),
               is_line_friend    = COALESCE(VALUES(is_line_friend), users.is_line_friend),
               updated_at        = VALUES(updated_at)',
            [$lineUserId, $displayName, $pictureUrl, $friend, $now, $now]
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
        $this->db->run(
            'UPDATE users SET is_line_friend = ?, updated_at = ? WHERE id = ?',
            [$isFriend === null ? null : ($isFriend ? 1 : 0), $now, $userId]
        );
    }
}
