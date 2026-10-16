<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Scheduling;

/** The final local fence rejected a dispatch before it reached the transport. */
final class DispatchRejectedException extends \RuntimeException
{
}
