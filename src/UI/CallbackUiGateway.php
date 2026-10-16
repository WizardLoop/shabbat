<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\UI;

/** Immutable per-request target: concurrent callbacks can never exchange their panel IDs. */
final class CallbackUiGateway implements UiGateway
{
    public function __construct(private readonly UiGateway $gateway, private readonly string $userId, private readonly int $messageId)
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $userId) || $messageId < 1 || $messageId > 2147483647) {
            throw new \InvalidArgumentException('An authenticated private panel is required.');
        }
    }

    public static function fromQuery(UiGateway $gateway, array $query): self
    {
        $user = (string)($query['user_id'] ?? '');
        $message = $query['message_id'] ?? null;
        if (($query['private'] ?? false) !== true || !is_int($message) || (isset($query['chat_id']) && (string)$query['chat_id'] !== $user)) {
            throw new \InvalidArgumentException('Only the originating private bot panel can be edited.');
        }
        return new self($gateway, $user, $message);
    }

    public function messageId(): int
    {
        return $this->messageId;
    }

    public function underlying(): UiGateway
    {
        return $this->gateway;
    }

    public function isGroupAdmin(string $userId, string $groupId): bool
    {
        return $this->gateway->isGroupAdmin($userId, $groupId);
    }

    public function sendPrivate(string $userId, string $text, array $markup = []): void
    {
        $this->editPrivate($userId, $this->messageId, $text, $markup);
    }

    public function editPrivate(string $userId, int $messageId, string $text, array $markup = []): void
    {
        $this->target($userId, $messageId);
        try {
            $this->gateway->editPrivate($userId, $messageId, $text, $markup);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new PanelEditException('The original bot panel cannot be edited.', 0, $error);
        }
    }

    public function preview(array $payload): void
    {
        if (!$this->gateway instanceof PanelPayloadGateway) {
            throw new PanelEditException('This transport cannot edit rich previews.');
        }
        try {
            $this->gateway->editPrivatePayload($this->userId, $this->messageId, $payload);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new PanelEditException('The original bot preview cannot be edited.', 0, $error);
        }
    }

    private function target(string $userId, int $messageId): void
    {
        if ($userId !== $this->userId || $messageId !== $this->messageId) {
            throw new \LogicException('A callback cannot edit a different user or message.');
        }
    }

    public function answerCallback(string $queryId, string $text): void
    {
        $this->gateway->answerCallback($queryId, $text);
    }

    public function answerInline(string $queryId, array $results, array $options = []): void
    {
        $this->gateway->answerInline($queryId, $results, $options);
    }

    public function botUsername(): string
    {
        return $this->gateway->botUsername();
    }
}
