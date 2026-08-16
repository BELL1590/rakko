/** 公開ページ（トップ・静的アセット）。 */

import { Hono } from 'hono';
import type { AppEnv } from '../env';
import { listTrips, getUserById } from '../db/queries';
import { getUserSession } from '../services/session';
import { homePage } from '../views/home';
import { APP_CSS } from '../styles/app.css';
import { alertFromCode } from '../lib/messages';
import { nowUtc } from '../lib/time';

export const publicRoutes = new Hono<AppEnv>();

publicRoutes.get('/assets/app.css', (c) => {
  return c.body(APP_CSS, 200, {
    'Content-Type': 'text/css; charset=utf-8',
    'Cache-Control': 'public, max-age=3600',
  });
});

publicRoutes.get('/', async (c) => {
  const trips = await listTrips(c.env.DB, nowUtc());
  const session = await getUserSession(c);
  const user = session ? await getUserById(c.env.DB, session.uid) : null;

  return c.html(
    homePage({
      trips,
      userName: user?.line_display_name ?? null,
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

/** 死活監視用。 */
publicRoutes.get('/healthz', (c) => c.json({ ok: true }));
