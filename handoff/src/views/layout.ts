import { esc, when } from '../lib/html';

export interface LayoutOptions {
  title: string;
  /** ログイン中ユーザーの表示名（ヘッダー表示用） */
  userName?: string | null;
  /** 管理画面レイアウトにする */
  admin?: boolean;
  /** ページ上部に出すフラッシュメッセージ */
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
  bodyEnd?: string;
}

export function layout(options: LayoutOptions, content: string): string {
  const headerClass = options.admin ? 'site-header admin-header' : 'site-header';
  const wrapClass = options.admin ? 'wrap admin-wrap' : 'wrap';
  const homeHref = options.admin ? '/admin' : '/';
  const brand = options.admin ? 'らっこ号 管理画面' : '🚌 らっこ号 池袋便';

  return `<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="${options.admin ? 'noindex, nofollow' : 'index, follow'}">
<meta name="theme-color" content="#d0121b">
<title>${esc(options.title)}</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="${headerClass}">
  <div class="${wrapClass}">
    <div class="header-row">
      <a class="brand" href="${homeHref}">${esc(brand)}</a>
      <nav class="header-nav">
        ${
          options.admin
            ? `<a href="/admin">ダッシュボード</a><form method="post" action="/admin/logout" style="margin:0"><button class="header-logout" type="submit">ログアウト</button></form>`
            : `<a href="/my-bookings">マイ予約</a>${when(
                options.userName,
                `<span style="align-self:center;font-size:.8rem">${esc(options.userName)}さん</span>`,
              )}`
        }
      </nav>
    </div>
  </div>
</header>
<main class="${wrapClass}">
${
  options.alert
    ? `<div class="alert alert-${esc(options.alert.type)}" role="alert">${esc(options.alert.message)}</div>`
    : ''
}
${content}
</main>
<footer class="site-footer">
  <p>草加健康センター「らっこ号 池袋便」予約システム</p>
  <p class="muted" style="color:#b9b0a2">運行当日の連絡・変更は草加健康センターまでお問い合わせください。</p>
</footer>
${options.bodyEnd ?? ''}
</body>
</html>`;
}

/**
 * 「池袋西口 マクドナルド前辺り」のような1カラムの地名を、
 * 主要地名と補足に分けて表示するためのヘルパー。
 * DBの値は変更しない（表示層のみの分割）。
 */
export function splitPlace(place: string): { main: string; sub: string } {
  const normalized = place.trim();
  const match = /^(\S+)[\s　]+(.+)$/.exec(normalized);
  if (!match || !match[1] || !match[2]) return { main: normalized, sub: '' };
  return { main: match[1], sub: match[2] };
}

/** 残席の表示区分。色だけに頼らず文字でも状態を示す。 */
export function seatBadge(trip: { is_full: boolean; remaining_seats: number }): string {
  if (trip.is_full) return '<span class="seat-badge is-full">満席</span>';
  if (trip.remaining_seats <= 6) return '<span class="seat-badge is-few">残りわずか</span>';
  return '<span class="seat-badge is-open">空席あり</span>';
}

/** 料金の参考情報（決済はMVP対象外）。 */
export function priceInfoCard(): string {
  return `<section class="card">
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
</section>`;
}

/** 乗車前の注意事項。 */
export function noticeCard(): string {
  return `<section class="card">
  <h3 style="margin-top:0">ご利用にあたっての注意事項</h3>
  <ul class="notes">
    <li>出発時刻の15分前までに集合場所へお越しください。バスは定刻に出発します。</li>
    <li>行き・帰りはそれぞれ別のご予約です。両方ご利用の場合は2件ご予約ください。</li>
    <li>1回のご予約につき1〜4名までお申し込みいただけます。</li>
    <li>ご予約人数を変更する場合は、一度キャンセルのうえ再度ご予約ください。</li>
    <li>キャンセルは「マイ予約」からお願いします。無断キャンセルはご遠慮ください。</li>
    <li>座席指定はできません。乗車順は当日の受付順となります。</li>
    <li>車内での飲酒・喫煙はご遠慮ください。</li>
    <li>交通状況により到着時刻が前後する場合があります。</li>
  </ul>
</section>`;
}
