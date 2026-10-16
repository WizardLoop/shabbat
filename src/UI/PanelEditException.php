<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\UI;

/** Editing failed; the user must reopen a panel explicitly instead of receiving a new one. */
final class PanelEditException extends \RuntimeException
{
}
