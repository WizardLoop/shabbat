<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Scheduling;

/** Local wall-clock quiet hours, including repeated/missing DST hours. */
final class QuietHours
{
    public static function contains(int $at, string $zone, ?string $start, ?string $end): bool
    {
        if ($start === null || $end === null || $start === $end) {
            return false;
        }
        $time = (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($zone))->format('H:i');
        return $start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
    }

    public static function nextBoundary(int $at, string $zone, ?string $start, ?string $end): ?int
    {
        if ($start === null || $end === null || $start === $end) {
            return null;
        }
        $tz = new \DateTimeZone($zone);
        $date = (new \DateTimeImmutable('@' . $at))->setTimezone($tz)->setTime(0, 0);
        $candidates = [];
        // All offsets retain both occurrences of a repeated wall-clock boundary.
        $transitions = $tz->getTransitions($at - 172800, $at + 345600) ?: [];
        $offsets = [$tz->getOffset($date)];
        foreach ($transitions as $transition) {
            $offsets[] = $transition['offset'];
            $candidates[] = $transition['ts'];
        }
        for ($day = 0; $day <= 3; $day++) {
            $ymd = $date->modify('+' . $day . ' days')->format('Y-m-d');
            foreach ([$start, $end] as $time) {
                $wall = (new \DateTimeImmutable($ymd . ' ' . $time, new \DateTimeZone('UTC')))->getTimestamp();
                foreach (array_unique($offsets) as $offset) {
                    $candidate = $wall - $offset;
                    if ((new \DateTimeImmutable('@' . $candidate))->setTimezone($tz)->format('Y-m-d H:i') === $ymd . ' ' . $time) {
                        $candidates[] = $candidate;
                    }
                }
            }
        }
        sort($candidates);
        foreach ($candidates as $candidate) {
            if ($candidate > $at && self::contains($candidate - 1, $zone, $start, $end) !== self::contains($candidate, $zone, $start, $end)) {
                return $candidate;
            }
        }
        return null;
    }
}
