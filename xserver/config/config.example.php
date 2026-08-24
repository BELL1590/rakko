<?php
/**
 * 設定サンプル。これを config.local.php としてコピーし、実際の値を入れる。
 * config.local.php は Git 管理外（.gitignore 済み）。実値は絶対にコミットしないこと。
 *
 * 本番では config/ をドキュメントルート外（例: /home/<server-id>/rakko-app/config/）へ置く。
 */

declare(strict_types=1);

return [
    // 公開URL（末尾スラッシュなし）。LINEのcallback URL組み立てに使う。
    'APP_URL' => 'https://example.xsrv.jp',

    // 'production' | 'development'
    'APP_ENV' => 'development',

    // セッションCookie署名鍵。32文字以上のランダム文字列。
    // 生成例: php -r 'echo bin2hex(random_bytes(32));'
    'SESSION_SECRET' => '',

    // --- XSERVER MySQL ---
    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_NAME' => '',
    'DB_USER' => '',
    'DB_PASSWORD' => '',

    // =====================================================================
    // LINE設定の前提（必ず先に済ませること）
    //
    // 予約専用LINE公式アカウント
    //   + そのMessaging APIチャネル
    //   + LINE Loginチャネル
    // の3つを「リンク」しておく必要がある。
    //
    // 公開予約は友だち追加を必須にしており、その判定に
    //   GET https://api.line.me/friendship/v1/status
    // を使う。このAPIが返す friendFlag は
    // 「LINE Loginチャネルにリンクされた公式アカウント」との友だち状態であり、
    // 任意のアカウントを指定することはできない。
    //
    // リンクしていない / 別のアカウントをリンクしていると、
    // 予約専用アカウントを友だち追加しても friendFlag が true にならず、
    // 誰も予約できない状態になる。
    // =====================================================================

    // --- LINE Login Channel ---（必須）
    'LINE_LOGIN_CHANNEL_ID' => '',
    'LINE_LOGIN_CHANNEL_SECRET' => '',
    // 友だち追加を促す任意パラメータ（bot_prompt）。
    // 空 = 送らない（既定）。'normal' / 'aggressive' のみ指定できる。
    // 上記のリンク設定が未完了のまま送ると authorize が400になりログインできない。
    // ※ 任意なのはこのパラメータを送るかどうかだけで、リンク設定自体は必須。
    'LINE_LOGIN_BOT_PROMPT' => '',

    // LIFFアプリのID（LINE Loginチャネルに追加したLIFFアプリ）。（設定推奨）
    // 例: '2001234567-AbCdEfGh'
    // 設定すると /liff で LIFF 経由のログイン導線が使えるようになり、
    // 新しい端末・ブラウザでもLINEアプリのログイン状態からWebセッションを作れる。
    // 未設定でも既存のLINE Login（OAuth/OIDC）だけで動作する。
    // Endpoint URL は https://<本番ドメイン>/liff、scope は openid + profile。
    'LINE_LIFF_ID' => '',

    // --- LINE Messaging API Channel ---（必須）
    // 予約専用LINE公式アカウントのMessaging APIチャネルの長期アクセストークン。
    // 予約完了通知・リマインドをこのアカウントから送る。
    'LINE_MESSAGING_CHANNEL_ACCESS_TOKEN' => '',
    // 予約専用LINE公式アカウントの友だち追加URL。（設定推奨）
    // 公開予約は友だち追加が必須のため、未追加の利用者にこのリンクを案内する。
    // LINE Official Account Manager の「友だち追加ガイド」で確認できる
    // https://lin.ee/xxxxxxx 形式のURL、または https://line.me/R/ti/p/@xxxxxxx。
    // 未設定でも予約フロー自体は動くが、利用者が自力で検索する必要がある。
    // ※ LINE Loginチャネルにリンクしたアカウントと同一のものを指定すること。
    'LINE_FRIEND_URL' => '',

    // --- 管理画面 ---
    'ADMIN_USERNAME' => '',
    // password_hash() の出力を入れる。平文は保存しない。
    // 生成例: php -r 'echo password_hash("パスワード", PASSWORD_DEFAULT), PHP_EOL;'
    'ADMIN_PASSWORD_HASH' => '',

    // LINE認証情報なしで全画面を確認するための疑似ログイン。
    // production では必ず false（true のままだと起動時にエラーになる）。
    'DEMO_MODE' => true,
];
