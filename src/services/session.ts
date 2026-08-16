/**
 * 署名付きCookieによるセッション管理。
 * 外部ストアを増やさず、HMAC-SHA256署名付きのCookieだけで完結させる。
 */

import type { Context } from 'hono';
import { getCookie, setCookie, deleteCookie } from 'hono/cookie';
import type { AppEnv, Bindings } from '../env';
import { isProduction, sessionSecret } from '../env';

export const USER_SESSION_COOKIE = 'rk_session';
export const ADMIN_SESSION_COOKIE = 'rk_admin';
export const OAUTH_STATE_COOKIE = 'rk_oauth';
export const CSRF_COOKIE = 'rk_csrf';

const USER_SESSION_MAX_AGE = 60 * 60 * 24 * 30; // 30日
const ADMIN_SESSION_MAX_AGE = 60 * 60 * 8; // 8時間
const OAUTH_STATE_MAX_AGE = 60 * 10; // 10分

export interface UserSession {
  /** internal user id */
  uid: number;
  /** 発行時刻（UNIX秒） */
  iat: number;
}

export interface AdminSession {
  admin: string;
  iat: number;
}

export interface OAuthStateSession {
  state: string;
  nonce: string;
  codeVerifier: string;
  /** ログイン後の戻り先（同一オリジン内のパスのみ） */
  redirectTo: string;
  iat: number;
}

// ---------------------------------------------------------------------------
// base64url / HMAC
// ---------------------------------------------------------------------------

function base64UrlEncode(bytes: Uint8Array): string {
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function base64UrlDecode(value: string): Uint8Array {
  const padded = value.replace(/-/g, '+').replace(/_/g, '/');
  const binary = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4));
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return bytes;
}

export function base64UrlEncodeString(value: string): string {
  return base64UrlEncode(new TextEncoder().encode(value));
}

async function hmacKey(secret: string): Promise<CryptoKey> {
  return await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign', 'verify'],
  );
}

export async function hmacSha256(secret: string, message: string): Promise<string> {
  const key = await hmacKey(secret);
  const signature = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(message));
  return base64UrlEncode(new Uint8Array(signature));
}

/** タイミング安全な文字列比較。 */
export function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

/** 値をJSON化し `payload.signature` 形式へ署名する。 */
export async function signPayload(secret: string, payload: unknown): Promise<string> {
  const encoded = base64UrlEncodeString(JSON.stringify(payload));
  const signature = await hmacSha256(secret, encoded);
  return `${encoded}.${signature}`;
}

/** 署名付き文字列を検証して復号する。改ざん時は null。 */
export async function verifyPayload<T>(secret: string, token: string | undefined): Promise<T | null> {
  if (!token) return null;
  const separator = token.lastIndexOf('.');
  if (separator <= 0) return null;
  const encoded = token.slice(0, separator);
  const signature = token.slice(separator + 1);
  const expected = await hmacSha256(secret, encoded);
  if (!timingSafeEqual(signature, expected)) return null;
  try {
    return JSON.parse(new TextDecoder().decode(base64UrlDecode(encoded))) as T;
  } catch {
    return null;
  }
}

export function randomToken(byteLength = 32): string {
  const bytes = new Uint8Array(byteLength);
  crypto.getRandomValues(bytes);
  return base64UrlEncode(bytes);
}

// ---------------------------------------------------------------------------
// PKCE
// ---------------------------------------------------------------------------

export function createCodeVerifier(): string {
  return randomToken(32);
}

export async function createCodeChallenge(verifier: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
  return base64UrlEncode(new Uint8Array(digest));
}

// ---------------------------------------------------------------------------
// Cookie
// ---------------------------------------------------------------------------

function cookieOptions(env: Bindings, maxAge: number, sameSite: 'Lax' | 'Strict' = 'Lax') {
  return {
    path: '/',
    httpOnly: true,
    secure: isProduction(env),
    sameSite,
    maxAge,
  } as const;
}

export async function setUserSession(c: Context<AppEnv>, userId: number): Promise<void> {
  const token = await signPayload(sessionSecret(c.env), {
    uid: userId,
    iat: Math.floor(Date.now() / 1000),
  } satisfies UserSession);
  setCookie(c, USER_SESSION_COOKIE, token, cookieOptions(c.env, USER_SESSION_MAX_AGE));
}

export async function getUserSession(c: Context<AppEnv>): Promise<UserSession | null> {
  const session = await verifyPayload<UserSession>(
    sessionSecret(c.env),
    getCookie(c, USER_SESSION_COOKIE),
  );
  if (!session || typeof session.uid !== 'number') return null;
  return session;
}

export function clearUserSession(c: Context<AppEnv>): void {
  deleteCookie(c, USER_SESSION_COOKIE, { path: '/' });
}

export async function setAdminSession(c: Context<AppEnv>, username: string): Promise<void> {
  const token = await signPayload(sessionSecret(c.env), {
    admin: username,
    iat: Math.floor(Date.now() / 1000),
  } satisfies AdminSession);
  setCookie(c, ADMIN_SESSION_COOKIE, token, {
    ...cookieOptions(c.env, ADMIN_SESSION_MAX_AGE, 'Strict'),
    path: '/admin',
  });
}

export async function getAdminSession(c: Context<AppEnv>): Promise<AdminSession | null> {
  const session = await verifyPayload<AdminSession>(
    sessionSecret(c.env),
    getCookie(c, ADMIN_SESSION_COOKIE),
  );
  if (!session || typeof session.admin !== 'string') return null;
  if (typeof session.iat !== 'number') return null;
  if (Math.floor(Date.now() / 1000) - session.iat > ADMIN_SESSION_MAX_AGE) return null;
  // 認証情報が変わった場合に既存セッションを無効化する
  if (c.env.ADMIN_USERNAME && session.admin !== c.env.ADMIN_USERNAME) return null;
  return session;
}

export function clearAdminSession(c: Context<AppEnv>): void {
  deleteCookie(c, ADMIN_SESSION_COOKIE, { path: '/admin' });
}

export async function setOAuthState(
  c: Context<AppEnv>,
  value: Omit<OAuthStateSession, 'iat'>,
): Promise<void> {
  const token = await signPayload(sessionSecret(c.env), {
    ...value,
    iat: Math.floor(Date.now() / 1000),
  } satisfies OAuthStateSession);
  setCookie(c, OAUTH_STATE_COOKIE, token, cookieOptions(c.env, OAUTH_STATE_MAX_AGE));
}

export async function takeOAuthState(c: Context<AppEnv>): Promise<OAuthStateSession | null> {
  const session = await verifyPayload<OAuthStateSession>(
    sessionSecret(c.env),
    getCookie(c, OAUTH_STATE_COOKIE),
  );
  deleteCookie(c, OAUTH_STATE_COOKIE, { path: '/' });
  if (!session || typeof session.state !== 'string') return null;
  if (Math.floor(Date.now() / 1000) - session.iat > OAUTH_STATE_MAX_AGE) return null;
  return session;
}

// ---------------------------------------------------------------------------
// CSRF（double submit cookie）
// ---------------------------------------------------------------------------

export async function ensureCsrfToken(c: Context<AppEnv>): Promise<string> {
  const existing = await verifyPayload<{ t: string }>(
    sessionSecret(c.env),
    getCookie(c, CSRF_COOKIE),
  );
  if (existing?.t) return existing.t;

  const token = randomToken(24);
  const signed = await signPayload(sessionSecret(c.env), { t: token });
  setCookie(c, CSRF_COOKIE, signed, cookieOptions(c.env, USER_SESSION_MAX_AGE));
  return token;
}

export async function verifyCsrfToken(
  c: Context<AppEnv>,
  submitted: string | undefined,
): Promise<boolean> {
  if (!submitted) return false;
  const stored = await verifyPayload<{ t: string }>(
    sessionSecret(c.env),
    getCookie(c, CSRF_COOKIE),
  );
  if (!stored?.t) return false;
  return timingSafeEqual(stored.t, submitted);
}

/** ログイン後の戻り先として安全なパスだけを許可する（オープンリダイレクト防止）。 */
export function safeRedirectPath(value: string | undefined | null, fallback = '/'): string {
  if (!value) return fallback;
  if (!value.startsWith('/') || value.startsWith('//')) return fallback;
  return value;
}
