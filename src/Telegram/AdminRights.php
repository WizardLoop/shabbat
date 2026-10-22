<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Telegram;

/** Read-only diagnostic catalog from the locked Telegram layer 232 constructor. */
final class AdminRights
{
    public const FLAGS = [
        'change_info', 'post_messages', 'edit_messages', 'delete_messages',
        'ban_users', 'invite_users', 'pin_messages', 'add_admins', 'anonymous',
        'manage_call', 'other', 'manage_topics', 'post_stories', 'edit_stories',
        'delete_stories', 'manage_direct_messages', 'manage_ranks', 'manage_linked_peers', 'manage_welcome_messages',
    ];

    public static function normalize(?array $rights): array
    {
        $result = array_fill_keys(self::FLAGS, false);
        if ($rights === null) {
            return $result;
        }
        if (($rights['_'] ?? null) !== 'chatAdminRights') {
            throw new \RuntimeException('Unverified administrator-rights constructor.');
        }
        foreach ($rights as $flag => $value) {
            if ($flag === '_') {
                continue;
            }
            if (!in_array($flag, self::FLAGS, true) || !is_bool($value)) {
                throw new \RuntimeException('Unknown or malformed administrator-rights flag.');
            }
            $result[$flag] = $value;
        }
        return $result;
    }
}
