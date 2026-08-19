<?php

declare(strict_types=1);

namespace App\Views;

use App\Support\Html;
use App\Support\Time;

/**
 * 公開トップ。公開中の予約ページを「受付中 / 受付開始前 / 受付終了」の3分類で並べる。
 * Workers版 src/views/home.ts（Phase 2F）の移植。
 */
final class HomeView
{
    /**
     * @param list<array{page: array<string, mixed>, slots: list<array<string, mixed>>}> $entries
     * @param array{type: string, message: string}|null $alert
     */
    public static function render(
        array $entries,
        ?string $userName,
        string $nowUtc,
        ?array $alert = null
    ): string {
        $open = [];
        $waiting = [];
        $closed = [];

        foreach ($entries as $entry) {
            $slots = array_values(array_filter(
                $entry['slots'],
                static fn (array $slot): bool => !empty($slot['is_visible'])
            ));
            $states = array_map(
                static fn (array $slot): string => SlotParts::state($slot, $nowUtc),
                $slots
            );

            $bucket = 'closed';
            if (in_array('open', $states, true)) {
                $bucket = 'open';
            } elseif (in_array('before_open', $states, true)) {
                $bucket = 'waiting';
            }

            $item = ['page' => $entry['page'], 'slots' => $slots, 'states' => $states];
            if ($bucket === 'open') {
                $open[] = $item;
            } elseif ($bucket === 'waiting') {
                $waiting[] = $item;
            } else {
                $closed[] = $item;
            }
        }

        // 受付開始前は「開始が早い順」に並べる
        usort($waiting, static function (array $a, array $b): int {
            return self::earliestOpenAt($a['slots']) <=> self::earliestOpenAt($b['slots']);
        });

        $content = '
<section class="hero" style="margin:-16px -16px 16px">
  <h1>草加健康センター<br>予約センター</h1>
  <span class="hero-sub">オンライン予約</span>
  <p>ご利用になる予約ページを選んでください。</p>
</section>

<h2>受付中の予約</h2>
' . ($open === []
            ? '<div class="card"><p class="muted" style="margin:0">現在受付中の予約はありません。</p></div>'
            : implode("\n", array_map(
                static fn (array $item): string => self::pageCard($item, $nowUtc, 'open'),
                $open
            )));

        if ($waiting !== []) {
            $content .= '
<section class="section-head">
  <span class="section-head__title">受付開始前</span>
  <span class="section-head__count">' . count($waiting) . '件</span>
</section>
' . implode("\n", array_map(
                static fn (array $item): string => self::pageCard($item, $nowUtc, 'waiting'),
                $waiting
            ));
        }

        if ($closed !== []) {
            $content .= '
<section class="section-head">
  <span class="section-head__title">受付終了</span>
  <span class="section-head__count">' . count($closed) . '件</span>
</section>
' . implode("\n", array_map(
                static fn (array $item): string => self::closedCard($item, $nowUtc),
                $closed
            ));
        }

        $content .= '
<div class="notice" style="margin-bottom:16px">
  ご予約にはLINEログインが必要です。予約完了のお知らせと開始前のリマインドをLINEでお送りします。
</div>

<p><a class="btn btn-secondary" href="/my-bookings">マイ予約を確認する</a></p>

<h2>草加健康センター 館内料金（参考）</h2>
' . Layout::priceInfoCard();

        return Layout::render(
            [
                'title' => '草加健康センター 予約センター',
                'userName' => $userName,
                'alert' => $alert,
            ],
            $content
        );
    }

    /** @param list<array<string, mixed>> $slots */
    private static function earliestOpenAt(array $slots): string
    {
        $values = [];
        foreach ($slots as $slot) {
            if (!empty($slot['booking_open_at'])) {
                $values[] = (string) $slot['booking_open_at'];
            }
        }
        sort($values);
        return $values[0] ?? '9999-12-31 00:00:00';
    }

    /** @param array{page: array<string, mixed>, slots: list<array<string, mixed>>, states: list<string>} $item */
    private static function pageCard(array $item, string $nowUtc, string $bucket): string
    {
        $page = $item['page'];
        $slots = $item['slots'];
        $states = $item['states'];

        $remaining = 0;
        $capacity = 0;
        foreach ($slots as $slot) {
            $remaining += (int) $slot['remaining_seats'];
            $capacity += (int) $slot['capacity'];
        }
        $allFull = $slots !== [] && !in_array(false, array_map(
            static fn (array $slot): bool => (bool) $slot['is_full'],
            $slots
        ), true);
        $anyOpen = in_array('open', $states, true);
        $hasUnavailable = $anyOpen && array_filter(
            $states,
            static fn (string $state): bool => $state !== 'open'
        ) !== [];

        $lines = '';
        foreach ($slots as $index => $slot) {
            $state = $states[$index];
            $label = match ($state) {
                'full' => '満席',
                'suspended' => '停止中',
                'closed_time' => '終了',
                'before_open' => '開始前',
                default => '残り' . (int) $slot['remaining_seats'] . '席',
            };
            $lines .= '<li>
      <span class="slot-line__name">' . Html::esc($slot['name']) . '</span>
      <span class="slot-line__when">' . Html::esc(Time::formatJstLong((string) $slot['start_at'])) . '</span>
      <span class="slot-line__seats' . ($state === 'open' ? '' : ' is-muted') . '">' . $label . '</span>
    </li>';
        }

        // 受付中ページに締切が設定されていれば、最も早いものを案内する
        $closeAt = null;
        foreach ($slots as $index => $slot) {
            if ($states[$index] === 'open' && !empty($slot['booking_close_at'])) {
                $value = (string) $slot['booking_close_at'];
                if ($closeAt === null || $value < $closeAt) {
                    $closeAt = $value;
                }
            }
        }

        // 受付開始前ページは「いつから予約できるか」を主役にする
        $openAt = $bucket === 'waiting' ? self::earliestOpenAt($slots) : null;

        return '<article class="card trip-card">
  <div class="trip-card__head">
    <span class="trip-card__dir">' . Html::esc($page['title']) . '</span>
    <span class="trip-card__state">' . ($bucket === 'waiting' ? '受付開始前' : ($allFull ? '満席' : '受付中')) . '</span>
  </div>
  <div class="trip-card__body">
    ' . Html::when(
            !empty($page['description']),
            '<p class="trip-meta">' . Html::esc($page['description']) . '</p>'
        ) . '
    ' . Html::when(
            $openAt !== null && $openAt !== '9999-12-31 00:00:00',
            '<p class="slot-timing is-waiting"><strong>' . Html::esc(Time::formatJstLong((string) $openAt))
                . '</strong>から受付開始</p>'
        ) . '
    ' . Html::when($slots !== [], '<ul class="slot-lines">' . $lines . '</ul>') . '
    ' . Html::when(
            $closeAt !== null,
            '<p class="slot-timing">' . Html::esc(Time::formatJstLong((string) $closeAt)) . 'まで受付</p>'
        ) . '
    <p class="seats">残り <span class="seats-num">' . $remaining . '</span> 席 / ' . $capacity . '席'
            . Layout::seatBadge($allFull, $remaining, $capacity > 0 ? $capacity : null) . '</p>
    ' . Html::when(
            $hasUnavailable,
            '<p><span class="badge badge-closed">一部受付終了</span></p>'
        ) . '
    ' . ($anyOpen
            ? '<a class="btn" style="margin-top:14px" href="/reserve/' . Html::esc($page['slug'])
                . '">この予約ページを開く</a>'
            : '<a class="btn btn-secondary" style="margin-top:14px" href="/reserve/' . Html::esc($page['slug'])
                . '">内容を見る</a>') . '
  </div>
</article>';
    }

    /** @param array{page: array<string, mixed>, slots: list<array<string, mixed>>, states: list<string>} $item */
    private static function closedCard(array $item, string $nowUtc): string
    {
        $page = $item['page'];
        $states = $item['states'];

        $reason = '受付終了';
        if ($states !== [] && array_filter($states, static fn (string $s): bool => $s !== 'full') === []) {
            $reason = '満席';
        } elseif ($states !== [] && array_filter($states, static fn (string $s): bool => $s !== 'suspended') === []) {
            $reason = '受付停止中';
        }

        $first = $item['slots'][0] ?? null;

        return '<article class="card page-closed">
  <div class="page-closed__head">
    <span class="page-closed__title">' . Html::esc($page['title']) . '</span>
    <span class="page-closed__reason">' . $reason . '</span>
  </div>
  <p class="muted" style="margin:6px 0 0">'
            . ($first !== null ? Html::esc(Time::formatJstLong((string) $first['start_at'])) . ' ・ ' : '')
            . count($item['slots']) . '枠 ・ <a href="/reserve/' . Html::esc($page['slug']) . '">内容を見る</a></p>
</article>';
    }
}
