<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 予約処理の中断。トランザクション内から投げてロールバックさせる。
 *
 * PHP 8.0 互換のため `readonly` を外している（8.1で追加された機能）。
 * これらのプロパティはコンストラクタでのみ設定し、以後書き換えない約束で扱う。
 * 型宣言は残しているため、誤った型の代入は 8.0 でも TypeError になる。
 */
final class BookingException extends \RuntimeException
{
    public function __construct(
        public string $errorCode,
        string $message,
        public ?int $slotId = null
    ) {
        parent::__construct($message);
    }
}
