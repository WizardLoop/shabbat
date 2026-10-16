<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Telegram\TelegramGateway;
use Shabbat\UI\UiGateway;

final class RuntimeFakeGateway implements TelegramGateway, UiGateway
{
    public array $rights = [];
    public array $sent = [];
    public int $mutations = 0;
    public ?string $fail = null;
    public ?\Closure $beforeGetPermissions = null;
    public ?\Closure $beforeSetPermissions = null;
    public function getPermissions(string $group): array
    {
        if ($this->beforeGetPermissions !== null) {
            ($this->beforeGetPermissions)($group);
        }
        return $this->rights[$group] ??= ['_' => 'chatBannedRights','until_date' => 0,'send_messages' => false,'send_media' => false];
    }
    public function setPermissions(string $group, array $rights): void
    {
        if ($this->beforeSetPermissions !== null) {
            ($this->beforeSetPermissions)($group, $rights);
        }
        if ($group === $this->fail) {
            throw new \RuntimeException('Fake failure');
        }$this->rights[$group] = $rights;
        $this->mutations++;
    }
    public function send(string $peer, array $payload, string $randomId): array
    {
        $this->sent[] = [$peer,$payload,$randomId];
        return ['_' => 'updates','updates' => []];
    }
    public function isGroupAdmin(string $user, string $group): bool
    {
        return $user === '42';
    }
    public function sendPrivate(string $user, string $text, array $markup = []): void
    {
    }
    public function editPrivate(string $user, int $id, string $text, array $markup = []): void
    {
    }
    public function answerCallback(string $id, string $text): void
    {
    }
    public function answerInline(string $id, array $results, array $options = []): void
    {
    }
    public function botUsername(): string
    {
        return 'ExampleBot';
    }
}
