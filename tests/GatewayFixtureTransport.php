<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only

namespace Shabbat\Tests;

use Shabbat\Runtime\MadelineTransport;
use Throwable;

final class GatewayFixtureTransport implements MadelineTransport
{
    public array $calls = [];
    public array $responses = [];
    public array $peerInfos = [];
    public array $selfInfo = ['id' => 777, 'username' => 'FixtureShabbatBot', 'bot' => true];
    public array $peerInfo = ['type' => 'supergroup', 'Chat' => ['id' => 123]];
    public array $mediaInfo = ['InputMedia' => ['_' => 'inputMediaPhoto', 'id' => ['_' => 'inputPhoto', 'id' => 42, 'access_hash' => 88, 'file_reference' => 'fixture']]];
    public ?Throwable $error = null;
    public ?Throwable $infoError = null;
    public ?\Closure $onInfo = null;
    public function call(string $method, array $parameters): mixed
    {
        $this->calls[] = [$method, $parameters];
        if ($this->error) {
            throw $this->error;
        }
        return $this->responses[$method] ?? ['_' => 'updates', 'updates' => []];
    }
    public function info(string $peer): array
    {
        if ($this->infoError !== null) {
            throw $this->infoError;
        }
        ($this->onInfo)?->__invoke();
        return $this->peerInfos[$peer] ?? $this->peerInfo;
    }
    public function fullInfo(string $peer): array
    {
        return $this->call('getFullInfo', ['id' => $peer]);
    }
    public function fileInfo(string $fileId): array
    {
        return $this->mediaInfo;
    }
    public function self(): array
    {
        return $this->selfInfo;
    }
}
