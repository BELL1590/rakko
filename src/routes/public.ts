/** 公開ページ（トップ・静的アセット）。 */

import { Hono } from 'hono';
import type { AppEnv } from '../env';
import { listPublishedPages, listSlotsByPage, getUserById } from '../db/queries';
import { getUserSession } from '../services/session';
import { homePage, type HomePageEntry } from '../views/home';
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
  const now = nowUtc();
  const pages = await listPublishedPages(c.env.DB);

  const entries: HomePageEntry[] = [];
  for (const page of pages) {
    const slots = await listSlotsByPage(c.env.DB, page.id, now);
    entries.push({ page, slots: slots.filter((slot) => slot.is_visible) });
  }

  const session = await getUserSession(c);
  const user = session ? await getUserById(c.env.DB, session.uid) : null;

  return c.html(
    homePage({
      entries,
      userName: user?.line_display_name ?? null,
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

/** 死活監視用。 */
publicRoutes.get('/healthz', (c) => c.json({ ok: true }));
