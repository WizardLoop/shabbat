<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\Stream\BufferedStreamInterface;
use danog\MadelineProto\Stream\MTProtoBufferInterface;

/** Native buffered transport used by the SDK connection. */
interface ShutdownFixtureTransport extends BufferedStreamInterface, MTProtoBufferInterface
{
}
