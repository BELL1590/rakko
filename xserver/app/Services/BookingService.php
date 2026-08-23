<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Db;
use App\Repositories\BookingRepository;
use App\Repositories\SlotRepository;
use App\Repositories\UserRepository;
use App\Support\Time;
use App\Support\Uuid;

/**
 * 予約のドメインロジック。
 * 入力検証・定員制御・所有者チェックはすべてサーバー側（ここ）で行う。
 *
 * オーバーブッキング防止の設計（XSERVER MySQL版）:
 *   1. トランザクション開始
 *   2. 対象の reservation_slots を **ID昇順** に SELECT ... FOR UPDATE でロック
 *      （昇順で取ることでデッドロックの発生を抑える）
 *   3. ロック下で受付状態・受付期間・人数上限・残席・二重予約を再検証
 *   4. bookings を INSERT し、reservation_slots.reserved_seats を同一トランザクションで加算
 *   5. 全枠成功したときだけ COMMIT。1枠でも失敗すれば全体を ROLLBACK（全枠成功 or 全枠失敗）
 *
 * reserved_seats には CHECK (reserved_seats <= capacity) が付いており、
 * アプリ側の判定をすり抜けてもDBレベルで定員超過を拒否する（最終防衛線）。
 */
final class BookingService
{
    public const MIN_PARTY_SIZE = 1;
    /** 予約枠に max_party_size が設定されていないときの既定上限。 */
    public const MAX_PARTY_SIZE = 4;
    /** 1予約あたりの人数の絶対上限（DBのCHECK制約と揃える）。 */
    public const HARD_MAX_PARTY_SIZE = 20;

    /** 電話番号: 数字・ハイフン・括弧・+ のみ、数字10〜11桁。 */
    private const PHONE_PATTERN = '/^[0-9+\-() ]{10,20}$/';

    public function __construct(
        private Db $db,
        private SlotRepository $slots,
        private BookingRepository $bookings,
        private UserRepository $users
    ) {
    }

    /**
     * 代表者情報の検証。
     *
     * @return array{ok: true, representative_name: string, phone: string}|array{ok: false, code: string, message: string}
     */
    public function validateContact(
        string $representativeName,
        string $phone,
        bool $agreed,
        bool $requireAgreement
    ): array {
        $name = trim($representativeName);
        if ($name === '') {
            return ['ok' => false, 'code' => 'VALIDATION', 'message' => '代表者氏名を入力してください。'];
        }
        if (mb_strlen($name) > 50) {
            return ['ok' => false, 'code' => 'VALIDATION', 'message' => '代表者氏名が長すぎます。'];
        }

        $tel = trim($phone);
        if ($tel === '') {
            return ['ok' => false, 'code' => 'VALIDATION', 'message' => '電話番号を入力してください。'];
        }
        $digits = preg_replace('/\D/', '', $tel) ?? '';
        if (!preg_match(self::PHONE_PATTERN, $tel) || strlen($digits) < 10 || strlen($digits) > 11) {
            return [
                'ok' => false,
                'code' => 'VALIDATION',
                'message' => '電話番号の形式が正しくありません（数字10〜11桁）。',
            ];
        }

        if ($requireAgreement && !$agreed) {
            return ['ok' => false, 'code' => 'NOT_AGREED', 'message' => '注意事項への同意が必要です。'];
        }

        return ['ok' => true, 'representative_name' => $name, 'phone' => $tel];
    }

    /**
     * 1枠分の人数・同行者を検証する。
     * 空文字の同行者は除外する（JS無効時に空欄がPOSTされても成立させるため）。
     *
     * @param list<string> $companionNames
     * @param array<string, mixed> $slot
     * @return array{ok: true, party_size: int, companion_names: list<string>}|array{ok: false, code: string, message: string}
     */
    public function validateItem(int $partySize, array $companionNames, array $slot): array
    {
        $maxPartySize = min((int) $slot['max_party_size'], self::HARD_MAX_PARTY_SIZE);

        if ($partySize < self::MIN_PARTY_SIZE || $partySize > $maxPartySize) {
            return [
                'ok' => false,
                'code' => 'VALIDATION',
                'message' => sprintf(
                    '%sの人数は%d〜%d名で選択してください。',
                    (string) $slot['name'],
                    self::MIN_PARTY_SIZE,
                    $maxPartySize
                ),
            ];
        }

        $names = [];
        foreach (array_slice($companionNames, 0, max(0, $maxPartySize - 1)) as $name) {
            $trimmed = trim((string) $name);
            if ($trimmed !== '') {
                $names[] = $trimmed;
            }
        }

        if (count($names) < $partySize - 1) {
            return [
                'ok' => false,
                'code' => 'VALIDATION',
                'message' => sprintf('%sの同行者氏名をすべて入力してください。', (string) $slot['name']),
            ];
        }
        foreach ($names as $name) {
            if (mb_strlen($name) > 50) {
                return ['ok' => false, 'code' => 'VALIDATION', 'message' => '同行者氏名が長すぎます。'];
            }
        }

        // 代表者を含めた人数なので、同行者は partySize - 1 名
        return [
            'ok' => true,
            'party_size' => $partySize,
            'companion_names' => array_slice($names, 0, $partySize - 1),
        ];
    }

    /**
     * 同一ページの1〜複数枠をまとめて予約する（全枠成功 or 全枠失敗）。
     *
     * @param array{
     *   page_id: int, user_id: ?int, source: string, representative_name: string,
     *   phone: string, agreed: bool,
     *   items: list<array{slot_id: int, party_size: int, companion_names: list<string>}>
     * } $input
     * @return array{ok: true, group_id: ?string, booking_ids: list<int>}|array{ok: false, code: string, message: string, slot_id?: int}
     */
    public function createGroupBooking(array $input, ?string $now = null): array
    {
        $now ??= Time::nowUtc();
        $isAdmin = $input['source'] === 'admin';

        $contact = $this->validateContact(
            $input['representative_name'],
            $input['phone'],
            $input['agreed'],
            !$isAdmin
        );
        if ($contact['ok'] !== true) {
            return $contact;
        }

        $page = $this->slots->findPageById($input['page_id']);
        if ($page === null) {
            return ['ok' => false, 'code' => 'PAGE_NOT_FOUND', 'message' => '予約ページが見つかりません。'];
        }
        if (!$isAdmin && $page['status'] !== 'published') {
            return ['ok' => false, 'code' => 'PAGE_CLOSED', 'message' => 'この予約ページは現在受け付けていません。'];
        }

        // 公開予約は「予約専用LINE公式アカウントの友だち追加済み」を必須にする。
        // 予約完了通知・リマインドはpushで送るため、友だちでないと届かない。
        // Cookieやフォームの値ではなくDBの友だち状態を見るので、
        // 画面を迂回してPOSTしても通らない。
        // 管理者代理予約（source=admin）はLINEを使わないため対象外。
        if (!$isAdmin && (int) $page['requires_line_login'] === 1) {
            $friendCheck = $this->requireLineFriend($input['user_id']);
            if ($friendCheck !== null) {
                return $friendCheck;
            }
        }

        // 同じ枠を2回選んでいても1件として扱う
        $items = [];
        foreach ($input['items'] as $item) {
            $items[(int) $item['slot_id']] ??= $item;
        }
        $items = array_values($items);

        if ($items === []) {
            return ['ok' => false, 'code' => 'NO_SELECTION', 'message' => '予約する枠を1つ以上選択してください。'];
        }
        if (!$isAdmin && (int) $page['allow_multi_slot_booking'] === 0 && count($items) > 1) {
            return [
                'ok' => false,
                'code' => 'TOO_MANY_SLOTS',
                'message' => 'このページでは一度に1つの枠のみ予約できます。',
            ];
        }
        if (!$isAdmin && count($items) > (int) $page['max_slots_per_checkout']) {
            return [
                'ok' => false,
                'code' => 'TOO_MANY_SLOTS',
                'message' => sprintf('一度に予約できるのは%d枠までです。', (int) $page['max_slots_per_checkout']),
            ];
        }

        // 枠ID昇順に並べ、ロック順序を決定的にする（デッドロック対策）
        usort($items, static fn (array $a, array $b): int => $a['slot_id'] <=> $b['slot_id']);

        $groupId = count($items) > 1 ? $this->uuid4() : null;

        try {
            return $this->db->transaction(function (Db $db) use ($items, $page, $input, $contact, $isAdmin, $now, $groupId): array {
                $prepared = [];

                foreach ($items as $item) {
                    $slotId = (int) $item['slot_id'];

                    // ---- ここが要。行ロックを取ってから残席を判定する ----
                    $slot = $db->first(
                        'SELECT * FROM reservation_slots WHERE id = ? FOR UPDATE',
                        [$slotId]
                    );
                    if ($slot === null || (int) $slot['reservation_page_id'] !== (int) $page['id']) {
                        throw new BookingException('SLOT_NOT_FOUND', '予約枠が見つかりません。');
                    }

                    $validated = $this->validateItem(
                        (int) $item['party_size'],
                        $item['companion_names'],
                        $slot
                    );
                    if ($validated['ok'] !== true) {
                        throw new BookingException($validated['code'], $validated['message'], $slotId);
                    }

                    if ((string) $slot['start_at'] <= $now) {
                        throw new BookingException(
                            'DEPARTED',
                            sprintf('%sは受付を終了しています。', (string) $slot['name']),
                            $slotId
                        );
                    }

                    $remaining = max(0, (int) $slot['capacity'] - (int) $slot['reserved_seats']);
                    $withinWindow =
                        ($slot['booking_open_at'] === null || (string) $slot['booking_open_at'] <= $now)
                        && ($slot['booking_close_at'] === null || (string) $slot['booking_close_at'] > $now);
                    $isBookable = $slot['booking_status'] === 'open' && $remaining > 0 && $withinWindow;

                    if (!$isAdmin && !$isBookable && $remaining > 0) {
                        throw new BookingException(
                            'CLOSED',
                            sprintf('%sは現在受付を停止しています。', (string) $slot['name']),
                            $slotId
                        );
                    }
                    if ($remaining < $validated['party_size']) {
                        throw new BookingException(
                            'FULL',
                            sprintf(
                                '%sが満席のため、予約は確定していません。選択内容を見直してください。（%sの残席：%d席）',
                                (string) $slot['name'],
                                (string) $slot['name'],
                                $remaining
                            ),
                            $slotId
                        );
                    }

                    $prepared[] = ['slot' => $slot, 'validated' => $validated];
                }

                // 二重予約（同一ユーザー・同一枠）— ロック下で再確認する
                if ($input['user_id'] !== null) {
                    $slotIds = array_map(
                        static fn (array $entry): int => (int) $entry['slot']['id'],
                        $prepared
                    );
                    $duplicated = $this->bookings->confirmedSlotIdsForUser((int) $input['user_id'], $slotIds);
                    if ($duplicated !== []) {
                        $name = 'この枠';
                        foreach ($prepared as $entry) {
                            if ((int) $entry['slot']['id'] === $duplicated[0]) {
                                $name = (string) $entry['slot']['name'];
                            }
                        }
                        throw new BookingException(
                            'DUPLICATE',
                            sprintf('%sは既に予約済みです。変更する場合は一度キャンセルしてください。', $name),
                            $duplicated[0]
                        );
                    }
                }

                $bookingIds = [];
                foreach ($prepared as $entry) {
                    $slotId = (int) $entry['slot']['id'];
                    $partySize = $entry['validated']['party_size'];

                    $bookingIds[] = $this->bookings->insert([
                        'reservation_slot_id' => $slotId,
                        'booking_group_id' => $groupId,
                        'user_id' => $input['user_id'],
                        'source' => $input['source'],
                        'representative_name' => $contact['representative_name'],
                        'phone' => $contact['phone'],
                        'party_size' => $partySize,
                        'companion_names_json' => json_encode(
                            $entry['validated']['companion_names'],
                            JSON_UNESCAPED_UNICODE
                        ),
                    ], $now);

                    // 予約席数カウンタを同一トランザクションで更新する。
                    // CHECK (reserved_seats <= capacity) がDBレベルの最終防衛線。
                    $db->run(
                        'UPDATE reservation_slots
                            SET reserved_seats = reserved_seats + ?, updated_at = ?
                          WHERE id = ?',
                        [$partySize, $now, $slotId]
                    );
                }

                return ['ok' => true, 'group_id' => $groupId, 'booking_ids' => $bookingIds];
            });
        } catch (BookingException $e) {
            $result = ['ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->slotId !== null) {
                $result['slot_id'] = $e->slotId;
            }
            return $result;
        } catch (\PDOException $e) {
            return $this->mapPdoException($e);
        }
    }

    /**
     * 単一枠の予約（管理者代理予約・互換用）。
     *
     * @param array{slot_id: int, user_id: ?int, source: string, representative_name: string,
     *   phone: string, party_size: int, companion_names: list<string>, agreed: bool} $input
     * @return array{ok: true, booking_id: int}|array{ok: false, code: string, message: string}
     */
    public function createBooking(array $input, ?string $now = null): array
    {
        $now ??= Time::nowUtc();
        $slot = $this->slots->findSlotWithPage($input['slot_id'], $now);
        if ($slot === null) {
            return ['ok' => false, 'code' => 'SLOT_NOT_FOUND', 'message' => '予約枠が見つかりません。'];
        }

        $result = $this->createGroupBooking([
            'page_id' => (int) $slot['reservation_page_id'],
            'user_id' => $input['user_id'],
            'source' => $input['source'],
            'representative_name' => $input['representative_name'],
            'phone' => $input['phone'],
            'agreed' => $input['agreed'],
            'items' => [[
                'slot_id' => $input['slot_id'],
                'party_size' => $input['party_size'],
                'companion_names' => $input['companion_names'],
            ]],
        ], $now);

        if ($result['ok'] !== true) {
            return ['ok' => false, 'code' => $result['code'], 'message' => $result['message']];
        }
        return ['ok' => true, 'booking_id' => $result['booking_ids'][0]];
    }

    /**
     * 予約をキャンセルする（論理削除）。キャンセルは枠単位。
     * 残席カウンタを同一トランザクションで戻す。
     *
     * @return array{ok: true}|array{ok: false, code: string, message: string}
     */
    public function cancelBooking(int $bookingId, ?int $userId, bool $asAdmin, ?string $now = null): array
    {
        $now ??= Time::nowUtc();

        try {
            return $this->db->transaction(function (Db $db) use ($bookingId, $userId, $asAdmin, $now): array {
                $booking = $db->first('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
                if ($booking === null) {
                    throw new BookingException('NOT_FOUND', '予約が見つかりません。');
                }

                if (!$asAdmin) {
                    if ($booking['user_id'] === null || (int) $booking['user_id'] !== $userId) {
                        // 他人の予約の存在を推測させないため NOT_FOUND 相当にする
                        throw new BookingException('FORBIDDEN', '予約が見つかりません。');
                    }
                }
                if ($booking['status'] === 'cancelled') {
                    throw new BookingException('ALREADY_CANCELLED', '既にキャンセル済みです。');
                }

                // 残席を戻すため枠をロックする
                $slot = $db->first(
                    'SELECT * FROM reservation_slots WHERE id = ? FOR UPDATE',
                    [(int) $booking['reservation_slot_id']]
                );
                if ($slot === null) {
                    throw new BookingException('NOT_FOUND', '予約枠が見つかりません。');
                }

                if (!$asAdmin) {
                    if ((string) $slot['start_at'] <= $now) {
                        throw new BookingException('DEPARTED', '開始後のキャンセルはできません。');
                    }
                    if ($slot['booking_close_at'] !== null && (string) $slot['booking_close_at'] <= $now) {
                        throw new BookingException(
                            'DEPARTED',
                            'キャンセル受付は締め切られました。お手数ですが直接お問い合わせください。'
                        );
                    }
                }

                $changed = $this->bookings->cancel($bookingId, $userId, !$asAdmin, $now);
                if ($changed === 0) {
                    throw new BookingException('NOT_FOUND', '予約が見つかりません。');
                }

                $db->run(
                    'UPDATE reservation_slots
                        SET reserved_seats = GREATEST(0, CAST(reserved_seats AS SIGNED) - ?),
                            updated_at = ?
                      WHERE id = ?',
                    [(int) $booking['party_size'], $now, (int) $slot['id']]
                );

                return ['ok' => true];
            });
        } catch (BookingException $e) {
            return ['ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()];
        }
    }

    /** 所有者チェック付きの予約取得。他人の予約IDを渡しても null。 @return array<string, mixed>|null */
    public function findOwnedBooking(int $bookingId, int $userId): ?array
    {
        $booking = $this->bookings->find($bookingId);
        if ($booking === null) {
            return null;
        }
        if ($booking['user_id'] === null || (int) $booking['user_id'] !== $userId) {
            return null;
        }
        return $booking;
    }

    /**
     * 定員変更。既存の予約人数を下回る値は拒否する。
     * DB側の CHECK (reserved_seats <= capacity) も同じ条件を守る。
     *
     * @return array{ok: true}|array{ok: false, code: string, message: string}
     */
    public function updateCapacity(int $slotId, int $capacity, ?string $now = null): array
    {
        $now ??= Time::nowUtc();
        try {
            return $this->db->transaction(function (Db $db) use ($slotId, $capacity, $now): array {
                $slot = $db->first('SELECT * FROM reservation_slots WHERE id = ? FOR UPDATE', [$slotId]);
                if ($slot === null) {
                    throw new BookingException('SLOT_NOT_FOUND', '予約枠が見つかりません。');
                }
                if ($capacity < (int) $slot['reserved_seats']) {
                    throw new BookingException(
                        'CAPACITY_TOO_SMALL',
                        '既存の予約人数を下回る定員には変更できません。'
                    );
                }
                $db->run(
                    'UPDATE reservation_slots SET capacity = ?, updated_at = ? WHERE id = ?',
                    [$capacity, $now, $slotId]
                );
                return ['ok' => true];
            });
        } catch (BookingException $e) {
            return ['ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()];
        }
    }

    /**
     * reserved_seats と実データの整合性を確認する（テスト・保守用）。
     *
     * @return list<array{slot_id: int, reserved_seats: int, actual: int}> 不一致の枠
     */
    public function findCounterMismatches(): array
    {
        $rows = $this->db->all(
            'SELECT s.id AS slot_id, s.reserved_seats,
                    COALESCE((SELECT SUM(b.party_size) FROM bookings b
                               WHERE b.reservation_slot_id = s.id AND b.status = \'confirmed\'), 0) AS actual
               FROM reservation_slots s
              HAVING reserved_seats <> actual'
        );
        return array_map(static fn (array $row): array => [
            'slot_id' => (int) $row['slot_id'],
            'reserved_seats' => (int) $row['reserved_seats'],
            'actual' => (int) $row['actual'],
        ], $rows);
    }

    /** @return array{ok: false, code: string, message: string} */
    private function mapPdoException(\PDOException $e): array
    {
        $message = $e->getMessage();

        // 生成カラム dedupe_key の UNIQUE 違反 = 同一ユーザー・同一枠の二重予約
        if (str_contains($message, 'ux_bookings_user_slot_confirmed') || str_contains($message, '1062')) {
            return [
                'ok' => false,
                'code' => 'DUPLICATE',
                'message' => '既に予約済みの枠が含まれています。マイ予約をご確認ください。',
            ];
        }
        // CHECK (reserved_seats <= capacity) 違反 = 定員超過
        if (str_contains($message, 'ck_slots_reserved') || str_contains($message, '4025')
            || str_contains($message, '3819')) {
            return [
                'ok' => false,
                'code' => 'FULL',
                'message' => '満席の枠が含まれるため、予約は確定していません。選択内容を見直してください。',
            ];
        }
        throw $e;
    }

    /**
     * LINEログイン必須ページの友だち追加チェック。
     * 問題なければ null、駄目ならエラー配列を返す。
     *
     * @return array{ok: false, code: string, message: string}|null
     */
    private function requireLineFriend(?int $userId): ?array
    {
        if ($userId === null) {
            return [
                'ok' => false,
                'code' => 'LOGIN_REQUIRED',
                'message' => 'ご予約にはLINEログインが必要です。',
            ];
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            return [
                'ok' => false,
                'code' => 'LOGIN_REQUIRED',
                'message' => 'ご予約にはLINEログインが必要です。',
            ];
        }

        // NULL（友だち状態を取得できなかった）も未追加として扱う。
        // 「たぶん友だち」で予約を通すと、通知が届かないまま当日を迎える。
        if ((int) ($user['is_line_friend'] ?? 0) !== 1) {
            return [
                'ok' => false,
                'code' => 'FRIEND_REQUIRED',
                'message' => '予約専用LINE公式アカウントの友だち追加が必要です。'
                    . '友だち追加のうえ、もう一度LINEログインしてからご予約ください。',
            ];
        }

        return null;
    }

    private function uuid4(): string
    {
        return Uuid::v4();
    }
}
