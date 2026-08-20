<?php

declare(strict_types=1);

namespace App\Views\admin;

use App\Support\Html;
use App\Support\Time;
use App\Views\Layout;

/**
 * 管理画面: 予約ページの一覧・作成・編集と、配下の予約枠の管理。
 *
 * Workers版 src/views/admin/pages.ts の移植。
 * ルート・POST先・name属性・select の値は一切変更していない。
 */
final class AdminPagesView
{
    private const PAGE_STATUS_LABEL = [
        'draft' => '下書き',
        'published' => '公開中',
        'closed' => '受付終了',
        'archived' => 'アーカイブ',
    ];

    private const PAGE_TYPE_LABEL = [
        'bus' => 'バス送迎',
        'event' => 'イベント',
        'time_slot' => '時間枠（貸切など）',
        'other' => 'その他',
    ];

    private static function statusBadge(string $status): string
    {
        $cls = match ($status) {
            'published' => 'badge-open',
            'draft' => 'badge-proxy',
            default => 'badge-closed',
        };
        $label = self::PAGE_STATUS_LABEL[$status] ?? $status;

        return '<span class="badge ' . $cls . '">' . Html::esc($label) . '</span>';
    }

    /**
     * 予約ページ一覧 `/admin/reservations`
     *
     * @param array<int,array<string,mixed>> $pages
     * @param array{type:string,message:string}|null $alert
     */
    public static function list(array $pages, string $baseUrl, string $csrfToken, ?array $alert = null): string
    {
        $cards = [];
        foreach ($pages as $page) {
            $publicUrl = $baseUrl . '/reserve/' . (string) $page['slug'];
            $capacityTotal = (int) ($page['capacity_total'] ?? 0);
            $bookedSeats = (int) ($page['booked_seats'] ?? 0);
            $pct = $capacityTotal > 0
                ? min(100, (int) round($bookedSeats / $capacityTotal * 100))
                : 0;
            $typeLabel = self::PAGE_TYPE_LABEL[(string) $page['page_type']] ?? (string) $page['page_type'];
            $id = (int) $page['id'];
            $status = (string) $page['status'];

            $statusButton = $status === 'published'
                ? '<button class="btn btn-sm btn-danger-outline" type="submit" name="status" value="closed">受付を停止</button>'
                : '<button class="btn btn-sm" type="submit" name="status" value="published">公開する</button>';

            $cards[] = '<article class="page-row' . ($status === 'published' ? '' : ' is-draft') . '">
  <div class="page-row__head">
    <span class="page-row__title">' . Html::esc($page['title']) . '</span>
    ' . self::statusBadge($status) . '
  </div>
  <p class="page-row__meta">' . Html::esc($typeLabel) . '
    ・ 予約枠 ' . (int) ($page['slot_count'] ?? 0) . '件 ・ 作成 ' . Html::esc(Time::formatJstIsoLike((string) $page['created_at'])) . '</p>
  <p class="page-row__stat"><strong>' . $bookedSeats . '</strong><span>/ ' . $capacityTotal . '名</span></p>
  <div class="progress" aria-hidden="true"><span style="width:' . $pct . '%"></span></div>
  <p class="page-row__url">公開URL：<a href="/reserve/' . Html::esc($page['slug']) . '">' . Html::esc($publicUrl) . '</a></p>
  <div class="btn-row">
    <a class="btn btn-sm" href="/admin/reservations/' . $id . '">編集・予約枠</a>
    <form method="post" action="/admin/reservations/' . $id . '/duplicate" style="margin:0">
      <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">
      <button class="btn btn-sm btn-secondary" type="submit">複製</button>
    </form>
    <a class="btn btn-sm btn-secondary" href="/admin/reservations/' . $id . '/roster.csv">全枠CSV</a>
    <form method="post" action="/admin/reservations/' . $id . '/status" style="margin:0">
      <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">
      ' . $statusButton . '
    </form>
  </div>
</article>';
        }

        $content = '
<h2>予約ページ</h2>
<p><a class="btn" href="/admin/reservations/new">新しい予約ページを作成</a></p>
' . (count($pages) === 0
            ? '<div class="card"><p class="muted" style="margin:0">まだ予約ページがありません。</p></div>'
            : implode("\n", $cards)) . '
';

        return Layout::render(
            ['title' => '予約ページ一覧 | 管理画面', 'admin' => true, 'alert' => $alert],
            $content,
        );
    }

    /**
     * 予約ページの作成・編集フォーム。
     *
     * @param array<string,mixed>|null $page
     * @param array<int,array<string,mixed>> $slots
     * @param array{type:string,message:string}|null $alert
     */
    public static function form(
        ?array $page,
        array $slots,
        string $csrfToken,
        string $baseUrl,
        ?array $alert = null,
    ): string {
        $isNew = $page === null;
        $action = $isNew ? '/admin/reservations' : '/admin/reservations/' . (int) $page['id'];

        $vSlug = (string) ($page['slug'] ?? '');
        $vTitle = (string) ($page['title'] ?? '');
        $vDescription = (string) ($page['description'] ?? '');
        $vNoticeText = (string) ($page['notice_text'] ?? '');
        $vStatus = (string) ($page['status'] ?? 'draft');
        $vPageType = (string) ($page['page_type'] ?? 'other');
        $vAllowMulti = $page !== null ? (int) $page['allow_multi_slot_booking'] === 1 : true;
        $vRequiresLogin = $page !== null ? (int) $page['requires_line_login'] === 1 : true;
        $vMaxSlots = (int) ($page['max_slots_per_checkout'] ?? 4);
        $vCheckinLabel = (string) ($page['checkin_label'] ?? '受付');

        $statusOptions = '';
        foreach (self::PAGE_STATUS_LABEL as $key => $label) {
            $statusOptions .= '<option value="' . $key . '"'
                . ($vStatus === $key ? ' selected' : '') . '>' . Html::esc($label) . '</option>';
        }

        $typeOptions = '';
        foreach (self::PAGE_TYPE_LABEL as $key => $label) {
            $typeOptions .= '<option value="' . $key . '"'
                . ($vPageType === $key ? ' selected' : '') . '>' . Html::esc($label) . '</option>';
        }

        $slotRows = [];
        foreach ($slots as $slot) {
            $bookingStatus = (string) $slot['booking_status'];
            $badgeCls = match ($bookingStatus) {
                'open' => 'badge-open',
                'hidden' => 'badge-proxy',
                default => 'badge-closed',
            };
            $badgeLabel = match ($bookingStatus) {
                'open' => '受付中',
                'hidden' => '非表示',
                default => '受付停止',
            };
            $place = ($slot['origin'] ?? null) !== null && ($slot['destination'] ?? null) !== null
                ? $slot['origin'] . ' → ' . $slot['destination']
                : (string) ($slot['location'] ?? '-');
            $endAt = $slot['end_at'] ?? null;
            $reminderAt = $slot['reminder_at'] ?? null;
            $slotId = (int) $slot['id'];

            $slotRows[] = '<article class="book-row">
  <div class="book-row__head">
    <span class="book-row__name">' . Html::esc($slot['name']) . '</span>
    <span class="book-row__size">' . (int) $slot['booked_seats'] . ' / ' . (int) $slot['capacity'] . '名</span>
    <span class="badge ' . $badgeCls . '" style="margin-left:auto">' . $badgeLabel . '</span>
  </div>
  <p class="book-row__meta">
    ' . Html::esc(Time::formatJstLong((string) $slot['start_at']))
                . ($endAt !== null ? '〜' . Html::esc(Time::formatJstLong((string) $endAt)) : '') . '<br>
    ' . Html::esc($place) . '<br>
    1予約あたり最大' . (int) $slot['max_party_size'] . '名 ・ 残り' . (int) $slot['remaining_seats'] . '席
    ' . ($reminderAt !== null
                ? ' ・ リマインド ' . Html::esc(Time::formatJstLong((string) $reminderAt))
                : ' ・ リマインドなし') . '
  </p>
  <div class="btn-row">
    <a class="btn btn-sm" href="/admin/slots/' . $slotId . '">名簿・受付</a>
    <a class="btn btn-sm btn-secondary" href="/admin/reservation-slots/' . $slotId . '/roster.csv">名簿CSV</a>
  </div>
</article>';
        }

        $slotForm = $isNew ? '' : '<h2>予約枠を追加</h2>
' . self::slotFormFields(
            '/admin/reservations/' . (int) $page['id'] . '/slots',
            $csrfToken,
            '予約枠を追加',
            $vPageType,
            null,
            count($slots) + 1,
        );

        $tail = $isNew
            ? '<p class="muted">作成後に予約枠を追加できます。</p>'
            : '<h2>予約枠（' . count($slots) . '件）</h2>
<div class="stack">' . (count($slotRows) > 0 ? implode("\n", $slotRows) : '<p class="muted">まだ予約枠がありません。</p>') . '</div>
' . Html::when(
                count($slots) > 0,
                '<p style="margin-top:12px"><a class="btn btn-sm btn-secondary" href="/admin/reservations/'
                    . (int) $page['id'] . '/roster.csv">このイベントの全予約をCSV</a></p>',
            ) . '
' . $slotForm;

        $content = '
<p><a href="/admin/reservations">← 予約ページ一覧へ</a></p>
<h2>' . ($isNew ? '新しい予約ページ' : Html::esc($page['title'])) . '</h2>

<form class="card" method="post" action="' . $action . '">
  <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">

  <div class="form-section">
    <p class="form-section__title">基本情報</p>
    <div class="field">
      <label for="title">ページ名<span class="req">必須</span></label>
      <input type="text" id="title" name="title" value="' . Html::esc($vTitle) . '" maxlength="80" required>
    </div>
    <div class="field">
      <label for="slug">slug（公開URL）<span class="req">必須</span></label>
      <input type="text" id="slug" name="slug" value="' . Html::esc($vSlug) . '" maxlength="60"
        pattern="[a-z0-9-]+" required>
      <p class="hint">半角英小文字・数字・ハイフン。公開URLは ' . Html::esc($baseUrl) . '/reserve/<strong>slug</strong> になります。</p>
    </div>
    <div class="field">
      <label for="description">説明</label>
      <input type="text" id="description" name="description" value="' . Html::esc($vDescription) . '" maxlength="300">
    </div>
    <div class="field-2col">
      <div class="field">
        <label for="status">公開状態</label>
        <select id="status" name="status">' . $statusOptions . '</select>
      </div>
      <div class="field">
        <label for="page_type">種別</label>
        <select id="page_type" name="page_type">' . $typeOptions . '</select>
      </div>
    </div>
    <p class="hint">種別は表示の初期値が変わるだけで、予約ロジックはこの値に依存しません。</p>
  </div>

  <div class="form-section">
    <p class="form-section__title">公開注意事項</p>
    <div class="field">
      <label for="notice_text">注意事項</label>
      <textarea id="notice_text" name="notice_text" rows="8" maxlength="3000"
        placeholder="例：開始10分前までに受付へお越しください。">' . Html::esc($vNoticeText) . '</textarea>
      <p class="hint">イベントごとに変更できます。1行につき1項目として公開ページに表示します。空欄の場合は従来の共通注意事項を表示します。</p>
    </div>
  </div>

  <div class="form-section">
    <p class="form-section__title">予約設定</p>
    <div class="field checkbox-field">
      <input type="checkbox" id="requires_line_login" name="requires_line_login" value="1"'
            . ($vRequiresLogin ? ' checked' : '') . '>
      <label for="requires_line_login">LINEログインを必須にする</label>
    </div>
    <div class="field checkbox-field">
      <input type="checkbox" id="allow_multi_slot_booking" name="allow_multi_slot_booking" value="1"'
            . ($vAllowMulti ? ' checked' : '') . '>
      <label for="allow_multi_slot_booking">同一ページの複数枠をまとめて予約できるようにする</label>
    </div>
    <div class="field-2col">
      <div class="field">
        <label for="max_slots_per_checkout">一度に選べる最大枠数</label>
        <input type="number" id="max_slots_per_checkout" name="max_slots_per_checkout"
          value="' . $vMaxSlots . '" min="1" max="20" required>
      </div>
      <div class="field">
        <label for="checkin_label">受付確認の呼び方</label>
        <input type="text" id="checkin_label" name="checkin_label" value="' . Html::esc($vCheckinLabel) . '" maxlength="10">
      </div>
    </div>
    <p class="hint">例：乗車 / 受付 / 来場。管理画面の「〇〇済人数」に使います。</p>
  </div>

  <button class="btn btn-sm" type="submit">' . ($isNew ? '作成する' : '保存する') . '</button>
</form>

' . $tail . '
';

        return Layout::render(
            [
                'title' => ($isNew ? '予約ページ作成' : (string) $page['title']) . ' | 管理画面',
                'admin' => true,
                'alert' => $alert,
            ],
            $content,
        );
    }

    /**
     * 予約枠の作成/編集フォーム（作成と編集で同じ項目を使う）。
     *
     * @param array<string,mixed>|null $slot
     */
    public static function slotFormFields(
        string $action,
        string $csrfToken,
        string $submitLabel,
        string $pageType,
        ?array $slot,
        ?int $nextSortOrder = null,
    ): string {
        $isBus = $pageType === 'bus';
        $bookingStatus = $slot !== null ? (string) $slot['booking_status'] : null;

        $localValue = static function (?array $slot, string $key): string {
            if ($slot === null || ($slot[$key] ?? null) === null) {
                return '';
            }

            return Html::esc(Time::toJstDatetimeLocal((string) $slot[$key]));
        };

        return '<form class="card" method="post" action="' . Html::esc($action) . '">
  <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">

  <div class="form-section">
    <p class="form-section__title">枠の内容</p>
    <div class="field">
      <label for="name">枠名<span class="req">必須</span></label>
      <input type="text" id="name" name="name" value="' . Html::esc($slot['name'] ?? '') . '" maxlength="60" required
        placeholder="' . ($isBus ? '行き / 帰り' : '13:00回 / 10:00〜11:00') . '">
    </div>
    <div class="field">
      <label for="description">説明</label>
      <input type="text" id="description" name="description" value="' . Html::esc($slot['description'] ?? '') . '" maxlength="200">
    </div>
    <div class="field">
      <label for="start_at">開始日時（JST）<span class="req">必須</span></label>
      <input type="datetime-local" id="start_at" name="start_at"
        value="' . ($slot !== null ? Html::esc(Time::toJstDatetimeLocal((string) $slot['start_at'])) : '') . '" required>
    </div>
    <div class="field">
      <label for="end_at">終了日時（JST・任意）</label>
      <input type="datetime-local" id="end_at" name="end_at"
        value="' . $localValue($slot, 'end_at') . '">
    </div>
    <div class="field">
      <label for="origin">出発地（バス用・任意）</label>
      <input type="text" id="origin" name="origin" value="' . Html::esc($slot['origin'] ?? '') . '" maxlength="100">
    </div>
    <div class="field">
      <label for="destination">到着地（バス用・任意）</label>
      <input type="text" id="destination" name="destination" value="' . Html::esc($slot['destination'] ?? '') . '" maxlength="100">
    </div>
    <div class="field">
      <label for="location">会場（任意）</label>
      <input type="text" id="location" name="location" value="' . Html::esc($slot['location'] ?? '') . '" maxlength="100">
      <p class="hint">出発地・到着地が空のときは、会場を1行で表示します。</p>
    </div>
  </div>

  <div class="form-section">
    <p class="form-section__title">定員</p>
    <div class="field-2col">
      <div class="field">
        <label for="capacity">定員<span class="req">必須</span></label>
        <input type="number" id="capacity" name="capacity" value="' . (int) ($slot['capacity'] ?? 24) . '" min="0" max="500" required>
      </div>
      <div class="field">
        <label for="max_party_size">1予約あたりの最大人数<span class="req">必須</span></label>
        <input type="number" id="max_party_size" name="max_party_size"
          value="' . (int) ($slot['max_party_size'] ?? 4) . '" min="1" max="20" required>
      </div>
    </div>
    <p class="form-note">' . Html::when(
            $slot !== null,
            '既存の確定予約人数（' . (int) ($slot['booked_seats'] ?? 0) . '名）を下回る定員には変更できません。',
        ) . '1予約あたりの最大人数を1にすると、公開側では人数選択UIを表示しません。</p>
  </div>

  <div class="form-section">
    <p class="form-section__title">受付期間・リマインド</p>
    <div class="field">
      <label for="booking_open_at">予約受付開始日時（JST・任意）</label>
      <input type="datetime-local" id="booking_open_at" name="booking_open_at"
        value="' . $localValue($slot, 'booking_open_at') . '">
    </div>
    <div class="field">
      <label for="booking_close_at">予約締切日時（JST・任意）</label>
      <input type="datetime-local" id="booking_close_at" name="booking_close_at"
        value="' . $localValue($slot, 'booking_close_at') . '">
    </div>
    <div class="field">
      <label for="reminder_at">リマインド送信日時（JST・任意）</label>
      <input type="datetime-local" id="reminder_at" name="reminder_at"
        value="' . $localValue($slot, 'reminder_at') . '">
      <p class="hint">この時刻を過ぎるとCron（5分毎）がLINEリマインドを送信します。空欄なら送信しません。</p>
    </div>
    <div class="field-2col">
      <div class="field">
        <label for="booking_status">受付状態</label>
        <select id="booking_status" name="booking_status">
          <option value="open"' . ($bookingStatus === 'open' || $slot === null ? ' selected' : '') . '>受付中</option>
          <option value="closed"' . ($bookingStatus === 'closed' ? ' selected' : '') . '>受付停止</option>
          <option value="hidden"' . ($bookingStatus === 'hidden' ? ' selected' : '') . '>非表示</option>
        </select>
      </div>
      <div class="field">
        <label for="sort_order">表示順</label>
        <input type="number" id="sort_order" name="sort_order"
          value="' . (int) ($slot['sort_order'] ?? $nextSortOrder ?? 1) . '" min="0" max="999" required>
      </div>
    </div>
  </div>

  <button class="btn btn-sm" type="submit">' . Html::esc($submitLabel) . '</button>
</form>';
    }
}
