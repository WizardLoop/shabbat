<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Telegram;

interface TelegramGateway
{
    /** MTProto chatBannedRights from a fresh, complete, matching native peer reply.
     * Missing/minimal/cached/unverifiable evidence must throw; never invent default permissions. */
    public function getPermissions(string $groupId): array;
    public function setPermissions(string $groupId, array $permissions): void;
    /** Returns the confirmed RPC response. randomId is a durable signed 64-bit decimal identifier. */
    public function send(string $peer, array $payload, string $randomId): array;
}
