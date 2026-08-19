<?php

declare(strict_types=1);

namespace App\Views\admin;

use App\Support\Html;
use App\Support\Time;
use App\Views\Layout;
use App\Views\SlotParts;

/** 管理者ログイン画面とダッシュボード（Phase 2F の運用KPI付き）。 */
final class AdminDashboardView
{
    private const STATE_BADGE_CLASS = [
        'open' => 'badge-open',
        'before_open' => 'badge-proxy',
        'closed_time' => 'badge-closed',
        'suspended' => 'badge-closed',
        'full' => 'badge-full',
    ];

    /** @param array{type: string, message: string}|null $alert */
    public static function login(string $csrfToken, ?array $alert = null): string
    {
        $content = '
<h2>管理者ログイン</h2>
<form method="post" action="/admin/login" class="card stack">
  <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">
  <div class="field">
    <label for="username">ユーザー名</label>
    <input type="text" id="username" name="username" required autocomplete="username">
  </div>
  <div class="field">
    <label for="password">パスワード</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
  </div>
  <button class="btn" type="submit">ログイン</button>
</form>
';
        return Layout::render(
            ['title' => '管理者ログイン | 予約管理', 'admin' => true, 'alert' => $alert],
            $content
        );
    }

    /**
     * 予約枠の状態バッジ。公開側と同じ SlotParts::state() を使って表示を一致させる。
     *
     * @param array<string, mixed> $slot
     */
    public static function slotStateBadge(array $slot, string $nowUtc): string
    {
        $state = SlotParts::state($slot, $nowUtc);
        $class = self::STATE_BADGE_CLASS[$state];
        return '<span class="badge ' . $class . '">' . Html::esc(SlotParts::stateLabel($slot, $nowUtc)) . '</span>';
    }

    /**
     * @param list<array{page: array<string, mixed>, slots: list<array<string, mixed>>}> $entries
     * @param array{type: string, message: string}|null $alert
     */
    public static function dashboard(array $entries, string $nowUtc, ?array $alert = null): string
    {
        $today = Time::jstDate($nowUtc);

        // 全枠を平坦化して当日分・残席わずかを集計する
        $flat = [];
        foreach ($entries as $entry) {
            foreach ($entry['slots'] as $slot) {
                $flat[] = ['slot' => $slot, 'pageTitle' => (string) $entry['page']['title']];
            }
        }

        $todaySlots = array_values(array_filter(
            $flat,
            static fn (array $x): bool => Time::jstDate((string) $x['slot']['start_at']) === $today
        ));
        usort(
            $todaySlots,
            static fn (array $a, array $b): int => strcmp(
                (string) $a['slot']['start_at'],
                (string) $b['slot']['start_at']
            )
        );

        $todaySeats = 0;
        foreach ($todaySlots as $x) {
            $todaySeats += (int) $x['slot']['booked_seats'];
        }

        $fewSlots = array_values(array_filter(
            $flat,
            static fn (array $x): bool => SlotParts::state($x['slot'], $nowUtc) === 'open'
                && Layout::isFewSeats((int) $x['slot']['remaining_seats'], (int) $x['slot']['capacity'])
        ));
        usort(
            $fewSlots,
            static fn (array $a, array $b): int => (int) $a['slot']['remaining_seats']
                <=> (int) $b['slot']['remaining_seats']
        );

        $publishedCount = count(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['page']['status'] === 'published'
        ));
        $draftCount = count(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['page']['status'] === 'draft'
        ));

        $kpis = '<div class="kpi-grid">
  <div class="kpi">
    <span class="kpi__label">本日の予約人数</span>
    <span class="kpi__value">' . $todaySeats . '<small>名</small></span>
    <span class="kpi__note">確定済みの合計</span>
  </div>
  <div class="kpi is-dark">
    <span class="kpi__label">本日の受付予定</span>
    <span class="kpi__value">' . count($todaySlots) . '<small>枠</small></span>
    <span class="kpi__note">' . ($todaySlots === []
            ? '予定なし'
            : Html::esc(Time::formatJstTime((string) $todaySlots[0]['slot']['start_at']))) . '</span>
  </div>
  <div class="kpi' . ($fewSlots !== [] ? ' is-alert' : '') . '">
    <span class="kpi__label">残席わずか</span>
    <span class="kpi__value">' . count($fewSlots) . '<small>枠</small></span>
    <span class="kpi__note">定員に対して残りが少ない枠</span>
  </div>
  <div class="kpi is-ok">
    <span class="kpi__label">公開中ページ</span>
    <span class="kpi__value">' . $publishedCount . '<small>件</small></span>
    <span class="kpi__note">' . ($draftCount > 0 ? '下書き' . $draftCount . '件' : '下書きなし') . '</span>
  </div>
</div>';

        $todayCard = '<div class="list-card">
  <div class="list-card__head">本日の受付予定</div>
  ' . ($todaySlots === []
            ? '<div class="list-card__row"><span class="muted">本日受付予定の枠はありません。</span></div>'
            : implode('', array_map(
                static fn (array $x): string => '<div class="list-card__row">
    <span class="list-card__time">' . Html::esc(Time::formatJstTime((string) $x['slot']['start_at'])) . '</span>
    <span class="list-card__main">
      <span class="list-card__name">' . Html::esc($x['slot']['name']) . '</span>
      <span class="list-card__sub">' . Html::esc($x['pageTitle']) . '</span>
    </span>
    <span class="list-card__num"><strong>' . (int) $x['slot']['booked_seats'] . '名</strong>
      <small>/ ' . (int) $x['slot']['capacity'] . '</small></span>
    <a class="btn btn-sm" href="/admin/slots/' . (int) $x['slot']['id'] . '">名簿</a>
  </div>',
                $todaySlots
            ))) . '
</div>';

        $fewCard = $fewSlots === [] ? '' : '<div class="list-card is-alert">
  <div class="list-card__head">残席わずか</div>
  ' . implode('', array_map(
            static fn (array $x): string => '<div class="list-card__row">
    <span class="list-card__main">
      <span class="list-card__name">' . Html::esc($x['slot']['name']) . '</span>
      <span class="list-card__sub">' . Html::esc($x['pageTitle']) . ' ・ '
                . Html::esc(Time::formatJstLong((string) $x['slot']['start_at'])) . '</span>
    </span>
    <span class="badge-few">残り' . (int) $x['slot']['remaining_seats'] . '</span>
    <a class="btn btn-sm btn-secondary" href="/admin/slots/' . (int) $x['slot']['id'] . '">開く</a>
  </div>',
            $fewSlots
        )) . '
</div>';

        $pageBlocks = implode("\n", array_map(
            static function (array $entry) use ($nowUtc): string {
                $slotCards = implode("\n", array_map(
                    static function (array $slot) use ($nowUtc): string {
                        $few = empty($slot['is_full'])
                            && Layout::isFewSeats((int) $slot['remaining_seats'], (int) $slot['capacity']);
                        $capacity = (int) $slot['capacity'];
                        $rate = $capacity > 0
                            ? min(100, (int) round((int) $slot['booked_seats'] / $capacity * 100))
                            : 0;

                        return '<article class="card admin-card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
    <h3 style="margin:0">' . Html::esc($slot['name']) . '</h3>
    <span>' . self::slotStateBadge($slot, $nowUtc)
                            . ($slot['booking_status'] === 'hidden'
                                ? ' <span class="badge badge-proxy">非表示</span>'
                                : '') . '</span>
  </div>
  <p class="muted" style="margin:6px 0 0">' . Html::esc(Time::formatJstLong((string) $slot['start_at'])) . '</p>
  <p class="stat">' . (int) $slot['booked_seats'] . ' <small>/ ' . $capacity . '名</small></p>
  <p class="stat-remaining' . ($few ? ' is-few' : '') . '">残り ' . (int) $slot['remaining_seats'] . '席</p>
  <div class="progress" aria-hidden="true"><span style="width:' . $rate . '%"></span></div>
  <a class="btn btn-secondary" style="margin-top:14px" href="/admin/slots/' . (int) $slot['id']
                            . '">予約一覧・受付</a>
</article>';
                    },
                    $entry['slots']
                ));

                return '<h3 style="margin-top:24px">' . Html::esc($entry['page']['title'])
                    . ' <a class="btn btn-sm btn-secondary" style="margin-left:8px" href="/admin/reservations/'
                    . (int) $entry['page']['id'] . '">設定</a></h3>
' . ($entry['slots'] === []
                    ? '<p class="muted">予約枠がまだありません。</p>'
                    : '<div class="admin-grid">' . $slotCards . '</div>');
            },
            $entries
        ));

        $content = '
<h2>ダッシュボード</h2>
' . $kpis . $todayCard . $fewCard . '

<h2>予約ページ別の状況</h2>
<p><a class="btn btn-secondary" href="/admin/reservations">予約ページの管理</a></p>
' . ($entries === []
            ? '<div class="card"><p class="muted" style="margin:0">公開中の予約ページはありません。</p></div>'
            : $pageBlocks) . '

<h2>その他</h2>
<div class="card">
  <p style="margin-top:0">リマインドはCron（5分毎）で自動送信されます。</p>
  <form method="post" action="/admin/reminders/run" style="margin:0">
    <button class="btn btn-secondary btn-sm" type="submit">リマインド処理を今すぐ実行</button>
  </form>
</div>
';

        return Layout::render(
            ['title' => '管理ダッシュボード | 予約管理', 'admin' => true, 'alert' => $alert],
            $content
        );
    }
}
