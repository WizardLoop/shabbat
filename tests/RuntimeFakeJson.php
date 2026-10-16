<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Http\JsonClient;

final class RuntimeFakeJson implements JsonClient
{
    public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
    {
        $start = $query['start'];
        $end = $query['end'];
        if (str_ends_with($url, '/hebcal')) {
            return ['range' => ['start' => $start,'end' => $end],'items' => []];
        }
        $times = [];
        $zone = new \DateTimeZone($query['tzid']);
        foreach (['sunset' => '18:00','tzeit85deg' => '18:40','tzeit7083deg' => '18:30','alotHaShachar' => '05:30','misheyakir' => '05:45','sunrise' => '06:30','minchaGedola' => '12:30'] as $name => $hour) {
            for ($date = new \DateTimeImmutable($start, $zone); $date->format('Y-m-d') <= $end; $date = $date->modify('+1 day')) {
                $key = $date->format('Y-m-d');
                $times[$name][$key] = (new \DateTimeImmutable($key . ' ' . $hour, $zone))->format(DATE_ATOM);
            }
        }
        return ['date' => ['start' => $start,'end' => $end],'times' => $times];
    }
}
