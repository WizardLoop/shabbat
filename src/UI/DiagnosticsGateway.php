<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

/** Optional private diagnostics transport; never accepts user-controlled paths. */
interface DiagnosticsGateway
{
    public function sendDiagnosticPanel(string $user, string $text, array $markup): int;
    public function sendDiagnosticDocument(string $user, string $json, string $caption, string $randomId): void;
}
