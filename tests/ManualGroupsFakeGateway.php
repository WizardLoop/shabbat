<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Closure;
use RuntimeException;
use Shabbat\Telegram\TelegramGateway;

final class ManualGroupsFakeGateway implements TelegramGateway
{
    public array $rights = [];
    public array $changes = [];
    public ?Closure $onSet = null;
    public function getPermissions(string $groupId): array
    {
        return $this->rights[$groupId] ?? ['_' => 'chatBannedRights','send_polls' => true];
    }
    public function setPermissions(string $groupId, array $permissions): void
    {
        if ($this->onSet !== null) {
            ($this->onSet)($groupId, $permissions);
        }
        $this->rights[$groupId] = $permissions;
        $this->changes[] = [$groupId,$permissions];
    }
    public function send(string $peer, array $payload, string $randomId): array
    {
        throw new RuntimeException('Manual service must not send an unverified announcement.');
    }
}
