<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

interface UiGateway
{
    public function isGroupAdmin(string $userId, string $groupId): bool;
    public function sendPrivate(string $userId, string $text, array $markup = []): void;
    public function editPrivate(string $userId, int $messageId, string $text, array $markup = []): void;
    public function answerCallback(string $queryId, string $text): void;
    public function answerInline(string $queryId, array $results, array $options = []): void;
    public function botUsername(): string;
}
