/**
 * らっこ号 池袋便 予約システム — Cloudflare Workers エントリポイント。
 */

import { Hono } from 'hono';
import { secureHeaders } from 'hono/secure-headers';
import type { AppEnv, Bindings } from './env';
import { assertDemoModeSafety, ConfigError } from './env';
import { publicRoutes } from './routes/public';
import { authRoutes } from './routes/auth';
import { bookingRoutes } from './routes/booking';
import { adminRoutes } from './routes/admin';
import { handleScheduled } from './routes/cron';
import { layout } from './views/layout';

const app = new Hono<AppEnv>();

app.use(
  '*',
  secureHeaders({
    contentSecurityPolicy: {
      defaultSrc: ["'self'"],
      // ページ内スクリプトはSSRで埋め込む最小限のもののみ
      scriptSrc: ["'self'", "'unsafe-inline'"],
      styleSrc: ["'self'"],
      imgSrc: ["'self'", 'https://profile.line-scdn.net', 'data:'],
      formAction: ["'self'"],
      frameAncestors: ["'none'"],
      baseUri: ["'self'"],
    },
    referrerPolicy: 'strict-origin-when-cross-origin',
  }),
);

/** production で DEMO_MODE が有効なら、いかなるリクエストも処理しない。 */
app.use('*', async (c, next) => {
  assertDemoModeSafety(c.env);
  return next();
});

app.route('/', publicRoutes);
app.route('/', authRoutes);
app.route('/', bookingRoutes);
app.route('/admin', adminRoutes);

app.notFound((c) =>
  c.html(
    layout(
      { title: 'ページが見つかりません | らっこ号 池袋便' },
      `<h2>ページが見つかりません</h2>
       <div class="card"><p>お探しのページは存在しないか、アクセス権限がありません。</p>
       <a class="btn" href="/">トップへ戻る</a></div>`,
    ),
    404,
  ),
);

app.onError((error, c) => {
  if (error instanceof ConfigError) {
    // 設定ミスは内容を伏せずに運用者へ伝える（機密値は含めない）
    return c.text(`Configuration error: ${error.message}`, 500);
  }
  console.error(`[error] ${error.name}: ${error.message}`);
  return c.html(
    layout(
      { title: 'エラー | らっこ号 池袋便' },
      `<h2>エラーが発生しました</h2>
       <div class="card"><p>時間をおいて再度お試しください。</p>
       <a class="btn" href="/">トップへ戻る</a></div>`,
    ),
    500,
  );
});

export default {
  fetch: app.fetch,
  scheduled: async (
    event: ScheduledController,
    env: Bindings,
    ctx: ExecutionContext,
  ): Promise<void> => {
    ctx.waitUntil(handleScheduled(event, env).then(() => undefined));
  },
};
