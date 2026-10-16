<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\EventHandler\Message;
use Shabbat\Runtime\Application;

/** Real bridge methods, with only the application primed; SDK sessions are never constructed. */
final class ReplyScopeFixture extends \danog\MadelineProto\SimpleEventHandler
{
    use \Shabbat\Runtime\BotIntegration;

    public static function create(Application $app): self
    {
        $fixture = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        self::$applications ??= new \WeakMap();
        self::$applications[$fixture] = $app;
        return $fixture;
    }
    private function parseButtons(string $input): array
    {
        return [];
    }
    public function language(Message $message): string
    {
        return $this->replyScopeLanguage($message);
    }
    public function sourceForMessage(Message $message, string $kind): string
    {
        return $this->v3Source($kind, (string)$message->senderId, $this->replyScopeLanguage($message));
    }
    public function statsForMessage(Message $message): string
    {
        return $this->v3StatsText((string)$message->senderId, $this->replyScopeLanguage($message));
    }
    public function privateSourceCallback(string $user, string $kind = 'source'): string
    {
        return $this->v3Source($kind, $user);
    }

    public function rawLocation(array $update, int $self, callable $replyLookup): bool
    {
        return $this->v3RawLocation($update, $self, $replyLookup);
    }
}
