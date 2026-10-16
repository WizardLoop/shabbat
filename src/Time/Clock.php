<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Time;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
