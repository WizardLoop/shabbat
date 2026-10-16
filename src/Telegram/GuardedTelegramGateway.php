<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Telegram;

/** Optional transport capability for scheduled deliveries with a final dispatch fence. */
interface GuardedTelegramGateway extends TelegramGateway
{
    /**
     * Run the synchronous, non-yielding guard after all preflight work and immediately
     * before each actual send RPC, including any definitive-rejection fallback.
     * The guard returns void or throws; it must never perform provider/network work.
     */
    public function sendGuarded(string $peer, array $payload, string $randomId, callable $guard): array;
}
