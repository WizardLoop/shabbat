<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Telegram\TelegramGateway;

final class PermissionRetryFakeGateway implements TelegramGateway
{
    public array $permissions = [];
    public array $changes = [];
    public int $reads = 0;
    public int $writes = 0;
    public ?\Closure $onGet = null;
    public ?\Closure $onSet = null;
    public function getPermissions(string $group): array
    {
        $this->reads++;
        if ($this->onGet !== null) {
            ($this->onGet)($group, $this->reads);
        }
        return $this->permissions[$group] ?? ['_' => 'chatBannedRights','until_date' => 0,'send_polls' => true];
    }
    public function setPermissions(string $group, array $permissions): void
    {
        $this->writes++;
        if ($this->onSet !== null) {
            ($this->onSet)($group, $permissions, $this->writes);
        }
        $this->permissions[$group] = $permissions;
        $this->changes[] = [$group,$permissions];
    }
    public function send(string $peer, array $payload, string $randomId): array
    {
        throw new \RuntimeException('No message sends in permission tests.');
    }
    public function resetActivity(): void
    {
        $this->reads = 0;
        $this->writes = 0;
        $this->changes = [];
    }
}
