/**
 * LINE Login (OAuth 2.0 / OIDC) クライアント。
 *
 * - state 検証（呼び出し側の routes/auth.ts で実施）
 * - PKCE (S256)
 * - scope: openid profile
 * - nonce を id_token で検証
 *
 * LINE Login Channel と Messaging API Channel は同一Provider配下である前提。
 */

import { hmacSha256, timingSafeEqual } from './session';

const AUTHORIZE_ENDPOINT = 'https://access.line.me/oauth2/v2.1/authorize';
const TOKEN_ENDPOINT = 'https://api.line.me/oauth2/v2.1/token';
const PROFILE_ENDPOINT = 'https://api.line.me/v2/profile';
const FRIENDSHIP_ENDPOINT = 'https://api.line.me/friendship/v1/status';

export interface AuthorizeUrlParams {
  channelId: string;
  redirectUri: string;
  state: string;
  nonce: string;
  codeChallenge: string;
  /** 公式アカウントの友だち追加導線を出すか */
  promptAddFriend: boolean;
}

export function buildAuthorizeUrl(params: AuthorizeUrlParams): string {
  const url = new URL(AUTHORIZE_ENDPOINT);
  url.searchParams.set('response_type', 'code');
  url.searchParams.set('client_id', params.channelId);
  url.searchParams.set('redirect_uri', params.redirectUri);
  url.searchParams.set('state', params.state);
  url.searchParams.set('scope', 'openid profile');
  url.searchParams.set('nonce', params.nonce);
  url.searchParams.set('code_challenge', params.codeChallenge);
  url.searchParams.set('code_challenge_method', 'S256');
  if (params.promptAddFriend) {
    // 公式アカウントのリンク設定がある場合、友だち追加オプションを表示する
    url.searchParams.set('bot_prompt', 'aggressive');
  }
  return url.toString();
}

export interface TokenResponse {
  access_token: string;
  id_token?: string;
  refresh_token?: string;
  expires_in: number;
  token_type: string;
  /** bot_prompt 利用時に返る友だち関係 */
  friendship_status_changed?: boolean;
}

export interface LineProfile {
  userId: string;
  displayName: string;
  pictureUrl?: string;
}

export interface IdTokenClaims {
  iss: string;
  sub: string;
  aud: string;
  exp: number;
  iat: number;
  nonce?: string;
  name?: string;
  picture?: string;
}

export class LineLoginError extends Error {
  override name = 'LineLoginError';
}

export async function exchangeCodeForToken(params: {
  code: string;
  redirectUri: string;
  channelId: string;
  channelSecret: string;
  codeVerifier: string;
  fetchImpl?: typeof fetch;
}): Promise<TokenResponse> {
  const doFetch = params.fetchImpl ?? fetch;
  const body = new URLSearchParams({
    grant_type: 'authorization_code',
    code: params.code,
    redirect_uri: params.redirectUri,
    client_id: params.channelId,
    client_secret: params.channelSecret,
    code_verifier: params.codeVerifier,
  });

  const response = await doFetch(TOKEN_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString(),
  });

  if (!response.ok) {
    // アクセストークン等は絶対にログへ出さない。ステータスのみ扱う。
    throw new LineLoginError(`token exchange failed with status ${response.status}`);
  }
  return (await response.json()) as TokenResponse;
}

function base64UrlToString(value: string): string {
  const padded = value.replace(/-/g, '+').replace(/_/g, '/');
  const binary = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4));
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return new TextDecoder().decode(bytes);
}

/**
 * id_token (JWS / HS256, 署名鍵 = Channel Secret) を検証してクレームを返す。
 */
export async function verifyIdToken(params: {
  idToken: string;
  channelId: string;
  channelSecret: string;
  nonce: string;
  now?: Date;
}): Promise<IdTokenClaims> {
  const segments = params.idToken.split('.');
  if (segments.length !== 3) throw new LineLoginError('malformed id_token');
  const [headerPart, payloadPart, signaturePart] = segments as [string, string, string];

  const header = JSON.parse(base64UrlToString(headerPart)) as { alg?: string };
  if (header.alg !== 'HS256') throw new LineLoginError('unsupported id_token algorithm');

  const expected = await hmacSha256(params.channelSecret, `${headerPart}.${payloadPart}`);
  if (!timingSafeEqual(signaturePart, expected)) {
    throw new LineLoginError('invalid id_token signature');
  }

  const claims = JSON.parse(base64UrlToString(payloadPart)) as IdTokenClaims;
  if (claims.iss !== 'https://access.line.me') throw new LineLoginError('invalid id_token issuer');
  if (claims.aud !== params.channelId) throw new LineLoginError('invalid id_token audience');
  const nowSeconds = Math.floor((params.now?.getTime() ?? Date.now()) / 1000);
  if (typeof claims.exp !== 'number' || claims.exp < nowSeconds) {
    throw new LineLoginError('expired id_token');
  }
  if (!claims.nonce || claims.nonce !== params.nonce) {
    throw new LineLoginError('invalid id_token nonce');
  }
  if (!claims.sub) throw new LineLoginError('id_token has no subject');
  return claims;
}

export async function fetchProfile(
  accessToken: string,
  fetchImpl: typeof fetch = fetch,
): Promise<LineProfile> {
  const response = await fetchImpl(PROFILE_ENDPOINT, {
    headers: { Authorization: `Bearer ${accessToken}` },
  });
  if (!response.ok) throw new LineLoginError(`profile fetch failed with status ${response.status}`);
  return (await response.json()) as LineProfile;
}

/**
 * 公式アカウントとの友だち状態。
 * 取得できない場合（権限・エラー）は null を返し、「不明」として扱う。
 */
export async function fetchFriendshipStatus(
  accessToken: string,
  fetchImpl: typeof fetch = fetch,
): Promise<boolean | null> {
  try {
    const response = await fetchImpl(FRIENDSHIP_ENDPOINT, {
      headers: { Authorization: `Bearer ${accessToken}` },
    });
    if (!response.ok) return null;
    const data = (await response.json()) as { friendFlag?: boolean };
    return typeof data.friendFlag === 'boolean' ? data.friendFlag : null;
  } catch {
    return null;
  }
}
