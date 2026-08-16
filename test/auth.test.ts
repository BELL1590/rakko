import { beforeEach, afterEach, describe, expect, it } from 'vitest';
import {
  createTestDb,
  createTestUser,
  tripIdBySlug,
  NOW,
  OUTBOUND_SLUG,
  type TestDatabase,
} from './helpers/db';
import { TestClient, testEnv } from './helpers/client';
import { createBooking, cancelBooking, getOwnedBooking } from '../src/services/booking-service';
import {
  buildAuthorizeUrl,
  verifyIdToken,
  LineLoginError,
} from '../src/services/line-login';
import {
  createCodeChallenge,
  createCodeVerifier,
  hmacSha256,
  signPayload,
  verifyPayload,
  safeRedirectPath,
  base64UrlEncodeString,
} from '../src/services/session';

let db: TestDatabase;

const base = {
  representativeName: '山田太郎',
  phone: '090-1234-5678',
  agreed: true,
  source: 'line' as const,
};

beforeEach(() => {
  db = createTestDb();
});

afterEach(() => {
  db.close();
});

describe('予約の所有者チェック', () => {
  it('他人の予約は閲覧できない', async () => {
    const owner = await createTestUser(db.d1, 'OWNER');
    const other = await createTestUser(db.d1, 'OTHER');
    const tripId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);

    const created = await createBooking(
      db.d1,
      { ...base, tripId, userId: owner, partySize: 1, companionNames: [] },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');

    expect(await getOwnedBooking(db.d1, created.bookingId, owner)).not.toBeNull();
    expect(await getOwnedBooking(db.d1, created.bookingId, other)).toBeNull();
  });

  it('他人の予約はキャンセルできない', async () => {
    const owner = await createTestUser(db.d1, 'OWNER');
    const other = await createTestUser(db.d1, 'OTHER');
    const tripId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);

    const created = await createBooking(
      db.d1,
      { ...base, tripId, userId: owner, partySize: 1, companionNames: [] },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');

    const result = await cancelBooking(
      db.d1,
      { bookingId: created.bookingId, userId: other, asAdmin: false },
      NOW,
    );
    expect(result).toMatchObject({ ok: false, code: 'FORBIDDEN' });

    const row = await db.d1
      .prepare('SELECT status FROM bookings WHERE id = ?1')
      .bind(created.bookingId)
      .first<{ status: string }>();
    expect(row?.status).toBe('confirmed');
  });

  it('HTTP経由でも他人の予約詳細は 404 になる', async () => {
    const tripId = await tripIdBySlug(db.d1, OUTBOUND_SLUG);
    const owner = await createTestUser(db.d1, 'demo-owner');
    const created = await createBooking(
      db.d1,
      { ...base, tripId, userId: owner, partySize: 1, companionNames: [] },
      NOW,
    );
    if (!created.ok) throw new Error('setup failed');

    // 別ユーザーとしてデモログイン
    const client = new TestClient(testEnv({ DB: db.d1 }));
    const csrf = await client.csrfTokenFrom('/login');
    await client.post('/auth/demo/login', {
      csrf_token: csrf,
      demo_user_id: 'demo-attacker',
      demo_display_name: '別人',
      redirect_to: '/',
    });

    const response = await client.get(`/bookings/${created.bookingId}`);
    expect(response.status).toBe(404);
  });
});

describe('管理画面の認証', () => {
  it('未認証では管理画面へアクセスできない', async () => {
    const client = new TestClient(testEnv({ DB: db.d1 }));
    const response = await client.get('/admin');
    expect(response.status).toBe(303);
    expect(response.headers.get('location')).toContain('/admin/login');
  });

  it('未認証のPOSTは401になる', async () => {
    const client = new TestClient(testEnv({ DB: db.d1 }));
    const response = await client.post('/admin/trips/x/capacity', { capacity: '100' });
    expect(response.status).toBe(401);
  });

  it('正しい認証情報でログインできる', async () => {
    const client = new TestClient(testEnv({ DB: db.d1 }));
    const csrf = await client.csrfTokenFrom('/admin/login');

    const bad = await client.post('/admin/login', {
      csrf_token: csrf,
      username: 'staff',
      password: 'wrong',
    });
    expect(bad.headers.get('location')).toContain('admin_login_failed');

    const good = await client.post('/admin/login', {
      csrf_token: csrf,
      username: 'staff',
      password: 'staff-password',
    });
    expect(good.status).toBe(303);
    expect(good.headers.get('location')).toBe('/admin');

    const dashboard = await client.get('/admin');
    expect(dashboard.status).toBe(200);
    expect(await dashboard.text()).toContain('ダッシュボード');
  });

  it('管理者が未設定ならログインできない', async () => {
    const client = new TestClient(
      testEnv({ DB: db.d1, ADMIN_USERNAME: undefined, ADMIN_PASSWORD: undefined }),
    );
    const csrf = await client.csrfTokenFrom('/admin/login');
    const response = await client.post('/admin/login', {
      csrf_token: csrf,
      username: 'staff',
      password: 'staff-password',
    });
    expect(response.headers.get('location')).toContain('admin_not_configured');
  });
});

describe('CSRF対策', () => {
  it('CSRFトークンが無いPOSTは処理されない', async () => {
    const client = new TestClient(testEnv({ DB: db.d1 }));
    await client.get('/admin/login');
    const response = await client.post('/admin/login', {
      username: 'staff',
      password: 'staff-password',
    });
    expect(response.headers.get('location')).toContain('msg=csrf');
  });
});

describe('DEMO_MODE', () => {
  it('開発環境では疑似ログインできる', async () => {
    const client = new TestClient(testEnv({ DB: db.d1 }));
    const loginPage = await client.get('/login');
    expect(await loginPage.text()).toContain('デモログイン');

    const csrf = await client.csrfTokenFrom('/login');
    const response = await client.post('/auth/demo/login', {
      csrf_token: csrf,
      demo_user_id: 'demo-user-001',
      demo_display_name: 'デモユーザー',
      redirect_to: '/my-bookings',
    });
    expect(response.status).toBe(303);
    expect(response.headers.get('location')).toBe('/my-bookings');

    const myBookings = await client.get('/my-bookings');
    expect(myBookings.status).toBe(200);
    expect(await myBookings.text()).toContain('あなたの予約');

    const user = await db.d1
      .prepare('SELECT line_display_name FROM users WHERE line_user_id = ?1')
      .bind('demo-user-001')
      .first<{ line_display_name: string }>();
    expect(user?.line_display_name).toBe('デモユーザー');
  });

  it('production では DEMO_MODE が有効だとリクエストを拒否する', async () => {
    const client = new TestClient(
      testEnv({ DB: db.d1, ENVIRONMENT: 'production', DEMO_MODE: 'true' }),
    );
    const response = await client.get('/');
    expect(response.status).toBe(500);
    expect(await response.text()).toContain('DEMO_MODE must be disabled in production');
  });

  it('production ではデモログインURLが使えない', async () => {
    const client = new TestClient(
      testEnv({
        DB: db.d1,
        ENVIRONMENT: 'production',
        DEMO_MODE: 'false',
        BASE_URL: 'https://example.com',
      }),
    );
    const response = await client.post('/auth/demo/login', {
      demo_user_id: 'demo-user-001',
      demo_display_name: 'デモユーザー',
    });
    expect(response.headers.get('location')).toContain('demo_disabled');

    const count = await db.d1.prepare('SELECT COUNT(*) AS c FROM users').first<{ c: number }>();
    expect(count?.c).toBe(0);
  });
});

describe('未ログイン時の予約導線', () => {
  it('予約ページはログインへ誘導される', async () => {
    const client = new TestClient(testEnv({ DB: db.d1 }));
    const response = await client.get(`/trips/${OUTBOUND_SLUG}/book`);
    expect(response.status).toBe(303);
    const location = response.headers.get('location') ?? '';
    expect(location).toContain('/login');
    expect(location).toContain('msg=login_required');
    expect(decodeURIComponent(location)).toContain(`/trips/${OUTBOUND_SLUG}/book`);
  });
});

describe('セッション署名', () => {
  it('改ざんされたトークンは検証に失敗する', async () => {
    const secret = 'secret-value';
    const token = await signPayload(secret, { uid: 1 });
    expect(await verifyPayload<{ uid: number }>(secret, token)).toEqual({ uid: 1 });

    const [payload] = token.split('.');
    const forged = `${base64UrlEncodeString(JSON.stringify({ uid: 999 }))}.${token.split('.')[1]}`;
    expect(await verifyPayload(secret, forged)).toBeNull();
    expect(await verifyPayload('other-secret', token)).toBeNull();
    expect(await verifyPayload(secret, `${payload}.invalid`)).toBeNull();
  });

  it('オープンリダイレクトを防ぐ', () => {
    expect(safeRedirectPath('/my-bookings')).toBe('/my-bookings');
    expect(safeRedirectPath('//evil.example.com')).toBe('/');
    expect(safeRedirectPath('https://evil.example.com')).toBe('/');
    expect(safeRedirectPath(undefined)).toBe('/');
  });
});

describe('LINE Login', () => {
  it('認可URLに state / PKCE / scope が含まれる', async () => {
    const verifier = createCodeVerifier();
    const challenge = await createCodeChallenge(verifier);
    const url = new URL(
      buildAuthorizeUrl({
        channelId: '1234567890',
        redirectUri: 'https://example.com/auth/line/callback',
        state: 'state-value',
        nonce: 'nonce-value',
        codeChallenge: challenge,
        promptAddFriend: true,
      }),
    );

    expect(url.origin + url.pathname).toBe('https://access.line.me/oauth2/v2.1/authorize');
    expect(url.searchParams.get('response_type')).toBe('code');
    expect(url.searchParams.get('client_id')).toBe('1234567890');
    expect(url.searchParams.get('state')).toBe('state-value');
    expect(url.searchParams.get('nonce')).toBe('nonce-value');
    expect(url.searchParams.get('scope')).toBe('openid profile');
    expect(url.searchParams.get('code_challenge_method')).toBe('S256');
    expect(url.searchParams.get('code_challenge')).toBe(challenge);
    expect(url.searchParams.get('bot_prompt')).toBe('aggressive');
    expect(challenge).not.toBe(verifier);
  });

  it('id_token の署名・nonce を検証する', async () => {
    const channelSecret = 'channel-secret';
    const channelId = '1234567890';
    const nonce = 'nonce-value';

    const buildToken = async (claims: Record<string, unknown>): Promise<string> => {
      const header = base64UrlEncodeString(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
      const payload = base64UrlEncodeString(JSON.stringify(claims));
      const signature = await hmacSha256(channelSecret, `${header}.${payload}`);
      return `${header}.${payload}.${signature}`;
    };

    const validClaims = {
      iss: 'https://access.line.me',
      sub: 'Uline123',
      aud: channelId,
      exp: Math.floor(Date.now() / 1000) + 600,
      iat: Math.floor(Date.now() / 1000),
      nonce,
      name: 'LINE太郎',
    };

    const claims = await verifyIdToken({
      idToken: await buildToken(validClaims),
      channelId,
      channelSecret,
      nonce,
    });
    expect(claims.sub).toBe('Uline123');

    // nonce 不一致
    await expect(
      verifyIdToken({
        idToken: await buildToken(validClaims),
        channelId,
        channelSecret,
        nonce: 'different-nonce',
      }),
    ).rejects.toThrow(LineLoginError);

    // 署名鍵が違う
    await expect(
      verifyIdToken({
        idToken: await buildToken(validClaims),
        channelId,
        channelSecret: 'wrong-secret',
        nonce,
      }),
    ).rejects.toThrow(LineLoginError);

    // 期限切れ
    await expect(
      verifyIdToken({
        idToken: await buildToken({ ...validClaims, exp: Math.floor(Date.now() / 1000) - 10 }),
        channelId,
        channelSecret,
        nonce,
      }),
    ).rejects.toThrow(LineLoginError);
  });
});
