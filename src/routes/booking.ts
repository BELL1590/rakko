/** 公開予約ページ・一括予約・マイ予約・キャンセル。 */

import { Hono } from 'hono';
import type { Context } from 'hono';
import type { AppEnv } from '../env';
import {
  getPageBySlug,
  getSlotByLegacyTripSlug,
  getUserById,
  listBookingsByGroup,
  listBookingsByUser,
  listSlotsByPage,
} from '../db/queries';
import type { SlotWithAvailability, UserRow } from '../db/types';
import { ensureCsrfToken, getUserSession, verifyCsrfToken } from '../services/session';
import {
  cancelBooking,
  createGroupBooking,
  getOwnedBooking,
  type BookingItemInput,
} from '../services/booking-service';
import { sendBookingConfirmation } from '../services/reminder-service';
import {
  emptyReserveValues,
  reservePage,
  type ReservePageValues,
} from '../views/reserve-page';
import { bookingDetailPage } from '../views/booking-detail';
import { myBookingsPage } from '../views/my-bookings';
import { alertFromCode } from '../lib/messages';
import { nowUtc } from '../lib/time';

export const bookingRoutes = new Hono<AppEnv>();

function loginUrlFor(c: Context<AppEnv>, path: string): string {
  return `/login?redirect_to=${encodeURIComponent(path)}&msg=login_required`;
}

/** ログイン必須。未ログインならログインページへ誘導する。 */
async function requireUser(c: Context<AppEnv>): Promise<UserRow | Response> {
  const session = await getUserSession(c);
  const user = session ? await getUserById(c.env.DB, session.uid) : null;
  if (!user) {
    const target = new URL(c.req.url);
    return c.redirect(loginUrlFor(c, `${target.pathname}${target.search}`), 303);
  }
  return user;
}

async function currentUser(c: Context<AppEnv>): Promise<UserRow | null> {
  const session = await getUserSession(c);
  return session ? await getUserById(c.env.DB, session.uid) : null;
}

function isResponse(value: unknown): value is Response {
  return value instanceof Response;
}

/** 予約完了通知はリクエストをブロックしない。失敗しても予約は維持する。 */
function scheduleConfirmation(c: Context<AppEnv>, bookingIds: number[]): void {
  const task = sendBookingConfirmation(c.env.DB, c.env, bookingIds).catch(() => undefined);
  try {
    c.executionCtx.waitUntil(task);
  } catch {
    // executionCtx が無い環境（テスト等）では何もしない
  }
}

/** フォーム入力から枠ごとの入力値を取り出す。 */
function parseSlotValues(
  form: FormData,
  slots: SlotWithAvailability[],
): { values: ReservePageValues; items: BookingItemInput[] } {
  const selected = new Set(
    form.getAll('slot_selected').map((value) => Number(String(value))),
  );

  const values: ReservePageValues = {
    representativeName: String(form.get('representative_name') ?? ''),
    phone: String(form.get('phone') ?? ''),
    agreed: form.get('agreed') !== null,
    slots: {},
  };
  const items: BookingItemInput[] = [];

  for (const slot of slots) {
    const partySize = Number(form.get(`party_size_${slot.id}`) ?? 1) || 1;
    const companionNames = form
      .getAll(`companion_${slot.id}`)
      .map((value) => String(value))
      .slice(0, Math.max(0, slot.max_party_size - 1));

    values.slots[slot.id] = {
      selected: selected.has(slot.id),
      partySize,
      companionNames,
    };
    if (selected.has(slot.id)) {
      items.push({ slotId: slot.id, partySize, companionNames });
    }
  }

  return { values, items };
}

// ---------------------------------------------------------------------------
// 公開予約ページ
// ---------------------------------------------------------------------------

bookingRoutes.get('/reserve/:slug', async (c) => {
  const slug = c.req.param('slug');
  const now = nowUtc();
  const page = await getPageBySlug(c.env.DB, slug);
  if (!page) return c.notFound();

  const user = await currentUser(c);
  // 下書き・アーカイブは一般公開しない
  if (page.status !== 'published' && !user) return c.notFound();

  const slots = await listSlotsByPage(c.env.DB, page.id, now);
  const csrfToken = await ensureCsrfToken(c);
  const loggedIn = page.requires_line_login === 0 ? true : user !== null;

  return c.html(
    reservePage({
      page,
      slots,
      values: emptyReserveValues(),
      csrfToken,
      userName: user?.line_display_name ?? null,
      isLineFriend: user?.is_line_friend ?? null,
      loggedIn,
      loginUrl: loginUrlFor(c, `/reserve/${slug}`),
      alert: alertFromCode(c.req.query('msg')),
    }),
  );
});

bookingRoutes.post('/reserve/:slug/book', async (c) => {
  const slug = c.req.param('slug');
  const page = await getPageBySlug(c.env.DB, slug);
  if (!page) return c.notFound();

  const user =
    page.requires_line_login === 1 ? await requireUser(c) : await currentUser(c);
  if (isResponse(user)) return user;

  const now = nowUtc();
  const form = await c.req.formData();
  if (!(await verifyCsrfToken(c, String(form.get('csrf_token') ?? '')))) {
    return c.redirect(`/reserve/${encodeURIComponent(slug)}?msg=csrf`, 303);
  }

  const slots = await listSlotsByPage(c.env.DB, page.id, now);
  const { values, items } = parseSlotValues(form, slots);

  const result = await createGroupBooking(
    c.env.DB,
    {
      pageId: page.id,
      userId: user?.id ?? null,
      source: 'line',
      representativeName: values.representativeName,
      phone: values.phone,
      agreed: values.agreed,
      items,
    },
    now,
  );

  if (!result.ok) {
    // 入力し直せるよう、選択内容を保持したままエラーを表示する
    const latestSlots = await listSlotsByPage(c.env.DB, page.id, now);
    const csrfToken = await ensureCsrfToken(c);
    return c.html(
      reservePage({
        page,
        slots: latestSlots,
        values,
        csrfToken,
        userName: user?.line_display_name ?? null,
        isLineFriend: user?.is_line_friend ?? null,
        loggedIn: true,
        loginUrl: loginUrlFor(c, `/reserve/${slug}`),
        alert: { type: 'error', message: result.message },
      }),
      400,
    );
  }

  scheduleConfirmation(c, result.bookingIds);
  return c.redirect(`/bookings/${result.bookingIds[0]}?completed=1`, 303);
});

/** 旧URL `/trips/:slug/book` の互換導線。 */
bookingRoutes.get('/trips/:slug/book', async (c) => {
  const user = await requireUser(c);
  if (isResponse(user)) return user;

  const slot = await getSlotByLegacyTripSlug(c.env.DB, c.req.param('slug'), nowUtc());
  if (!slot) return c.redirect('/?msg=slot_not_found', 303);
  return c.redirect(`/reserve/${encodeURIComponent(slot.page_slug)}`, 303);
});

// ---------------------------------------------------------------------------
// マイ予約 / 予約詳細 / キャンセル
// ---------------------------------------------------------------------------

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

  const groupBookings = booking.booking_group_id
    ? (await listBookingsByGroup(c.env.DB, booking.booking_group_id)).filter(
        (entry) => entry.user_id === user.id,
      )
    : [];

  const csrfToken = await ensureCsrfToken(c);
  const justCompleted = c.req.query('completed') === '1';
  const notificationNote =
    justCompleted && user.is_line_friend === 0
      ? 'LINEでの通知をご希望の場合は、草加健康センター公式アカウントを友だち追加してください。'
      : null;

  return c.html(
    bookingDetailPage({
      booking,
      groupBookings,
      csrfToken,
      userName: user.line_display_name,
      justCompleted,
      notificationNote,
      nowUtc: nowUtc(),
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
    const code =
      result.code === 'NOT_FOUND' || result.code === 'FORBIDDEN' ? 'not_found' : 'cancel_failed';
    return c.redirect(`/my-bookings?msg=${code}`, 303);
  }
  return c.redirect('/my-bookings?msg=cancelled', 303);
});
