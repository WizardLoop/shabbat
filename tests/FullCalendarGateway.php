<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Telegram\TelegramGateway;
use Shabbat\UI\UiGateway;

final class FullCalendarGateway implements TelegramGateway, UiGateway
{
    public array $private = [];
    public array $rights = [];
    public array $sent = [];
    public int $permissionChanges = 0;
    public function isGroupAdmin(string $userId, string $groupId): bool
    {
        return $userId === '42';
    }
    public function sendPrivate(string $userId, string $text, array $markup = []): void
    {
        $this->private[] = ['user' => $userId,'text' => $text,'markup' => $markup];
    }
    public function editPrivate(string $userId, int $messageId, string $text, array $markup = []): void
    {
        $this->sendPrivate($userId, $text, $markup);
    }
    public function answerCallback(string $queryId, string $text): void
    {
    }
    public function answerInline(string $queryId, array $results, array $options = []): void
    {
    }
    public function botUsername(): string
    {
        return 'OfflineCalendarBot';
    }
    public function getPermissions(string $groupId): array
    {
        return $this->rights[$groupId] ??= ['_' => 'chatBannedRights','until_date' => 0,'send_messages' => false,'send_media' => false];
    }
    public function setPermissions(string $groupId, array $rights): void
    {
        $this->rights[$groupId] = $rights;
        $this->permissionChanges++;
    }
    public function send(string $peer, array $payload, string $randomId): array
    {
        $this->sent[] = [$peer,$payload,$randomId];
        return ['_' => 'updates','updates' => []];
    }
}
