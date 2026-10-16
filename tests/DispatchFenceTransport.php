<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use Closure;
use Shabbat\Runtime\MadelineTransport;

/** Offline native transport with controllable yielding-preflight equivalents. */
final class DispatchFenceTransport implements MadelineTransport
{
    public array $calls = [];
    public ?Closure $onCall = null;
    public ?Closure $onFile = null;

    public function call(string $method, array $parameters): mixed
    {
        $this->calls[] = [$method, $parameters];
        ($this->onCall)?->__invoke($method, $parameters);
        if ($method === 'messages.getForumTopicsByID') {
            return ['topics' => [['id' => 7, 'closed' => false]]];
        }
        if ($method === 'messages.getCustomEmojiDocuments') {
            return (new EmojiFixtureTransport())->documents;
        }
        return ['_' => 'updates', 'updates' => []];
    }

    public function fileInfo(string $fileId): array
    {
        ($this->onFile)?->__invoke();
        return ['InputMedia' => ['_' => 'inputMediaPhoto', 'id' => ['_' => 'inputPhoto', 'id' => 42, 'access_hash' => 88, 'file_reference' => 'fixture']]];
    }

    public function info(string $peer): array
    {
        return ['type' => 'supergroup', 'Chat' => ['id' => 123]];
    }

    public function fullInfo(string $peer): array
    {
        return FreshPeerFixture::info($peer, null);
    }

    public function self(): array
    {
        return ['id' => 777, 'username' => 'FixtureBot', 'bot' => true];
    }
}
