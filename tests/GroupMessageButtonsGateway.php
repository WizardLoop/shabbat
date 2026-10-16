<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Telegram\{MessageMaintenanceGateway, SentMessageIds, TelegramGateway};
use Shabbat\UI\UiGateway;

/** Offline boundary; returning native acknowledgement fixtures is the only send effect. */
final class GroupMessageButtonsGateway implements TelegramGateway, UiGateway, MessageMaintenanceGateway
{
    public array $sent = [];
    public bool $verified = true;
    public int $nextId = 101;
    public function isGroupAdmin(string $user, string $group): bool
    {
        return $user === '42';
    }
    public function sendPrivate(string $user, string $text, array $markup = []): void
    {
    }
    public function editPrivate(string $user, int $message, string $text, array $markup = []): void
    {
    }
    public function answerCallback(string $query, string $text): void
    {
    }
    public function answerInline(string $query, array $results, array $options = []): void
    {
    }
    public function botUsername(): string
    {
        return 'FixtureShabbatBot';
    }
    public function getPermissions(string $group): array
    {
        return ['_' => 'chatBannedRights', 'until_date' => 0, 'send_messages' => false];
    }
    public function setPermissions(string $group, array $rights): void
    {
        throw new \RuntimeException('Integration must not mutate Telegram permissions.');
    }
    public function send(string $peer, array $payload, string $randomId): array
    {
        $id = $this->nextId++;
        $this->sent[] = compact('peer', 'payload', 'randomId', 'id');
        return $this->verified ? ['_' => 'updateShortSentMessage', 'id' => $id] : ['_' => 'updates', 'updates' => []];
    }
    public function deleteMessages(string $peer, array $ids): void
    {
        throw new \RuntimeException('Integration must not delete Telegram messages.');
    }
    public function senderIsBot(string $group, string $sender): ?bool
    {
        return null;
    }
    public function botCapabilities(string $group): array
    {
        return [];
    }
    public function botCanDeleteMessages(string $group): bool
    {
        return false;
    }
    public function selfUserId(): string
    {
        return '999';
    }
    public function extractSentMessageIds(array $response, ?string $randomId = null, ?string $peer = null): array
    {
        return SentMessageIds::extract($response, $randomId, $peer);
    }
}
