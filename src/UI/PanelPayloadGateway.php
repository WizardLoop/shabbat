<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\UI;

/** A rich preview edits the already authenticated private panel, preserving native entities. */
interface PanelPayloadGateway
{
    public function editPrivatePayload(string $userId, int $messageId, array $payload): void;
}
