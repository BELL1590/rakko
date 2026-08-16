/**
 * Worker のバインディング定義と、環境まわりの共通ヘルパー。
 */

export type Bindings = {
  DB: D1Database;

  // vars
  BASE_URL: string;
  DEMO_MODE: string;
  ENVIRONMENT: string;

  // secrets（wrangler secret put / .dev.vars）
  LINE_LOGIN_CHANNEL_ID?: string;
  LINE_LOGIN_CHANNEL_SECRET?: string;
  LINE_MESSAGING_CHANNEL_ACCESS_TOKEN?: string;
  SESSION_SECRET?: string;
  ADMIN_USERNAME?: string;
  ADMIN_PASSWORD?: string;
};

export type AppVariables = {
  csrfToken: string;
};

export type AppEnv = { Bindings: Bindings; Variables: AppVariables };

export function isProduction(env: Bindings): boolean {
  return (env.ENVIRONMENT ?? '').toLowerCase() === 'production';
}

export function isTruthy(value: string | undefined): boolean {
  if (!value) return false;
  const v = value.trim().toLowerCase();
  return v === 'true' || v === '1' || v === 'yes' || v === 'on';
}

/**
 * DEMO_MODE が有効かどうか。
 * production では常に無効（有効設定は {@link assertDemoModeSafety} でエラーにする）。
 */
export function isDemoMode(env: Bindings): boolean {
  return isTruthy(env.DEMO_MODE) && !isProduction(env);
}

/**
 * production で DEMO_MODE が有効になっている場合は起動を許さない。
 * 全リクエストの入口で呼ぶ。
 */
export function assertDemoModeSafety(env: Bindings): void {
  if (isProduction(env) && isTruthy(env.DEMO_MODE)) {
    throw new ConfigError(
      'DEMO_MODE must be disabled in production. Set DEMO_MODE=false and redeploy.',
    );
  }
}

export class ConfigError extends Error {
  override name = 'ConfigError';
}

/** LINE Login が実際に使える設定になっているか。 */
export function hasLineLoginConfig(env: Bindings): boolean {
  return Boolean(env.LINE_LOGIN_CHANNEL_ID && env.LINE_LOGIN_CHANNEL_SECRET);
}

/** Messaging API が使える設定になっているか。 */
export function hasLineMessagingConfig(env: Bindings): boolean {
  return Boolean(env.LINE_MESSAGING_CHANNEL_ACCESS_TOKEN);
}

/**
 * セッション署名鍵。未設定の非productionでは開発用の固定値へフォールバックする。
 * production では未設定を許さない。
 */
export function sessionSecret(env: Bindings): string {
  if (env.SESSION_SECRET && env.SESSION_SECRET.length > 0) return env.SESSION_SECRET;
  if (isProduction(env)) {
    throw new ConfigError('SESSION_SECRET is required in production.');
  }
  return 'dev-only-insecure-session-secret';
}

/** BASE_URL（末尾スラッシュなし）。 */
export function baseUrl(env: Bindings, req?: Request): string {
  const configured = (env.BASE_URL ?? '').trim().replace(/\/+$/, '');
  if (configured) return configured;
  if (req) {
    const url = new URL(req.url);
    return `${url.protocol}//${url.host}`;
  }
  return 'http://localhost:8787';
}
