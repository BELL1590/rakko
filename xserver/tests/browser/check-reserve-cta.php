<?php

declare(strict_types=1);

/**
 * 予約CTA導線を実ブラウザ（Chromium）で確認する任意チェック。
 *
 *   php8.0 tests/browser/check-reserve-cta.php
 *
 * PHPのテストランナーからは呼ばれない（Chromiumが必要なため）。
 * reserve.js は defer で読み込まれるので、操作は DOMContentLoaded 後に行う。
 *
 * Chromium が見つからない場合は skip して終了コード0で返す。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI only.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';

/** Chromium実行ファイルを探す。 */
function findChromium(): ?string
{
    $candidates = glob('/opt/pw-browsers/chromium-*/chrome-linux/chrome') ?: [];
    foreach (['chromium', 'chromium-browser', 'google-chrome'] as $name) {
        $which = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        if ($which !== '') {
            $candidates[] = $which;
        }
    }
    foreach ($candidates as $path) {
        if (is_string($path) && is_executable($path)) {
            return $path;
        }
    }
    return null;
}

$chromium = findChromium();
if ($chromium === null) {
    echo "SKIP: Chromium が見つかりません（実ブラウザ確認をスキップします）\n";
    exit(0);
}

$root = dirname(__DIR__, 2);

// --- 実際の公開予約ページをレンダリングする
$app = makeApp();
$pageId = Fixtures::page($app, ['slug' => 'browser-cta-check', 'requires_line_login' => false]);
Fixtures::slot($app, $pageId, ['name' => '行き', 'capacity' => 10, 'max_party_size' => 4]);

$response = request($app, 'GET', '/reserve/browser-cta-check');
if ($response->status !== 200) {
    fwrite(STDERR, "予約ページの取得に失敗しました: HTTP {$response->status}\n");
    exit(1);
}

// file:// で開くため、アセット参照を実パスへ差し替える
$html = str_replace(
    ['/assets/app.css', '/assets/reserve.js'],
    ['file://' . $root . '/public/assets/app.css', 'file://' . $root . '/public/assets/reserve.js'],
    $response->body
);

// --- 利用者の操作を再現し、結果をDOMへ書き出すドライバ
$driver = <<<'JS'
window.addEventListener('DOMContentLoaded', function () {
  var out = [];
  function rec(k, v) { out.push(k + '=' + v); }

  var form = document.getElementById('reserve-form');
  var sticky = document.getElementById('sticky-cta');
  var panel = document.getElementById('reserve-confirm');
  var openBtn = document.getElementById('open-confirm');
  var submitBtn = document.getElementById('submit-button');
  var dismiss = document.getElementById('confirm-dismiss');

  rec('submit_in_form', !!(form && submitBtn && form.contains(submitBtn)));

  // 1. 初期表示: sticky CTA が見えていること
  rec('init_sticky_hidden', sticky.hidden);
  rec('init_openbtn_text', openBtn.textContent.trim());
  rec('init_openbtn_disabled', openBtn.disabled);

  // 2. 枠を選ぶと未入力項目が出ること
  var toggle = form.querySelector('[data-slot-toggle]');
  toggle.checked = true;
  toggle.dispatchEvent(new Event('change', { bubbles: true }));
  rec('after_pick_hint', document.querySelector('[data-sticky-hint]').textContent.trim());

  // 3. 必要項目を埋めるとCTAが有効になること
  var name = document.getElementById('representative_name');
  var phone = document.getElementById('phone');
  var agreed = document.getElementById('agreed');
  name.value = '山田太郎'; name.dispatchEvent(new Event('input', { bubbles: true }));
  phone.value = '090-1234-5678'; phone.dispatchEvent(new Event('input', { bubbles: true }));
  agreed.checked = true; agreed.dispatchEvent(new Event('change', { bubbles: true }));
  rec('ready_openbtn_disabled', openBtn.disabled);
  rec('ready_openbtn_text', openBtn.textContent.trim());

  // 4. 押すと確認パネルが開き、送信ボタンが見えること
  openBtn.click();
  rec('opened_panel_hidden', panel.hidden);
  rec('opened_submit_visible', submitBtn.offsetParent !== null);
  rec('opened_confirm_name', document.querySelector('[data-confirm-name]').textContent.trim());

  // 5. 「内容を変更する」で戻れること
  dismiss.click();
  rec('dismissed_panel_hidden', panel.hidden);
  rec('dismissed_sticky_hidden', sticky.hidden);

  var pre = document.createElement('pre');
  pre.id = 'driver-result';
  pre.textContent = out.join('\n');
  document.body.appendChild(pre);
});
JS;

$page = str_replace('</body>', '<script>' . $driver . '</script></body>', $html);
$file = sys_get_temp_dir() . '/rakko-reserve-cta-' . getmypid() . '.html';
file_put_contents($file, $page);

$command = sprintf(
    '%s --headless --disable-gpu --no-sandbox --virtual-time-budget=6000 --dump-dom %s 2>/dev/null',
    escapeshellarg($chromium),
    escapeshellarg('file://' . $file)
);
$dom = (string) shell_exec($command);
@unlink($file);

if (preg_match('/<pre id="driver-result">(.*?)<\/pre>/s', $dom, $matches) !== 1) {
    fwrite(STDERR, "ブラウザからの結果を取得できませんでした\n");
    exit(1);
}

$actual = [];
foreach (explode("\n", html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) as $line) {
    [$key, $value] = array_pad(explode('=', trim($line), 2), 2, '');
    if ($key !== '') {
        $actual[$key] = $value;
    }
}

$expected = [
    'submit_in_form' => 'true',
    'init_sticky_hidden' => 'false',
    'init_openbtn_text' => '予約する枠を選んでください',
    'init_openbtn_disabled' => 'true',
    'after_pick_hint' => '未入力：代表者氏名 / 電話番号 / 注意事項の同意',
    'ready_openbtn_disabled' => 'false',
    'ready_openbtn_text' => '選択した1件の内容を確認する',
    'opened_panel_hidden' => 'false',
    'opened_submit_visible' => 'true',
    'opened_confirm_name' => '山田太郎',
    'dismissed_panel_hidden' => 'true',
    'dismissed_sticky_hidden' => 'false',
];

$failed = 0;
foreach ($expected as $key => $want) {
    $got = $actual[$key] ?? '(missing)';
    $ok = $got === $want;
    printf("%s %-28s expected=%-28s actual=%s\n", $ok ? 'OK  ' : 'NG  ', $key, $want, $got);
    if (!$ok) {
        $failed++;
    }
}

echo str_repeat('-', 60), "\n";
printf("%s  %d / %d\n", $failed === 0 ? 'PASS' : 'FAIL', count($expected) - $failed, count($expected));
exit($failed === 0 ? 0 : 1);
