<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use Shabbat\Telegram\{TelegramGateway,MessageMaintenanceGateway,SentMessageIds};
use Shabbat\UI\UiGateway;

final class CleanupFakeGateway implements TelegramGateway, UiGateway, MessageMaintenanceGateway
{
    public array $rights = [];
    public array $deleted = [];
    public array $sent = [];
    public array $bots = ['42' => false, '43' => true];
    public bool $canDelete = true;
    public ?\Closure $beforeCapabilities = null;
    public ?\Closure $beforePermissions = null;
    public ?\Throwable $deleteError = null;
    public ?\Throwable $sendError = null;
    public int $nextId = 100;
    public int $classifications = 0;
    public function getPermissions(string $group): array
    {
        if ($this->beforePermissions !== null) {
            ($this->beforePermissions)();
        }
        return $this->rights[$group] ?? ['_' => 'chatBannedRights', 'until_date' => 0, 'send_messages' => false];
    }
    public function setPermissions(string $group, array $rights): void
    {
        $this->rights[$group] = $rights;
    }
    public function send(string $peer, array $payload, string $randomId): array
    {
        if ($this->sendError !== null) {
            throw $this->sendError;
        }
        $id = $this->nextId++;
        $this->sent[] = [$peer, $payload, $randomId, $id];
        return ['_' => 'updates', 'updates' => [['_' => 'updateMessageID','random_id' => (int)$randomId,'id' => $id]]];
    }
    public function deleteMessages(string $peer, array $messageIds): void
    {
        if ($this->deleteError !== null) {
            throw $this->deleteError;
        }
        $this->deleted[] = [$peer, $messageIds];
    }
    public function senderIsBot(string $group, string $senderId): ?bool
    {
        $this->classifications++;
        return $this->bots[$senderId] ?? null;
    }
    public function botCapabilities(string $group): array
    {
        if ($this->beforeCapabilities !== null) {
            ($this->beforeCapabilities)();
        }
        return ['can_delete_messages' => $this->canDelete];
    }
    public function botCanDeleteMessages(string $group): bool
    {
        return $this->botCapabilities($group)['can_delete_messages'];
    }
    public function selfUserId(): string
    {
        return '9000';
    }
    public function extractSentMessageIds(array $response, ?string $expectedRandomId = null, ?string $expectedPeer = null): array
    {
        return SentMessageIds::extract($response, $expectedRandomId, $expectedPeer);
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
        return 'CleanupFixtureBot';
    }
}
