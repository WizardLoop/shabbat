<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

/** Listing only. Opening a group and all mutations require fresh authorization. */
interface GroupDiscoveryGateway
{
    public function isListedAdmin(string $userId, string $groupId): bool;
}
