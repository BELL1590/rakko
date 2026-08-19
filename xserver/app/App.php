<?php

declare(strict_types=1);

namespace App;

use App\Auth\AdminAuth;
use App\Auth\LineLogin;
use App\Auth\Session;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\BookingController;
use App\Controllers\PublicController;
use App\Database\Db;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repositories\BookingRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\SlotRepository;
use App\Repositories\UserRepository;
use App\Services\BookingService;
use App\Services\CurlHttpClient;
use App\Services\HttpClient;
use App\Services\LineMessenger;
use App\Services\ReminderService;
use App\Support\Config;
use App\Support\ConfigError;

/**
 * 依存の組み立てとルーティング。
 *
 * URLは Cloudflare Workers 版（src/index.ts + src/routes/*.ts）と1対1で対応させる。
 * public/index.php と tests/ の両方がここを通るため、
 * 「テストで通った経路」と「本番で動く経路」が同一になる。
 */
final class App
{
    public readonly SlotRepository $slots;
    public readonly BookingRepository $bookings;
    public readonly UserRepository $users;
    public readonly NotificationRepository $notifications;
    public readonly BookingService $booking;
    public readonly ReminderService $reminders;
    public readonly LineMessenger $messenger;
    public readonly Session $session;
    public readonly AdminAuth $adminAuth;
    public readonly ?LineLogin $lineLogin;

    private readonly Router $router;

    public function __construct(
        public readonly Config $config,
        public readonly Db $db,
        ?HttpClient $http = null,
    ) {
        $http ??= new CurlHttpClient();

        $this->slots = new SlotRepository($db);
        $this->bookings = new BookingRepository($db);
        $this->users = new UserRepository($db);
        $this->notifications = new NotificationRepository($db);

        $this->booking = new BookingService($db, $this->slots, $this->bookings);
        $this->messenger = new LineMessenger($config, $http);
        $this->reminders = new ReminderService(
            $config,
            $this->bookings,
            $this->notifications,
            $this->users,
            $this->messenger,
        );

        $this->session = new Session($config);
        $this->adminAuth = new AdminAuth($config);
        $this->lineLogin = $config->hasLineLogin() ? new LineLogin($config, $http) : null;

        $this->router = $this->buildRouter();
    }

    public function handle(Request $request): Response
    {
        try {
            // production で DEMO_MODE が有効なら、いかなるリクエストも処理しない
            $this->config->assertDemoModeSafety();

            if (str_starts_with($request->path, '/admin')) {
                $guard = $this->adminController()->guard($request);
                if ($guard !== null) {
                    return $guard;
                }
            }

            $response = $this->router->dispatch($request);
            return $response ?? PublicController::notFound();
        } catch (ConfigError $e) {
            // 設定ミスは内容を伏せずに運用者へ伝える（機密値は含めない）
            return Response::text('Configuration error: ' . $e->getMessage(), 500);
        } catch (\Throwable $e) {
            error_log('[error] ' . $e::class . ': ' . $e->getMessage());
            return PublicController::serverError();
        }
    }

    public function publicController(): PublicController
    {
        return new PublicController($this->slots, $this->users, $this->session);
    }

    public function authController(): AuthController
    {
        return new AuthController($this->config, $this->session, $this->users, $this->lineLogin);
    }

    public function bookingController(): BookingController
    {
        return new BookingController(
            $this->slots,
            $this->bookings,
            $this->users,
            $this->booking,
            $this->reminders,
            $this->session,
        );
    }

    public function adminController(): AdminController
    {
        return new AdminController(
            $this->config,
            $this->slots,
            $this->bookings,
            $this->notifications,
            $this->booking,
            $this->reminders,
            $this->session,
            $this->adminAuth,
        );
    }

    private function buildRouter(): Router
    {
        $router = new Router();

        // ---- 公開 -----------------------------------------------------
        $router->get('/', fn (Request $r, array $p) => $this->publicController()->home($r));
        $router->get('/healthz', fn (Request $r, array $p) => $this->publicController()->healthz());

        // ---- 認証 -----------------------------------------------------
        $router->get('/login', fn (Request $r, array $p) => $this->authController()->loginPage($r));
        $router->post('/auth/line/start', fn (Request $r, array $p) => $this->authController()->lineStart($r));
        $router->get('/auth/line/callback', fn (Request $r, array $p) => $this->authController()->lineCallback($r));
        $router->post('/auth/demo/login', fn (Request $r, array $p) => $this->authController()->demoLogin($r));
        $router->post('/logout', fn (Request $r, array $p) => $this->authController()->logout($r));

        // ---- 予約 -----------------------------------------------------
        $router->get('/reserve/{slug}', fn (Request $r, array $p) => $this->bookingController()->reservePage($r, $p));
        $router->post('/reserve/{slug}/book', fn (Request $r, array $p) => $this->bookingController()->book($r, $p));
        $router->get('/trips/{slug}/book', fn (Request $r, array $p) => $this->bookingController()->legacyTripBook($r, $p));
        $router->get('/my-bookings', fn (Request $r, array $p) => $this->bookingController()->myBookings($r));
        $router->get('/bookings/{id}', fn (Request $r, array $p) => $this->bookingController()->detail($r, $p));
        $router->post('/bookings/{id}/cancel', fn (Request $r, array $p) => $this->bookingController()->cancel($r, $p));

        // ---- 管理 -----------------------------------------------------
        $router->get('/admin/login', fn (Request $r, array $p) => $this->adminController()->loginPage($r));
        $router->post('/admin/login', fn (Request $r, array $p) => $this->adminController()->login($r));
        $router->post('/admin/logout', fn (Request $r, array $p) => $this->adminController()->logout($r));
        $router->get('/admin', fn (Request $r, array $p) => $this->adminController()->dashboard($r));
        $router->post('/admin/reminders/run', fn (Request $r, array $p) => $this->adminController()->runReminders($r));

        $router->get('/admin/reservations', fn (Request $r, array $p) => $this->adminController()->pagesList($r));
        $router->get('/admin/reservations/new', fn (Request $r, array $p) => $this->adminController()->pageNew($r));
        $router->post('/admin/reservations', fn (Request $r, array $p) => $this->adminController()->pageCreate($r));
        $router->get('/admin/reservations/{id}/roster.csv', fn (Request $r, array $p) => $this->adminController()->pageRosterCsv($r, $p));
        $router->get('/admin/reservations/{id}', fn (Request $r, array $p) => $this->adminController()->pageEdit($r, $p));
        $router->post('/admin/reservations/{id}', fn (Request $r, array $p) => $this->adminController()->pageUpdate($r, $p));
        $router->post('/admin/reservations/{id}/status', fn (Request $r, array $p) => $this->adminController()->pageStatus($r, $p));
        $router->post('/admin/reservations/{id}/duplicate', fn (Request $r, array $p) => $this->adminController()->pageDuplicate($r, $p));
        $router->post('/admin/reservations/{id}/slots', fn (Request $r, array $p) => $this->adminController()->slotCreate($r, $p));

        $router->get('/admin/reservation-slots/{id}/roster.csv', fn (Request $r, array $p) => $this->adminController()->slotRosterCsv($r, $p));
        $router->get('/admin/slots/{id}', fn (Request $r, array $p) => $this->adminController()->slotDetail($r, $p));
        $router->post('/admin/slots/{id}', fn (Request $r, array $p) => $this->adminController()->slotUpdate($r, $p));
        $router->post('/admin/slots/{id}/bookings', fn (Request $r, array $p) => $this->adminController()->proxyBooking($r, $p));

        $router->post('/admin/bookings/{id}/checkin', fn (Request $r, array $p) => $this->adminController()->checkin($r, $p));
        $router->post('/admin/bookings/{id}/cancel', fn (Request $r, array $p) => $this->adminController()->cancelBooking($r, $p));
        $router->get('/admin/trips/{slug}', fn (Request $r, array $p) => $this->adminController()->legacyTrip($r, $p));

        return $router;
    }
}
