<?php

declare(strict_types=1);

/**
 * 予約フォームのCTA導線の構造回帰テスト。
 *
 * 実機で「予約内容を確認する / 予約を確定する が一切出ない」障害が出た。
 * 原因は reserve.js が sticky CTA を form.querySelector() で探していた一方、
 * sticky CTA は </form> の外に描画されていたこと。
 * 取得できないまま確認パネルだけ hidden にするため、送信手段がゼロになっていた。
 *
 * ここでは「JSがどのルート要素から探すか」と
 * 「実際のHTMLでその要素がどこにあるか」の整合を機械的に検査する。
 * 同じ組み合わせのミスが再発したら必ず落ちる。
 */

/** 予約フォームが出ている状態の公開ページHTMLを得る。 */
function reservePageHtml(): string
{
    resetRequestState();
    $app = makeApp();
    $pageId = Fixtures::page($app, [
        'slug' => 'cta-structure',
        'requires_line_login' => false,
    ]);
    Fixtures::slot($app, $pageId, ['name' => '行き', 'capacity' => 10, 'max_party_size' => 4]);

    $response = request($app, 'GET', '/reserve/cta-structure');
    assertSame(200, $response->status);
    return $response->body;
}

/** HTMLをDOMへ読み込む。 */
function reserveDom(string $html): DOMXPath
{
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($doc);
}

/** `[data-foo]` 属性セレクタをXPathへ。 */
function attrXpath(string $attribute): string
{
    return "//*[@" . $attribute . "]";
}

/** reserve.js のソース。 */
function reserveJs(): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/public/assets/reserve.js');
}

/**
 * reserve.js から `<root>.querySelector('[data-x]')` の使用箇所を拾う。
 *
 * @return array<string, list<string>> ルート変数名 => data属性名のリスト
 */
function reserveJsLookups(): array
{
    $found = ['form' => [], 'document' => []];
    $pattern = '/\b(form|document)\.querySelector(?:All)?\(\s*[\'"]\[(data-[a-z-]+)\]/';
    if (preg_match_all($pattern, reserveJs(), $matches, PREG_SET_ORDER) !== false) {
        foreach ($matches as $match) {
            $found[$match[1]][] = $match[2];
        }
    }
    $found['form'] = array_values(array_unique($found['form']));
    $found['document'] = array_values(array_unique($found['document']));
    return $found;
}

describe('予約CTAのDOM構造', function (): void {
    test('フォーム・確認パネル・送信ボタンの位置関係', function (): void {
        $xpath = reserveDom(reservePageHtml());

        $form = $xpath->query("//form[@id='reserve-form']");
        assertSame(1, $form->length, '予約フォームが1つあること');

        // 確認パネルと送信ボタンはフォーム内（POSTできる位置）にある
        assertSame(
            1,
            $xpath->query("//form[@id='reserve-form']//*[@data-confirm-panel]")->length,
            '確認パネルはフォーム内にあること'
        );
        assertSame(
            1,
            $xpath->query("//form[@id='reserve-form']//button[@id='submit-button']")->length,
            '送信ボタンはフォーム内にあること（外にあるとPOSTできない）'
        );

        // sticky CTA はページに存在する
        assertSame(1, $xpath->query(attrXpath('data-sticky-cta'))->length, 'sticky CTAがあること');
        assertSame(1, $xpath->query(attrXpath('data-open-confirm'))->length, '確認を開くボタンがあること');
    });

    test('JSの探索ルートとHTML上の位置が一致している（今回の障害の再発防止）', function (): void {
        $xpath = reserveDom(reservePageHtml());
        $lookups = reserveJsLookups();

        assertTrue(count($lookups['form']) > 0, 'form起点の探索が検出できること');

        // form.querySelector で探しているものは、必ずフォーム内に存在すること。
        // 外にある要素を form 起点で探すと必ず null になる。
        foreach ($lookups['form'] as $attribute) {
            $inDocument = $xpath->query(attrXpath($attribute))->length;
            $inForm = $xpath->query("//form[@id='reserve-form']" . attrXpath($attribute))->length;

            assertTrue(
                $inDocument > 0,
                '[' . $attribute . '] がページに存在すること'
            );
            assertTrue(
                $inForm > 0,
                'reserve.js は form.querySelector で [' . $attribute . '] を探しているが、'
                    . 'この要素はフォームの外にある。document 起点で探すか、フォーム内へ移すこと'
            );
        }

        // document.querySelector で探しているものは、少なくともページに存在すること
        foreach ($lookups['document'] as $attribute) {
            assertTrue(
                $xpath->query(attrXpath($attribute))->length > 0,
                'reserve.js が探している [' . $attribute . '] がページに存在しないこと'
            );
        }
    });

    test('sticky CTA と開くボタンは document 起点で探している', function (): void {
        $xpath = reserveDom(reservePageHtml());
        $lookups = reserveJsLookups();

        // 現在のHTMLでは sticky CTA は </form> の外にある
        assertSame(
            0,
            $xpath->query("//form[@id='reserve-form']" . attrXpath('data-sticky-cta'))->length,
            'sticky CTAはフォーム外にある（position:fixedの下部固定バー）'
        );

        foreach (['data-sticky-cta', 'data-open-confirm'] as $attribute) {
            assertFalse(
                in_array($attribute, $lookups['form'], true),
                '[' . $attribute . '] を form 起点で探すと必ず null になる'
            );
            assertTrue(
                in_array($attribute, $lookups['document'], true),
                '[' . $attribute . '] は document 起点で探すこと'
            );
        }
    });

    test('確認パネルを隠すのは代替導線が揃っているときだけ', function (): void {
        $js = reserveJs();

        // sticky CTA と開くボタンが取れないときに確認パネルを隠すと、
        // 送信ボタンへ到達する手段が無くなる（今回の障害そのもの）
        assertNotContains(
            'if (panel) panel.hidden = true;',
            $js,
            '無条件に確認パネルを隠さないこと'
        );
        assertContains('hasStickyPath', $js, '代替導線の有無で分岐すること');
    });

    test('JS無効時は確認パネルがそのまま表示され送信できる', function (): void {
        $html = reservePageHtml();
        $xpath = reserveDom($html);

        // サーバー出力では確認パネルに hidden が付いていない
        $panel = $xpath->query("//*[@data-confirm-panel]")->item(0);
        assertNotNull($panel);
        assertFalse(
            $panel instanceof DOMElement && $panel->hasAttribute('hidden'),
            'JS無効でも確認パネルが見えること（progressive enhancement）'
        );

        // sticky CTA はJS専用なのでサーバー側では hidden
        $sticky = $xpath->query(attrXpath('data-sticky-cta'))->item(0);
        assertTrue(
            $sticky instanceof DOMElement && $sticky->hasAttribute('hidden'),
            'JS無効なら押せないCTAは出さないこと'
        );

        // 送信ボタンは hidden でない
        $submit = $xpath->query("//button[@id='submit-button']")->item(0);
        assertNotNull($submit);
        assertFalse(
            $submit instanceof DOMElement && $submit->hasAttribute('hidden'),
            'JS無効でも送信ボタンが押せること'
        );
    });

    test('CTAの初期文言と確認パネルのボタン文言', function (): void {
        $html = reservePageHtml();

        assertContains('予約する枠を選んでください', $html, 'CTAの初期文言');
        assertContains('選択した予約をまとめて確定する', $html, '確定ボタン');
        assertContains('内容を変更する', $html, '確認パネルを閉じるボタン');
        assertContains('id="open-confirm"', $html);
        assertContains('id="submit-button"', $html);
        assertContains('id="confirm-dismiss"', $html);
    });
});
