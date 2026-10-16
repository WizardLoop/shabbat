<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Runtime\FreshPeerReader;
use Shabbat\Runtime\Runner;

/** Actual pinned getInfo/getFullInfo/genAll methods with only RPC/storage boundaries stubbed. */
final class NativePeerReadHarness
{
    use \danog\MadelineProto\MTProtoTools\PeerHandler;

    public object $peerDatabase;
    public object $minDatabase;
    public object $logger;
    public object $TL;
    public array $cached;
    public array $server;
    public array $calls = [];
    public ?array $reply = null;
    public bool $keepStaleCache = false;
    public int $cacheLifetime = 0;
    public ?\Throwable $failure = null;

    public function __construct(public string $peer = '-1000000000123', string $type = 'supergroup')
    {
        $rights = ['_' => 'chatBannedRights', 'until_date' => 0, 'send_photos' => true, 'invite_users' => true];
        $this->server = FreshPeerFixture::info($peer, $rights, $type);
        $this->cached = $this->server['Chat'];
        $this->peerDatabase = new class ($this) {
            public array $full;
            public function __construct(public NativePeerReadHarness $owner)
            {
                $this->full = ['inserted' => time(), 'full' => $owner->server['full']];
            }
            public function isset(int $id): bool
            {
                return true;
            }
            public function get(int $id): array
            {
                return $this->owner->cached;
            }
            public function getFull(int $id): array
            {
                return $this->full;
            }
        };
        $this->minDatabase = new class {
            public function hasPeer(int $id): bool
            {
                return true;
            }
        };
        $this->logger = new class {
            public function logger(mixed ...$arguments): void
            {
            }
        };
        $this->TL = new class {
            public function getConstructors(): object
            {
                return new class {
                    public function findByPredicate(string $constructor): array
                    {
                        return ['type' => 'Chat'];
                    }
                };
            }
        };
    }
    public function getIdInternal(mixed $id): int
    {
        return (int)$id;
    }
    public function getSettings(): \danog\MadelineProto\Settings
    {
        $settings = Runner::settings();
        $settings->getPeer()->setFullInfoCacheTime($this->cacheLifetime);
        return $settings;
    }
    public function methodCallAsyncRead(string $method, array $parameters): array
    {
        $this->calls[] = $method;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if (!$this->keepStaleCache) {
            $this->cached = $this->server['Chat'];
        }
        return $this->reply ?? $this->server[FreshPeerReader::EVIDENCE_KEY];
    }
}
