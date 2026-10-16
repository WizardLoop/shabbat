<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Telegram\TelegramGateway;
use Shabbat\UI\{DiagnosticsGateway, UiGateway};

interface DiagnosticsGatewayFixture extends TelegramGateway, UiGateway, DiagnosticsGateway
{
}
