<?php

declare(strict_types=1);

namespace App\Views;

use App\Support\Html;

/** LINEログイン誘導ページ。DEMO_MODE のときだけ疑似ログインも表示する。 */
final class LoginView
{
    /** @param array{type: string, message: string}|null $alert */
    public static function render(
        string $redirectTo,
        bool $demoMode,
        bool $lineConfigured,
        string $csrfToken,
        ?array $alert = null
    ): string {
        $redirect = Html::esc($redirectTo);

        $lineBlock = $lineConfigured
            ? '<form method="post" action="/auth/line/start" style="margin:0">
    <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">
    <input type="hidden" name="redirect_to" value="' . $redirect . '">
    <button class="btn btn-line" type="submit">LINEでログイン</button>
  </form>'
            : '<p class="alert alert-error" style="margin:0">LINEログインが設定されていません'
                . '（LINE_LOGIN_CHANNEL_ID / LINE_LOGIN_CHANNEL_SECRET 未設定）。</p>';

        $demoBlock = Html::when(
            $demoMode,
            '<div class="card" style="border-style:dashed">
    <h3 style="margin-top:0">開発用：デモログイン</h3>
    <p class="muted">DEMO_MODE のときだけ表示されます。本番環境では利用できません。</p>
    <form method="post" action="/auth/demo/login" class="stack">
      <input type="hidden" name="csrf_token" value="' . Html::esc($csrfToken) . '">
      <input type="hidden" name="redirect_to" value="' . $redirect . '">
      <div class="field">
        <label for="demo_user_id">デモLINEユーザーID</label>
        <input type="text" id="demo_user_id" name="demo_user_id" value="demo-user-001" required>
      </div>
      <div class="field">
        <label for="demo_display_name">表示名</label>
        <input type="text" id="demo_display_name" name="demo_display_name" value="デモユーザー" required>
      </div>
      <button class="btn btn-secondary" type="submit">デモユーザーでログイン</button>
    </form>
  </div>'
        );

        $content = '
<h2>予約するにはログイン</h2>
<div class="card stack">
  <p style="margin-top:0">予約・確認・キャンセルにはLINEログインを使用します。</p>
  <ul class="notes" style="margin:0">
    <li>LINEから予約内容をいつでも確認できます</li>
    <li>開始前にリマインドが届きます</li>
    <li>同じ枠の二重予約を防げます</li>
  </ul>
  ' . $lineBlock . '
  <div class="notice">
    <strong>予約専用LINE公式アカウントの友だち追加が必要です</strong><br>
    ご予約には、予約専用LINE公式アカウントの友だち追加が必要です。
    予約完了通知と開始前リマインドはこのアカウントからお送りします。
    友だち追加をしなくてもご予約自体は可能です。
  </div>
  <p class="muted" style="margin:0">取得する情報は表示名とユーザーIDのみです。トークの内容は取得しません。</p>
</div>
' . $demoBlock . '
<p class="center"><a href="/">トップへ戻る</a></p>
';

        return Layout::render(['title' => 'ログイン | 草加健康センター 予約センター', 'alert' => $alert], $content);
    }
}
