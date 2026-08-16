/** 予約フォーム・予約作成・マイ予約・キャンセル。 */

import { Hono } from 'hono';
import type { Context } from 'hono';
import type { AppEnv } from '../env';
import {
  getTripBySlug,
  getUserById,
  listBookingsByUser,
} from '../db/queries';
import type { UserRow } from '../db/types';
import { ensureCsrfToken, getUserSession, verifyCsrfToken } from '../services/session';
import {
  cancelBooking,
  createBooking,
  getOwnedBooking,
  MAX_PARTY_SIZE,
} from '../services/booking-service';
import { sendBookingConfirmation } from '../services/reminder-service';
import { bookingFormPage, type BookingFormValues } from '../views/booking-form';
import { bookingDetailPage } from '../views/booking-detail';
import { myBookingsPage } from '../views/my-bookings';
import { alertFromCode, bookingErrorToCode } from '../lib/messages';
import { nowUtc } from '../lib/time';

export const bookingRoutes = new Hono<AppEnv>();

/** ログイン必須。未ログインならログインページへ誘導する。 */
async function requireUser(c: Context<AppEnv>): Promise<UserRow | Response> {
  const session = await getUserSession(c);
  const user = session ? await getUserById(c.env.DB, session.uid) : null;
  if (!user) {
    const target = new URL(c.req.url);
    const redirectTo = `${target.pathname}${target.search}`;
    return c.redirect(
      `/login?redirect_to=${encodeURIComponent(redirectTo)}&msg=login_required`,
      303,
    );
  }
  return user;
}

function isResponse(value: unknown): value is Response {
  return value instanceof Response;
}

/** 予約完了通知はリクエストをブロックしない。失敗しても予約は維持する。 */
function scheduleConfirmation(c: Context<AppEnv>, bookingId: number): void {
  const task = sendBookingConfirmation(c.env.DB, c.env, bookingId).catch(() => undefined);
  try {
    c.executionCtx.waitUntil(task);
  } catch {
    // executionCtx が無い環境（テスト等）では何もしない
  }
}

bookingRoutes.get('/trips/:slug/book', async (c) => {
  const user = await requireUser(c);
  if (isResponse(user)) return user;

  const trip = await getTripBySlug(c.env.DB, c.req.param('slug'), nowUtc());
  if (!trip) return c.redirect('/?msg=trip_not_found', 303);
  if (trip.is_full) return c.redirect('/?msg=trip_full', 303);
  if (!trip.is_bookable) return c.redirect('/?msg=trip_closed', 303);

  const csrfToken = await ensureCsrfToken(c);
  const values: BookingFormValues = {
    representativeName: '',
    phone: '',
    partySize: 1,
    companionNames: [],
    agreed: false,
  };

  return c.html(
    bookingFormPage({
      trip,
      values,
      csrfToken,
      userName: user.line_display_name,
      isLineFriend: user.is_line_friend,
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

bookingRoutes.post('/trips/:slug/book', async (c) => {
  const user = await requireUser(c);
  if (isResponse(user)) return user;

  const slug = c.req.param('slug');
  const form = await c.req.formData();

  if (!(await verifyCsrfToken(c, String(form.get('csrf_token') ?? '')))) {
    return c.redirect(`/trips/${encodeURIComponent(slug)}/book?msg=csrf`, 303);
  }

  const trip = await getTripBySlug(c.env.DB, slug, nowUtc());
  if (!trip) return c.redirect('/?msg=trip_not_found', 303);

  const values: BookingFormValues = {
    representativeName: String(form.get('representative_name') ?? ''),
    phone: String(form.get('phone') ?? ''),
    partySize: Number(form.get('party_size') ?? 0),
    companionNames: form
      .getAll('companion_names')
      .map((value) => String(value))
      .slice(0, MAX_PARTY_SIZE - 1),
    agreed: form.get('agreed') !== null,
  };

  const result = await createBooking(c.env.DB, {
    tripId: trip.id,
    userId: user.id,
    source: 'line',
    representativeName: values.representativeName,
    phone: values.phone,
    partySize: values.partySize,
    companionNames: values.companionNames,
    agreed: values.agreed,
  });

  if (!result.ok) {
    // 入力エラーは入力値を保持して再表示、それ以外はトップ等へ誘導
    if (result.code === 'VALIDATION' || result.code === 'NOT_AGREED') {
      const csrfToken = await ensureCsrfToken(c);
      const latest = (await getTripBySlug(c.env.DB, slug, nowUtc())) ?? trip;
      return c.html(
        bookingFormPage({
          trip: latest,
          values,
          csrfToken,
          userName: user.line_display_name,
          isLineFriend: user.is_line_friend,
          alert: { type: 'error', message: result.message },
        }),
        400,
      );
    }
    return c.redirect(`/?msg=${bookingErrorToCode(result.code)}`, 303);
  }

  scheduleConfirmation(c, result.bookingId);
  return c.redirect(`/bookings/${result.bookingId}?completed=1`, 303);
});

bookingRoutes.get('/my-bookings', async (c) => {
  const user = await requireUser(c);
  if (isResponse(user)) return user;

  const bookings = await listBookingsByUser(c.env.DB, user.id);
  const csrfToken = await ensureCsrfToken(c);

  return c.html(
    myBookingsPage({
      bookings,
      csrfToken,
      userName: user.line_display_name,
      nowUtc: nowUtc(),
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

bookingRoutes.get('/bookings/:id', async (c) => {
  const user = await requireUser(c);
  if (isResponse(user)) return user;

  const bookingId = Number(c.req.param('id'));
  if (!Number.isInteger(bookingId)) return c.redirect('/my-bookings?msg=not_found', 303);

  // 所有者以外には存在を明かさない
  const booking = await getOwnedBooking(c.env.DB, bookingId, user.id);
  if (!booking) return c.notFound();

  const csrfToken = await ensureCsrfToken(c);
  const justCompleted = c.req.query('completed') === '1';
  const notificationNote =
    justCompleted && user.is_line_friend === 0
      ? 'LINEでの通知をご希望の場合は、草加健康センター公式アカウントを友だち追加してください。'
      : null;

  return c.html(
    bookingDetailPage({
      booking,
      csrfToken,
      userName: user.line_display_name,
      justCompleted,
      notificationNote,
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

bookingRoutes.post('/bookings/:id/cancel', async (c) => {
  const user = await requireUser(c);
  if (isResponse(user)) return user;

  const bookingId = Number(c.req.param('id'));
  const form = await c.req.formData();
  if (!(await verifyCsrfToken(c, String(form.get('csrf_token') ?? '')))) {
    return c.redirect('/my-bookings?msg=csrf', 303);
  }
  if (!Number.isInteger(bookingId)) return c.redirect('/my-bookings?msg=not_found', 303);

  const result = await cancelBooking(c.env.DB, {
    bookingId,
    userId: user.id,
    asAdmin: false,
  });

  if (!result.ok) {
    const code = result.code === 'NOT_FOUND' || result.code === 'FORBIDDEN' ? 'not_found' : 'cancel_failed';
    return c.redirect(`/my-bookings?msg=${code}`, 303);
  }
  return c.redirect('/my-bookings?msg=cancelled', 303);
});
