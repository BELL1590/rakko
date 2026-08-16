/**
 * リダイレクト時のフラッシュメッセージ。
 * URLの文字列をそのまま表示すると任意文言を差し込まれるため、コードで受け渡す。
 */

export type AlertType = 'error' | 'success' | 'info';
export interface Alert {
  type: AlertType;
  message: string;
}

const MESSAGES: Record<string, Alert> = {
  login_required: { type: 'info', message: 'ご予約にはLINEログインが必要です。' },
  login_failed: { type: 'error', message: 'ログインに失敗しました。もう一度お試しください。' },
  logged_out: { type: 'info', message: 'ログアウトしました。' },
  session_expired: { type: 'error', message: 'セッションの有効期限が切れました。もう一度お試しください。' },
  csrf: { type: 'error', message: '不正なリクエストです。もう一度お試しください。' },
  cancelled: { type: 'success', message: '予約をキャンセルしました。' },
  cancel_failed: { type: 'error', message: 'キャンセルできませんでした。' },
  not_found: { type: 'error', message: '予約が見つかりません。' },
  trip_not_found: { type: 'error', message: '便が見つかりません。' },
  trip_full: { type: 'error', message: '満席のため予約できませんでした。' },
  trip_closed: { type: 'error', message: 'この便は現在予約を受け付けていません。' },
  duplicate: { type: 'error', message: 'この便は既に予約済みです。マイ予約からご確認ください。' },
  demo_disabled: { type: 'error', message: 'デモログインは利用できません。' },

  admin_login_failed: { type: 'error', message: 'ユーザー名またはパスワードが違います。' },
  admin_login_required: { type: 'error', message: '管理画面へのアクセスにはログインが必要です。' },
  admin_not_configured: {
    type: 'error',
    message: '管理者認証が未設定です（ADMIN_USERNAME / ADMIN_PASSWORD）。',
  },
  admin_logged_out: { type: 'info', message: 'ログアウトしました。' },
  saved: { type: 'success', message: '更新しました。' },
  save_failed: { type: 'error', message: '更新できませんでした。入力内容をご確認ください。' },
  capacity_too_small: {
    type: 'error',
    message: '既存の予約人数を下回る定員には変更できません。',
  },
  booking_created: { type: 'success', message: '代理予約を登録しました。' },
  reminder_done: { type: 'success', message: 'リマインド処理を実行しました。' },
};

export function alertFromCode(code: string | undefined | null): Alert | null {
  if (!code) return null;
  return MESSAGES[code] ?? null;
}

/** 予約エラーコード → フラッシュメッセージコード */
export function bookingErrorToCode(code: string): string {
  switch (code) {
    case 'FULL':
      return 'trip_full';
    case 'DUPLICATE':
      return 'duplicate';
    case 'CLOSED':
    case 'DEPARTED':
      return 'trip_closed';
    case 'TRIP_NOT_FOUND':
      return 'trip_not_found';
    default:
      return 'save_failed';
  }
}
