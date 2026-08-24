<?php

declare(strict_types=1);

/**
 * 未ログイン状態の /reserve/{slug} で、人数選択に応じた同行者欄の
 * 表示切り替えが動くことを実ブラウザ（Chromium）で確認する任意チェック。
 *
 *   php8.0 tests/browser/check-reserve-unauth.php
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
function findChromiumForUnauthCheck(): ?string
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

$chromium = findChromiumForUnauthCheck();
if ($chromium === null) {
    echo "SKIP: Chromium が見つかりません（実ブラウザ確認をスキップします）\n";
    exit(0);
}

$root = dirname(__DIR__, 2);

// --- 未ログイン状態の公開予約ページをレンダリングする
//     （requires_line_login はデフォルトのtrueのまま = 未ログインなら reserve-form は出ない）
$app = makeApp();
$pageId = Fixtures::page($app, ['slug' => 'browser-unauth-check']);
Fixtures::slot($app, $pageId, ['name' => '行き', 'capacity' => 10, 'max_party_size' => 4]);

$response = request($app, 'GET', '/reserve/browser-unauth-check');
if ($response->status !== 200) {
    fwrite(STDERR, "予約ページの取得に失敗しました: HTTP {$response->status}\n");
    exit(1);
}
if (strpos($response->body, 'id="reserve-form"') !== false) {
    fwrite(STDERR, "このチェックは未ログイン状態（reserve-formなし）を前提にしています\n");
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
window.jsErrors = [];
window.onerror = function (message) { window.jsErrors.push(String(message)); };

window.addEventListener('DOMContentLoaded', function () {
  var out = [];
  function rec(k, v) { out.push(k + '=' + v); }

  function visibleCompanionCount(block) {
    return block.querySelectorAll('.companion-field:not([hidden])').length;
  }

  rec('reserve_form_exists', !!document.getElementById('reserve-form'));

  var block = document.querySelector('[data-slot-block]');
  rec('slot_block_found', !!block);

  var fields = block.querySelector('[data-slot-fields]');
  var toggle = block.querySelector('[data-slot-toggle]');

  // 1. 初期表示: 枠が未選択なので人数/同行者欄は隠れている
  rec('init_fields_hidden', fields.hidden);
  rec('init_companion_visible', visibleCompanionCount(block));

  // 2. 枠を選択（人数はデフォルトの1名）→ 同行者欄は0のまま
  toggle.checked = true;
  toggle.dispatchEvent(new Event('change', { bubbles: true }));
  rec('picked_fields_hidden', fields.hidden);
  rec('party1_companion_visible', visibleCompanionCount(block));

  // 3. 人数を2名にする → 同行者欄1
  var radio2 = block.querySelector('input[type="radio"][value="2"]');
  radio2.checked = true;
  radio2.dispatchEvent(new Event('change', { bubbles: true }));
  rec('party2_companion_visible', visibleCompanionCount(block));

  // 4. 人数を4名にする → 同行者欄3
  var radio4 = block.querySelector('input[type="radio"][value="4"]');
  radio4.checked = true;
  radio4.dispatchEvent(new Event('change', { bubbles: true }));
  rec('party4_companion_visible', visibleCompanionCount(block));

  // 5. 枠の選択を外す → 人数・同行者欄は再び隠れる
  toggle.checked = false;
  toggle.dispatchEvent(new Event('change', { bubbles: true }));
  rec('unpicked_fields_hidden', fields.hidden);
  rec('unpicked_companion_visible', visibleCompanionCount(block));

  rec('js_errors', window.jsErrors.length);

  var pre = document.createElement('pre');
  pre.id = 'driver-result';
  pre.textContent = out.join('\n');
  document.body.appendChild(pre);
});
JS;

$page = str_replace('</body>', '<script>' . $driver . '</script></body>', $html);
$file = sys_get_temp_dir() . '/rakko-reserve-unauth-' . getmypid() . '.html';
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
    'reserve_form_exists' => 'false',
    'slot_block_found' => 'true',
    'init_fields_hidden' => 'true',
    'init_companion_visible' => '0',
    'picked_fields_hidden' => 'false',
    'party1_companion_visible' => '0',
    'party2_companion_visible' => '1',
    'party4_companion_visible' => '3',
    'unpicked_fields_hidden' => 'true',
    'unpicked_companion_visible' => '0',
    'js_errors' => '0',
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
