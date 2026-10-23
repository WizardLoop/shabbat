<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Telegram;

/** A definitive rejection of a private reply, not a failure of its UI action. */
final class PrivateChatUnavailableException extends \RuntimeException
{
    public function __construct(public readonly string $userId, \danog\MadelineProto\RPCErrorException $previous)
    {
        parent::__construct('Telegram cannot address this private chat; the user must open the bot first.', 0, $previous);
    }
}
