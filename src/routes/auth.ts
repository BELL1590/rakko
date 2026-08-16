/**
 * LINE Login（OAuth2 / OIDC + PKCE）と、DEMO_MODE の疑似ログイン。
 */

import { Hono } from 'hono';
import type { AppEnv } from '../env';
import { baseUrl, hasLineLoginConfig, isDemoMode } from '../env';
import { upsertUserByLineId } from '../db/queries';
import {
  buildAuthorizeUrl,
  exchangeCodeForToken,
  fetchFriendshipStatus,
  fetchProfile,
  verifyIdToken,
} from '../services/line-login';
import {
  clearUserSession,
  createCodeChallenge,
  createCodeVerifier,
  ensureCsrfToken,
  randomToken,
  safeRedirectPath,
  setOAuthState,
  setUserSession,
  takeOAuthState,
  timingSafeEqual,
  verifyCsrfToken,
} from '../services/session';
import { loginPage } from '../views/login';
import { alertFromCode } from '../lib/messages';

export const authRoutes = new Hono<AppEnv>();

export function loginCallbackUrl(env: AppEnv['Bindings'], req: Request): string {
  return `${baseUrl(env, req)}/auth/line/callback`;
}

authRoutes.get('/login', async (c) => {
  const redirectTo = safeRedirectPath(c.req.query('redirect_to'), '/');
  const csrfToken = await ensureCsrfToken(c);
  return c.html(
    loginPage({
      redirectTo,
      demoMode: isDemoMode(c.env),
      lineConfigured: hasLineLoginConfig(c.env),
      csrfToken,
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

authRoutes.post('/auth/line/start', async (c) => {
  const form = await c.req.formData();
  if (!(await verifyCsrfToken(c, String(form.get('csrf_token') ?? '')))) {
    return c.redirect('/login?msg=csrf', 303);
  }
  if (!hasLineLoginConfig(c.env)) {
    return c.redirect('/login?msg=login_failed', 303);
  }

  const redirectTo = safeRedirectPath(String(form.get('redirect_to') ?? ''), '/');
  const state = randomToken(24);
  const nonce = randomToken(24);
  const codeVerifier = createCodeVerifier();
  const codeChallenge = await createCodeChallenge(codeVerifier);

  await setOAuthState(c, { state, nonce, codeVerifier, redirectTo });

  const authorizeUrl = buildAuthorizeUrl({
    channelId: c.env.LINE_LOGIN_CHANNEL_ID as string,
    redirectUri: loginCallbackUrl(c.env, c.req.raw),
    state,
    nonce,
    codeChallenge,
    // 公式アカウントの友だち追加導線を表示する（拒否しても予約は可能）
    promptAddFriend: true,
  });
  return c.redirect(authorizeUrl, 302);
});

authRoutes.get('/auth/line/callback', async (c) => {
  const stored = await takeOAuthState(c);
  const returnedState = c.req.query('state') ?? '';
  const code = c.req.query('code') ?? '';
  const error = c.req.query('error');

  if (error || !code) {
    return c.redirect('/login?msg=login_failed', 303);
  }
  if (!stored || !timingSafeEqual(stored.state, returnedState)) {
    // state 不一致は CSRF の可能性があるため必ず拒否する
    return c.redirect('/login?msg=session_expired', 303);
  }
  if (!hasLineLoginConfig(c.env)) {
    return c.redirect('/login?msg=login_failed', 303);
  }

  const channelId = c.env.LINE_LOGIN_CHANNEL_ID as string;
  const channelSecret = c.env.LINE_LOGIN_CHANNEL_SECRET as string;

  try {
    const token = await exchangeCodeForToken({
      code,
      redirectUri: loginCallbackUrl(c.env, c.req.raw),
      channelId,
      channelSecret,
      codeVerifier: stored.codeVerifier,
    });

    let lineUserId: string;
    let displayName = '';
    let pictureUrl: string | null = null;

    if (token.id_token) {
      const claims = await verifyIdToken({
        idToken: token.id_token,
        channelId,
        channelSecret,
        nonce: stored.nonce,
      });
      lineUserId = claims.sub;
      displayName = claims.name ?? '';
      pictureUrl = claims.picture ?? null;
    } else {
      const profile = await fetchProfile(token.access_token);
      lineUserId = profile.userId;
      displayName = profile.displayName;
      pictureUrl = profile.pictureUrl ?? null;
    }

    if (!displayName) {
      const profile = await fetchProfile(token.access_token);
      displayName = profile.displayName;
      pictureUrl = pictureUrl ?? profile.pictureUrl ?? null;
    }

    const isFriend = await fetchFriendshipStatus(token.access_token);

    const user = await upsertUserByLineId(c.env.DB, {
      lineUserId,
      displayName,
      pictureUrl,
      isLineFriend: isFriend,
    });

    await setUserSession(c, user.id);
    return c.redirect(stored.redirectTo, 303);
  } catch {
    // アクセストークン等が混入しないよう、詳細はログに残さない
    return c.redirect('/login?msg=login_failed', 303);
  }
});

/** DEMO_MODE 専用の疑似ログイン。production では絶対に有効化されない。 */
authRoutes.post('/auth/demo/login', async (c) => {
  if (!isDemoMode(c.env)) {
    return c.redirect('/login?msg=demo_disabled', 303);
  }
  const form = await c.req.formData();
  if (!(await verifyCsrfToken(c, String(form.get('csrf_token') ?? '')))) {
    return c.redirect('/login?msg=csrf', 303);
  }

  const lineUserId = (String(form.get('demo_user_id') ?? '').trim() || 'demo-user-001').slice(0, 64);
  const displayName =
    (String(form.get('demo_display_name') ?? '').trim() || 'デモユーザー').slice(0, 50);
  const redirectTo = safeRedirectPath(String(form.get('redirect_to') ?? ''), '/');

  const user = await upsertUserByLineId(c.env.DB, {
    lineUserId,
    displayName,
    pictureUrl: null,
    // デモでは友だち状態は不明扱い
    isLineFriend: null,
  });
  await setUserSession(c, user.id);
  return c.redirect(redirectTo, 303);
});

authRoutes.post('/logout', async (c) => {
  const form = await c.req.formData();
  if (!(await verifyCsrfToken(c, String(form.get('csrf_token') ?? '')))) {
    return c.redirect('/?msg=csrf', 303);
  }
  clearUserSession(c);
  return c.redirect('/?msg=logged_out', 303);
});
