<?php

declare(strict_types=1);

/**
 * 通知の送信権取得（claim）の排他制御。
 *
 * 旧実装は claim() が status を変えずに attempt_count だけ増やしていたため、
 * 通知行が pending のまま残り、2つのプロセスの UPDATE が両方とも
 * `status IN ('pending','failed')` に一致して二重送信になり得た。
 *
 * 現在は `pending / failed -> sending` の遷移に成功した1プロセスだけが送信する。
 * ここでは実MySQLに対し、別接続・別プロセスの両方で検証する。
 */

use App\Database\Connection;
use App\Database\Db;
use App\Repositories\NotificationRepository;
use App\Services\ReminderService;
use App\Support\Config;
use App\Support\Uuid;

/** 同じ設定で独立したDB接続を張る（＝別セッション）。 */
function makeIsolatedNotifications(Config $config): NotificationRepository
{
    return new NotificationRepository(new Db((new Connection($config))->pdo()));
}

/** claim 対象となる確定予約を1件作る。 */
function makeNotifiableBooking(App\App $app): int
{
    $slotId = Fixtures::slot($app, Fixtures::page($app), [
        'start_at' => '2099-08-21 11:00:00',
        'reminder_at' => '2099-08-21 08:00:00',
    ]);
    $userId = Fixtures::user($app, 'U-claim-001');

    $booking = $app->booking->createBooking([
        'slot_id' => $slotId,
        'user_id' => $userId,
        'source' => 'line',
        'representative_name' => '山田太郎',
        'phone' => '090-1234-5678',
        'party_size' => 2,
        'companion_names' => ['花子'],
        'agreed' => true,
    ]);

    return $booking['booking_id'];
}

describe('通知の送信権（claim）', function (): void {
    test('別接続から同時に claim すると片方だけが true になる', function (): void {
        resetRequestState();
        $app = makeApp();
        $bookingId = makeNotifiableBooking($app);

        $a = makeIsolatedNotifications($app->config);
        $b = makeIsolatedNotifications($app->config);

        $first = $a->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
        $second = $b->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);

        assertNotNull($first, '先に取ったプロセスは送信できる');
        assertNull($second, '送信中の通知は別プロセスが取れない');
        assertTrue(Uuid::isValid($first['retry_key']), 'retry key は UUID 形式であること');

        $row = $app->notifications->find($bookingId, 'reminder');
        assertSame('sending', $row['status'], '送信中は sending で保持される');
        assertSame(1, (int) $row['attempt_count'], '試行回数は1回だけ増える');
    });

    test('送信権を持たないプロセスの finish() は状態を書き換えられない', function (): void {
        resetRequestState();
        $app = makeApp();
        $bookingId = makeNotifiableBooking($app);

        $a = makeIsolatedNotifications($app->config);
        $b = makeIsolatedNotifications($app->config);

        $claim = $a->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
        assertNotNull($claim);
        assertNull($b->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS));

        // 送信権を持たない側が finish しても sending のまま
        $b->finish($bookingId, 'reminder', 'someone-elses-token', 'requested', null);
        assertSame('sending', $app->notifications->find($bookingId, 'reminder')['status']);

        // token が一致する側の finish だけが通る
        $a->finish($bookingId, 'reminder', $claim['token'], 'requested', null);
        assertSame('requested', $app->notifications->find($bookingId, 'reminder')['status']);
    });

    test('sending -> requested / failed / skipped へ遷移する', function (): void {
        resetRequestState();
        $app = makeApp();

        foreach (['requested', 'failed', 'skipped'] as $final) {
            truncateAll($app->db);
            $bookingId = makeNotifiableBooking($app);

            $claim = $app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
            assertNotNull($claim);
            assertSame('sending', $app->notifications->find($bookingId, 'reminder')['status']);

            $app->notifications->finish($bookingId, 'reminder', $claim['token'], $final, null);
            assertSame($final, $app->notifications->find($bookingId, 'reminder')['status'], $final . ' へ遷移すること');
        }
    });

    test('requested になった通知は二度と claim できない', function (): void {
        resetRequestState();
        $app = makeApp();
        $bookingId = makeNotifiableBooking($app);

        $claim = $app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
        assertNotNull($claim);
        $app->notifications->finish($bookingId, 'reminder', $claim['token'], 'requested', null);

        assertNull($app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS));
    });

    test('failed になった通知は上限まで再 claim できる', function (): void {
        resetRequestState();
        $app = makeApp();
        $bookingId = makeNotifiableBooking($app);

        for ($attempt = 1; $attempt <= ReminderService::MAX_ATTEMPTS; $attempt++) {
            $claim = $app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
            assertNotNull($claim, $attempt . '回目は再試行できること');
            $app->notifications->finish($bookingId, 'reminder', $claim['token'], 'failed', 'server error');
        }

        assertNull(
            $app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS),
            '上限を超えたら諦めること'
        );
    });

    test('送信中に落ちた通知は放置時間を過ぎれば再取得できる', function (): void {
        resetRequestState();
        $app = makeApp();
        $bookingId = makeNotifiableBooking($app);

        $staleClaim = $app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
        assertNotNull($staleClaim);
        // finish されないままプロセスが落ちた状況を作る
        $app->db->run(
            'UPDATE notifications SET updated_at = ? WHERE booking_id = ? AND notification_type = ?',
            [
                gmdate('Y-m-d H:i:s', time() - NotificationRepository::STALE_SENDING_SECONDS - 60),
                $bookingId,
                'reminder',
            ]
        );

        $freshClaim = $app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
        assertNotNull($freshClaim, '放置された sending は再取得できること');
        assertNotSame($staleClaim['token'], $freshClaim['token'], '再取得すると claim_token が変わること');
        assertSame(
            $staleClaim['retry_key'],
            $freshClaim['retry_key'],
            'stale再取得でも LINE retry key は同じものを使い続けること'
        );
        assertSame(2, (int) $app->notifications->find($bookingId, 'reminder')['attempt_count']);

        // 後から目を覚ました元のプロセスの finish は無視される
        $app->notifications->finish($bookingId, 'reminder', $staleClaim['token'], 'requested', null);
        assertSame('sending', $app->notifications->find($bookingId, 'reminder')['status']);

        $app->notifications->finish($bookingId, 'reminder', $freshClaim['token'], 'requested', null);
        assertSame('requested', $app->notifications->find($bookingId, 'reminder')['status']);
    });

    test('送信中の通知はリマインド対象から外れる', function (): void {
        resetRequestState();
        $app = makeApp();
        $bookingId = makeNotifiableBooking($app);
        $now = '2099-08-21 08:01:00';

        assertSame(1, count($app->notifications->listDueReminderTargets($now, ReminderService::MAX_ATTEMPTS)));

        assertNotNull($app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS, $now));
        assertSame(
            0,
            count($app->notifications->listDueReminderTargets($now, ReminderService::MAX_ATTEMPTS)),
            '送信中は別のCronが拾わないこと'
        );
    });

    test('別プロセスから同時に claim しても Messaging API 相当処理は1回だけ', function (): void {
        resetRequestState();
        $app = makeApp();
        $bookingId = makeNotifiableBooking($app);

        $apiLog = sys_get_temp_dir() . '/rakko-claim-' . getmypid() . '-' . $bookingId . '.log';
        @unlink($apiLog);

        $script = dirname(__DIR__) . '/tests/support/claim-worker.php';
        assertTrue(is_file($script), 'ワーカースクリプトが存在すること');

        $workers = 8;
        $procs = [];
        for ($i = 0; $i < $workers; $i++) {
            $procs[$i] = popen(sprintf(
                '%s %s %d %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($script),
                $bookingId,
                escapeshellarg('reminder'),
                escapeshellarg($apiLog)
            ), 'r');
        }

        $claimed = 0;
        $skipped = 0;
        $errors = [];
        foreach ($procs as $handle) {
            $output = trim((string) stream_get_contents($handle));
            pclose($handle);
            if (str_contains($output, 'CLAIMED')) {
                $claimed++;
            } elseif (str_contains($output, 'SKIPPED')) {
                $skipped++;
            } else {
                $errors[] = $output;
            }
        }

        assertSame([], $errors, 'ワーカーが例外なく完了すること');
        assertSame(1, $claimed, '送信権を取れるのは1プロセスだけ（実際: ' . $claimed . '）');
        assertSame($workers - 1, $skipped, '残りは送信をスキップすること');

        $apiCalls = is_file($apiLog)
            ? count(array_filter(explode("\n", (string) file_get_contents($apiLog)), static fn ($l) => trim($l) !== ''))
            : 0;
        @unlink($apiLog);

        assertSame(1, $apiCalls, 'Messaging API 相当処理が1回だけ実行されること（実際: ' . $apiCalls . '）');

        $row = $app->notifications->find($bookingId, 'reminder');
        assertSame('requested', $row['status']);
        assertSame(1, (int) $row['attempt_count'], '試行回数も1回だけ増えること');
    });

    test('予約完了通知とリマインドは互いに独立して claim できる', function (): void {
        resetRequestState();
        $app = makeApp();
        $bookingId = makeNotifiableBooking($app);

        assertNotNull($app->notifications->claim($bookingId, 'booking_confirmation', ReminderService::MAX_ATTEMPTS));
        assertNotNull($app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS), '種別が違えば別枠');
        assertNull($app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS));
    });
});

/** FakeHttpClient の呼び出しから X-Line-Retry-Key を取り出す。 */
function retryKeysOf(FakeHttpClient $http): array
{
    $keys = [];
    foreach ($http->calls as $call) {
        if (str_contains($call['url'], '/v2/bot/message/push')) {
            $keys[] = $call['headers']['X-Line-Retry-Key'] ?? null;
        }
    }
    return $keys;
}

describe('LINE retry key（ネットワーク境界の二重送信防止）', function (): void {
    /** リマインド対象の予約を1件作る。 */
    $prepare = static function (FakeHttpClient $http): array {
        resetRequestState();
        $app = makeApp([], $http);
        $slotId = Fixtures::slot($app, Fixtures::page($app), [
            'start_at' => '2099-08-21 11:00:00',
            'reminder_at' => '2099-08-21 08:00:00',
        ]);
        $userId = Fixtures::user($app, 'U-retry-001');

        $booking = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 2,
            'companion_names' => ['花子'],
            'agreed' => true,
        ]);

        return [$app, $booking['booking_id']];
    };

    test('初回送信から X-Line-Retry-Key が必ず付く', function () use ($prepare): void {
        $http = new FakeHttpClient(200, '{}');
        [$app, $bookingId] = $prepare($http);

        assertSame('requested', $app->reminders->sendBookingConfirmation([$bookingId]));

        $keys = retryKeysOf($http);
        assertSame(1, count($keys));
        assertNotNull($keys[0], '初回からヘッダが付いていること');
        assertTrue(Uuid::isValid((string) $keys[0]), 'LINE仕様のUUID形式であること');

        $stored = $app->notifications->find($bookingId, 'booking_confirmation');
        assertSame($stored['line_retry_key'], $keys[0], 'DBに保存したキーを送っていること');
    });

    test('5xx の再試行では同じ retry key を再利用する', function () use ($prepare): void {
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 500, 'body' => 'server error'],
            ['status' => 500, 'body' => 'server error'],
            ['status' => 200, 'body' => '{}'],
        ];
        [$app, $bookingId] = $prepare($http);

        assertSame('failed', $app->reminders->sendBookingConfirmation([$bookingId]));
        assertSame('failed', $app->reminders->sendBookingConfirmation([$bookingId]));
        assertSame('requested', $app->reminders->sendBookingConfirmation([$bookingId]));

        $keys = retryKeysOf($http);
        assertSame(3, count($keys), '3回pushしていること');
        assertSame(1, count(array_unique($keys)), '3回とも同じ retry key であること');
        assertTrue(Uuid::isValid((string) $keys[0]));
    });

    test('タイムアウト（status 0）の再試行でも同じ retry key を再利用する', function () use ($prepare): void {
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 0, 'body' => ''],
            ['status' => 200, 'body' => '{}'],
        ];
        [$app, $bookingId] = $prepare($http);

        assertSame('failed', $app->reminders->sendBookingConfirmation([$bookingId]));
        assertSame('requested', $app->reminders->sendBookingConfirmation([$bookingId]));

        $keys = retryKeysOf($http);
        assertSame(2, count($keys));
        assertSame(1, count(array_unique($keys)), 'タイムアウト後も同じキーで再送すること');
    });

    test('stale再取得でも同じ retry key を使う', function () use ($prepare): void {
        $http = new FakeHttpClient();
        // 1回目: LINEは受理したが、こちらがDBへ書く前に落ちた状況を作る
        $http->responses = [
            ['status' => 200, 'body' => '{}'],
            ['status' => 409, 'body' => '{"message":"Duplicate request"}'],
        ];
        [$app, $bookingId] = $prepare($http);

        $claim = $app->notifications->claim($bookingId, 'booking_confirmation', ReminderService::MAX_ATTEMPTS);
        assertNotNull($claim);
        $firstKey = $claim['retry_key'];

        // push は成功したが finish できずにプロセスが落ちた（sending のまま放置）
        $app->messenger->push('U-retry-001', '本文', $firstKey);
        $app->db->run(
            'UPDATE notifications SET updated_at = ? WHERE booking_id = ? AND notification_type = ?',
            [
                gmdate('Y-m-d H:i:s', time() - NotificationRepository::STALE_SENDING_SECONDS - 60),
                $bookingId,
                'booking_confirmation',
            ]
        );

        // stale再取得したプロセスが送り直す
        assertSame('requested', $app->reminders->sendBookingConfirmation([$bookingId]));

        $keys = retryKeysOf($http);
        assertSame(2, count($keys));
        assertSame($firstKey, $keys[0]);
        assertSame($firstKey, $keys[1], 'stale再取得でも最初の retry key を使い続けること');
    });

    test('409 が返ったら再送せず requested 扱いにする', function () use ($prepare): void {
        $http = new FakeHttpClient(409, '{"message":"Duplicate request"}');
        [$app, $bookingId] = $prepare($http);

        assertSame(
            'requested',
            $app->reminders->sendBookingConfirmation([$bookingId]),
            '既に受理済みなので成功として確定させる'
        );

        $row = $app->notifications->find($bookingId, 'booking_confirmation');
        assertSame('requested', $row['status']);
        assertNotNull($row['requested_at'], '送信時刻が記録されること');
        assertSame(1, count(retryKeysOf($http)), '409のあと再送しないこと');

        // 確定済みなので、以降のCronが拾って送り直すこともない
        assertNull($app->notifications->claim($bookingId, 'booking_confirmation', ReminderService::MAX_ATTEMPTS));
        assertSame('already', $app->reminders->sendBookingConfirmation([$bookingId]));
        assertSame(1, count(retryKeysOf($http)));
    });

    test('push() は 409 を deduplicated として返す', function (): void {
        $http = new FakeHttpClient(409, '{"message":"Duplicate request"}');
        resetRequestState();
        $app = makeApp([], $http);

        $result = $app->messenger->push('U-x', '本文', Uuid::v4());
        assertTrue($result['ok'], '409 は成功扱い');
        assertTrue($result['deduplicated']);

        $ok = new FakeHttpClient(200, '{}');
        resetRequestState();
        $normal = makeApp([], $ok)->messenger->push('U-x', '本文', Uuid::v4());
        assertTrue($normal['ok']);
        assertFalse($normal['deduplicated'], '通常の200は重複ではない');
    });

    test('4xx の通常エラーはこれまでどおり skipped', function () use ($prepare): void {
        $http = new FakeHttpClient(400, '{"message":"invalid"}');
        [$app, $bookingId] = $prepare($http);

        assertSame('skipped', $app->reminders->sendBookingConfirmation([$bookingId]));
        assertSame('skipped', $app->notifications->find($bookingId, 'booking_confirmation')['status']);
    });

    test('別の通知には別の retry key が割り当てられる', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp([], $http);
        $pageId = Fixtures::page($app);
        $slotId = Fixtures::slot($app, $pageId, ['capacity' => 20]);

        $ids = [];
        foreach ([1, 2] as $i) {
            $booking = $app->booking->createBooking([
                'slot_id' => $slotId,
                'user_id' => Fixtures::user($app, 'U-retry-multi-' . $i, '利用者' . $i),
                'source' => 'line',
                'representative_name' => '予約者' . $i,
                'phone' => '090-0000-000' . $i,
                'party_size' => 1,
                'companion_names' => [],
                'agreed' => true,
            ]);
            $ids[] = $booking['booking_id'];
        }

        foreach ($ids as $bookingId) {
            assertSame('requested', $app->reminders->sendBookingConfirmation([$bookingId]));
        }

        $keys = retryKeysOf($http);
        assertSame(2, count($keys));
        assertNotSame($keys[0], $keys[1], '別の通知には別のキーを使うこと');

        // 予約完了通知とリマインドも別のキー
        $confirmation = $app->notifications->find($ids[0], 'booking_confirmation');
        $reminderClaim = $app->notifications->claim($ids[0], 'reminder', ReminderService::MAX_ATTEMPTS);
        assertNotNull($reminderClaim);
        assertNotSame(
            $confirmation['line_retry_key'],
            $reminderClaim['retry_key'],
            '種別が違えば別のキーであること'
        );
    });

    test('一括予約は1通にまとめるため、同じ顔ぶれの再送は同じキーになる', function (): void {
        $http = new FakeHttpClient();
        $http->responses = [
            ['status' => 500, 'body' => 'server error'],
            ['status' => 200, 'body' => '{}'],
        ];
        resetRequestState();
        $app = makeApp([], $http);
        $pageId = Fixtures::page($app);
        $outbound = Fixtures::slot($app, $pageId, ['name' => '行き']);
        $return = Fixtures::slot($app, $pageId, [
            'name' => '帰り',
            'start_at' => '2099-08-21 23:10:00',
            'sort_order' => 2,
        ]);

        $result = $app->booking->createGroupBooking([
            'page_id' => $pageId,
            'user_id' => Fixtures::user($app, 'U-retry-group'),
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'agreed' => true,
            'items' => [
                ['slot_id' => $outbound, 'party_size' => 2, 'companion_names' => ['花子']],
                ['slot_id' => $return, 'party_size' => 2, 'companion_names' => ['花子']],
            ],
        ]);
        assertTrue($result['ok']);

        assertSame('failed', $app->reminders->sendBookingConfirmation($result['booking_ids']));
        assertSame('requested', $app->reminders->sendBookingConfirmation($result['booking_ids']));

        $keys = retryKeysOf($http);
        assertSame(2, count($keys), '2件の予約を1通にまとめて2回送っていること');
        assertSame(1, count(array_unique($keys)), '同じ顔ぶれの再送は同じキーであること');
        assertTrue(Uuid::isValid((string) $keys[0]), 'まとめ送信のキーもUUID形式であること');
    });

    test('導出キーは要素が同じなら安定し、変われば別になる', function (): void {
        $a = Uuid::v4();
        $b = Uuid::v4();

        assertSame(Uuid::derive([$a, $b]), Uuid::derive([$a, $b]), '同じ集合なら同じキー');
        assertSame(Uuid::derive([$a, $b]), Uuid::derive([$b, $a]), '順序に依存しないこと');
        assertNotSame(Uuid::derive([$a, $b]), Uuid::derive([$a]), '顔ぶれが変われば別キー');
        assertTrue(Uuid::isValid(Uuid::derive([$a, $b])), 'UUID形式であること');
    });

    test('不正な形式の retry key はヘッダに載せない', function (): void {
        $http = new FakeHttpClient(200, '{}');
        resetRequestState();
        $app = makeApp([], $http);

        $app->messenger->push('U-x', '本文', 'not-a-uuid');
        assertSame([null], retryKeysOf($http), 'LINEに400で弾かれる値は送らないこと');
    });
});
