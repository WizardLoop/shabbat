<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Calendar;

use Shabbat\Http\JsonClient;
use Shabbat\Http\ProviderException;

final class HebcalProvider
{
    /**
     * Daily solar fields understood by this application. Hebcal also returns empty
     * elevation-only seaLevel series and sparse lunar events in the same object.
     * Those unsupported fields, and future provider extensions, are not daily
     * schedules and must not control coverage or become operational time values.
     */
    private const DAILY_SOLAR_FIELDS = [
        'chatzotNight',
        'alosBaalHatanya',
        'alotHaShachar',
        'misheyakir',
        'misheyakirMachmir',
        'dawn',
        'sunrise',
        'sofZmanShmaMGA19Point8',
        'sofZmanShmaMGA16Point1',
        'sofZmanShmaMGA',
        'sofZmanShmaBaalHatanya',
        'sofZmanShma',
        'sofZmanTfillaMGA19Point8',
        'sofZmanTfillaMGA16Point1',
        'sofZmanTfillaMGA',
        'sofZmanTfilaBaalHatanya',
        'sofZmanTfilla',
        'chatzot',
        'minchaGedola',
        'minchaGedolaBaalHatanya',
        'minchaGedolaMGA',
        'minchaKetana',
        'minchaKetanaBaalHatanya',
        'minchaKetanaMGA',
        'plagHaMincha',
        'plagHaminchaBaalHatanya',
        'sunset',
        'beinHaShmashos',
        'dusk',
        'tzaisBaalHatanya',
        'tzeit7083deg',
        'tzeit85deg',
        'tzeit42min',
        'tzeit50min',
        'tzeit72min',
    ];
    private array $lastSolarMetadata = [];
    public function __construct(private JsonClient $http)
    {
    }
    public function lastSolarMetadata(): array
    {
        return $this->lastSolarMetadata;
    }
    public function calendar(string $start, string $end, bool $israel, string $language = 'he'): array
    {
        self::range($start, $end);
        $providerLanguage = self::providerLanguage($language);
        $data = $this->http->get('https://www.hebcal.com/hebcal', [
            'v' => 1,'cfg' => 'json','start' => $start,'end' => $end,'i' => $israel ? 'on' : 'off',
            'maj' => 'on','min' => 'on','mf' => 'on','s' => 'on','mvch' => 'on','o' => 'on','F' => 'on','d' => 'on','hdp' => 1,'lg' => $providerLanguage,
        ]);
        if (
            !is_array($data['range'] ?? null) || !is_string($data['range']['start'] ?? null) || !is_string($data['range']['end'] ?? null)
            || !is_array($data['items'] ?? null) || !array_is_list($data['items'])
        ) {
            throw new ProviderException('Malformed calendar response.');
        }
        self::providerDate($data['range']['start']);
        self::providerDate($data['range']['end']);
        if ($data['range']['start'] > $start || $data['range']['end'] < $end) {
            throw new ProviderException('Calendar response does not cover the requested date range.');
        }
        foreach ($data['items'] as $item) {
            if (!is_array($item) || !is_string($item['date'] ?? null) || !is_string($item['category'] ?? null)) {
                throw new ProviderException('Malformed calendar event.');
            }
            self::providerDate($item['date']);
            if ($item['date'] < $start || $item['date'] > $end) {
                throw new ProviderException('Out-of-range calendar event.');
            }
            if (isset($item['yomtov']) && !is_bool($item['yomtov'])) {
                throw new ProviderException('Malformed calendar holiday classification.');
            }
            if ($item['category'] === 'omer' && isset($item['omer']['count']['he']) && !is_string($item['omer']['count']['he'])) {
                throw new ProviderException('Malformed Omer count metadata.');
            }
        }
        return $data + ['requested_language' => $language,'provider_language' => $providerLanguage];
    }
    /** Official Hebrew/French/Spanish/Russian aliases; Portuguese was checked against the live public API. */
    public static function providerLanguage(string $language): string
    {
        return match ($language) {
            'he','ru','fr','es'=>$language,'en'=>'s','pt-br'=>'pt',default=>throw new \InvalidArgumentException('Unsupported calendar language.')
        };
    }
    public function solar(array $settings, string $start, string $end): array
    {
        self::range($start, $end);
        $zone = new \DateTimeZone($settings['timezone_id']);
        foreach (['latitude' => 90,'longitude' => 180] as $name => $bound) {
            if (!isset($settings[$name]) || !is_numeric($settings[$name]) || !is_finite((float)$settings[$name]) || abs((float)$settings[$name]) > $bound) {
                throw new \InvalidArgumentException('Invalid solar coordinates.');
            }
        }
        $data = $this->http->get('https://www.hebcal.com/zmanim', [
            'cfg' => 'json','latitude' => $settings['latitude'],'longitude' => $settings['longitude'],
            'tzid' => $settings['timezone_id'],'start' => $start,'end' => $end,
        ]);
        if (($data['date']['start'] ?? null) !== $start || ($data['date']['end'] ?? null) !== $end || !is_array($data['times'] ?? null) || $data['times'] === []) {
            throw new ProviderException('Solar response does not cover the requested date range.');
        }
        if (isset($data['location']['tzid']) && $data['location']['tzid'] !== $settings['timezone_id']) {
            throw new ProviderException('Solar provider timezone mismatch.');
        }
        $dailyTimes = array_intersect_key($data['times'], array_flip(self::DAILY_SOLAR_FIELDS));
        foreach ($dailyTimes as $values) {
            if (!is_array($values)) {
                throw new ProviderException('Malformed solar time series.');
            }
        }
        if (!array_key_exists('sunset', $dailyTimes)) {
            throw new ProviderException('Missing sunset time series.');
        }
        $days = [];
        for ($date = new \DateTimeImmutable($start, new \DateTimeZone('UTC')); $date->format('Y-m-d') <= $end; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $days[$key] = [];
            foreach ($dailyTimes as $name => $values) {
                if (!array_key_exists($key, $values)) {
                    throw new ProviderException('Incomplete solar date coverage.');
                }
                $value = $values[$key];
                if ($value === null) {
                    $days[$key][$name] = null;
                    continue;
                }
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value)) {
                    throw new ProviderException('Invalid solar timestamp.');
                }
                $instant = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
                $errors = \DateTimeImmutable::getLastErrors();
                if (
                    $instant === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
                    || $instant->setTimezone($zone)->format('Y-m-d\TH:i:sP') !== $value || !self::matchesSolarDay($name, $key, $instant, $zone)
                ) {
                    throw new ProviderException('Solar date/timezone mismatch.');
                }
                $days[$key][$name] = $instant->getTimestamp();
            }
            if (isset($days[$key]['chatzotNight'])) {
                $midnight = $days[$key]['chatzotNight'];
                $sunrise = $days[$key]['sunrise'] ?? null;
                $previousSunset = $days[$date->modify('-1 day')->format('Y-m-d')]['sunset'] ?? null;
                if (($sunrise !== null && $midnight >= $sunrise) || ($previousSunset !== null && $midnight <= $previousSunset)) {
                    throw new ProviderException('Solar midnight is outside its preceding sunset/sunrise night.');
                }
            }
            if (isset($days[$key]['sunset'])) {
                foreach (['tzeit85deg','tzeit7083deg'] as $night) {
                    if (isset($days[$key][$night]) && $days[$key][$night] <= $days[$key]['sunset']) {
                        throw new ProviderException('Nightfall precedes sunset.');
                    }
                }
                if (isset($days[$key]['alotHaShachar']) && $days[$key]['alotHaShachar'] >= $days[$key]['sunset']) {
                    throw new ProviderException('Dawn follows sunset.');
                }
            }
        }
        $this->lastSolarMetadata = $data['__shabbat_cache'] ?? [];
        return $days;
    }
    private static function matchesSolarDay(string $name, string $day, \DateTimeImmutable $instant, \DateTimeZone $zone): bool
    {
        if ($name !== 'chatzotNight') {
            return $instant->format('Y-m-d') === $day;
        }
        // Hebcal assigns the preceding solar night to this day's sunrise. In winter,
        // its midpoint can be on the previous civil date. Preserve the exact instant,
        // allowing only this preceding night; all operational daytime fields stay strict.
        $noon = new \DateTimeImmutable($day . ' 12:00:00', $zone);
        return $instant >= $noon->modify('-1 day') && $instant < $noon;
    }
    private static function range(string $start, string $end): void
    {
        self::date($start);
        self::date($end);
        if ($start > $end || (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days > 179) {
            throw new \InvalidArgumentException('Provider date range must be ordered and at most 180 inclusive days.');
        }
    }
    private static function providerDate(string $value): void
    {
        try {
            self::date($value);
        } catch (\InvalidArgumentException $error) {
            throw new ProviderException('Invalid provider civil date.', 0, $error);
        }
    }
    public static function date(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid Gregorian date.');
        }
    }
}
