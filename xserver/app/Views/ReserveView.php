<?php

declare(strict_types=1);

namespace App\Views;

use App\Support\Html;
use App\Support\Time;

/**
 * 公開予約ページ `/reserve/:slug`（Phase 2F）。
 *
 * - 枠ごとに「予約する/しない」＋人数＋同行者を選び、まとめて確定する
 * - 下部固定CTAの選択中サマリー、送信前の確認セクション（同一フォーム内）
 * - max_party_size = 1 の枠は人数UIを出さず hidden で 1 を送る
 * - 同行者欄にサーバー側で hidden を付けない（JS無効でも複数名を入力・POSTできる）
 */
final class ReserveView
{
    /**
     * @param array<string, mixed> $page
     * @param list<array<string, mixed>> $slots
     * @param array{representative_name: string, phone: string, agreed: bool,
     *   slots: array<int, array{selected: bool, party_size: int, companion_names: list<string>}>} $values
     * @param array{type: string, message: string}|null $alert
     * @param string|null $friendUrl 予約専用LINE公式アカウントの友だち追加URL（未設定ならnull）
     */
    public static function render(
        array $page,
        array $slots,
        array $values,
        string $csrfToken,
        ?string $userName,
        ?int $isLineFriend,
        bool $loggedIn,
        string $loginUrl,
        string $nowUtc,
        ?array $alert = null,
        ?string $friendUrl = null
    ): string {
        // 公開予約は「予約専用LINE公式アカウントの友だち追加済み」が必須。
        // 未追加（0）も取得できなかった場合（null）も予約させない。
        // サーバー側の BookingService でも同じ条件を検証している。
        $friendRequired = (int) ($page['requires_line_login'] ?? 1) === 1;
        $needsFriendAdd = $friendRequired && $loggedIn && $isLineFriend !== 1;
        $visible = array_values(array_filter(
            $slots,
            static fn (array $slot): bool => !empty($slot['is_visible'])
        ));
        $bookable = array_values(array_filter(
            $visible,
            static fn (array $slot): bool => SlotParts::state($slot, $nowUtc) === 'open'
        ));

        $slotCards = implode("\n", array_map(
            static fn (array $slot): string => self::slotBlock(
                $slot,
                $values['slots'][(int) $slot['id']] ?? ['selected' => false, 'party_size' => 1, 'companion_names' => []],
                $nowUtc
            ),
            $visible
        ));

        $multiHint = (int) $page['allow_multi_slot_booking'] === 1 && count($bookable) > 1
            ? '<div class="notice" style="margin-bottom:16px">
        予約したい枠にチェックを入れて、それぞれの人数を選んでください。
        <strong>最大' . (int) $page['max_slots_per_checkout'] . '枠までまとめて予約できます。</strong>
        枠ごとに人数が違っても構いません。
      </div>'
            : '';

        // 料金・注意事項は「同意する」より前に置く。
        // 画面下部にあると、読まずに同意する導線になってしまう。
        $infoSections = Html::when(
            $page['page_type'] === 'bus',
            '<h2>料金のご案内</h2>' . Layout::priceInfoCard()
        ) . '
<h2 id="notices">注意事項</h2>
' . Layout::noticeCard(isset($page['notice_text']) ? (string) $page['notice_text'] : null);

        $content = '
<section class="hero" style="margin:-16px -16px 16px">
  <h1>' . Html::esc($page['title']) . '</h1>
  ' . Html::when(
            !empty($page['description']),
            '<p>' . nl2br(Html::esc($page['description'])) . '</p>'
        ) . '
</section>

' . $multiHint . '

<h2>予約する枠を選ぶ</h2>
' . ($visible === []
            ? '<div class="card"><p class="muted" style="margin:0">現在ご案内できる枠はありません。</p></div>'
            : '');

        if ($needsFriendAdd) {
            $content .= self::friendRequiredCard($friendUrl, $loginUrl);
        } elseif ($loggedIn) {
            $content .= '<form method="post" action="/reserve/' . Html::esc($page['slug']) . '/book" id="reserve-form">
  <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">
  ' . $slotCards . '

' . $infoSections . '

<h2>代表者のご入力</h2>
<div class="card">
  <div class="field">
    <label for="representative_name">代表者氏名<span class="req">必須</span></label>
    <input type="text" id="representative_name" name="representative_name"
      value="' . Html::esc($values['representative_name']) . '" maxlength="50" required autocomplete="name">
    <p class="hint">当日受付でお呼びするお名前です。LINEの表示名とは別に入力してください。</p>
  </div>

  <div class="field">
    <label for="phone">電話番号<span class="req">必須</span></label>
    <input type="tel" id="phone" name="phone" value="' . Html::esc($values['phone']) . '"
      inputmode="tel" maxlength="20" required autocomplete="tel">
    <p class="hint">当日ご連絡できる番号（ハイフンあり・なしどちらも可）</p>
  </div>

  <div class="field">
    <p class="hint" style="margin-top:0">
      ご予約の前に<a href="#notices">上記の注意事項</a>をご確認ください。
    </p>
    <div class="checkbox-field">
      <input type="checkbox" id="agreed" name="agreed" value="1" required'
                . ($values['agreed'] ? ' checked' : '') . '>
      <label for="agreed">上記の注意事項を確認し、内容に同意します<span class="req">必須</span></label>
    </div>
  </div>
</div>

' . self::confirmPanel() . '
</form>

' . self::stickyCta();
        } else {
            $content .= $slotCards . '
<div class="card center">
  <p style="margin-top:0">ご予約にはLINEログインが必要です。</p>
  <a class="btn btn-line" href="' . Html::esc($loginUrl) . '">LINEでログインして予約する</a>
</div>';
        }

        // フォームを出している場合は、その中（同意欄の直前）に出しているので重複させない
        $content .= '
' . (($loggedIn && !$needsFriendAdd) ? '' : $infoSections) . '

<p class="center"><a class="btn btn-secondary" href="/my-bookings">マイ予約を確認する</a></p>
';

        return Layout::render(
            [
                'title' => (string) $page['title'] . ' | 予約',
                'userName' => $userName,
                'alert' => $alert,
                'bodyEnd' => $loggedIn ? '<script src="/assets/reserve.js" defer></script>' : '',
            ],
            $content
        );
    }

    /**
     * @param array<string, mixed> $slot
     * @param array{selected: bool, party_size: int, companion_names: list<string>} $value
     */
    private static function slotBlock(array $slot, array $value, string $nowUtc): string
    {
        $state = SlotParts::state($slot, $nowUtc);
        $bookable = $state === 'open';
        $slotId = (int) $slot['id'];

        $cardClass = 'card trip-card slot-card' . ($bookable ? '' : ' is-full');

        $picker = $bookable
            ? '<div class="slot-pick">
      <label class="checkbox-field slot-toggle" for="slot_' . $slotId . '">
        <input type="checkbox" id="slot_' . $slotId . '" name="slot_selected" value="' . $slotId . '"
          data-slot-toggle' . ($value['selected'] ? ' checked' : '') . '>
        <span><strong>この枠を予約する</strong></span>
      </label>
      <div class="slot-fields" data-slot-fields>
        ' . self::partyField($slot, $value) . '
        ' . self::companionFields($slot, $value) . '
      </div>
    </div>'
            : '<p class="muted" style="margin:12px 0 0">' . match ($state) {
                'full' => 'この枠は満席です。',
                'suspended' => 'この枠は現在受付を停止しています。',
                'closed_time' => 'この枠の受付は終了しました。',
                'before_open' => 'この枠はまだ受付を開始していません。',
                default => 'この枠は現在予約できません。',
            } . '</p>';

        return '<article class="' . $cardClass . '" data-slot-block
  data-slot-name="' . Html::esc($slot['name']) . '"
  data-slot-when="' . Html::esc(Time::formatJstLong((string) $slot['start_at'])) . '"
  data-slot-where="' . Html::esc(self::whereText($slot)) . '">
  <div class="trip-card__head">
    <span class="trip-card__dir">' . Html::esc($slot['name']) . '</span>
    <span class="trip-card__state">' . Html::esc(SlotParts::stateLabel($slot, $nowUtc)) . '</span>
  </div>
  <div class="trip-card__body">
    ' . SlotParts::when($slot) . '
    ' . Html::when(
            !empty($slot['description']),
            '<p class="trip-meta">' . Html::esc($slot['description']) . '</p>'
        ) . '
    ' . SlotParts::route($slot) . '
    ' . SlotParts::seats($slot, $nowUtc) . '
    ' . SlotParts::timing($slot, $nowUtc) . '
    ' . $picker . '
  </div>
</article>';
    }

    /** @param array<string, mixed> $slot */
    private static function whereText(array $slot): string
    {
        if (!empty($slot['origin']) && !empty($slot['destination'])) {
            return (string) $slot['origin'] . ' → ' . (string) $slot['destination'];
        }
        return (string) ($slot['location'] ?? $slot['origin'] ?? '');
    }

    /**
     * @param array<string, mixed> $slot
     * @param array{selected: bool, party_size: int, companion_names: list<string>} $value
     */
    private static function partyField(array $slot, array $value): string
    {
        $slotId = (int) $slot['id'];
        $maxPartySize = (int) $slot['max_party_size'];
        $remaining = (int) $slot['remaining_seats'];

        // 1名固定の枠は人数選択UIを出さず、POSTされる値の形だけ揃える
        if ($maxPartySize === 1) {
            return '<input type="hidden" name="party_size_' . $slotId . '" value="1">
        <p class="slot-single-party">この枠はお1人ずつのご予約です。</p>';
        }

        $maxSelectable = min($maxPartySize, $remaining);
        $checked = $value['party_size'] >= 1 && $value['party_size'] <= $maxSelectable
            ? $value['party_size']
            : 1;

        $options = '';
        for ($n = 1; $n <= $maxPartySize; $n++) {
            $disabled = $n > $maxSelectable;
            $id = sprintf('party_size_%d_%d', $slotId, $n);
            $options .= '<label class="party__opt" for="' . $id . '">
        <input type="radio" id="' . $id . '" name="party_size_' . $slotId . '" value="' . $n . '"'
                . ($n === $checked && !$disabled ? ' checked' : '')
                . ($disabled ? ' disabled' : '') . '>
        <span>' . $n . '名</span>
      </label>';
        }

        $hint = $maxSelectable < $maxPartySize
            ? sprintf('残り%d席のため、%d名以上は選択できません。', $remaining, $maxSelectable + 1)
            : sprintf('代表者を含めた人数です（最大%d名 / 残り%d席）。', $maxPartySize, $remaining);

        return '<div class="field">
          <label id="party_label_' . $slotId . '">ご予約人数<span class="req">必須</span></label>
          <div class="party' . ($maxPartySize > 4 ? ' party--wide' : '') . '" role="group"
            aria-labelledby="party_label_' . $slotId . '">' . $options . '</div>
          <p class="hint">' . Html::esc($hint) . '</p>
        </div>';
    }

    /**
     * 同行者欄。サーバー側では hidden も required も付けない（JS無効時に入力できるように）。
     *
     * @param array<string, mixed> $slot
     * @param array{selected: bool, party_size: int, companion_names: list<string>} $value
     */
    private static function companionFields(array $slot, array $value): string
    {
        $slotId = (int) $slot['id'];
        $maxPartySize = (int) $slot['max_party_size'];
        if ($maxPartySize <= 1) {
            return '';
        }

        $fields = '';
        for ($i = 1; $i < $maxPartySize; $i++) {
            $id = sprintf('companion_%d_%d', $slotId, $i);
            $fields .= '<div class="field companion-field" data-companion-index="' . $i . '">
      <label for="' . $id . '">同行者' . $i . 'のお名前</label>
      <input type="text" id="' . $id . '" name="companion_' . $slotId . '[]"
        value="' . Html::esc($value['companion_names'][$i - 1] ?? '') . '" maxlength="50" autocomplete="off">
    </div>';
        }

        return '<div class="companion-group" data-companion-group>
      <p class="companion-group__lead">同行者のお名前（選んだ人数に応じて上から順にご記入ください）</p>
      ' . $fields . '
    </div>';
    }

    /**
     * 予約専用LINE公式アカウントの友だち追加が必要なときの案内。
     *
     * 友だち追加そのものはLINE側で行うため、追加後に友だち状態を取り直す必要がある。
     * 友だち状態はLINEログイン時に更新するので、「追加した」導線は再ログインへ送る。
     */
    private static function friendRequiredCard(?string $friendUrl, string $loginUrl): string
    {
        $addButton = $friendUrl !== null
            ? '<a class="btn btn-line" href="' . Html::esc($friendUrl) . '" target="_blank" rel="noopener">'
                . '予約専用LINE公式アカウントを友だち追加する</a>'
            : '<p class="hint">LINEアプリで予約専用LINE公式アカウントを検索し、友だち追加してください。</p>';

        return '<section class="card center" id="friend-required">
  <h3 style="margin-top:0">予約専用LINE公式アカウントの友だち追加が必要です</h3>
  <p>ご予約の確定通知と開始前のリマインドは、予約専用LINE公式アカウントからお送りします。
    お知らせが届くよう、友だち追加をお願いします。</p>
  ' . $addButton . '
  <p class="hint" style="margin-top:14px">友だち追加が終わったら、下のボタンで状態を更新してください。</p>
  <a class="btn btn-secondary" href="' . Html::esc($loginUrl) . '">友だち追加を確認して予約に進む</a>
</section>';
    }

    /** 送信前の確認セクション。JS無効時は最初から開いた状態で表示される。 */
    private static function confirmPanel(): string
    {
        return '<section class="confirm-panel" id="reserve-confirm" data-confirm-panel>
  <h3>ご予約内容の確認</h3>
  <p class="confirm-lead">まだ予約は確定していません。内容をご確認のうえ、下のボタンで確定してください。</p>

  <div data-confirm-slots></div>

  <ul class="summary-list" data-confirm-rep>
    <li><span class="k">代表者</span><span class="v" data-confirm-name>—</span></li>
    <li><span class="k">電話番号</span><span class="v" data-confirm-phone>—</span></li>
    <li><span class="k">予約件数</span><span class="v" data-confirm-count>—</span></li>
  </ul>

  <button class="btn" type="submit" id="submit-button">選択した予約をまとめて確定する</button>
  <p class="hint center" style="margin-top:8px">送信は1回だけ押してください。</p>
  <button class="btn btn-secondary" type="button" id="confirm-dismiss" hidden
    style="margin-top:10px">内容を変更する</button>
</section>';
    }

    /** 下部固定CTA。JS専用のためサーバー側では hidden で出力する。 */
    private static function stickyCta(): string
    {
        return '<div class="sticky-cta" id="sticky-cta" hidden data-sticky-cta>
  <div class="sticky-cta__summary" data-sticky-summary hidden>
    <div class="sticky-cta__head">
      <span data-sticky-count>選択中 0件</span>
      <span class="sticky-cta__total" data-sticky-total>合計 0名</span>
    </div>
    <ul class="sticky-cta__list" data-sticky-list></ul>
  </div>
  <button class="btn" type="button" id="open-confirm" data-open-confirm disabled>予約する枠を選んでください</button>
  <p class="sticky-cta__hint" data-sticky-hint></p>
</div>';
    }

    /**
     * 空の入力値。
     *
     * @return array{representative_name: string, phone: string, agreed: bool, slots: array<int, array{selected: bool, party_size: int, companion_names: list<string>}>}
     */
    public static function emptyValues(): array
    {
        return ['representative_name' => '', 'phone' => '', 'agreed' => false, 'slots' => []];
    }
}
