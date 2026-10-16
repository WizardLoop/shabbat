<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Scheduling;

final class DeliveryDeferredException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAt)
    {
        parent::__construct($message);
    }
}
