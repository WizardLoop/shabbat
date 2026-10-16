<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

/** Runs the pinned SDK refresh implementation with an isolated in-memory RPC boundary. */
final class PinnedPeerRefreshHarness
{
    use \danog\MadelineProto\MTProtoTools\PeerHandler;

    public object $peerDatabase;
    public array $cached;
    public array $server;
    public array $calls = [];
    public function __construct(string $type, array $rights)
    {
        $this->cached = FreshPeerFixture::info($type === 'chat' ? '-1' : '-1000000000001', $rights, $type);
        $this->server = $this->cached;
        $this->peerDatabase = new class {
            public array $full;
            public function getFull(int $id): array
            {
                return $this->full;
            }
        };
        $this->peerDatabase->full = ['inserted' => time(), 'full' => $this->cached['full']];
    }
    public function getInfo(mixed $id, int $type = \danog\MadelineProto\API::INFO_TYPE_ALL): array
    {
        return $this->cached;
    }
    public function getSettings(): \danog\MadelineProto\Settings
    {
        return \Shabbat\Runtime\Runner::settings();
    }
    public function methodCallAsyncRead(string $method, array $parameters): array
    {
        $this->calls[] = $method;
        $this->cached = $this->server;
        $this->peerDatabase->full['inserted'] = time();
        $reply = $this->server[\Shabbat\Runtime\FreshPeerReader::EVIDENCE_KEY];
        $reply['chats'] = [$this->server['Chat']];
        return $reply;
    }
}
