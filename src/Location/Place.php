<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Location;

final class Place
{
    public static function validate(array $place): array
    {
        foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $key => [$min, $max]) {
            if (!isset($place[$key]) || !is_numeric($place[$key]) || !is_finite((float) $place[$key]) || $place[$key] < $min || $place[$key] > $max) {
                throw new LocationException('Invalid ' . $key);
            }
            $place[$key] = (float) $place[$key];
        }
        if (!in_array($place['timezone_id'] ?? '', \DateTimeZone::listIdentifiers(), true)) {
            throw new LocationException('Timezone must be an IANA identifier');
        }
        $name = trim((string) ($place['location_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new LocationException('A location name of 1–160 characters is required');
        }
        $place['location_name'] = $name;
        $place['country'] = (string) ($place['country'] ?? '');
        $place['geoname_id'] = isset($place['geoname_id']) ? (int) $place['geoname_id'] : null;
        $place['location_source'] = (string) ($place['location_source'] ?? 'coordinates');
        return $place;
    }
    public static function common(): array
    {
        return [
            'jerusalem' => ['location_name' => 'Jerusalem / ירושלים', 'country' => 'Israel', 'geoname_id' => 281184, 'latitude' => 31.76904, 'longitude' => 35.21633, 'timezone_id' => 'Asia/Jerusalem', 'location_source' => 'curated'],
            'telaviv' => ['location_name' => 'Tel Aviv / תל אביב', 'country' => 'Israel', 'geoname_id' => 293397, 'latitude' => 32.08088, 'longitude' => 34.78057, 'timezone_id' => 'Asia/Jerusalem', 'location_source' => 'curated'],
            'london' => ['location_name' => 'London / לונדון', 'country' => 'United Kingdom', 'geoname_id' => 2643743, 'latitude' => 51.50853, 'longitude' => -0.12574, 'timezone_id' => 'Europe/London', 'location_source' => 'curated'],
            'newyork' => ['location_name' => 'New York / ניו יורק', 'country' => 'United States', 'geoname_id' => 5128581, 'latitude' => 40.71427, 'longitude' => -74.00597, 'timezone_id' => 'America/New_York', 'location_source' => 'curated'],
        ];
    }
}
