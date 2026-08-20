<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Session;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\SlotRepository;
use App\Repositories\UserRepository;
use App\Support\Messages;
use App\Support\Time;
use App\Views\HomeView;
use App\Views\Layout;

/** 公開トップと死活監視。 */
final class PublicController
{
    public function __construct(
        private SlotRepository $slots,
        private UserRepository $users,
        private Session $session,
    ) {
    }

    public function home(Request $request): Response
    {
        $now = Time::nowUtc();
        $entries = [];
        foreach ($this->slots->listPublishedPages() as $page) {
            $slots = array_values(array_filter(
                $this->slots->listSlotsByPage((int) $page['id'], $now),
                static fn (array $slot): bool => !empty($slot['is_visible']),
            ));
            $entries[] = ['page' => $page, 'slots' => $slots];
        }

        $userId = $this->session->userId();
        $user = $userId !== null ? $this->users->findById($userId) : null;

        return Response::html(HomeView::render(
            $entries,
            $user['line_display_name'] ?? null,
            $now,
            Messages::fromCode($request->query('msg')),
        ));
    }

    public function healthz(): Response
    {
        return Response::json(['ok' => true]);
    }

    public static function notFound(): Response
    {
        return Response::html(
            Layout::render(
                ['title' => 'ページが見つかりません | 草加健康センター 予約センター'],
                '<h2>ページが見つかりません</h2>
       <div class="card"><p>お探しのページは存在しないか、アクセス権限がありません。</p>
       <a class="btn" href="/">トップへ戻る</a></div>',
            ),
            404,
        );
    }

    public static function serverError(): Response
    {
        return Response::html(
            Layout::render(
                ['title' => 'エラー | 草加健康センター 予約センター'],
                '<h2>エラーが発生しました</h2>
       <div class="card"><p>時間をおいて再度お試しください。</p>
       <a class="btn" href="/">トップへ戻る</a></div>',
            ),
            500,
        );
    }
}
