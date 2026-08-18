/** 管理画面。単一管理者のユーザー名/パスワード認証 + 署名付きCookie。 */

import { Hono } from 'hono';
import type { Context } from 'hono';
import type { AppEnv } from '../env';
import { baseUrl } from '../env';
import {
  createReservationPage,
  createReservationSlot,
  getBookedSeats,
  getBookingById,
  getPageById,
  getPageBySlug,
  getSlotById,
  getSlotByLegacyTripSlug,
  listBookingsByPage,
  listBookingsBySlot,
  listNotificationsForSlot,
  listPagesWithStats,
  listRosterBySlot,
  listSlotsByPage,
  updateCheckedInCount,
  updatePageStatus,
  updateReservationPage,
  updateReservationSlot,
  type PageInput,
  type SlotInput,
} from '../db/queries';
import type { PageStatus, PageType } from '../db/types';
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
import { buildRosterCsv, csvFileName } from '../services/csv';
import { adminDashboardPage, adminLoginPage } from '../views/admin/dashboard';
import { adminPageFormPage, adminPagesListPage } from '../views/admin/pages';
import { adminSlotDetailPage } from '../views/admin/slot-detail';
import { alertFromCode, bookingErrorToCode } from '../lib/messages';
import { fromJstDatetimeLocal, nowUtc } from '../lib/time';

export const adminRoutes = new Hono<AppEnv>();

const SLUG_PATTERN = /^[a-z0-9-]{1,60}$/;

function adminConfigured(c: Context<AppEnv>): boolean {
  return Boolean(c.env.ADMIN_USERNAME && c.env.ADMIN_PASSWORD);
}

/** 管理画面の認証ガード。ログイン系のパス以外はすべて認証必須。 */
adminRoutes.use('*', async (c, next) => {
  if (c.req.path === '/admin/login') return next();

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

function optionalText(form: FormData, key: string, max = 200): string | null {
  const value = String(form.get(key) ?? '').trim();
  return value ? value.slice(0, max) : null;
}

function optionalDatetime(form: FormData, key: string): string | null {
  const value = String(form.get(key) ?? '').trim();
  if (!value) return null;
  return fromJstDatetimeLocal(value);
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
  const now = nowUtc();
  const pages = await listPagesWithStats(c.env.DB);
  const visible = pages.filter((page) => page.status !== 'archived');

  const entries = [];
  for (const page of visible) {
    entries.push({ page, slots: await listSlotsByPage(c.env.DB, page.id, now) });
  }

  return c.html(adminDashboardPage({ entries, alert: alertFromCode(c.req.query('msg')) }));
});

adminRoutes.post('/reminders/run', async (c) => {
  await processDueReminders(c.env.DB, c.env);
  return c.redirect('/admin?msg=reminder_done', 303);
});

// ---------------------------------------------------------------------------
// 予約ページ
// ---------------------------------------------------------------------------

adminRoutes.get('/reservations', async (c) => {
  const pages = await listPagesWithStats(c.env.DB);
  const csrfToken = await ensureCsrfToken(c);
  return c.html(
    adminPagesListPage({
      pages,
      baseUrl: baseUrl(c.env, c.req.raw),
      csrfToken,
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

adminRoutes.get('/reservations/new', async (c) => {
  const csrfToken = await ensureCsrfToken(c);
  return c.html(
    adminPageFormPage({
      page: null,
      slots: [],
      csrfToken,
      baseUrl: baseUrl(c.env, c.req.raw),
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

function readPageInput(form: FormData): PageInput | { error: string } {
  const slug = String(form.get('slug') ?? '').trim().toLowerCase();
  if (!SLUG_PATTERN.test(slug)) return { error: 'slug_invalid' };

  const title = String(form.get('title') ?? '').trim();
  if (!title) return { error: 'save_failed' };

  const status = String(form.get('status') ?? 'draft') as PageStatus;
  if (!['draft', 'published', 'closed', 'archived'].includes(status)) {
    return { error: 'save_failed' };
  }
  const pageType = String(form.get('page_type') ?? 'other') as PageType;
  if (!['bus', 'event', 'time_slot', 'other'].includes(pageType)) {
    return { error: 'save_failed' };
  }

  const maxSlots = Number(form.get('max_slots_per_checkout') ?? 4);
  if (!Number.isInteger(maxSlots) || maxSlots < 1 || maxSlots > 20) {
    return { error: 'save_failed' };
  }

  return {
    slug,
    title: title.slice(0, 80),
    description: String(form.get('description') ?? '').trim().slice(0, 300),
    status,
    pageType,
    allowMultiSlotBooking: form.get('allow_multi_slot_booking') !== null,
    requiresLineLogin: form.get('requires_line_login') !== null,
    maxSlotsPerCheckout: maxSlots,
    checkinLabel: (String(form.get('checkin_label') ?? '').trim() || '受付').slice(0, 10),
  };
}

adminRoutes.post('/reservations', async (c) => {
  const form = await readForm(c);
  if (!form) return c.redirect('/admin/reservations?msg=csrf', 303);

  const input = readPageInput(form);
  if ('error' in input) return c.redirect(`/admin/reservations/new?msg=${input.error}`, 303);
  if (await getPageBySlug(c.env.DB, input.slug)) {
    return c.redirect('/admin/reservations/new?msg=slug_taken', 303);
  }

  const pageId = await createReservationPage(c.env.DB, input);
  return c.redirect(`/admin/reservations/${pageId}?msg=page_created`, 303);
});

adminRoutes.get('/reservations/:id', async (c) => {
  const pageId = Number(c.req.param('id'));
  const page = await getPageById(c.env.DB, pageId);
  if (!page) return c.redirect('/admin/reservations?msg=page_not_found', 303);

  const slots = await listSlotsByPage(c.env.DB, page.id, nowUtc());
  const csrfToken = await ensureCsrfToken(c);
  return c.html(
    adminPageFormPage({
      page,
      slots,
      csrfToken,
      baseUrl: baseUrl(c.env, c.req.raw),
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

adminRoutes.post('/reservations/:id', async (c) => {
  const pageId = Number(c.req.param('id'));
  const form = await readForm(c);
  if (!form) return c.redirect(`/admin/reservations/${pageId}?msg=csrf`, 303);

  const page = await getPageById(c.env.DB, pageId);
  if (!page) return c.redirect('/admin/reservations?msg=page_not_found', 303);

  const input = readPageInput(form);
  if ('error' in input) return c.redirect(`/admin/reservations/${pageId}?msg=${input.error}`, 303);

  const existing = await getPageBySlug(c.env.DB, input.slug);
  if (existing && existing.id !== pageId) {
    return c.redirect(`/admin/reservations/${pageId}?msg=slug_taken`, 303);
  }

  await updateReservationPage(c.env.DB, pageId, input);
  return c.redirect(`/admin/reservations/${pageId}?msg=saved`, 303);
});

adminRoutes.post('/reservations/:id/status', async (c) => {
  const pageId = Number(c.req.param('id'));
  const form = await readForm(c);
  if (!form) return c.redirect('/admin/reservations?msg=csrf', 303);

  const status = String(form.get('status') ?? '') as PageStatus;
  if (!['draft', 'published', 'closed', 'archived'].includes(status)) {
    return c.redirect('/admin/reservations?msg=save_failed', 303);
  }
  await updatePageStatus(c.env.DB, pageId, status);
  return c.redirect('/admin/reservations?msg=saved', 303);
});

/** ページと枠をまとめて複製する（下書きとして作成）。 */
adminRoutes.post('/reservations/:id/duplicate', async (c) => {
  const pageId = Number(c.req.param('id'));
  const form = await readForm(c);
  if (!form) return c.redirect('/admin/reservations?msg=csrf', 303);

  const page = await getPageById(c.env.DB, pageId);
  if (!page) return c.redirect('/admin/reservations?msg=page_not_found', 303);

  let slug = `${page.slug}-copy`;
  let suffix = 2;
  while (await getPageBySlug(c.env.DB, slug)) {
    slug = `${page.slug}-copy${suffix}`;
    suffix += 1;
    if (suffix > 50) return c.redirect('/admin/reservations?msg=slug_taken', 303);
  }

  const newPageId = await createReservationPage(c.env.DB, {
    slug,
    title: `${page.title}（複製）`,
    description: page.description,
    status: 'draft',
    pageType: page.page_type,
    allowMultiSlotBooking: page.allow_multi_slot_booking === 1,
    requiresLineLogin: page.requires_line_login === 1,
    maxSlotsPerCheckout: page.max_slots_per_checkout,
    checkinLabel: page.checkin_label,
  });

  for (const slot of await listSlotsByPage(c.env.DB, page.id, nowUtc())) {
    await createReservationSlot(c.env.DB, newPageId, {
      name: slot.name,
      description: slot.description,
      startAt: slot.start_at,
      endAt: slot.end_at,
      origin: slot.origin,
      destination: slot.destination,
      location: slot.location,
      capacity: slot.capacity,
      maxPartySize: slot.max_party_size,
      bookingOpenAt: slot.booking_open_at,
      bookingCloseAt: slot.booking_close_at,
      reminderAt: slot.reminder_at,
      bookingStatus: slot.booking_status,
      sortOrder: slot.sort_order,
    });
  }

  return c.redirect(`/admin/reservations/${newPageId}?msg=page_duplicated`, 303);
});

// ---------------------------------------------------------------------------
// 予約枠
// ---------------------------------------------------------------------------

function readSlotInput(form: FormData): SlotInput | { error: string } {
  const name = String(form.get('name') ?? '').trim();
  if (!name) return { error: 'save_failed' };

  const startAt = optionalDatetime(form, 'start_at');
  if (!startAt) return { error: 'save_failed' };

  const capacity = Number(form.get('capacity'));
  if (!Number.isInteger(capacity) || capacity < 0 || capacity > 500) {
    return { error: 'save_failed' };
  }

  const maxPartySize = Number(form.get('max_party_size'));
  if (!Number.isInteger(maxPartySize) || maxPartySize < 1 || maxPartySize > 20) {
    return { error: 'save_failed' };
  }

  const bookingStatus = String(form.get('booking_status') ?? 'open');
  if (!['open', 'closed', 'hidden'].includes(bookingStatus)) {
    return { error: 'save_failed' };
  }

  const sortOrder = Number(form.get('sort_order') ?? 0);
  if (!Number.isInteger(sortOrder) || sortOrder < 0 || sortOrder > 999) {
    return { error: 'save_failed' };
  }

  return {
    name: name.slice(0, 60),
    description: String(form.get('description') ?? '').trim().slice(0, 200),
    startAt,
    endAt: optionalDatetime(form, 'end_at'),
    origin: optionalText(form, 'origin', 100),
    destination: optionalText(form, 'destination', 100),
    location: optionalText(form, 'location', 100),
    capacity,
    maxPartySize,
    bookingOpenAt: optionalDatetime(form, 'booking_open_at'),
    bookingCloseAt: optionalDatetime(form, 'booking_close_at'),
    reminderAt: optionalDatetime(form, 'reminder_at'),
    bookingStatus: bookingStatus as 'open' | 'closed' | 'hidden',
    sortOrder,
  };
}

adminRoutes.post('/reservations/:id/slots', async (c) => {
  const pageId = Number(c.req.param('id'));
  const form = await readForm(c);
  if (!form) return c.redirect(`/admin/reservations/${pageId}?msg=csrf`, 303);

  const page = await getPageById(c.env.DB, pageId);
  if (!page) return c.redirect('/admin/reservations?msg=page_not_found', 303);

  const input = readSlotInput(form);
  if ('error' in input) return c.redirect(`/admin/reservations/${pageId}?msg=${input.error}`, 303);

  await createReservationSlot(c.env.DB, pageId, input);
  return c.redirect(`/admin/reservations/${pageId}?msg=slot_created`, 303);
});

adminRoutes.get('/slots/:id', async (c) => {
  const slotId = Number(c.req.param('id'));
  const slot = await getSlotById(c.env.DB, slotId, nowUtc());
  if (!slot) return c.redirect('/admin/reservations?msg=slot_not_found', 303);

  const page = await getPageById(c.env.DB, slot.reservation_page_id);
  if (!page) return c.redirect('/admin/reservations?msg=page_not_found', 303);

  const search = c.req.query('q') ?? '';
  const [bookings, notifications] = await Promise.all([
    listBookingsBySlot(c.env.DB, slot.id, search || null),
    listNotificationsForSlot(c.env.DB, slot.id),
  ]);
  const csrfToken = await ensureCsrfToken(c);

  return c.html(
    adminSlotDetailPage({
      page,
      slot,
      bookings,
      notifications,
      search,
      csrfToken,
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

adminRoutes.post('/slots/:id', async (c) => {
  const slotId = Number(c.req.param('id'));
  const form = await readForm(c);
  if (!form) return c.redirect(`/admin/slots/${slotId}?msg=csrf`, 303);

  const slot = await getSlotById(c.env.DB, slotId, nowUtc());
  if (!slot) return c.redirect('/admin/reservations?msg=slot_not_found', 303);

  const input = readSlotInput(form);
  if ('error' in input) return c.redirect(`/admin/slots/${slotId}?msg=${input.error}`, 303);

  // 既存の確定予約を下回る定員は許可しない（定員超過状態を作らない）
  const booked = await getBookedSeats(c.env.DB, slotId);
  if (input.capacity < booked) {
    return c.redirect(`/admin/slots/${slotId}?msg=capacity_too_small`, 303);
  }

  await updateReservationSlot(c.env.DB, slotId, input);
  return c.redirect(`/admin/slots/${slotId}?msg=saved`, 303);
});

/** 管理者代理予約。LINE通知は送らない。 */
adminRoutes.post('/slots/:id/bookings', async (c) => {
  const slotId = Number(c.req.param('id'));
  const form = await readForm(c);
  if (!form) return c.redirect(`/admin/slots/${slotId}?msg=csrf`, 303);

  const companionNames = String(form.get('companion_names_text') ?? '')
    .split(/[、,\n]/)
    .map((name) => name.trim())
    .filter((name) => name.length > 0);

  const result = await createBooking(c.env.DB, {
    slotId,
    userId: null,
    source: 'admin',
    representativeName: String(form.get('representative_name') ?? ''),
    phone: String(form.get('phone') ?? ''),
    partySize: Number(form.get('party_size') ?? 0),
    companionNames,
    agreed: true,
  });

  const code = result.ok ? 'booking_created' : bookingErrorToCode(result.code);
  return c.redirect(`/admin/slots/${slotId}?msg=${code}`, 303);
});

// ---------------------------------------------------------------------------
// 名簿CSV
// ---------------------------------------------------------------------------

function csvResponse(c: Context<AppEnv>, csv: string, fileName: string): Response {
  return c.body(csv, 200, {
    'Content-Type': 'text/csv; charset=utf-8',
    'Content-Disposition': `attachment; filename="${fileName}"`,
  });
}

adminRoutes.get('/reservation-slots/:id/roster.csv', async (c) => {
  const slotId = Number(c.req.param('id'));
  const slot = await getSlotById(c.env.DB, slotId, nowUtc());
  if (!slot) return c.redirect('/admin/reservations?msg=slot_not_found', 303);

  const includeCancelled = c.req.query('include') === 'cancelled';
  const bookings = await listRosterBySlot(c.env.DB, slotId, includeCancelled);
  return csvResponse(c, buildRosterCsv(bookings), csvFileName('slot', slotId));
});

adminRoutes.get('/reservations/:id/roster.csv', async (c) => {
  const pageId = Number(c.req.param('id'));
  const page = await getPageById(c.env.DB, pageId);
  if (!page) return c.redirect('/admin/reservations?msg=page_not_found', 303);

  const includeCancelled = c.req.query('include') === 'cancelled';
  const bookings = await listBookingsByPage(c.env.DB, pageId, includeCancelled);
  return csvResponse(c, buildRosterCsv(bookings), csvFileName('event', pageId));
});

// ---------------------------------------------------------------------------
// 予約操作
// ---------------------------------------------------------------------------

function backToSlot(form: FormData | null): string {
  const slotId = Number(form?.get('slot_id') ?? 0);
  return Number.isInteger(slotId) && slotId > 0 ? `/admin/slots/${slotId}` : '/admin';
}

adminRoutes.post('/bookings/:id/checkin', async (c) => {
  const bookingId = Number(c.req.param('id'));
  const form = await readForm(c);
  const back = backToSlot(form);
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
  const back = backToSlot(form);
  if (!form) return c.redirect(`${back}?msg=csrf`, 303);

  const result = await cancelBooking(c.env.DB, { bookingId, userId: null, asAdmin: true });
  return c.redirect(`${back}?msg=${result.ok ? 'cancelled' : 'cancel_failed'}`, 303);
});

/** 旧URL `/admin/trips/:slug` の互換導線。 */
adminRoutes.get('/trips/:slug', async (c) => {
  const slot = await getSlotByLegacyTripSlug(c.env.DB, c.req.param('slug'), nowUtc());
  if (!slot) return c.redirect('/admin?msg=slot_not_found', 303);
  return c.redirect(`/admin/slots/${slot.id}`, 303);
});
