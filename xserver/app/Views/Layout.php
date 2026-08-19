<?php

declare(strict_types=1);

namespace App\Views;

use App\Support\Html;

/** 共通レイアウトと共通パーツ。Workers版 src/views/layout.ts の移植。 */
final class Layout
{
    /**
     * @param array{title: string, userName?: ?string, admin?: bool,
     *   alert?: array{type: string, message: string}|null, bodyEnd?: string} $options
     */
    public static function render(array $options, string $content): string
    {
        $admin = (bool) ($options['admin'] ?? false);
        $headerClass = $admin ? 'site-header admin-header' : 'site-header';
        $wrapClass = $admin ? 'wrap admin-wrap' : 'wrap';
        $homeHref = $admin ? '/admin' : '/';
        $brand = $admin ? '予約管理' : '🛁 草加健康センター 予約';
        $userName = $options['userName'] ?? null;
        $alert = $options['alert'] ?? null;

        $nav = $admin
            ? '<a href="/admin">ダッシュボード</a><form method="post" action="/admin/logout" style="margin:0">'
                . '<button class="header-logout" type="submit">ログアウト</button></form>'
            : '<a href="/my-bookings">マイ予約</a>' . Html::when(
                $userName,
                '<span style="align-self:center;font-size:.8rem">' . Html::esc($userName) . 'さん</span>'
            );

        $alertHtml = $alert === null
            ? ''
            : sprintf(
                '<div class="alert alert-%s" role="alert">%s</div>',
                Html::esc($alert['type']),
                Html::esc($alert['message'])
            );

        return '<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="' . ($admin ? 'noindex, nofollow' : 'index, follow') . '">
<meta name="theme-color" content="#d0121b">
<title>' . Html::esc($options['title']) . '</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="' . $headerClass . '">
  <div class="' . $wrapClass . '">
    <div class="header-row">
      <a class="brand" href="' . $homeHref . '">' . Html::esc($brand) . '</a>
      <nav class="header-nav">
        ' . $nav . '
      </nav>
    </div>
  </div>
</header>
<main class="' . $wrapClass . '">
' . $alertHtml . '
' . $content . '
</main>
<footer class="site-footer">
  <p>草加健康センター オンライン予約</p>
  <p class="muted" style="color:#b9b0a2">当日の連絡・変更は草加健康センターまでお問い合わせください。</p>
</footer>
' . ($options['bodyEnd'] ?? '') . '
</body>
</html>';
    }

    /**
     * 「池袋西口 マクドナルド前辺り」のような1カラムの地名を主要地名と補足に分ける。
     * DBの値は変更しない（表示層のみの分割）。
     *
     * @return array{main: string, sub: string}
     */
    public static function splitPlace(?string $place): array
    {
        $normalized = trim((string) $place);
        if (preg_match('/^(\S+)[\s　]+(.+)$/u', $normalized, $m) === 1) {
            return ['main' => $m[1], 'sub' => $m[2]];
        }
        return ['main' => $normalized, 'sub' => ''];
    }

    /** 「残りわずか」のしきい値。定員6名の貸切枠を常に満席直前扱いしない。 */
    public static function fewSeatsThreshold(?int $capacity): int
    {
        if ($capacity === null || $capacity <= 0) {
            return 6;
        }
        return min(6, max(1, (int) ceil($capacity * 0.25)));
    }

    /** 残席が「わずか」か。公開側のバッジと管理側のアラートで必ずこれを使う。 */
    public static function isFewSeats(int $remaining, ?int $capacity): bool
    {
        return $remaining > 0 && $remaining <= self::fewSeatsThreshold($capacity);
    }

    /** 残席の表示区分。色だけに頼らず文字でも状態を示す。 */
    public static function seatBadge(bool $isFull, int $remaining, ?int $capacity = null): string
    {
        if ($isFull) {
            return '<span class="seat-badge is-full">満席</span>';
        }
        if (self::isFewSeats($remaining, $capacity)) {
            return '<span class="seat-badge is-few">残りわずか</span>';
        }
        return '<span class="seat-badge is-open">空席あり</span>';
    }

    /** 料金の参考情報（決済はMVP対象外）。 */
    public static function priceInfoCard(): string
    {
        return '<section class="card">
  <h3 style="margin-top:0">草加健康センター 館内料金（参考）</h3>
  <table class="price-table">
    <tbody>
      <tr><th>入館料</th><td>2,250円</td></tr>
      <tr><th>深夜料金（深夜2:00以降）</th><td>+1,500円</td></tr>
    </tbody>
  </table>
  <p class="muted" style="margin-bottom:0">
    入館料にはリクライニングシート利用・館内着・タオルセットが含まれます。<br>
    深夜2:00以降のご滞在は深夜料金1,500円が自動加算されます。<br>
    ※料金のお支払いは現地でのお手続きとなります。本システムでの決済は行いません。
  </p>
</section>';
    }

    /** 利用にあたっての注意事項。 */
    public static function noticeCard(): string
    {
        return '<section class="card">
  <h3 style="margin-top:0">ご利用にあたっての注意事項</h3>
  <ul class="notes">
    <li>開始時刻の15分前までに集合場所へお越しください。定刻に出発・開始します。</li>
    <li>枠ごとに別のご予約です。複数の枠をご利用の場合はまとめてご予約ください。</li>
    <li>ご予約人数を変更する場合は、一度キャンセルのうえ再度ご予約ください。</li>
    <li>キャンセルは「マイ予約」からお願いします。無断キャンセルはご遠慮ください。</li>
    <li>座席指定はできません。当日の受付順となります。</li>
    <li>車内・館内での迷惑行為はご遠慮ください。</li>
    <li>交通状況により到着時刻が前後する場合があります。</li>
  </ul>
</section>';
    }
}
