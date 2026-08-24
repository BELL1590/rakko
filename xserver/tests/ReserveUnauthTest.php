<?php

declare(strict_types=1);

/**
 * 未ログイン状態の /reserve/{slug} で、人数選択に応じた同行者欄の
 * 表示切り替えが崩れる不具合の回帰テスト。
 *
 * 実機で「未ログインで枠を開くと、人数を1名にしても同行者1〜3の
 * 入力欄が全部表示されたまま」という障害が出た。
 *
 * 原因は二段構えだった。
 * 1. ReserveView は未ログインでも slotCards（枠カード）を描画するが、
 *    reserve.js は $loggedIn のときしか <script> を読み込んでいなかった。
 * 2. たとえ読み込んでも、reserve.js の冒頭は
 *    `var form = document.getElementById('reserve-form'); if (!form) return;`
 *    で、#reserve-form が無い（未ログイン時はフォーム自体が無い）と
 *    即座に全処理を抜けていたため、同行者欄の表示切り替えも動かなかった。
 *
 * ここでは「reserve.js が常に読み込まれること」と
 * 「同行者欄の同期ロジックが #reserve-form の有無より前に、
 *   document 起点で動くこと」を静的・構造的に検査する。
 * 実際にJSを動かした表示切り替えの検証（1/2/4名→同行者0/1/3欄）は
 * tests/browser/check-reserve-unauth.php（Chromium実機）で行う。
 */

/** 未ログイン状態の公開予約ページHTMLを得る（requires_line_loginはデフォルトのtrue）。 */
function unauthReservePageHtml(string $slug = 'unauth-reserve'): string
{
    resetRequestState();
    $app = makeApp();
    $pageId = Fixtures::page($app, ['slug' => $slug]);
    Fixtures::slot($app, $pageId, ['name' => '行き', 'capacity' => 10, 'max_party_size' => 4]);

    $response = request($app, 'GET', '/reserve/' . $slug);
    assertSame(200, $response->status);
    return $response->body;
}

/** ログイン済み相当（requires_line_login=false）の公開予約ページHTMLを得る。 */
function loggedInReservePageHtml(string $slug = 'loggedin-reserve'): string
{
    resetRequestState();
    $app = makeApp();
    $pageId = Fixtures::page($app, ['slug' => $slug, 'requires_line_login' => false]);
    Fixtures::slot($app, $pageId, ['name' => '行き', 'capacity' => 10, 'max_party_size' => 4]);

    $response = request($app, 'GET', '/reserve/' . $slug);
    assertSame(200, $response->status);
    return $response->body;
}

/** reserve.js のソース。 */
function reserveUnauthJs(): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/public/assets/reserve.js');
}

describe('未ログイン時の人数UI同期（不具合再発防止）', function (): void {
    test('未ログインでも reserve.js が読み込まれる', function (): void {
        $html = unauthReservePageHtml();

        assertNotContains('id="reserve-form"', $html, '未ログインには予約フォームが無いこと（前提の確認）');
        assertContains(
            '<script src="/assets/reserve.js" defer></script>',
            $html,
            '未ログインでも同行者欄の表示切り替えJSは読み込まれること'
        );
    });

    test('ログイン済みでも reserve.js は変わらず読み込まれる（既存動作の維持）', function (): void {
        $html = loggedInReservePageHtml();

        assertContains('id="reserve-form"', $html);
        assertContains('<script src="/assets/reserve.js" defer></script>', $html);
    });

    test('未ログインの枠カードにも人数ラジオと同行者欄が同じ構造で出る', function (): void {
        $html = unauthReservePageHtml();

        assertContains('data-slot-block', $html);
        assertContains('data-slot-toggle', $html);
        assertContains('data-slot-fields', $html);
        assertContains('data-companion-group', $html);
        // サーバー側では hidden も required も付けない（JS無効時の入力を妨げないため）
        assertNotContains('data-companion-index="1" hidden', $html);
    });

    test('同行者欄の同期ロジックは document 起点で枠カードを取得する（form起点だと未ログインでnullになる）', function (): void {
        $js = reserveUnauthJs();

        assertContains(
            "document.querySelectorAll('[data-slot-block]')",
            $js,
            '枠カードは document から探すこと（フォームの外にも出るため）'
        );
        assertNotContains(
            "form.querySelectorAll('[data-slot-block]')",
            $js,
            '枠カードを form 起点で探すと、未ログイン時（フォーム無し）に何も取れなくなる'
        );
    });

    test('人数・同行者欄の初期同期は #reserve-form の有無チェックより前で行われる', function (): void {
        $js = reserveUnauthJs();

        $syncPos = strpos($js, 'blocks.forEach(syncBlock)');
        $formGuardPos = strpos($js, "document.getElementById('reserve-form')");

        assertTrue($syncPos !== false, '初期同期 blocks.forEach(syncBlock) がソースにあること');
        assertTrue($formGuardPos !== false, '#reserve-form の有無チェックがソースにあること');
        assertTrue(
            $syncPos < $formGuardPos,
            '同行者欄などの表示同期は、予約フォームの有無に関係なく先に実行されること'
            . '（fromの後ろにあると、未ログイン時は早期returnで一切動かなくなる）'
        );
    });

    test('#reserve-form の有無チェックより前では form 要素を参照しない（未ログイン時にJS例外を出さないため）', function (): void {
        $js = reserveUnauthJs();

        $formGuardPos = strpos($js, "if (!form) return;");
        assertTrue($formGuardPos !== false, '早期returnのガードがソースにあること');

        $before = substr($js, 0, $formGuardPos);
        assertNotContains(
            'form.',
            $before,
            'ガードより前で form.* を参照すると、未ログイン時（formがnull）にTypeErrorになる'
        );
    });

    test('#reserve-form が無いときは送信・確認CTA等の初期化をしない早期returnがある', function (): void {
        $js = reserveUnauthJs();

        assertContains(
            "var form = document.getElementById('reserve-form');\n  if (!form) return;",
            $js,
            '予約フォームが無ければ、送信・確認CTA関連の初期化より前で抜けること'
        );
    });
});
