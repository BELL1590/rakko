<?php

declare(strict_types=1);

namespace App\Views;

use App\Services\CsvService;
use App\Support\Html;
use App\Support\Time;

/**
 * 予約完了 / 予約詳細ページ。
 * キャンセルは2段階（同一ページ内で確認セクションを開くだけ。POST先は不変）。
 * JS無効時は確認セクションが開いた状態＋confirm() にフォールバックする。
 */
final class BookingDetailView
{
    /**
     * @param array<string, mixed> $booking
     * @param list<array<string, mixed>> $groupBookings
     * @param array{type: string, message: string}|null $alert
     */
    public static function render(
        array $booking,
        array $groupBookings,
        string $csrfToken,
        ?string $userName,
        bool $justCompleted,
        ?string $notificationNote,
        string $nowUtc,
        ?array $alert = null
    ): string {
        $cancelled = $booking['status'] === 'cancelled';
        $started = (string) $booking['start_at'] <= $nowUtc;
        $canCancel = !$cancelled && !$started;
        $heading = $justCompleted ? 'ご予約が完了しました' : 'ご予約の詳細';

        $bookingId = (int) $booking['id'];
        $others = array_values(array_filter(
            $groupBookings,
            static fn (array $entry): bool => (int) $entry['id'] !== $bookingId
        ));

        $cancelForm = '<form method="post" action="/bookings/' . $bookingId . '/cancel" style="margin:0"
      id="cancel-form" onsubmit="return confirm(\'この予約をキャンセルします。よろしいですか？\');">
      <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">
      <button class="btn btn-danger" type="submit">キャンセルを確定する</button>
    </form>';

        $cancelSection = $canCancel
            ? '<div class="btn-stack" id="cancel-trigger-wrap" hidden>
    <button class="btn btn-danger-outline" type="button" id="cancel-trigger">この予約をキャンセルする</button>
  </div>

  <section class="cancel-panel" id="cancel-panel" style="margin-bottom:16px">
    <p class="cancel-lead">この予約をキャンセルしますか？</p>
    <ul class="summary-list" style="margin-bottom:12px">
      <li><span class="k">予約ID</span><span class="v">#' . $bookingId . '</span></li>
      <li><span class="k">予約枠</span><span class="v">' . Html::esc($booking['page_title']) . '／'
                . Html::esc($booking['slot_name']) . '</span></li>
      <li><span class="k">日時</span><span class="v">'
                . Html::esc(Time::formatJstLong((string) $booking['start_at'])) . '</span></li>
      <li><span class="k">ご予約人数</span><span class="v">' . (int) $booking['party_size'] . '名</span></li>
    </ul>
    <p class="muted" style="margin-top:0">キャンセルすると座席は他のお客様へ開放されます。取り消しはできません。'
                . Html::when(
                    $others !== [],
                    'まとめて予約した他の枠はキャンセルされません（枠ごとにお手続きください）。'
                ) . '</p>
    <div class="btn-stack">
      <button class="btn btn-secondary" type="button" id="cancel-dismiss" hidden>やめる（予約を続ける）</button>
      ' . $cancelForm . '
    </div>
  </section>'
            : '';

        $content = '
' . Html::when(
            $justCompleted,
            '<div class="alert alert-success done-head" role="status">
    <span class="done-mark" aria-hidden="true">✓</span>
    <span>
      <strong style="display:block;font-size:1.15rem">ご予約が完了しました</strong>
      当日お気をつけてお越しください。
    </span>
  </div>'
        ) . '

<h2>' . $heading . '</h2>

' . self::summaryCard($booking) . '

' . Html::when(
            $others !== [],
            '<h2>同時に予約した枠</h2>' . implode("\n", array_map(
                static fn (array $entry): string => self::summaryCard($entry),
                $others
            )) . '<p class="muted">キャンセルは枠ごとに、それぞれの詳細ページからお手続きいただけます。</p>'
        ) . '

' . Html::when(
            $notificationNote !== null && $notificationNote !== '',
            '<div class="notice" style="margin-bottom:16px">' . Html::esc($notificationNote) . '</div>'
        ) . '

<div class="btn-row" style="margin-bottom:16px">
  <a class="btn btn-secondary" href="/my-bookings">マイ予約へ</a>
  <a class="btn btn-secondary" href="/reserve/' . Html::esc($booking['page_slug']) . '">予約ページへ</a>
</div>

' . $cancelSection . '
';

        return Layout::render(
            [
                'title' => $heading . ' | ' . (string) $booking['page_title'],
                'userName' => $userName,
                'alert' => $alert,
                'bodyEnd' => $canCancel ? '<script src="/assets/booking-cancel.js" defer></script>' : '',
            ],
            $content
        );
    }

    /** @param array<string, mixed> $booking */
    private static function summaryCard(array $booking): string
    {
        $companions = CsvService::companions($booking);
        $cancelled = $booking['status'] === 'cancelled';

        return '<section class="card trip-card">
  <div class="trip-card__head">
    <span class="trip-card__dir">' . Html::esc($booking['slot_name']) . '</span>
    <span class="trip-card__state">' . ($cancelled ? 'キャンセル済み' : '予約済み') . '</span>
  </div>
  <div class="trip-card__body">
    ' . SlotParts::when($booking) . '
    ' . SlotParts::route($booking) . '
    <ul class="summary-list">
      <li><span class="k">予約ID</span><span class="v">#' . (int) $booking['id'] . '</span></li>
      <li><span class="k">予約ページ</span><span class="v">' . Html::esc($booking['page_title']) . '</span></li>
      <li><span class="k">代表者</span><span class="v">' . Html::esc($booking['representative_name']) . '</span></li>
      <li><span class="k">ご予約人数</span><span class="v">' . (int) $booking['party_size'] . '名</span></li>
      ' . Html::when(
            $companions !== [],
            '<li><span class="k">同行者</span><span class="v">' . Html::esc(implode('、', $companions))
                . '</span></li>'
        ) . '
      <li><span class="k">ステータス</span><span class="v">' . ($cancelled
            ? '<span class="badge badge-cancelled">キャンセル済み</span>'
            : '<span class="badge badge-confirmed">予約済み</span>') . '</span></li>
    </ul>
  </div>
</section>';
    }
}
