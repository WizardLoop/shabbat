<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Scheduling;

final class Permissions
{
    /** All boolean fields of chatBannedRights in the locked Telegram layer. */
    public const FLAGS = ['view_messages','send_messages','send_media','send_stickers','send_gifs','send_games','send_inline','embed_links','send_polls',
        'change_info','invite_users','pin_messages','manage_topics','send_photos','send_videos','send_roundvideos','send_audios','send_voices','send_docs','send_plain','edit_rank','send_reactions'];
    public const DEFAULT_BLOCK_FLAGS = ['send_messages','send_media','send_stickers','send_gifs','send_games','send_inline','embed_links','send_polls',
        'change_info','invite_users','pin_messages','manage_topics','send_photos','send_videos','send_roundvideos','send_audios','send_voices','send_docs','send_plain','edit_rank','send_reactions'];
    public const BLOCK = self::DEFAULT_BLOCK_FLAGS;
    /** Telegram rejects view_messages for global default permissions. */
    public const BLOCK_FLAGS = self::DEFAULT_BLOCK_FLAGS;
    /** Legacy aggregate bits are redundant only when every granular bit is explicit. */
    private const MEDIA_FLAGS = ['send_photos','send_videos','send_roundvideos','send_audios','send_voices','send_docs'];
    private const MESSAGE_FLAGS = [...self::MEDIA_FLAGS,'send_plain','send_stickers','send_gifs','send_games','send_inline','embed_links','send_polls'];

    public static function validateBlockFlags(array $flags): array
    {
        if (!array_is_list($flags) || count($flags) > count(self::FLAGS)) {
            throw new \InvalidArgumentException('Lock flags must be a bounded list.');
        }
        foreach ($flags as $flag) {
            if (!is_string($flag) || !in_array($flag, self::BLOCK_FLAGS, true)) {
                throw new \InvalidArgumentException('Unknown native group restriction.');
            }
        }
        if (count(array_unique($flags)) !== count($flags)) {
            throw new \InvalidArgumentException('Duplicate group restrictions.');
        }
        return array_values(array_intersect(self::FLAGS, $flags));
    }
    public static function validate(array $rights): void
    {
        if (($rights['_'] ?? 'chatBannedRights') !== 'chatBannedRights') {
            throw new \RuntimeException('Invalid permission snapshot constructor.');
        }
        foreach ($rights as $key => $value) {
            if (!is_string($key) || ($key !== '_' && $key !== 'until_date' && !is_bool($value))) {
                throw new \RuntimeException('Malformed permission snapshot.');
            }
            if ($key === 'until_date' && (!is_int($value) || $value < 0)) {
                throw new \RuntimeException('Malformed permission expiry.');
            }
        }
    }
    public static function locked(array $original, ?array $flags = null): array
    {
        self::validate($original);
        $flags = self::validateBlockFlags($flags ?? self::DEFAULT_BLOCK_FLAGS);
        // Current official clients use granular rights. Keep legacy selections useful
        // without depending on the server to expand an aggregate request for us.
        if (in_array('send_messages', $flags, true)) {
            $flags = array_merge($flags, self::MESSAGE_FLAGS);
        } elseif (in_array('send_media', $flags, true)) {
            $flags = array_merge($flags, self::MEDIA_FLAGS);
        }
        $original['_'] = 'chatBannedRights';
        $original['until_date'] = 0;
        foreach ($flags as $flag) {
            $original[$flag] = true;
        }
        return $original;
    }
    public static function canonical(array $rights): string
    {
        return \Shabbat\Storage\Database::encode(self::normalized($rights));
    }
    /** Compare global default permissions without rewriting the stored native snapshot. */
    private static function normalized(array $rights): array
    {
        self::validate($rights);
        // TDLib writes 0 but expects INT32_MAX in default restrictions readback.
        // Do not discard arbitrary finite expiries or any individual restriction.
        $normalized = [];
        foreach ($rights as $key => $value) {
            if ($key === '_' || ($key === 'until_date' && in_array($value, [0, 2147483647], true)) || $value === false) {
                continue;
            }
            $normalized[$key] = $value;
        }
        foreach (['send_media' => self::MEDIA_FLAGS, 'send_messages' => self::MESSAGE_FLAGS] as $aggregate => $members) {
            if (count(array_filter($members, static fn(string $flag): bool => ($rights[$flag] ?? false) === true)) === count($members)) {
                unset($normalized[$aggregate]);
            }
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }
    /** Controlled diagnostic keys; unknown native fields are never exposed as chat text. */
    public static function differentFields(array $a, array $b): array
    {
        $a = self::normalized($a);
        $b = self::normalized($b);
        $fields = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $field) {
            if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
                $fields[] = in_array($field, [...self::FLAGS, 'until_date'], true) ? $field : 'unknown';
            }
        }
        return array_values(array_unique($fields));
    }
    /** Three-way recovery is limited to known fields the bot never changed. */
    public static function externalBaseline(array $baseline, array $applied, array $current): ?array
    {
        $differences = self::differentFields($current, $applied);
        if ($differences === [] || array_diff($differences, self::FLAGS) !== []) {
            return null;
        }
        $owned = self::differentFields($baseline, $applied);
        if (array_intersect($differences, $owned) !== []) {
            return null;
        }
        // Raw aggregate changes are ambiguous even when clients normalize other bits.
        if (array_intersect($differences, ['view_messages','send_messages','send_media']) !== []) {
            return null;
        }
        foreach ($differences as $field) {
            // Relaxing a restriction can also be a partial unlock: require an explicit choice.
            if (($current[$field] ?? false) !== true) {
                return null;
            }
            $baseline[$field] = true;
        }
        return $baseline;
    }
    public static function equal(array $a, array $b): bool
    {
        return hash_equals(self::canonical($a), self::canonical($b));
    }
    public static function alreadyLocked(array $rights): bool
    {
        return ($rights['view_messages'] ?? false) || ($rights['send_messages'] ?? false) || ($rights['send_plain'] ?? false);
    }
}
