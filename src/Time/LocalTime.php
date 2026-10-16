<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Time;

final class LocalTime
{
    /** Missing DST hours are skipped; repeated hours use the first occurrence, once. */
    public static function resolve(string $date, string $time, string $timezone): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            throw new \InvalidArgumentException('Invalid local date or HH:mm.');
        }
        $zone = new \DateTimeZone($timezone);
        $wall = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", new \DateTimeZone('UTC'));
        if ($wall === false || $wall->format('Y-m-d H:i') !== "$date $time") {
            throw new \InvalidArgumentException('Invalid local date.');
        }
        $stamp = $wall->getTimestamp();
        $offsets = [$zone->getOffset($wall)];
        foreach ($zone->getTransitions($stamp - 172800, $stamp + 172800) ?: [] as $transition) {
            $offsets[] = $transition['offset'];
        }
        $matches = [];
        foreach (array_unique($offsets) as $offset) {
            $candidate = $stamp - $offset;
            if ((new \DateTimeImmutable('@' . $candidate))->setTimezone($zone)->format('Y-m-d H:i') === "$date $time") {
                $matches[] = $candidate;
            }
        }
        return $matches === [] ? null : min($matches);
    }
}
