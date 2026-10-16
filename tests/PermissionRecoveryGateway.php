<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Telegram\TelegramGateway;

final class PermissionRecoveryGateway implements TelegramGateway
{
    public array $current = ['_' => 'chatBannedRights','until_date' => 0];
    public array $writes = [];
    public ?\Closure $onRead = null;
    public ?\Closure $onWrite = null;
    public function getPermissions(string $groupId): array
    {
        if ($this->onRead !== null) {
            ($this->onRead)();
        }
        return $this->current;
    }
    public function setPermissions(string $groupId, array $permissions): void
    {
        $this->writes[] = $permissions;
        $this->current = $permissions;
        if ($this->onWrite !== null) {
            ($this->onWrite)();
        }
    }
    public function send(string $peer, array $payload, string $randomId): array
    {
        throw new \LogicException('Recovery must never send messages.');
    }
}
