<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Runtime;

/** Bounded public diagnosis; arbitrary exception text stays out of chat replies. */
final class PermissionFailure
{
    public static function code(\Throwable $error): string
    {
        if ($error instanceof \danog\MadelineProto\RPCErrorException) {
            return preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $error->rpc) ? $error->rpc : 'RPC_ERROR';
        }
        $text = $error->getMessage();
        if (str_contains($text, 'view_messages') || str_contains($text, 'group restriction')) {
            return 'BANNED_RIGHTS_INVALID';
        }
        if (str_contains($text, 'snapshot') || str_contains($text, 'current default permissions') || str_contains($text, 'refreshed peer information')) {
            return 'SNAPSHOT_UNVERIFIED';
        }
        if ($error instanceof \Shabbat\Http\ProviderException) {
            return 'PROVIDER_UNAVAILABLE';
        }
        return 'OPERATION_UNCONFIRMED';
    }
}
