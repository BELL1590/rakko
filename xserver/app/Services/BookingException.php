<?php

declare(strict_types=1);

namespace App\Services;

/** 予約処理の中断。トランザクション内から投げてロールバックさせる。 */
final class BookingException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $slotId = null
    ) {
        parent::__construct($message);
    }
}
