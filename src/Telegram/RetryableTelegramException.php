<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Telegram;

final class RetryableTelegramException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 30, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
