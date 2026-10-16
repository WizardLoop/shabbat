<?php

declare(strict_types=1);

namespace Shabbat\Runtime;

// SPDX-License-Identifier: AGPL-3.0-only

/** A narrow boundary for offline RPC fixtures; production uses MadelineProto. */
interface MadelineTransport
{
    public function call(string $method, array $parameters): mixed;
    public function info(string $peer): array;
    public function fullInfo(string $peer): array;
    public function fileInfo(string $fileId): array;
    public function self(): array;
}
