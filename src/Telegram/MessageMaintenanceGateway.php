<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Telegram;

/** Optional moderation boundary; callers must record authenticated message origin. */
interface MessageMaintenanceGateway
{
    /** @param list<int> $messageIds Positive native int32 IDs, at most 100 per call. */
    public function deleteMessages(string $peer, array $messageIds): void;
    /** null means the sender type is unverified and must never be guessed. */
    public function senderIsBot(string $group, string $senderId): ?bool;
    public function botCapabilities(string $group): array;
    public function botCanDeleteMessages(string $group): bool;
    public function selfUserId(): string;
    /** @return list<int> Verified sent IDs; unknown response shapes return an empty list. */
    public function extractSentMessageIds(array $response, ?string $expectedRandomId = null, ?string $expectedPeer = null): array;
}
