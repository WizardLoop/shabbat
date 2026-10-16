<?php

declare(strict_types=1);

namespace Shabbat\Runtime;

// SPDX-License-Identifier: AGPL-3.0-only

final class EventHandlerTransport implements MadelineTransport
{
    public function __construct(private \danog\MadelineProto\SimpleEventHandler $handler)
    {
    }

    public function call(string $method, array $parameters): mixed
    {
        [$namespace, $name] = explode('.', $method, 2);
        return $this->handler->{$namespace}->{$name}($parameters);
    }

    public function info(string $peer): array
    {
        return $this->handler->getInfo($peer);
    }

    public function fullInfo(string $peer): array
    {
        return FreshPeerReader::read(
            $peer,
            $this->handler->getSettings(),
            $this->handler->getFullInfo(...),
        );
    }

    public function fileInfo(string $fileId): array
    {
        return $this->handler->getFileInfo($fileId);
    }

    public function self(): array
    {
        return $this->handler->getSelf();
    }
}
