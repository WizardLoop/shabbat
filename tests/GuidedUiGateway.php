<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Telegram\TelegramGateway;
use Shabbat\UI\{PanelPayloadGateway, UiGateway};

/** Strict offline gateway shared by guided template and custom-donation interaction tests. */
final class GuidedUiGateway implements UiGateway, TelegramGateway, PanelPayloadGateway
{
    public array $sent = [];
    public array $edited = [];
    public array $payloads = [];
    public array $answers = [];
    public bool $admin = true;
    public bool $editFails = false;
    public ?\Closure $onAdmin = null;
    public int $adminChecks = 0;

    public function isGroupAdmin(string $user, string $group): bool
    {
        $this->adminChecks++;
        if ($this->onAdmin !== null) {
            $hook = $this->onAdmin;
            $this->onAdmin = null;
            $hook();
        }
        return $this->admin && $user === '1' && $group === '-1001';
    }

    public function sendPrivate(string $user, string $text, array $markup = []): void
    {
        $this->sent[] = compact('user', 'text', 'markup');
    }

    public function editPrivate(string $user, int $id, string $text, array $markup = []): void
    {
        if ($this->editFails) {
            throw new \RuntimeException('Fixture panel was deleted.');
        }
        $this->edited[] = compact('user', 'id', 'text', 'markup');
    }

    public function editPrivatePayload(string $userId, int $messageId, array $payload): void
    {
        $this->payloads[] = compact('userId', 'messageId', 'payload');
    }

    public function answerCallback(string $id, string $text): void
    {
        $this->answers[] = compact('id', 'text');
    }

    public function answerInline(string $id, array $results, array $options = []): void
    {
        throw new \RuntimeException('No inline requests in guided editor tests.');
    }

    public function botUsername(): string
    {
        return 'FixtureBot';
    }

    public function getPermissions(string $group): array
    {
        throw new \RuntimeException('No Telegram group operations in guided tests.');
    }

    public function setPermissions(string $group, array $permissions): void
    {
        throw new \RuntimeException('No Telegram group operations in guided tests.');
    }

    public function send(string $peer, array $payload, string $randomId): array
    {
        throw new \RuntimeException('No Telegram deliveries in guided tests.');
    }
}
