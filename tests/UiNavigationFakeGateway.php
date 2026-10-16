<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\UI\PanelPayloadGateway;
use Shabbat\UI\UiGateway;

/** Records real transport operations without contacting Telegram. */
final class UiNavigationFakeGateway implements UiGateway, PanelPayloadGateway
{
    public array $sent = [];
    public array $edited = [];
    public array $answers = [];
    public array $inline = [];
    public array $payloads = [];
    public bool $admin = true;
    public ?\Closure $onEdit = null;

    public function isGroupAdmin(string $userId, string $groupId): bool
    {
        return $this->admin && $userId === '1' && in_array($groupId, ['-1001', '-1002'], true);
    }

    public function sendPrivate(string $userId, string $text, array $markup = []): void
    {
        $this->sent[] = compact('userId', 'text', 'markup');
    }

    public function editPrivate(string $userId, int $messageId, string $text, array $markup = []): void
    {
        $this->edited[] = compact('userId', 'messageId', 'text', 'markup');
        if ($this->onEdit !== null) {
            ($this->onEdit)($userId, $messageId);
        }
    }

    public function editPrivatePayload(string $userId, int $messageId, array $payload): void
    {
        $this->payloads[] = compact('userId', 'messageId', 'payload');
    }

    public function answerCallback(string $queryId, string $text): void
    {
        $this->answers[] = compact('queryId', 'text');
    }

    public function answerInline(string $queryId, array $results, array $options = []): void
    {
        $this->inline[] = compact('queryId', 'results', 'options');
    }

    public function botUsername(): string
    {
        return 'ShabbatFixtureBot';
    }
}
