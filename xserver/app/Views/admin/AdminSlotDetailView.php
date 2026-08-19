<?php

declare(strict_types=1);

namespace App\Views\admin;

use App\Services\CsvService;
use App\Support\Html;
use App\Support\Time;
use App\Views\Layout;

/**
 * 管理画面: 予約枠の詳細（名簿 / 受付確認 / 代理予約 / 枠の設定 / CSV）。
 *
 * Workers版 src/views/admin/slot-detail.ts の移植。
 * ルート・POST先・name属性（op=dec|inc|all, slot_id, q など）は一切変更していない。
 */
final class AdminSlotDetailView
{
    private const NOTIFICATION_LABEL = [
        'pending' => '未送信',
        'requested' => '送信要求済み',
        'failed' => '失敗',
        'skipped' => 'スキップ',
    ];

    /**
     * @param array<int,array<string,mixed>> $notifications
     */
    private static function notificationCell(array $notifications, int $bookingId): string
    {
        $parts = [];
        foreach ($notifications as $n) {
            if ((int) $n['booking_id'] !== $bookingId) {
                continue;
            }
            $typeLabel = (string) $n['notification_type'] === 'reminder' ? 'リマインド' : '予約完了';
            $status = self::NOTIFICATION_LABEL[(string) $n['status']] ?? (string) $n['status'];
            $attempts = (int) ($n['attempt_count'] ?? 0);
            $parts[] = $typeLabel . '：' . Html::esc($status) . ($attempts > 1 ? '(' . $attempts . '回)' : '');
        }

        if ($parts === []) {
            return '<span class="muted">-</span>';
        }

        return implode(' ／ ', $parts);
    }

    /**
     * @param array<string,mixed> $page
     * @param array<string,mixed> $slot
     * @param array<int,array<string,mixed>> $bookings
     * @param array<int,array<string,mixed>> $notifications
     * @param array{type:string,message:string}|null $alert
     */
    public static function detail(
        array $page,
        array $slot,
        array $bookings,
        array $notifications,
        string $search,
        string $csrfToken,
        ?array $alert = null,
    ): string {
        $label = (string) ($page['checkin_label'] ?? '') !== '' ? (string) $page['checkin_label'] : '受付';
        $slotId = (int) $slot['id'];
        $pageId = (int) $page['id'];

        $seatsTotal = 0;
        $checkedTotal = 0;
        foreach ($bookings as $booking) {
            if ((string) $booking['status'] === 'cancelled') {
                continue;
            }
            $seatsTotal += (int) $booking['party_size'];
            $checkedTotal += (int) $booking['checked_in_count'];
        }
        $remainingToCheck = max(0, $seatsTotal - $checkedTotal);
        // 検索中は一覧が絞り込まれるため、合計が枠全体と一致しないことを明示する
        $isFiltered = $search !== '';

        $rows = [];
        foreach ($bookings as $booking) {
            $bookingId = (int) $booking['id'];
            $partySize = (int) $booking['party_size'];
            $checkedIn = (int) $booking['checked_in_count'];
            $companions = CsvService::companions($booking);
            $cancelled = (string) $booking['status'] === 'cancelled';
            $done = $checkedIn >= $partySize;
            $partial = $checkedIn > 0 && !$done;

            $stateBadge = $cancelled
                ? '<span class="badge badge-cancelled">キャンセル</span>'
                : ($done
                    ? '<span class="badge badge-confirmed">' . Html::esc($label) . '済み</span>'
                    : ($partial
                        ? '<span class="badge badge-proxy">一部' . Html::esc($label) . '</span>'
                        : '<span class="badge badge-closed">未' . Html::esc($label) . '</span>'));

            $isAdminSource = (string) $booking['source'] === 'admin';

            $ops = $cancelled ? '' : '<form class="inline-form" method="post" action="/admin/bookings/' . $bookingId . '/checkin">
    <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">
    <input type="hidden" name="slot_id" value="' . $slotId . '">
    <button class="counter-btn" type="submit" name="op" value="dec" aria-label="' . Html::esc($label) . '人数を1減らす">−</button>
    <button class="counter-btn" type="submit" name="op" value="inc" aria-label="' . Html::esc($label) . '人数を1増やす">＋</button>
    <button class="btn btn-sm btn-secondary btn-all" type="submit" name="op" value="all">全員' . Html::esc($label) . '</button>
  </form>
  <form method="post" action="/admin/bookings/' . $bookingId . '/cancel" style="margin:10px 0 0"
      onsubmit="return confirm(\'予約 #' . $bookingId . ' をキャンセルします。よろしいですか？\');">
    <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">
    <input type="hidden" name="slot_id" value="' . $slotId . '">
    <button class="btn btn-sm btn-danger-outline" type="submit">この予約をキャンセル</button>
  </form>';

            $rows[] = '<article class="book-row' . ($done && !$cancelled ? ' is-boarded' : '')
                . ($cancelled ? ' is-cancelled' : '') . '">
  <div class="book-row__head">
    <span class="book-row__id">#' . $bookingId . '</span>
    <span class="book-row__name">' . Html::esc($booking['representative_name']) . '</span>
    <span class="book-row__size">' . $partySize . '名</span>
    <span class="badge ' . ($isAdminSource ? 'badge-proxy' : 'badge-line') . '" style="margin-left:auto">'
                . ($isAdminSource ? '管理者代理' : 'LINE') . '</span>
  </div>
  <p class="book-row__meta">
    <a href="tel:' . Html::esc($booking['phone']) . '">' . Html::esc($booking['phone']) . '</a><br>
    同行者：' . (Html::esc(implode('、', $companions)) ?: '-') . '<br>
    予約 ' . Html::esc(Time::formatJstIsoLike((string) $booking['created_at'])) . ' ・ 通知 '
                . self::notificationCell($notifications, $bookingId)
                . (($booking['booking_group_id'] ?? null) !== null ? ' ・ まとめて予約' : '') . '
  </p>
  <div class="book-row__foot">
    ' . $stateBadge . '
    <span class="book-row__count">' . $checkedIn . ' <small>/ ' . $partySize . ' ' . Html::esc($label) . '</small></span>
  </div>
  ' . $ops . '
</article>';
        }

        $partyOptions = '';
        for ($n = 1; $n <= (int) $slot['max_party_size']; $n++) {
            $partyOptions .= '<option value="' . $n . '">' . $n . '名</option>';
        }

        $endAt = $slot['end_at'] ?? null;
        $place = ($slot['origin'] ?? null) !== null && ($slot['destination'] ?? null) !== null
            ? Html::esc($slot['origin']) . ' → ' . Html::esc($slot['destination'])
            : Html::esc($slot['location'] ?? '-');
        $bookingStatusBadge = match ((string) $slot['booking_status']) {
            'open' => '<span class="badge badge-open">受付中</span>',
            'hidden' => '<span class="badge badge-proxy">非表示</span>',
            default => '<span class="badge badge-closed">受付停止</span>',
        };
        $reminderNote = ($slot['reminder_at'] ?? null) !== null
            ? 'リマインド ' . Html::esc(Time::formatJstLong((string) $slot['reminder_at'])) . ' 送信予定'
            : 'リマインド設定なし';
        $checkedPct = $seatsTotal > 0 ? min(100, (int) round($checkedTotal / $seatsTotal * 100)) : 0;

        $content = '
<p><a href="/admin/reservations/' . $pageId . '">← ' . Html::esc($page['title']) . 'へ</a></p>
<h2>' . Html::esc($slot['name']) . '</h2>

<div class="kpi-grid">
  <div class="kpi is-primary">
    <p class="kpi__label">予約人数</p>
    <p class="kpi__value">' . (int) $slot['booked_seats'] . '<small> / ' . (int) $slot['capacity'] . '名</small></p>
    <p class="kpi__note">残り ' . (int) $slot['remaining_seats'] . '席</p>
  </div>
  <div class="kpi' . ($remainingToCheck > 0 ? '' : ' is-ok') . '">
    <p class="kpi__label">' . Html::esc($label) . '済み</p>
    <p class="kpi__value">' . $checkedTotal . '<small> / ' . $seatsTotal . '名</small></p>
    <p class="kpi__note">' . ($remainingToCheck > 0
            ? '未' . Html::esc($label) . ' ' . $remainingToCheck . '名'
            : '全員' . Html::esc($label) . '済み') . '</p>
  </div>
</div>

<section class="admin-card-plain">
  <p class="muted" style="margin-top:0">' . Html::esc($page['title']) . ' ／ '
            . Html::esc(Time::formatJstLong((string) $slot['start_at']))
            . ($endAt !== null ? '〜' . Html::esc(Time::formatJstTime((string) $endAt)) : '') . '<br>'
            . $place . '</p>
  <p style="margin:10px 0 0">受付状態：' . $bookingStatusBadge . ' <span class="muted">' . $reminderNote . '</span></p>
</section>

<h2>' . Html::esc($label) . '確認</h2>
<section class="admin-card-plain">
  <p class="stat" style="margin-top:0">' . $checkedTotal . ' <small>/ ' . $seatsTotal . '名 '
            . Html::esc($label) . '済み' . ($isFiltered ? '（検索結果のみ）' : '') . '</small></p>
  <div class="progress" aria-hidden="true"><span style="width:' . $checkedPct . '%"></span></div>
  <p class="muted" style="margin-bottom:0">下の名簿から、1件ずつ' . Html::esc($label) . '人数を記録してください。</p>
</section>

<h2>名簿（' . count($bookings) . '件' . ($isFiltered ? '・検索結果' : '') . '）</h2>
<form class="card search-form" method="get" action="/admin/slots/' . $slotId . '">
  <div class="field">
    <label for="q">検索（氏名・電話番号・予約ID）</label>
    <input type="search" id="q" name="q" value="' . Html::esc($search) . '">
  </div>
  <button class="btn btn-sm btn-secondary" type="submit">検索</button>
  ' . Html::when(
            $search !== '',
            '<a class="btn btn-sm btn-secondary" href="/admin/slots/' . $slotId . '">クリア</a>',
        ) . '
</form>

<div class="stack">
  ' . (count($rows) > 0 ? implode("\n", $rows) : '<p class="muted">該当する予約はありません。</p>') . '
</div>

<h2>名簿の出力</h2>
<section class="admin-card-plain">
  <div class="btn-stack">
    <a class="btn btn-sm" href="/admin/reservation-slots/' . $slotId . '/roster.csv">この枠の名簿CSV</a>
    <a class="btn btn-sm btn-secondary" href="/admin/reservations/' . $pageId . '/roster.csv">'
            . Html::esc($page['title']) . 'の全枠CSV</a>
  </div>
  <p class="hint" style="margin-bottom:0">確定済みの予約のみを出力します（UTF-8 BOM付き）。
    キャンセルを含める場合は末尾に <code>?include=cancelled</code> を付けてください。</p>
</section>

<h2>管理者代理予約</h2>
<form class="card" method="post" action="/admin/slots/' . $slotId . '/bookings">
  <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">
  <div class="alert alert-info" role="note" style="margin-top:0">
    スタッフによる代理予約です。LINEログインは不要ですが、<strong>LINE通知・リマインドは送信されません。</strong>定員管理は通常予約と同じです。
  </div>
  <div class="field">
    <label for="admin_name">代表者氏名<span class="req">必須</span></label>
    <input type="text" id="admin_name" name="representative_name" maxlength="50" required>
  </div>
  <div class="field">
    <label for="admin_phone">電話番号<span class="req">必須</span></label>
    <input type="tel" id="admin_phone" name="phone" inputmode="tel" maxlength="20" required>
  </div>
  <div class="field">
    <label for="admin_party_size">人数<span class="req">必須</span></label>
    <select id="admin_party_size" name="party_size" required>
      ' . $partyOptions . '
    </select>
    <p class="hint">残席は' . (int) $slot['remaining_seats'] . '席です。超過分はサーバー側で拒否されます。</p>
  </div>
  <div class="field">
    <label for="admin_companions">同行者氏名（読点・改行区切り）</label>
    <input type="text" id="admin_companions" name="companion_names_text" maxlength="200"
      placeholder="山田花子、佐藤次郎">
  </div>
  <button class="btn btn-sm" type="submit">代理予約を登録</button>
</form>

<h2>予約枠の設定</h2>
' . AdminPagesView::slotFormFields(
            '/admin/slots/' . $slotId,
            $csrfToken,
            '予約枠を保存',
            (string) $page['page_type'],
            $slot,
        ) . '
';

        return Layout::render(
            [
                'title' => (string) $slot['name'] . ' | ' . (string) $page['title'] . ' | 管理画面',
                'admin' => true,
                'alert' => $alert,
            ],
            $content,
        );
    }
}
