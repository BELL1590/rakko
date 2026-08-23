<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Session;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\BookingRepository;
use App\Repositories\SlotRepository;
use App\Repositories\UserRepository;
use App\Services\BookingService;
use App\Services\ReminderService;
use App\Support\Config;
use App\Support\Messages;
use App\Support\Time;
use App\Views\BookingDetailView;
use App\Views\MyBookingsView;
use App\Views\ReserveView;

/** 公開予約ページ・一括予約・マイ予約・キャンセル。 */
final class BookingController
{
    public function __construct(
        private Config $config,
        private SlotRepository $slots,
        private BookingRepository $bookings,
        private UserRepository $users,
        private BookingService $booking,
        private ReminderService $reminders,
        private Session $session,
    ) {
    }

    private static function loginUrlFor(string $path): string
    {
        return '/login?redirect_to=' . rawurlencode($path) . '&msg=login_required';
    }

    /** @return array<string, mixed>|null */
    private function currentUser(): ?array
    {
        $userId = $this->session->userId();
        return $userId !== null ? $this->users->findById($userId) : null;
    }

    /**
     * ログイン必須。未ログインならログインページへ誘導する。
     *
     * @return array<string, mixed>|Response
     */
    private function requireUser(Request $request): array|Response
    {
        $user = $this->currentUser();
        if ($user !== null) {
            return $user;
        }
        $target = $request->path;
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($qs !== '') {
            $target .= '?' . $qs;
        }
        return Response::redirect(self::loginUrlFor($target));
    }

    /**
     * フォーム入力から枠ごとの入力値を取り出す。
     *
     * @param list<array<string, mixed>> $slots
     * @return array{values: array<string, mixed>, items: list<array{slot_id: int, party_size: int, companion_names: list<string>}>}
     */
    public static function parseSlotValues(Request $request, array $slots): array
    {
        $selected = [];
        foreach ($request->all('slot_selected') as $raw) {
            $selected[(int) $raw] = true;
        }

        $values = [
            'representative_name' => $request->str('representative_name'),
            'phone' => $request->str('phone'),
            'agreed' => $request->has('agreed'),
            'slots' => [],
        ];
        $items = [];

        foreach ($slots as $slot) {
            $slotId = (int) $slot['id'];
            $partySize = $request->int('party_size_' . $slotId, 1);
            if ($partySize < 1) {
                $partySize = 1;
            }
            $companionNames = array_slice(
                $request->all('companion_' . $slotId),
                0,
                max(0, (int) $slot['max_party_size'] - 1),
            );

            $values['slots'][$slotId] = [
                'selected' => isset($selected[$slotId]),
                'party_size' => $partySize,
                'companion_names' => $companionNames,
            ];
            if (isset($selected[$slotId])) {
                $items[] = [
                    'slot_id' => $slotId,
                    'party_size' => $partySize,
                    'companion_names' => $companionNames,
                ];
            }
        }

        return ['values' => $values, 'items' => $items];
    }

    // -----------------------------------------------------------------
    // 公開予約ページ
    // -----------------------------------------------------------------

    /** @param array<string, string> $params */
    public function reservePage(Request $request, array $params): Response
    {
        $slug = $params['slug'];
        $now = Time::nowUtc();
        $page = $this->slots->findPageBySlug($slug);
        if ($page === null) {
            return PublicController::notFound();
        }

        $user = $this->currentUser();
        // 下書き・アーカイブは一般公開しない
        if ($page['status'] !== 'published' && $user === null) {
            return PublicController::notFound();
        }

        $slots = $this->slots->listSlotsByPage((int) $page['id'], $now);
        $loggedIn = (int) $page['requires_line_login'] === 0 ? true : $user !== null;

        return Response::html(ReserveView::render(
            $page,
            $slots,
            ReserveView::emptyValues(),
            $this->session->csrfToken(),
            $user['line_display_name'] ?? null,
            isset($user['is_line_friend']) && $user['is_line_friend'] !== null
                ? (int) $user['is_line_friend']
                : null,
            $loggedIn,
            self::loginUrlFor('/reserve/' . $slug),
            $now,
            Messages::fromCode($request->query('msg')),
            $this->config->lineFriendUrl(),
        ));
    }

    /** @param array<string, string> $params */
    public function book(Request $request, array $params): Response
    {
        $slug = $params['slug'];
        $page = $this->slots->findPageBySlug($slug);
        if ($page === null) {
            return PublicController::notFound();
        }

        if ((int) $page['requires_line_login'] === 1) {
            $user = $this->requireUser($request);
            if ($user instanceof Response) {
                return $user;
            }
        } else {
            $user = $this->currentUser();
        }

        if (!$this->session->verifyCsrf($request->input('csrf_token'))) {
            return Response::redirect('/reserve/' . rawurlencode($slug) . '?msg=csrf');
        }

        $now = Time::nowUtc();
        $slots = $this->slots->listSlotsByPage((int) $page['id'], $now);
        ['values' => $values, 'items' => $items] = self::parseSlotValues($request, $slots);

        $result = $this->booking->createGroupBooking([
            'page_id' => (int) $page['id'],
            'user_id' => isset($user['id']) ? (int) $user['id'] : null,
            'source' => 'line',
            'representative_name' => $values['representative_name'],
            'phone' => $values['phone'],
            'agreed' => (bool) $values['agreed'],
            'items' => $items,
        ], $now);

        if ($result['ok'] !== true) {
            // 入力し直せるよう、選択内容を保持したままエラーを表示する
            $latestSlots = $this->slots->listSlotsByPage((int) $page['id'], $now);
            return Response::html(
                ReserveView::render(
                    $page,
                    $latestSlots,
                    $values,
                    $this->session->csrfToken(),
                    $user['line_display_name'] ?? null,
                    isset($user['is_line_friend']) && $user['is_line_friend'] !== null
                        ? (int) $user['is_line_friend']
                        : null,
                    true,
                    self::loginUrlFor('/reserve/' . $slug),
                    $now,
                    ['type' => 'error', 'message' => $result['message']],
                    $this->config->lineFriendUrl(),
                ),
                400,
            );
        }

        // 予約完了通知は失敗しても予約を維持する
        try {
            $this->reminders->sendBookingConfirmation($result['booking_ids'], $now);
        } catch (\Throwable) {
            // 通知失敗は予約成功を妨げない
        }

        return Response::redirect('/bookings/' . $result['booking_ids'][0] . '?completed=1');
    }

    /** 旧URL `/trips/{slug}/book` の互換導線。 */
    public function legacyTripBook(Request $request, array $params): Response
    {
        $user = $this->requireUser($request);
        if ($user instanceof Response) {
            return $user;
        }
        $slot = $this->slots->findSlotByLegacyTripSlug($params['slug'], Time::nowUtc());
        if ($slot === null) {
            return Response::redirect('/?msg=slot_not_found');
        }
        return Response::redirect('/reserve/' . rawurlencode((string) $slot['page_slug']));
    }

    // -----------------------------------------------------------------
    // マイ予約 / 予約詳細 / キャンセル
    // -----------------------------------------------------------------

    public function myBookings(Request $request): Response
    {
        $user = $this->requireUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        return Response::html(MyBookingsView::render(
            $this->bookings->listByUser((int) $user['id']),
            $user['line_display_name'] ?? null,
            Time::nowUtc(),
            Messages::fromCode($request->query('msg')),
        ));
    }

    /** @param array<string, string> $params */
    public function detail(Request $request, array $params): Response
    {
        $user = $this->requireUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        if (!ctype_digit($params['id'])) {
            return Response::redirect('/my-bookings?msg=not_found');
        }
        $bookingId = (int) $params['id'];

        // 所有者以外には存在を明かさない
        $booking = $this->booking->findOwnedBooking($bookingId, (int) $user['id']);
        if ($booking === null) {
            return PublicController::notFound();
        }

        $groupBookings = [];
        if (($booking['booking_group_id'] ?? null) !== null) {
            $groupBookings = array_values(array_filter(
                $this->bookings->listByGroup((string) $booking['booking_group_id']),
                static fn (array $entry): bool => (int) ($entry['user_id'] ?? 0) === (int) $user['id'],
            ));
        }

        $justCompleted = $request->query('completed') === '1';
        $notificationNote = $justCompleted && (int) ($user['is_line_friend'] ?? -1) === 0
            ? 'LINEでの通知をご希望の場合は、予約専用LINE公式アカウントを友だち追加してください。'
            : null;

        return Response::html(BookingDetailView::render(
            $booking,
            $groupBookings,
            $this->session->csrfToken(),
            $user['line_display_name'] ?? null,
            $justCompleted,
            $notificationNote,
            Time::nowUtc(),
            Messages::fromCode($request->query('msg')),
        ));
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $user = $this->requireUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->session->verifyCsrf($request->input('csrf_token'))) {
            return Response::redirect('/my-bookings?msg=csrf');
        }
        if (!ctype_digit($params['id'])) {
            return Response::redirect('/my-bookings?msg=not_found');
        }

        $result = $this->booking->cancelBooking((int) $params['id'], (int) $user['id'], false);
        if ($result['ok'] !== true) {
            $code = in_array($result['code'], ['NOT_FOUND', 'FORBIDDEN'], true)
                ? 'not_found'
                : 'cancel_failed';
            return Response::redirect('/my-bookings?msg=' . $code);
        }
        return Response::redirect('/my-bookings?msg=cancelled');
    }
}
