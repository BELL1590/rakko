<?php

declare(strict_types=1);

describe('XSERVER app-root deployment', function (): void {
    test('ドメイン配下のサブドメインdocument rootから2階層上のapp-rootを自動検出する', function (): void {
        $source = file_get_contents(dirname(__DIR__) . '/public/index.php');
        assertTrue(is_string($source));

        assertContains("getenv('RAKKO_APP_ROOT')", $source, '環境変数overrideを維持する');
        assertContains("dirname(__DIR__) . '/app-root'", $source, '独立document rootの兄弟app-rootを維持する');
        assertContains(
            "dirname(dirname(__DIR__)) . '/app-root'",
            $source,
            '<domain>/public_html/<subdomain>/ から <domain>/app-root を検出する'
        );
        assertNotContains("\$root = '/home/", $source, '本番固有パスを埋め込まない');
    });
});
