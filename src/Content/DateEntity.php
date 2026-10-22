<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Content;

use danog\MadelineProto\EventHandler\Message\Entities\MessageEntity;

/** Validate persisted date entities before the SDK's deliberately permissive factory. */
final class DateEntity
{
    public static function normalize(array $entity): array
    {
        $type = $entity['_'] ?? $entity['type'] ?? '';
        if (!in_array($type, ['messageEntityFormattedDate', 'date_time'], true)) {
            return $entity;
        }
        $date = $type === 'date_time' ? 'unix_time' : 'date';
        foreach (['offset', 'length', $date] as $field) {
            if (!is_int($entity[$field] ?? null) || $entity[$field] < 0 || $entity[$field] > 2147483647) {
                throw new \InvalidArgumentException('Invalid date entity integer: ' . $field);
            }
        }
        if ($type === 'messageEntityFormattedDate') {
            foreach (['relative', 'short_time', 'long_time', 'short_date', 'long_date', 'day_of_week'] as $flag) {
                if (array_key_exists($flag, $entity) && !is_bool($entity[$flag])) {
                    throw new \InvalidArgumentException('Invalid date entity flag: ' . $flag);
                }
            }
            // Official semantics: relative takes precedence; otherwise long formats win.
            return $entity;
        }
        if (array_key_exists('date_time_format', $entity) && !is_string($entity['date_time_format'])) {
            throw new \InvalidArgumentException('Invalid date entity format.');
        }
        try {
            return MessageEntity::fromRawEntity($entity)->toMTProto();
        } catch (\AssertionError $error) {
            throw new \InvalidArgumentException('Invalid date entity format.', previous: $error);
        }
    }
}
