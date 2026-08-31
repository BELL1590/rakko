<?php

declare(strict_types=1);

use App\Repositories\UserRepository;
use App\Services\ReminderService;

/**
 * @return array{page_id: int, user_id: int, booking_ids: list<int>}
 */
function reviewCreateBookings(App\App $app, bool $multiple = false, string $lineId = 'U-review-hardening'): array
{
    $pageId = Fixtures::page($app, [
        'slug' => $multiple ? 'review-hardening-group' : 'review-hardening-single',
    ]);
    $slot1 = Fixtures::slot($app, $pageId, [
        'name' => '行き',
        'sort_order' => 1,
        'start_at' => '2099-09-01 01:00:00',
    ]);
    $userId = Fixtures::user($app, $lineId);

    $items = [[
        'slot_id' => $slot1,
        'party_size' => 1,
        'companion_names' => [],
    ]];

    if ($multiple) {
        $slot2 = Fixtures::slot($app, $pageId, [
            'name' => '帰り',
            'sort_order' => 2,
            'start_at' => '2099-09-01 10:00:00',
        ]);
        $items[] = [
            'slot_id' => $slot2,
            'party_size' => 1,
            'companion_names' => [],
        ];
    }

    $result = $app->booking->createGroupBooking([
        'page_id' => $pageId,
        'user_id' => $userId,
        'source' => 'line',
        'representative_name' => '山田太郎',
        'phone' => '090-1234-5678',
        'agreed' => true,
        'items' => $items,
    ]);

    assertTrue($result['ok'], 'レビュー用予約が作成できること');

    return [
        'page_id' => $pageId,
        'user_id' => $userId,
        'booking_ids' => $result['booking_ids'],
    ];
}

describe('Review fix: LINE友だち状態の鮮度', function (): void {
    test('0006 migrationでfriend_status_checked_atが追加される', function (): void {
        resetRequestState();
        $app = makeApp();
        $column = $app->db->first("SHOW COLUMNS FROM users LIKE 'friend_status_checked_at'");
        assertNotNull($column);
        assertSame('YES', $column['Null']);
    });

    test('LINEでtrueを確認できた時刻を保存する', function (): void {
        resetRequestState();
        $app = makeApp();
        $user = $app->users->upsertByLineId('U-fresh-friend', 'テスト太郎', null, true);

        assertSame(1, (int) $user['is_line_friend']);
        assertNotNull($user['friend_status_checked_at']);
        $forBooking = $app->users->findById((int) $user['id']);
        assertSame(1, (int) $forBooking['is_line_friend']);
    });

    test('5分を超えた過去のtrueは予約判定ではunknownに落とす', function (): void {
        resetRequestState();
        $app = makeApp();
        $pageId = Fixtures::page($app, ['slug' => 'stale-friend-review']);
        $slotId = Fixtures::slot($app, $pageId);
        $userId = Fixtures::user($app, 'U-stale-review');

        $app->db->run(
            'UPDATE users SET friend_status_checked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id = ?',
            [$userId]
        );

        $user = $app->users->findById($userId);
        assertNull($user['is_line_friend'], '古いtrueを予約に使わないこと');

        $result = $app->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => $userId,
            'source' => 'line',
            'representative_name' => '山田太郎',
            'phone' => '090-1234-5678',
            'party_size' => 1,
            'companion_names' => [],
            'agreed' => true,
        ]);

        assertFalse($result['ok']);
        assertSame('FRIEND_REQUIRED', $result['code']);
    });

    test('API取得失敗はtrueと確認時刻を両方失効させる', function (): void {
        resetRequestState();
        $app = makeApp();
        $app->users->upsertByLineId('U-friend-api-fail', 'テスト太郎', null, true);
        $user = $app->users->upsertByLineId('U-friend-api-fail', 'テスト太郎', null, null);

        assertNull($user['is_line_friend']);
        assertNull($user['friend_status_checked_at']);
    });

    test('既知のfalseはunknown後もfalseだが確認時刻は失効する', function (): void {
        resetRequestState();
        $app = makeApp();
        $app->users->upsertByLineId('U-friend-false-review', 'テスト太郎', null, false);
        $user = $app->users->upsertByLineId('U-friend-false-review', 'テスト太郎', null, null);

        assertSame(0, (int) $user['is_line_friend']);
        assertNull($user['friend_status_checked_at']);
    });
});

describe('Review fix: 予約完了通知の復旧とatomic group claim', function (): void {
    test('通知行作成前に落ちた予約もCronが拾って初回送信できる', function (): void {
        resetRequestState();
        $http = new FakeHttpClient(200, '{}');
        $app = makeApp([], $http);
        $fixture = reviewCreateBookings($app, false, 'U-missing-notification');
        $bookingId = $fixture['booking_ids'][0];

        assertNull(
            $app->notifications->find($bookingId, 'booking_confirmation'),
            '初回通知処理前なので通知行が無いこと'
        );

        $summary = $app->reminders->processFailedBookingConfirmations();
        assertSame(1, $summary['checked']);
        assertSame(1, $summary['requested']);

        $notification = $app->notifications->find($bookingId, 'booking_confirmation');
        assertSame('requested', $notification['status']);
        assertSame(1, (int) $notification['attempt_count']);
    });

    test('グループの1件が他プロセスにclaim済みなら残りだけをclaimしない', function (): void {
        resetRequestState();
        $app = makeApp();
        $fixture = reviewCreateBookings($app, true, 'U-partial-claim');
        [$firstId, $secondId] = $fixture['booking_ids'];

        $first = $app->notifications->claim(
            $firstId,
            'booking_confirmation',
            ReminderService::MAX_ATTEMPTS
        );
        assertNotNull($first);

        $groupClaim = $app->notifications->claimMany(
            [$firstId, $secondId],
            'booking_confirmation',
            ReminderService::MAX_ATTEMPTS
        );
        assertNull($groupClaim, '1件でもclaim不可ならグループ全体を取らないこと');

        $firstRow = $app->notifications->find($firstId, 'booking_confirmation');
        $secondRow = $app->notifications->find($secondId, 'booking_confirmation');
        assertSame('sending', $firstRow['status']);
        assertSame(1, (int) $firstRow['attempt_count']);
        assertSame('pending', $secondRow['status']);
        assertSame(0, (int) $secondRow['attempt_count'], '残りだけを部分claimしないこと');
    });

    test('8プロセス同時でも一括予約全件を取れるのは1プロセスだけ', function (): void {
        resetRequestState();
        $app = makeApp();
        $fixture = reviewCreateBookings($app, true, 'U-group-concurrency');
        $bookingIds = $fixture['booking_ids'];

        $apiLog = sys_get_temp_dir() . '/rakko-group-claim-' . getmypid() . '-' . $bookingIds[0] . '.log';
        @unlink($apiLog);

        $script = dirname(__DIR__) . '/tests/support/group-claim-worker.php';
        assertTrue(is_file($script), 'グループclaimワーカーが存在すること');

        $workers = 8;
        $procs = [];
        for ($i = 0; $i < $workers; $i++) {
            $procs[$i] = popen(sprintf(
                '%s %s %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($script),
                escapeshellarg(implode(',', $bookingIds)),
                escapeshellarg('booking_confirmation'),
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

        assertSame([], $errors, '全ワーカーが例外なく完了すること');
        assertSame(1, $claimed, '一括予約全件を取れるのは1プロセスだけ');
        assertSame($workers - 1, $skipped);

        $apiCalls = is_file($apiLog)
            ? count(array_filter(
                explode("\n", (string) file_get_contents($apiLog)),
                static fn ($line): bool => trim((string) $line) !== ''
            ))
            : 0;
        @unlink($apiLog);
        assertSame(1, $apiCalls, '一括メッセージ相当処理は1回だけ');

        foreach ($bookingIds as $bookingId) {
            $row = $app->notifications->find($bookingId, 'booking_confirmation');
            assertSame('requested', $row['status']);
            assertSame(1, (int) $row['attempt_count']);
        }
    });
});

describe('Review fix: app-root deployment', function (): void {
    test('標準XSERVER配置の兄弟app-rootを自動検出できるコードになっている', function (): void {
        $source = file_get_contents(dirname(__DIR__) . '/public/index.php');
        assertTrue(is_string($source));
        assertContains("getenv('RAKKO_APP_ROOT')", $source, '環境変数overrideを維持する');
        assertContains("dirname(__DIR__) . '/app-root'", $source, 'public_html兄弟のapp-rootを候補にする');
        assertContains('array_unique($candidates)', $source);
        assertNotContains("\$root = '/home/", $source, '本番固有パスを埋め込まない');
    });
});
