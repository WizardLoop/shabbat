<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Time;

final class FrozenClock implements Clock
{
    public function __construct(private \DateTimeImmutable $instant)
    {
    }
    public function now(): \DateTimeImmutable
    {
        return $this->instant->setTimezone(new \DateTimeZone('UTC'));
    }
    public function set(\DateTimeImmutable $instant): void
    {
        $this->instant = $instant;
    }
}
