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

        $token = $a->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
        assertNotNull($token);
        assertNull($b->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS));

        // 送信権を持たない側が finish しても sending のまま
        $b->finish($bookingId, 'reminder', 'someone-elses-token', 'requested', null);
        assertSame('sending', $app->notifications->find($bookingId, 'reminder')['status']);

        // token が一致する側の finish だけが通る
        $a->finish($bookingId, 'reminder', $token, 'requested', null);
        assertSame('requested', $app->notifications->find($bookingId, 'reminder')['status']);
    });

    test('sending -> requested / failed / skipped へ遷移する', function (): void {
        resetRequestState();
        $app = makeApp();

        foreach (['requested', 'failed', 'skipped'] as $final) {
            truncateAll($app->db);
            $bookingId = makeNotifiableBooking($app);

            $token = $app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
            assertNotNull($token);
            assertSame('sending', $app->notifications->find($bookingId, 'reminder')['status']);

            $app->notifications->finish($bookingId, 'reminder', $token, $final, null);
            assertSame($final, $app->notifications->find($bookingId, 'reminder')['status'], $final . ' へ遷移すること');
        }
    });

    test('requested になった通知は二度と claim できない', function (): void {
        resetRequestState();
        $app = makeApp();
        $bookingId = makeNotifiableBooking($app);

        $token = $app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
        assertNotNull($token);
        $app->notifications->finish($bookingId, 'reminder', $token, 'requested', null);

        assertNull($app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS));
    });

    test('failed になった通知は上限まで再 claim できる', function (): void {
        resetRequestState();
        $app = makeApp();
        $bookingId = makeNotifiableBooking($app);

        for ($attempt = 1; $attempt <= ReminderService::MAX_ATTEMPTS; $attempt++) {
            $token = $app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
            assertNotNull($token, $attempt . '回目は再試行できること');
            $app->notifications->finish($bookingId, 'reminder', $token, 'failed', 'server error');
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

        $staleToken = $app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
        assertNotNull($staleToken);
        // finish されないままプロセスが落ちた状況を作る
        $app->db->run(
            'UPDATE notifications SET updated_at = ? WHERE booking_id = ? AND notification_type = ?',
            [
                gmdate('Y-m-d H:i:s', time() - NotificationRepository::STALE_SENDING_SECONDS - 60),
                $bookingId,
                'reminder',
            ]
        );

        $freshToken = $app->notifications->claim($bookingId, 'reminder', ReminderService::MAX_ATTEMPTS);
        assertNotNull($freshToken, '放置された sending は再取得できること');
        assertNotSame($staleToken, $freshToken, '再取得すると token が変わること');
        assertSame(2, (int) $app->notifications->find($bookingId, 'reminder')['attempt_count']);

        // 後から目を覚ました元のプロセスの finish は無視される
        $app->notifications->finish($bookingId, 'reminder', $staleToken, 'requested', null);
        assertSame('sending', $app->notifications->find($bookingId, 'reminder')['status']);

        $app->notifications->finish($bookingId, 'reminder', $freshToken, 'requested', null);
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
