import { esc, when } from '../lib/html';
import { layout } from './layout';

/**
 * LINEログイン誘導ページ。
 * 「なぜLINEログインが必要なのか」を先に示し、CTAは画面内で1つだけ主役にする。
 * DEMO_MODE のときだけ疑似ログインのフォームも表示する。
 */
export function loginPage(params: {
  redirectTo: string;
  demoMode: boolean;
  lineConfigured: boolean;
  csrfToken: string;
  alert?: { type: 'error' | 'success' | 'info'; message: string } | null;
}): string {
  const redirect = esc(params.redirectTo);

  const lineBlock = params.lineConfigured
    ? `<form method="post" action="/auth/line/start" style="margin:0">
    <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
    <input type="hidden" name="redirect_to" value="${redirect}">
    <button class="btn btn-line" type="submit">LINEでログイン</button>
  </form>`
    : `<p class="alert alert-error" style="margin:0">LINEログインが設定されていません（LINE_LOGIN_CHANNEL_ID / LINE_LOGIN_CHANNEL_SECRET 未設定）。</p>`;

  const demoBlock = when(
    params.demoMode,
    `<div class="card" style="border-style:dashed">
    <h3 style="margin-top:0">開発用：デモログイン</h3>
    <p class="muted">DEMO_MODE のときだけ表示されます。本番環境では利用できません。</p>
    <form method="post" action="/auth/demo/login" class="stack">
      <input type="hidden" name="csrf_token" value="${esc(params.csrfToken)}">
      <input type="hidden" name="redirect_to" value="${redirect}">
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
  </div>`,
  );

  const content = `
<h2>らっこ号を予約する</h2>
<div class="card stack">
  <p style="margin-top:0">予約・確認・キャンセルにはLINEログインを使用します。</p>
  <ul class="notes" style="margin:0">
    <li>LINEから予約内容をいつでも確認できます</li>
    <li>乗車前にリマインドが届きます</li>
    <li>同じ便の二重予約を防げます</li>
  </ul>
  ${lineBlock}
  <div class="notice">
    <strong>公式アカウントの友だち追加のお願い</strong><br>
    LINEでの予約完了通知・乗車前リマインドを受け取るには、草加健康センター公式アカウントの友だち追加が必要です。
    友だち追加をしなくてもご予約自体は可能です。
  </div>
  <p class="muted" style="margin:0">取得する情報は表示名とユーザーIDのみです。トークの内容は取得しません。</p>
</div>
${demoBlock}
<p class="center"><a href="/">トップへ戻る</a></p>
`;

  return layout({ title: 'ログイン | らっこ号 池袋便', alert: params.alert ?? null }, content);
}
