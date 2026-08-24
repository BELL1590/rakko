<?php

declare(strict_types=1);

namespace App\Views;

use App\Support\Html;

/**
 * LIFF のブートストラップ画面。
 *
 * 新しい端末・ブラウザには rk_session が無いため、
 * LINEアプリのログイン状態からWebセッションを作るための中継ページ。
 *
 * JavaScriptが動かない／LIFFが使えない／初期化に失敗した場合に備えて、
 * 既存のLINE Login（OAuth/OIDC）へのリンクを最初からHTMLに置いておく。
 * これがあるので、JS無効でも利用者は行き止まりにならない。
 */
final class LiffView
{
    /** LIFF SDK v2 の配信元。CSPでもこのオリジンだけを許可する。 */
    public const SDK_URL = 'https://static.line-scdn.net/liff/edge/2/sdk.js';

    /** @param array{type: string, message: string}|null $alert */
    public static function render(
        ?string $liffId,
        string $redirectTo,
        string $csrfToken,
        bool $lineConfigured,
        ?array $alert = null
    ): string {
        $loginUrl = '/login?redirect_to=' . rawurlencode($redirectTo);

        // LIFF未設定なら中継する意味がないので、通常のログインへ案内するだけ
        if ($liffId === null) {
            $content = '<h2>LINEでログイン</h2>
<div class="card center">
  <p>LINEログインへお進みください。</p>
  <a class="btn btn-line" href="' . Html::esc($loginUrl) . '">LINEでログイン</a>
</div>';

            return Layout::render(
                ['title' => 'LINEでログイン | 草加健康センター 予約センター', 'alert' => $alert],
                $content
            );
        }

        // noscript とフォールバックリンクは常に出す。
        // JSが動いた場合だけ data-liff-status の表示を差し替える。
        $content = '<h2>LINEでログイン</h2>
<div class="card center" id="liff-bootstrap"
  data-liff-id="' . Html::esc($liffId) . '"
  data-redirect-to="' . Html::esc($redirectTo) . '"
  data-csrf-token="' . Html::esc($csrfToken) . '">
  <p id="liff-status" data-liff-status>LINEアカウントを確認しています…</p>
  <noscript>
    <p>JavaScriptが無効のため、通常のLINEログインへお進みください。</p>
  </noscript>
  <p id="liff-fallback">
    <a class="btn btn-line" href="' . Html::esc($loginUrl) . '">LINEでログイン</a>
  </p>
  <p class="hint" style="margin-top:12px">
    このまま進まない場合は上のボタンからログインしてください。
  </p>
</div>';

        return Layout::render(
            [
                'title' => 'LINEでログイン | 草加健康センター 予約センター',
                'alert' => $alert,
                'bodyEnd' => '<script src="' . self::SDK_URL . '"></script>'
                    . '<script src="/assets/liff.js" defer></script>',
            ],
            $content
        );
    }
}
