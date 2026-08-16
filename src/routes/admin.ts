/** 管理画面。単一管理者のBasicなユーザー名/パスワード認証 + 署名付きCookie。 */

import { Hono } from 'hono';
import type { Context } from 'hono';
import type { AppEnv } from '../env';
import {
  getBookingById,
  getBookedSeats,
  getTripBySlug,
  listBookingsByTrip,
  listNotificationsForTrip,
  listTrips,
  updateCheckedInCount,
  updateTripBookingStatus,
  updateTripCapacity,
  updateTripReminderAt,
} from '../db/queries';
import { parseCompanionNames } from '../db/types';
import {
  clearAdminSession,
  ensureCsrfToken,
  getAdminSession,
  setAdminSession,
  timingSafeEqual,
  verifyCsrfToken,
} from '../services/session';
import { cancelBooking, createBooking } from '../services/booking-service';
import { processDueReminders } from '../services/reminder-service';
import { adminDashboardPage, adminLoginPage } from '../views/admin/dashboard';
import { adminTripDetailPage } from '../views/admin/trip-detail';
import { alertFromCode, bookingErrorToCode } from '../lib/messages';
import { formatJstIsoLike, fromJstDatetimeLocal, nowUtc } from '../lib/time';

export const adminRoutes = new Hono<AppEnv>();

function adminConfigured(c: Context<AppEnv>): boolean {
  return Boolean(c.env.ADMIN_USERNAME && c.env.ADMIN_PASSWORD);
}

/** 管理画面の認証ガード。ログイン系のパス以外はすべて認証必須。 */
adminRoutes.use('*', async (c, next) => {
  const path = c.req.path;
  if (path === '/admin/login') return next();

  const session = await getAdminSession(c);
  if (!session) {
    if (c.req.method !== 'GET') return c.text('Unauthorized', 401);
    return c.redirect('/admin/login?msg=admin_login_required', 303);
  }
  return next();
});

/** POST の CSRF 検証をまとめて行う。 */
async function readForm(c: Context<AppEnv>): Promise<FormData | null> {
  const form = await c.req.formData();
  const ok = await verifyCsrfToken(c, String(form.get('csrf_token') ?? ''));
  return ok ? form : null;
}

// ---------------------------------------------------------------------------
// 認証
// ---------------------------------------------------------------------------

adminRoutes.get('/login', async (c) => {
  if (await getAdminSession(c)) return c.redirect('/admin', 303);
  const csrfToken = await ensureCsrfToken(c);
  return c.html(adminLoginPage({ csrfToken, alert: alertFromCode(c.req.query('msg')) }));
});

adminRoutes.post('/login', async (c) => {
  const form = await c.req.formData();
  if (!(await verifyCsrfToken(c, String(form.get('csrf_token') ?? '')))) {
    return c.redirect('/admin/login?msg=csrf', 303);
  }
  if (!adminConfigured(c)) {
    return c.redirect('/admin/login?msg=admin_not_configured', 303);
  }

  const username = String(form.get('username') ?? '');
  const password = String(form.get('password') ?? '');
  const okUser = timingSafeEqual(username, c.env.ADMIN_USERNAME as string);
  const okPass = timingSafeEqual(password, c.env.ADMIN_PASSWORD as string);
  if (!okUser || !okPass) {
    return c.redirect('/admin/login?msg=admin_login_failed', 303);
  }

  await setAdminSession(c, username);
  return c.redirect('/admin', 303);
});

adminRoutes.post('/logout', async (c) => {
  clearAdminSession(c);
  return c.redirect('/admin/login?msg=admin_logged_out', 303);
});

// ---------------------------------------------------------------------------
// ダッシュボード
// ---------------------------------------------------------------------------

adminRoutes.get('/', async (c) => {
  const trips = await listTrips(c.env.DB, nowUtc());
  return c.html(adminDashboardPage({ trips, alert: alertFromCode(c.req.query('msg')) }));
});

adminRoutes.post('/reminders/run', async (c) => {
  await processDueReminders(c.env.DB, c.env);
  return c.redirect('/admin?msg=reminder_done', 303);
});

// ---------------------------------------------------------------------------
// 便詳細
// ---------------------------------------------------------------------------

adminRoutes.get('/trips/:slug', async (c) => {
  const slug = c.req.param('slug');
  const trip = await getTripBySlug(c.env.DB, slug, nowUtc());
  if (!trip) return c.redirect('/admin?msg=trip_not_found', 303);

  const search = c.req.query('q') ?? '';
  const [bookings, notifications] = await Promise.all([
    listBookingsByTrip(c.env.DB, trip.id, search || null),
    listNotificationsForTrip(c.env.DB, trip.id),
  ]);
  const csrfToken = await ensureCsrfToken(c);

  return c.html(
    adminTripDetailPage({
      trip,
      bookings,
      notifications,
      search,
      csrfToken,
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

adminRoutes.get('/trips/:slug/bookings.csv', async (c) => {
  const slug = c.req.param('slug');
  const trip = await getTripBySlug(c.env.DB, slug, nowUtc());
  if (!trip) return c.redirect('/admin?msg=trip_not_found', 303);

  const bookings = await listBookingsByTrip(c.env.DB, trip.id, null);
  const header = [
    '予約ID',
    '予約日時(JST)',
    '便',
    '出発日時(JST)',
    '代表者氏名',
    '電話番号',
    '人数',
    '同行者',
    '経路',
    '乗車人数',
    'ステータス',
  ];

  const escapeCell = (value: string): string => `"${value.replace(/"/g, '""')}"`;
  const rows = bookings.map((booking) =>
    [
      String(booking.id),
      formatJstIsoLike(booking.created_at),
      trip.direction === 'outbound' ? '行き' : '帰り',
      formatJstIsoLike(booking.depart_at),
      booking.representative_name,
      booking.phone,
      String(booking.party_size),
      parseCompanionNames(booking.companion_names_json).join(' / '),
      booking.source === 'admin' ? '管理者代理' : 'LINE',
      String(booking.checked_in_count),
      booking.status === 'confirmed' ? '予約済み' : 'キャンセル',
    ]
      .map(escapeCell)
      .join(','),
  );

  // Excelでの文字化けを避けるためBOMを付与する
  const csv = `﻿${[header.map(escapeCell).join(','), ...rows].join('\r\n')}\r\n`;
  return c.body(csv, 200, {
    'Content-Type': 'text/csv; charset=utf-8',
    'Content-Disposition': `attachment; filename="bookings-${trip.slug}.csv"`,
  });
});

adminRoutes.post('/trips/:slug/capacity', async (c) => {
  const slug = c.req.param('slug');
  const form = await readForm(c);
  if (!form) return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=csrf`, 303);

  const trip = await getTripBySlug(c.env.DB, slug, nowUtc());
  if (!trip) return c.redirect('/admin?msg=trip_not_found', 303);

  const capacity = Number(form.get('capacity'));
  if (!Number.isInteger(capacity) || capacity < 0 || capacity > 200) {
    return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=save_failed`, 303);
  }

  // 既存の確定予約を下回る定員は許可しない（定員超過状態を作らない）
  const booked = await getBookedSeats(c.env.DB, trip.id);
  if (capacity < booked) {
    return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=capacity_too_small`, 303);
  }

  await updateTripCapacity(c.env.DB, trip.id, capacity);
  return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=saved`, 303);
});

adminRoutes.post('/trips/:slug/status', async (c) => {
  const slug = c.req.param('slug');
  const form = await readForm(c);
  if (!form) return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=csrf`, 303);

  const trip = await getTripBySlug(c.env.DB, slug, nowUtc());
  if (!trip) return c.redirect('/admin?msg=trip_not_found', 303);

  const status = String(form.get('booking_status') ?? '');
  if (status !== 'open' && status !== 'closed') {
    return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=save_failed`, 303);
  }

  await updateTripBookingStatus(c.env.DB, trip.id, status);
  return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=saved`, 303);
});

adminRoutes.post('/trips/:slug/reminder', async (c) => {
  const slug = c.req.param('slug');
  const form = await readForm(c);
  if (!form) return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=csrf`, 303);

  const trip = await getTripBySlug(c.env.DB, slug, nowUtc());
  if (!trip) return c.redirect('/admin?msg=trip_not_found', 303);

  // 入力はJST、保存はUTC
  const reminderAtUtc = fromJstDatetimeLocal(String(form.get('reminder_at') ?? ''));
  if (!reminderAtUtc) {
    return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=save_failed`, 303);
  }

  await updateTripReminderAt(c.env.DB, trip.id, reminderAtUtc);
  return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=saved`, 303);
});

/** 管理者代理予約。LINE通知は送らない。 */
adminRoutes.post('/trips/:slug/bookings', async (c) => {
  const slug = c.req.param('slug');
  const form = await readForm(c);
  if (!form) return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=csrf`, 303);

  const trip = await getTripBySlug(c.env.DB, slug, nowUtc());
  if (!trip) return c.redirect('/admin?msg=trip_not_found', 303);

  const companionNames = String(form.get('companion_names_text') ?? '')
    .split(/[、,\n]/)
    .map((name) => name.trim())
    .filter((name) => name.length > 0);

  const result = await createBooking(c.env.DB, {
    tripId: trip.id,
    userId: null,
    source: 'admin',
    representativeName: String(form.get('representative_name') ?? ''),
    phone: String(form.get('phone') ?? ''),
    partySize: Number(form.get('party_size') ?? 0),
    companionNames,
    agreed: true,
  });

  const code = result.ok ? 'booking_created' : bookingErrorToCode(result.code);
  return c.redirect(`/admin/trips/${encodeURIComponent(slug)}?msg=${code}`, 303);
});

// ---------------------------------------------------------------------------
// 予約操作
// ---------------------------------------------------------------------------

adminRoutes.post('/bookings/:id/checkin', async (c) => {
  const bookingId = Number(c.req.param('id'));
  const form = await readForm(c);
  const slug = String(form?.get('trip_slug') ?? '');
  const back = slug ? `/admin/trips/${encodeURIComponent(slug)}` : '/admin';
  if (!form) return c.redirect(`${back}?msg=csrf`, 303);

  const booking = await getBookingById(c.env.DB, bookingId);
  if (!booking) return c.redirect(`${back}?msg=not_found`, 303);

  const op = String(form.get('op') ?? '');
  let next = booking.checked_in_count;
  if (op === 'inc') next = Math.min(booking.party_size, booking.checked_in_count + 1);
  else if (op === 'dec') next = Math.max(0, booking.checked_in_count - 1);
  else if (op === 'all') next = booking.party_size;
  else return c.redirect(`${back}?msg=save_failed`, 303);

  await updateCheckedInCount(c.env.DB, bookingId, next);
  return c.redirect(`${back}?msg=saved`, 303);
});

adminRoutes.post('/bookings/:id/cancel', async (c) => {
  const bookingId = Number(c.req.param('id'));
  const form = await readForm(c);
  const slug = String(form?.get('trip_slug') ?? '');
  const back = slug ? `/admin/trips/${encodeURIComponent(slug)}` : '/admin';
  if (!form) return c.redirect(`${back}?msg=csrf`, 303);

  const result = await cancelBooking(c.env.DB, { bookingId, userId: null, asAdmin: true });
  return c.redirect(`${back}?msg=${result.ok ? 'cancelled' : 'cancel_failed'}`, 303);
});
