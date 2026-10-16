<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Telegram\TelegramGateway;
use Shabbat\UI\UiGateway;

/** A single mock contract lets tests forbid all outbound Telegram operations. */
interface CallbackRoutingGateway extends TelegramGateway, UiGateway
{
}
