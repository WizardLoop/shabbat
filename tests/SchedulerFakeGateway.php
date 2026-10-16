<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Closure;
use RuntimeException;
use Shabbat\Telegram\TelegramGateway;

final class SchedulerFakeGateway implements TelegramGateway
{
    public array $permissions = [];
    public array $changes = [];
    public array $sends = [];
    public ?Closure $onSet = null;
    public ?Closure $onSend = null;
    public function getPermissions(string $groupId): array
    {
        return $this->permissions[$groupId] ?? ['_' => 'chatBannedRights','send_polls' => true];
    }
    public function setPermissions(string $groupId, array $permissions): void
    {
        if ($this->onSet !== null) {
            ($this->onSet)($groupId, $permissions);
        }
        $this->changes[] = [$groupId,$permissions];
        $this->permissions[$groupId] = $permissions;
    }
    public function send(string $peer, array $payload, string $randomId): array
    {
        $this->sends[] = [$peer,$payload,$randomId];
        if ($this->onSend !== null) {
            return ($this->onSend)($peer, $payload, $randomId);
        }
        return ['_' => 'updates','message_id' => count($this->sends)];
    }
}
