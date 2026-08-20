<?php

declare(strict_types=1);

namespace App\Views;

use App\Services\CsvService;
use App\Support\Html;
use App\Support\Time;

/**
 * マイ予約。予約ページ単位でグルーピングし、
 * booking_group_id が2件以上あるものは「まとめて予約」で囲む。
 */
final class MyBookingsView
{
    /**
     * @param list<array<string, mixed>> $bookings
     * @param array{type: string, message: string}|null $alert
     */
    public static function render(
        array $bookings,
        ?string $userName,
        string $nowUtc,
        ?array $alert = null
    ): string {
        // 予約ページごとにまとめる
        $pages = [];
        foreach ($bookings as $booking) {
            $key = (string) $booking['page_slug'];
            $pages[$key] ??= [
                'title' => (string) $booking['page_title'],
                'slug' => $key,
                'bookings' => [],
            ];
            $pages[$key]['bookings'][] = $booking;
        }

        $sections = [];
        foreach ($pages as $page) {
            $sections[] = '<h2>' . Html::esc($page['title']) . '</h2>'
                . self::pageBlocks($page['bookings'], $nowUtc)
                . '<p class="muted"><a href="/reserve/' . Html::esc($page['slug']) . '">'
                . Html::esc($page['title']) . 'の予約ページを開く</a></p>';
        }

        $content = '
<h2>あなたの予約</h2>
' . ($bookings === []
            ? '<div class="card center"><p>まだご予約はありません。</p>
       <a class="btn" href="/">予約ページを見る</a></div>'
            : implode("\n", $sections)) . '
' . Html::when(
            $bookings !== [],
            '<p class="muted">キャンセルは各予約の詳細ページから、内容を確認したうえでお手続きいただけます。</p>
   <p style="margin-top:16px"><a class="btn btn-secondary" href="/">他の予約ページを見る</a></p>'
        ) . '
';

        return Layout::render(
            [
                'title' => 'マイ予約 | 草加健康センター 予約センター',
                'userName' => $userName,
                'alert' => $alert,
            ],
            $content
        );
    }

    /** @param list<array<string, mixed>> $bookings */
    private static function pageBlocks(array $bookings, string $nowUtc): string
    {
        $groups = [];
        $singles = [];

        foreach ($bookings as $booking) {
            $groupId = $booking['booking_group_id'];
            if ($groupId === null || $groupId === '') {
                $singles[] = $booking;
                continue;
            }
            $groups[(string) $groupId][] = $booking;
        }

        $blocks = [];
        foreach ($groups as $list) {
            // 1件しか残っていないグループ（片方だけキャンセル済み等）は囲まない
            if (count($list) < 2) {
                $singles = array_merge($singles, $list);
                continue;
            }
            $active = array_values(array_filter(
                $list,
                static fn (array $b): bool => $b['status'] !== 'cancelled'
            ));
            $seats = array_sum(array_map(static fn (array $b): int => (int) $b['party_size'], $active));
            $first = $list[0];

            $blocks[] = '<div class="booking-group">
  <div class="booking-group__head">
    <span class="booking-group__badge">まとめて予約</span>
    <span class="booking-group__meta">' . count($list) . '件'
                . ($seats > 0 ? ' ・ 計' . $seats . '名' : '') . '</span>
    <span class="booking-group__at">'
                . Html::esc(Time::formatJstIsoLike((string) $first['created_at'])) . '</span>
  </div>
  ' . implode("\n", array_map(
                static fn (array $booking): string => self::card($booking, $nowUtc),
                $list
            )) . '
  <p class="booking-group__note">まとめて予約した枠も、キャンセルは枠ごとに行います。片方だけの取り消しができます。</p>
</div>';
        }

        foreach ($singles as $booking) {
            $blocks[] = self::card($booking, $nowUtc);
        }

        return implode("\n", $blocks);
    }

    /** @param array<string, mixed> $booking */
    private static function card(array $booking, string $nowUtc): string
    {
        $cancelled = $booking['status'] === 'cancelled';
        $started = (string) $booking['start_at'] <= $nowUtc;
        $inactive = $cancelled || $started;
        $companions = CsvService::companions($booking);

        $long = Time::formatJstLong((string) $booking['start_at']);
        $time = Time::formatJstTime((string) $booking['start_at']);
        $date = str_ends_with($long, $time) ? substr($long, 0, strlen($long) - strlen($time)) : $long;

        $route = !empty($booking['origin']) && !empty($booking['destination'])
            ? (string) $booking['origin'] . ' → ' . (string) $booking['destination']
            : (string) ($booking['location'] ?? $booking['origin'] ?? '');

        return '<article class="card trip-card' . ($inactive ? ' is-full' : '') . '">
  <div class="trip-card__head">
    <span class="trip-card__dir">' . Html::esc($booking['slot_name']) . '</span>
    <span class="trip-card__state">'
            . ($cancelled ? 'キャンセル済み' : ($started ? '受付終了' : '予約済み')) . '</span>
  </div>
  <div class="trip-card__body">
    <p class="trip-when">
      <span class="trip-date">' . Html::esc($date) . '</span>
      <span class="trip-time">' . Html::esc($time) . '</span>
    </p>
    ' . Html::when($route !== '', '<p class="trip-route">' . Html::esc($route) . '</p>') . '
    <p class="trip-meta">' . (int) $booking['party_size'] . '名'
            . ($companions !== [] ? ' ・ 同行者：' . Html::esc(implode('、', $companions)) : '')
            . '<br>予約ID #' . (int) $booking['id'] . ' ・ '
            . ($cancelled
                ? '<span class="badge badge-cancelled">キャンセル済み</span>'
                : '<span class="badge badge-confirmed">予約済み</span>') . '</p>
    <div class="btn-row" style="margin-top:14px">
      <a class="btn btn-secondary btn-sm" href="/bookings/' . (int) $booking['id'] . '">予約の詳細</a>
      ' . (!$cancelled && !$started
                ? '<a class="btn btn-danger-outline btn-sm" href="/bookings/' . (int) $booking['id']
                    . '">キャンセル手続き</a>'
                : '') . '
    </div>
  </div>
</article>';
    }
}
