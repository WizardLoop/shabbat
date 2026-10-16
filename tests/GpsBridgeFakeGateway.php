<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Telegram\TelegramGateway;
use Shabbat\UI\UiGateway;

final class GpsBridgeFakeGateway implements TelegramGateway, UiGateway
{
    public array $messages = [];
    public bool $admin = true;
    public function isGroupAdmin(string $user, string $group): bool
    {
        return $this->admin && $user === '42';
    }
    public function sendPrivate(string $user, string $text, array $markup = []): void
    {
        $this->messages[] = compact('user', 'text', 'markup');
    }
    public function editPrivate(string $user, int $id, string $text, array $markup = []): void
    {
        $this->sendPrivate($user, $text, $markup);
    }
    public function answerCallback(string $id, string $text): void
    {
    }
    public function answerInline(string $id, array $results, array $options = []): void
    {
    }
    public function botUsername(): string
    {
        return 'FixtureBot';
    }
    public function getPermissions(string $group): array
    {
        throw new \RuntimeException('Permission RPC forbidden in GPS bridge tests.');
    }
    public function setPermissions(string $group, array $permissions): void
    {
        throw new \RuntimeException('Permission RPC forbidden in GPS bridge tests.');
    }
    public function send(string $peer, array $payload, string $randomId): array
    {
        throw new \RuntimeException('Telegram sends forbidden in GPS bridge tests.');
    }
}
