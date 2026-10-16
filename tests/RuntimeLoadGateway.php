<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Telegram\GuardedTelegramGateway;
use Shabbat\UI\UiGateway;

/** In-memory peer fixture. No method performs real network I/O. */
final class RuntimeLoadGateway implements GuardedTelegramGateway, UiGateway
{
    public array $rights = [];
    public array $sent = [];
    public array $mutations = [];
    public ?string $failGroup = null;
    public float $permissionDelay = 0.0;
    public int $activePermissionIo = 0;
    public int $maxPermissionIo = 0;
    public int $permissionRequests = 0;
    public ?\Closure $onPermissionIo = null;
    public ?\Closure $beforeSend = null;
    public static function original(): array
    {
        return ['_' => 'chatBannedRights','until_date' => 0,'send_messages' => false,'send_media' => false,'send_photos' => true,'invite_users' => true];
    }
    private function permissionIo(string $group, string $kind): void
    {
        $this->activePermissionIo++;
        $this->permissionRequests++;
        $this->maxPermissionIo = max($this->maxPermissionIo, $this->activePermissionIo);
        try {
            if ($this->onPermissionIo !== null) {
                ($this->onPermissionIo)($group, $kind);
            }
            if ($this->permissionDelay > 0) {
                \Amp\delay($this->permissionDelay);
            }
        } finally {
            $this->activePermissionIo--;
        }
    }
    public function getPermissions(string $groupId): array
    {
        $this->permissionIo($groupId, 'read');
        return $this->rights[$groupId] ??= self::original();
    }
    public function setPermissions(string $groupId, array $permissions): void
    {
        $this->permissionIo($groupId, 'write');
        if ($this->failGroup === $groupId) {
            throw new \RuntimeException('Simulated isolated permission failure.');
        }
        $this->rights[$groupId] = $permissions;
        $this->mutations[] = [$groupId,$permissions];
    }
    public function send(string $peer, array $payload, string $randomId): array
    {
        return $this->sendGuarded($peer, $payload, $randomId, static fn() => null);
    }
    public function sendGuarded(string $peer, array $payload, string $randomId, callable $guard): array
    {
        if ($this->beforeSend !== null) {
            ($this->beforeSend)($peer);
        }
        $guard();
        $this->sent[] = [$peer,$payload,$randomId];
        return ['_' => 'updateShortSentMessage','id' => count($this->sent)];
    }
    public function isGroupAdmin(string $userId, string $groupId): bool
    {
        return true;
    }
    public function sendPrivate(string $userId, string $text, array $markup = []): void
    {
    }
    public function editPrivate(string $userId, int $messageId, string $text, array $markup = []): void
    {
    }
    public function answerCallback(string $queryId, string $text): void
    {
    }
    public function answerInline(string $queryId, array $results, array $options = []): void
    {
    }
    public function botUsername(): string
    {
        return 'SyntheticLoadFixtureBot';
    }
}
