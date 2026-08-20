<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AdminAuth;
use App\Auth\Session;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\BookingRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\SlotRepository;
use App\Services\BookingService;
use App\Services\CsvService;
use App\Services\ReminderService;
use App\Support\Config;
use App\Support\Messages;
use App\Support\Time;
use App\Views\admin\AdminDashboardView;
use App\Views\admin\AdminPagesView;
use App\Views\admin\AdminSlotDetailView;

/** 管理画面。単一管理者のユーザー名/パスワード認証 + 署名付きCookie。 */
final class AdminController
{
    private const SLUG_PATTERN = '/^[a-z0-9-]{1,60}$/';
    private const PAGE_STATUSES = ['draft', 'published', 'closed', 'archived'];
    private const PAGE_TYPES = ['bus', 'event', 'time_slot', 'other'];
    private const BOOKING_STATUSES = ['open', 'closed', 'hidden'];

    public function __construct(
        private Config $config,
        private SlotRepository $slots,
        private BookingRepository $bookings,
        private NotificationRepository $notifications,
        private BookingService $booking,
        private ReminderService $reminders,
        private Session $session,
        private AdminAuth $auth,
    ) {
    }

    /**
     * 管理画面の認証ガード。`/admin/login` 以外はすべて認証必須。
     * 認証済みなら null を返す。
     */
    public function guard(Request $request): ?Response
    {
        if ($request->path === '/admin/login') {
            return null;
        }
        if ($this->session->adminUser() !== null) {
            return null;
        }
        if ($request->method !== 'GET') {
            return Response::text('Unauthorized', 401);
        }
        return Response::redirect('/admin/login?msg=admin_login_required');
    }

    private function csrfOk(Request $request): bool
    {
        return $this->session->verifyCsrf($request->input('csrf_token'));
    }

    private static function optionalText(Request $request, string $key, int $max = 200): ?string
    {
        $value = trim($request->str($key));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function optionalDatetime(Request $request, string $key): ?string
    {
        $value = trim($request->str($key));
        return $value === '' ? null : Time::fromJstDatetimeLocal($value);
    }

    // -----------------------------------------------------------------
    // 認証
    // -----------------------------------------------------------------

    public function loginPage(Request $request): Response
    {
        if ($this->session->adminUser() !== null) {
            return Response::redirect('/admin');
        }
        return Response::html(AdminDashboardView::login(
            $this->session->csrfToken(),
            Messages::fromCode($request->query('msg')),
        ));
    }

    public function login(Request $request): Response
    {
        if (!$this->csrfOk($request)) {
            return Response::redirect('/admin/login?msg=csrf');
        }
        if (!$this->auth->isConfigured()) {
            return Response::redirect('/admin/login?msg=admin_not_configured');
        }
        $username = $request->str('username');
        if (!$this->auth->verify($username, $request->str('password'))) {
            return Response::redirect('/admin/login?msg=admin_login_failed');
        }
        $this->session->startAdminSession($username);
        return Response::redirect('/admin');
    }

    public function logout(Request $request): Response
    {
        $this->session->clearAdminSession();
        return Response::redirect('/admin/login?msg=admin_logged_out');
    }

    // -----------------------------------------------------------------
    // ダッシュボード
    // -----------------------------------------------------------------

    public function dashboard(Request $request): Response
    {
        $now = Time::nowUtc();
        $entries = [];
        foreach ($this->slots->listPagesWithStats() as $page) {
            if ($page['status'] === 'archived') {
                continue;
            }
            $entries[] = [
                'page' => $page,
                'slots' => $this->slots->listSlotsByPage((int) $page['id'], $now),
            ];
        }

        return Response::html(AdminDashboardView::dashboard(
            $entries,
            $now,
            Messages::fromCode($request->query('msg')),
        ));
    }

    public function runReminders(Request $request): Response
    {
        $this->reminders->processDueReminders();
        return Response::redirect('/admin?msg=reminder_done');
    }

    // -----------------------------------------------------------------
    // 予約ページ
    // -----------------------------------------------------------------

    public function pagesList(Request $request): Response
    {
        return Response::html(AdminPagesView::list(
            $this->slots->listPagesWithStats(),
            $this->config->baseUrl(),
            $this->session->csrfToken(),
            Messages::fromCode($request->query('msg')),
        ));
    }

    public function pageNew(Request $request): Response
    {
        return Response::html(AdminPagesView::form(
            null,
            [],
            $this->session->csrfToken(),
            $this->config->baseUrl(),
            Messages::fromCode($request->query('msg')),
        ));
    }

    /**
     * @return array<string, mixed>|array{error: string}
     */
    public static function readPageInput(Request $request): array
    {
        $slug = strtolower(trim($request->str('slug')));
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return ['error' => 'slug_invalid'];
        }

        $title = trim($request->str('title'));
        if ($title === '') {
            return ['error' => 'save_failed'];
        }

        $status = $request->str('status', 'draft');
        if (!in_array($status, self::PAGE_STATUSES, true)) {
            return ['error' => 'save_failed'];
        }
        $pageType = $request->str('page_type', 'other');
        if (!in_array($pageType, self::PAGE_TYPES, true)) {
            return ['error' => 'save_failed'];
        }

        $rawMaxSlots = $request->str('max_slots_per_checkout', '4');
        if (!ctype_digit($rawMaxSlots)) {
            return ['error' => 'save_failed'];
        }
        $maxSlots = (int) $rawMaxSlots;
        if ($maxSlots < 1 || $maxSlots > 20) {
            return ['error' => 'save_failed'];
        }

        $checkinLabel = trim($request->str('checkin_label'));

        return [
            'slug' => $slug,
            'title' => mb_substr($title, 0, 80),
            'description' => mb_substr(trim($request->str('description')), 0, 300),
            'status' => $status,
            'page_type' => $pageType,
            'allow_multi_slot_booking' => $request->has('allow_multi_slot_booking'),
            'requires_line_login' => $request->has('requires_line_login'),
            'max_slots_per_checkout' => $maxSlots,
            'checkin_label' => mb_substr($checkinLabel !== '' ? $checkinLabel : '受付', 0, 10),
        ];
    }

    public function pageCreate(Request $request): Response
    {
        if (!$this->csrfOk($request)) {
            return Response::redirect('/admin/reservations?msg=csrf');
        }
        $input = self::readPageInput($request);
        if (isset($input['error'])) {
            return Response::redirect('/admin/reservations/new?msg=' . $input['error']);
        }
        if ($this->slots->findPageBySlug((string) $input['slug']) !== null) {
            return Response::redirect('/admin/reservations/new?msg=slug_taken');
        }

        $pageId = $this->slots->createPage($input);
        return Response::redirect('/admin/reservations/' . $pageId . '?msg=page_created');
    }

    /** @param array<string, string> $params */
    public function pageEdit(Request $request, array $params): Response
    {
        $page = $this->slots->findPageById((int) $params['id']);
        if ($page === null) {
            return Response::redirect('/admin/reservations?msg=page_not_found');
        }

        return Response::html(AdminPagesView::form(
            $page,
            $this->slots->listSlotsByPage((int) $page['id'], Time::nowUtc()),
            $this->session->csrfToken(),
            $this->config->baseUrl(),
            Messages::fromCode($request->query('msg')),
        ));
    }

    /** @param array<string, string> $params */
    public function pageUpdate(Request $request, array $params): Response
    {
        $pageId = (int) $params['id'];
        if (!$this->csrfOk($request)) {
            return Response::redirect('/admin/reservations/' . $pageId . '?msg=csrf');
        }
        if ($this->slots->findPageById($pageId) === null) {
            return Response::redirect('/admin/reservations?msg=page_not_found');
        }

        $input = self::readPageInput($request);
        if (isset($input['error'])) {
            return Response::redirect('/admin/reservations/' . $pageId . '?msg=' . $input['error']);
        }

        $existing = $this->slots->findPageBySlug((string) $input['slug']);
        if ($existing !== null && (int) $existing['id'] !== $pageId) {
            return Response::redirect('/admin/reservations/' . $pageId . '?msg=slug_taken');
        }

        $this->slots->updatePage($pageId, $input);
        return Response::redirect('/admin/reservations/' . $pageId . '?msg=saved');
    }

    /** @param array<string, string> $params */
    public function pageStatus(Request $request, array $params): Response
    {
        if (!$this->csrfOk($request)) {
            return Response::redirect('/admin/reservations?msg=csrf');
        }
        $status = $request->str('status');
        if (!in_array($status, self::PAGE_STATUSES, true)) {
            return Response::redirect('/admin/reservations?msg=save_failed');
        }
        $this->slots->updatePageStatus((int) $params['id'], $status);
        return Response::redirect('/admin/reservations?msg=saved');
    }

    /** ページと枠をまとめて複製する（下書きとして作成）。 */
    public function pageDuplicate(Request $request, array $params): Response
    {
        if (!$this->csrfOk($request)) {
            return Response::redirect('/admin/reservations?msg=csrf');
        }
        $pageId = (int) $params['id'];
        $page = $this->slots->findPageById($pageId);
        if ($page === null) {
            return Response::redirect('/admin/reservations?msg=page_not_found');
        }

        $slug = $page['slug'] . '-copy';
        $suffix = 2;
        while ($this->slots->findPageBySlug($slug) !== null) {
            $slug = $page['slug'] . '-copy' . $suffix;
            $suffix++;
            if ($suffix > 50) {
                return Response::redirect('/admin/reservations?msg=slug_taken');
            }
        }

        $newPageId = $this->slots->createPage([
            'slug' => $slug,
            'title' => $page['title'] . '（複製）',
            'description' => $page['description'],
            'status' => 'draft',
            'page_type' => $page['page_type'],
            'allow_multi_slot_booking' => (int) $page['allow_multi_slot_booking'] === 1,
            'requires_line_login' => (int) $page['requires_line_login'] === 1,
            'max_slots_per_checkout' => (int) $page['max_slots_per_checkout'],
            'checkin_label' => $page['checkin_label'],
        ]);

        foreach ($this->slots->listSlotsByPage($pageId, Time::nowUtc()) as $slot) {
            $this->slots->createSlot($newPageId, [
                'name' => $slot['name'],
                'description' => $slot['description'],
                'start_at' => $slot['start_at'],
                'end_at' => $slot['end_at'],
                'origin' => $slot['origin'],
                'destination' => $slot['destination'],
                'location' => $slot['location'],
                'capacity' => (int) $slot['capacity'],
                'max_party_size' => (int) $slot['max_party_size'],
                'booking_open_at' => $slot['booking_open_at'],
                'booking_close_at' => $slot['booking_close_at'],
                'reminder_at' => $slot['reminder_at'],
                'booking_status' => $slot['booking_status'],
                'sort_order' => (int) $slot['sort_order'],
            ]);
        }

        return Response::redirect('/admin/reservations/' . $newPageId . '?msg=page_duplicated');
    }

    // -----------------------------------------------------------------
    // 予約枠
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>|array{error: string}
     */
    public static function readSlotInput(Request $request): array
    {
        $name = trim($request->str('name'));
        if ($name === '') {
            return ['error' => 'save_failed'];
        }

        $startAt = self::optionalDatetime($request, 'start_at');
        if ($startAt === null) {
            return ['error' => 'save_failed'];
        }

        $rawCapacity = $request->str('capacity');
        if (!ctype_digit($rawCapacity)) {
            return ['error' => 'save_failed'];
        }
        $capacity = (int) $rawCapacity;
        if ($capacity < 0 || $capacity > 500) {
            return ['error' => 'save_failed'];
        }

        $rawMaxParty = $request->str('max_party_size');
        if (!ctype_digit($rawMaxParty)) {
            return ['error' => 'save_failed'];
        }
        $maxPartySize = (int) $rawMaxParty;
        if ($maxPartySize < 1 || $maxPartySize > 20) {
            return ['error' => 'save_failed'];
        }

        $bookingStatus = $request->str('booking_status', 'open');
        if (!in_array($bookingStatus, self::BOOKING_STATUSES, true)) {
            return ['error' => 'save_failed'];
        }

        $rawSortOrder = $request->str('sort_order', '0');
        if (!ctype_digit($rawSortOrder)) {
            return ['error' => 'save_failed'];
        }
        $sortOrder = (int) $rawSortOrder;
        if ($sortOrder < 0 || $sortOrder > 999) {
            return ['error' => 'save_failed'];
        }

        return [
            'name' => mb_substr($name, 0, 60),
            'description' => mb_substr(trim($request->str('description')), 0, 200),
            'start_at' => $startAt,
            'end_at' => self::optionalDatetime($request, 'end_at'),
            'origin' => self::optionalText($request, 'origin', 100),
            'destination' => self::optionalText($request, 'destination', 100),
            'location' => self::optionalText($request, 'location', 100),
            'capacity' => $capacity,
            'max_party_size' => $maxPartySize,
            'booking_open_at' => self::optionalDatetime($request, 'booking_open_at'),
            'booking_close_at' => self::optionalDatetime($request, 'booking_close_at'),
            'reminder_at' => self::optionalDatetime($request, 'reminder_at'),
            'booking_status' => $bookingStatus,
            'sort_order' => $sortOrder,
        ];
    }

    /** @param array<string, string> $params */
    public function slotCreate(Request $request, array $params): Response
    {
        $pageId = (int) $params['id'];
        if (!$this->csrfOk($request)) {
            return Response::redirect('/admin/reservations/' . $pageId . '?msg=csrf');
        }
        if ($this->slots->findPageById($pageId) === null) {
            return Response::redirect('/admin/reservations?msg=page_not_found');
        }

        $input = self::readSlotInput($request);
        if (isset($input['error'])) {
            return Response::redirect('/admin/reservations/' . $pageId . '?msg=' . $input['error']);
        }

        $this->slots->createSlot($pageId, $input);
        return Response::redirect('/admin/reservations/' . $pageId . '?msg=slot_created');
    }

    /** @param array<string, string> $params */
    public function slotDetail(Request $request, array $params): Response
    {
        $slotId = (int) $params['id'];
        $slot = $this->slots->findSlot($slotId, Time::nowUtc());
        if ($slot === null) {
            return Response::redirect('/admin/reservations?msg=slot_not_found');
        }
        $page = $this->slots->findPageById((int) $slot['reservation_page_id']);
        if ($page === null) {
            return Response::redirect('/admin/reservations?msg=page_not_found');
        }

        $search = $request->query('q') ?? '';

        return Response::html(AdminSlotDetailView::detail(
            $page,
            $slot,
            $this->bookings->listBySlot($slotId, $search !== '' ? $search : null),
            $this->notifications->listForSlot($slotId),
            $search,
            $this->session->csrfToken(),
            Messages::fromCode($request->query('msg')),
        ));
    }

    /** @param array<string, string> $params */
    public function slotUpdate(Request $request, array $params): Response
    {
        $slotId = (int) $params['id'];
        if (!$this->csrfOk($request)) {
            return Response::redirect('/admin/slots/' . $slotId . '?msg=csrf');
        }

        $slot = $this->slots->findSlot($slotId, Time::nowUtc());
        if ($slot === null) {
            return Response::redirect('/admin/reservations?msg=slot_not_found');
        }

        $input = self::readSlotInput($request);
        if (isset($input['error'])) {
            return Response::redirect('/admin/slots/' . $slotId . '?msg=' . $input['error']);
        }

        // 既存の確定予約を下回る定員は許可しない（定員超過状態を作らない）
        if ((int) $input['capacity'] < (int) $slot['booked_seats']) {
            return Response::redirect('/admin/slots/' . $slotId . '?msg=capacity_too_small');
        }

        $this->slots->updateSlot($slotId, $input);
        return Response::redirect('/admin/slots/' . $slotId . '?msg=saved');
    }

    /** 管理者代理予約。LINE通知は送らない。 */
    public function proxyBooking(Request $request, array $params): Response
    {
        $slotId = (int) $params['id'];
        if (!$this->csrfOk($request)) {
            return Response::redirect('/admin/slots/' . $slotId . '?msg=csrf');
        }

        $companionNames = array_values(array_filter(
            array_map(
                static fn (string $name): string => trim($name),
                preg_split('/[、,\n]/u', $request->str('companion_names_text')) ?: [],
            ),
            static fn (string $name): bool => $name !== '',
        ));

        $result = $this->booking->createBooking([
            'slot_id' => $slotId,
            'user_id' => null,
            'source' => 'admin',
            'representative_name' => $request->str('representative_name'),
            'phone' => $request->str('phone'),
            'party_size' => $request->int('party_size', 0),
            'companion_names' => $companionNames,
            'agreed' => true,
        ]);

        $code = $result['ok'] === true
            ? 'booking_created'
            : Messages::bookingErrorToCode((string) $result['code']);

        return Response::redirect('/admin/slots/' . $slotId . '?msg=' . $code);
    }

    // -----------------------------------------------------------------
    // 名簿CSV
    // -----------------------------------------------------------------

    /** @param array<string, string> $params */
    public function slotRosterCsv(Request $request, array $params): Response
    {
        $slotId = (int) $params['id'];
        if ($this->slots->findSlot($slotId, Time::nowUtc()) === null) {
            return Response::redirect('/admin/reservations?msg=slot_not_found');
        }
        $includeCancelled = $request->query('include') === 'cancelled';
        $bookings = $this->bookings->listRosterBySlot($slotId, $includeCancelled);

        return Response::csv(CsvService::buildRoster($bookings), CsvService::fileName('slot', $slotId));
    }

    /** @param array<string, string> $params */
    public function pageRosterCsv(Request $request, array $params): Response
    {
        $pageId = (int) $params['id'];
        if ($this->slots->findPageById($pageId) === null) {
            return Response::redirect('/admin/reservations?msg=page_not_found');
        }
        $includeCancelled = $request->query('include') === 'cancelled';
        $bookings = $this->bookings->listByPage($pageId, $includeCancelled);

        return Response::csv(CsvService::buildRoster($bookings), CsvService::fileName('event', $pageId));
    }

    // -----------------------------------------------------------------
    // 予約操作
    // -----------------------------------------------------------------

    private static function backToSlot(Request $request): string
    {
        $slotId = $request->int('slot_id', 0);
        return $slotId > 0 ? '/admin/slots/' . $slotId : '/admin';
    }

    /** @param array<string, string> $params */
    public function checkin(Request $request, array $params): Response
    {
        $back = self::backToSlot($request);
        if (!$this->csrfOk($request)) {
            return Response::redirect($back . '?msg=csrf');
        }

        $booking = $this->bookings->find((int) $params['id']);
        if ($booking === null) {
            return Response::redirect($back . '?msg=not_found');
        }

        $partySize = (int) $booking['party_size'];
        $current = (int) $booking['checked_in_count'];
        $next = match ($request->str('op')) {
            'inc' => min($partySize, $current + 1),
            'dec' => max(0, $current - 1),
            'all' => $partySize,
            default => null,
        };
        if ($next === null) {
            return Response::redirect($back . '?msg=save_failed');
        }

        $this->bookings->updateCheckedInCount((int) $params['id'], $next);
        return Response::redirect($back . '?msg=saved');
    }

    /** @param array<string, string> $params */
    public function cancelBooking(Request $request, array $params): Response
    {
        $back = self::backToSlot($request);
        if (!$this->csrfOk($request)) {
            return Response::redirect($back . '?msg=csrf');
        }

        $result = $this->booking->cancelBooking((int) $params['id'], null, true);
        return Response::redirect($back . '?msg=' . ($result['ok'] === true ? 'cancelled' : 'cancel_failed'));
    }

    /** 旧URL `/admin/trips/{slug}` の互換導線。 */
    public function legacyTrip(Request $request, array $params): Response
    {
        $slot = $this->slots->findSlotByLegacyTripSlug($params['slug'], Time::nowUtc());
        if ($slot === null) {
            return Response::redirect('/admin?msg=slot_not_found');
        }
        return Response::redirect('/admin/slots/' . (int) $slot['id']);
    }
}
