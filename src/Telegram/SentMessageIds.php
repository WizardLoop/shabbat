<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Telegram;

use danog\DialogId\DialogId;
use Shabbat\Runtime\TelegramInt64;

/** Extract native send acknowledgements and bind them to the known job context. */
final class SentMessageIds
{
    public static function extract(array $response, ?string $expectedRandomId = null, ?string $expectedPeer = null): array
    {
        try {
            $randomId = $expectedRandomId === null ? null : TelegramInt64::fromDecimal($expectedRandomId);
            $peer = $expectedPeer === null ? null : TelegramInt64::fromDecimal($expectedPeer);
        } catch (\InvalidArgumentException) {
            return [];
        }
        if (($randomId !== null && $randomId <= 0) || $peer === 0) {
            return [];
        }
        if (($response['_'] ?? '') === 'updateShortSentMessage') {
            return self::id($response['id'] ?? null) === null ? [] : [$response['id']];
        }
        $updates = match ($response['_'] ?? '') {
            'updates', 'updatesCombined' => $response['updates'] ?? [],
            'updateShort' => [$response['update'] ?? []],
            default => [],
        };
        if (!is_array($updates)) {
            return [];
        }
        $mapped = [];
        $outgoing = [];
        $hasMapping = false;
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            if (($update['_'] ?? '') === 'updateMessageID') {
                $hasMapping = true;
                $id = self::id($update['id'] ?? null);
                if ($id !== null && self::randomMatches($update['random_id'] ?? null, $randomId)) {
                    $mapped[$id] = $id;
                }
                continue;
            }
            if (!in_array($update['_'] ?? '', ['updateNewMessage', 'updateNewChannelMessage'], true)) {
                continue;
            }
            $message = $update['message'] ?? null;
            if (
                !is_array($message) || ($message['_'] ?? '') !== 'message' || ($message['out'] ?? false) !== true
                || ($peer !== null && self::peer($message['peer_id'] ?? null) !== $peer)
            ) {
                continue;
            }
            $id = self::id($message['id'] ?? null);
            if ($id !== null) {
                $outgoing[$id] = $id;
            }
        }
        if ($mapped !== [] || ($expectedRandomId !== null && $hasMapping)) {
            return array_values($mapped);
        }
        return array_values($outgoing);
    }

    private static function randomMatches(mixed $value, ?int $expected): bool
    {
        if (is_int($value)) {
            return $expected === null || $value === $expected;
        }
        if (!is_string($value) || strlen($value) !== 8) {
            return false;
        }
        return $expected === null || hash_equals(pack('P', $expected), $value);
    }

    private static function peer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value === 0 ? null : $value;
        }
        if (!is_array($value)) {
            return null;
        }
        $key = match ($value['_'] ?? '') {
            'peerUser' => 'user_id',
            'peerChat' => 'chat_id',
            'peerChannel' => 'channel_id',
            default => null,
        };
        $id = $key === null ? null : ($value[$key] ?? null);
        if (!is_int($id) || $id <= 0) {
            return null;
        }
        try {
            return match ($key) {
                'user_id' => $id,
                'chat_id' => DialogId::fromChatId($id),
                'channel_id' => DialogId::fromSupergroupOrChannelId($id),
                default => null,
            };
        } catch (\AssertionError) {
            return null;
        }
    }

    private static function id(mixed $value): ?int
    {
        return is_int($value) && $value > 0 && $value <= 2147483647 ? $value : null;
    }
}
