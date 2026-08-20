<?php

declare(strict_types=1);

namespace App\Support;

/**
 * リダイレクト時のフラッシュメッセージ。
 * URLの文字列をそのまま表示すると任意文言を差し込まれるため、コードで受け渡す。
 * Workers版 src/lib/messages.ts の移植。
 */
final class Messages
{
    /** @var array<string, array{type: string, message: string}> */
    private const MESSAGES = [
        'login_required' => ['type' => 'info', 'message' => 'ご予約にはLINEログインが必要です。'],
        'login_failed' => ['type' => 'error', 'message' => 'ログインに失敗しました。もう一度お試しください。'],
        'logged_out' => ['type' => 'info', 'message' => 'ログアウトしました。'],
        'session_expired' => ['type' => 'error', 'message' => 'セッションの有効期限が切れました。もう一度お試しください。'],
        'csrf' => ['type' => 'error', 'message' => '不正なリクエストです。もう一度お試しください。'],
        'cancelled' => ['type' => 'success', 'message' => '予約をキャンセルしました。'],
        'cancel_failed' => ['type' => 'error', 'message' => 'キャンセルできませんでした。'],
        'not_found' => ['type' => 'error', 'message' => '予約が見つかりません。'],
        'trip_full' => ['type' => 'error', 'message' => '満席のため予約できませんでした。'],
        'trip_closed' => ['type' => 'error', 'message' => 'この便は現在予約を受け付けていません。'],
        'duplicate' => ['type' => 'error', 'message' => 'この便は既に予約済みです。マイ予約からご確認ください。'],
        'demo_disabled' => ['type' => 'error', 'message' => 'デモログインは利用できません。'],
        'page_not_found' => ['type' => 'error', 'message' => '予約ページが見つかりません。'],
        'page_closed' => ['type' => 'error', 'message' => 'この予約ページは現在受け付けていません。'],
        'slot_not_found' => ['type' => 'error', 'message' => '予約枠が見つかりません。'],
        'no_selection' => ['type' => 'error', 'message' => '予約する枠を1つ以上選択してください。'],
        'too_many_slots' => ['type' => 'error', 'message' => '一度に選択できる枠数を超えています。'],

        'admin_login_failed' => ['type' => 'error', 'message' => 'ユーザー名またはパスワードが違います。'],
        'admin_login_required' => ['type' => 'error', 'message' => '管理画面へのアクセスにはログインが必要です。'],
        'admin_not_configured' => ['type' => 'error', 'message' => '管理者認証が未設定です（ADMIN_USERNAME / ADMIN_PASSWORD_HASH）。'],
        'admin_logged_out' => ['type' => 'info', 'message' => 'ログアウトしました。'],
        'saved' => ['type' => 'success', 'message' => '更新しました。'],
        'save_failed' => ['type' => 'error', 'message' => '更新できませんでした。入力内容をご確認ください。'],
        'capacity_too_small' => ['type' => 'error', 'message' => '既存の予約人数を下回る定員には変更できません。'],
        'booking_created' => ['type' => 'success', 'message' => '代理予約を登録しました。'],
        'reminder_done' => ['type' => 'success', 'message' => 'リマインド処理を実行しました。'],
        'page_created' => ['type' => 'success', 'message' => '予約ページを作成しました。予約枠を追加してください。'],
        'page_duplicated' => ['type' => 'success', 'message' => '予約ページを複製しました（下書き）。'],
        'slot_created' => ['type' => 'success', 'message' => '予約枠を追加しました。'],
        'slug_taken' => ['type' => 'error', 'message' => 'そのslugは既に使われています。別の値を指定してください。'],
        'slug_invalid' => ['type' => 'error', 'message' => 'slugは半角英小文字・数字・ハイフンで入力してください。'],
    ];

    /** @return array{type: string, message: string}|null */
    public static function fromCode(?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }
        return self::MESSAGES[$code] ?? null;
    }

    /** 予約エラーコード → フラッシュメッセージコード */
    public static function bookingErrorToCode(string $code): string
    {
        return match ($code) {
            'FULL' => 'trip_full',
            'DUPLICATE' => 'duplicate',
            'CLOSED', 'DEPARTED' => 'trip_closed',
            'PAGE_NOT_FOUND' => 'page_not_found',
            'PAGE_CLOSED' => 'page_closed',
            'SLOT_NOT_FOUND' => 'slot_not_found',
            'NO_SELECTION' => 'no_selection',
            'TOO_MANY_SLOTS' => 'too_many_slots',
            default => 'save_failed',
        };
    }
}
