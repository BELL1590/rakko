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
  page_not_found: { type: 'error', message: '予約ページが見つかりません。' },
  page_closed: { type: 'error', message: 'この予約ページは現在受け付けていません。' },
  slot_not_found: { type: 'error', message: '予約枠が見つかりません。' },
  no_selection: { type: 'error', message: '予約する枠を1つ以上選択してください。' },
  too_many_slots: { type: 'error', message: '一度に選択できる枠数を超えています。' },

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
  page_created: { type: 'success', message: '予約ページを作成しました。予約枠を追加してください。' },
  page_duplicated: { type: 'success', message: '予約ページを複製しました（下書き）。' },
  slot_created: { type: 'success', message: '予約枠を追加しました。' },
  slug_taken: { type: 'error', message: 'そのslugは既に使われています。別の値を指定してください。' },
  slug_invalid: {
    type: 'error',
    message: 'slugは半角英小文字・数字・ハイフンで入力してください。',
  },
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
    case 'PAGE_NOT_FOUND':
      return 'page_not_found';
    case 'PAGE_CLOSED':
      return 'page_closed';
    case 'SLOT_NOT_FOUND':
      return 'slot_not_found';
    case 'NO_SELECTION':
      return 'no_selection';
    case 'TOO_MANY_SLOTS':
      return 'too_many_slots';
    default:
      return 'save_failed';
  }
}
