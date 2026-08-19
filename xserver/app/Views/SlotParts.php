<?php

declare(strict_types=1);

namespace App\Views;

use App\Support\Html;
use App\Support\Time;

/**
 * 予約枠カードの共通パーツ。Workers版 src/views/slot-parts.ts の移植。
 *
 * 状態の優先順位（上から順に判定）:
 *   1. booking_status === 'closed'  → 受付停止中（運営が手で止めた状態が最優先）
 *   2. booking_close_at を経過       → 受付終了
 *   3. booking_open_at が未来        → 受付開始前
 *   4. 満席                          → 満席
 *   5. それ以外                      → 受付中
 * is_bookable=false をすべて「受付終了」に丸めない。
 */
final class SlotParts
{
    private const STATE_LABEL = [
        'open' => '受付中',
        'before_open' => '受付開始前',
        'closed_time' => '受付終了',
        'suspended' => '受付停止中',
        'full' => '満席',
    ];

    /** @param array<string, mixed> $slot */
    public static function state(array $slot, string $nowUtc): string
    {
        if (($slot['booking_status'] ?? '') === 'closed') {
            return 'suspended';
        }
        if (!empty($slot['booking_close_at']) && (string) $slot['booking_close_at'] <= $nowUtc) {
            return 'closed_time';
        }
        if (!empty($slot['booking_open_at']) && (string) $slot['booking_open_at'] > $nowUtc) {
            return 'before_open';
        }
        if (!empty($slot['is_full'])) {
            return 'full';
        }
        return 'open';
    }

    /** @param array<string, mixed> $slot */
    public static function stateLabel(array $slot, string $nowUtc): string
    {
        return self::STATE_LABEL[self::state($slot, $nowUtc)];
    }

    /** 「8月21日（金）」と「20:00」に分けて大きく見せる。 @param array<string, mixed> $slot */
    public static function when(array $slot): string
    {
        $long = Time::formatJstLong((string) $slot['start_at']);
        $time = Time::formatJstTime((string) $slot['start_at']);
        $date = str_ends_with($long, $time) ? substr($long, 0, strlen($long) - strlen($time)) : $long;
        $end = !empty($slot['end_at']) ? '〜' . Time::formatJstTime((string) $slot['end_at']) : '';

        return '<p class="trip-when">
      <span class="trip-date">' . Html::esc($date) . '</span>
      <span class="trip-time">' . Html::esc($time) . Html::esc($end) . '</span>
    </p>';
    }

    /** 出発地 ▶ 到着地。会場のみの枠では会場表示にする。 @param array<string, mixed> $slot */
    public static function route(array $slot): string
    {
        $origin = $slot['origin'] ?? null;
        $destination = $slot['destination'] ?? null;
        $location = $slot['location'] ?? null;

        if (!empty($origin) && !empty($destination)) {
            $from = Layout::splitPlace((string) $origin);
            $to = Layout::splitPlace((string) $destination);
            return '<div class="route">
      <span class="route__col from">
        <span class="route__label">出発・集合</span>
        <span class="route__place">' . Html::esc($from['main']) . '</span>
        ' . ($from['sub'] !== '' ? '<span class="route__sub">' . Html::esc($from['sub']) . '</span>' : '') . '
      </span>
      <span class="route__arrow" aria-hidden="true">▶</span>
      <span class="route__col to">
        <span class="route__label">到着</span>
        <span class="route__place">' . Html::esc($to['main']) . '</span>
        ' . ($to['sub'] !== '' ? '<span class="route__sub">' . Html::esc($to['sub']) . '</span>' : '') . '
      </span>
    </div>';
        }

        $place = $location ?? $origin;
        if (empty($place)) {
            return '';
        }
        $parts = Layout::splitPlace((string) $place);
        return '<div class="route route--single">
    <span class="route__col from">
      <span class="route__label">' . (!empty($location) ? '会場' : '集合') . '</span>
      <span class="route__place">' . Html::esc($parts['main']) . '</span>
      ' . ($parts['sub'] !== '' ? '<span class="route__sub">' . Html::esc($parts['sub']) . '</span>' : '') . '
    </span>
  </div>';
    }

    /**
     * 残席表示。状態を表す語は1カードに1回だけにする。
     * 予約できない枠では席数を出さない。
     *
     * @param array<string, mixed> $slot
     */
    public static function seats(array $slot, string $nowUtc): string
    {
        $state = self::state($slot, $nowUtc);

        if ($state === 'full') {
            return '<p class="seats is-full">満席</p>';
        }
        if ($state === 'suspended') {
            return '<p class="seats is-closed">受付停止中</p>';
        }
        if ($state === 'closed_time') {
            return '<p class="seats is-closed">受付終了</p>';
        }
        if ($state === 'before_open') {
            // 開始前は残席よりも「いつから予約できるか」が知りたい情報
            return '<p class="seats is-waiting">受付開始前</p>';
        }

        return '<p class="seats">残り <span class="seats-num">' . (int) $slot['remaining_seats']
            . '</span> 席 / ' . (int) $slot['capacity'] . '席'
            . Layout::seatBadge(false, (int) $slot['remaining_seats'], (int) $slot['capacity']) . '</p>';
    }

    /**
     * 受付期間の案内文。booking_open_at / booking_close_at を公開UIに出す。
     *
     * @param array<string, mixed> $slot
     */
    public static function timing(array $slot, string $nowUtc): string
    {
        $state = self::state($slot, $nowUtc);

        if ($state === 'before_open' && !empty($slot['booking_open_at'])) {
            return '<p class="slot-timing is-waiting">
      <strong>' . Html::esc(Time::formatJstLong((string) $slot['booking_open_at'])) . '</strong>から受付開始
    </p>';
        }
        if ($state === 'closed_time' && !empty($slot['booking_close_at'])) {
            return '<p class="slot-timing">
      ' . Html::esc(Time::formatJstLong((string) $slot['booking_close_at'])) . 'に受付を終了しました
    </p>';
        }
        if ($state === 'open' && !empty($slot['booking_close_at'])) {
            return '<p class="slot-timing">
      ' . Html::esc(Time::formatJstLong((string) $slot['booking_close_at'])) . 'まで受付
    </p>';
        }
        if ($state === 'suspended') {
            return '<p class="slot-timing">現在、この枠の受付を停止しています。</p>';
        }
        if ($state === 'full') {
            return '<p class="slot-timing">キャンセルが出た場合、再度予約できることがあります。</p>';
        }
        return '';
    }
}
