<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Calendar;

use Amp\CancelledException;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\TimeoutException;
use Shabbat\Http\AmpJsonClient;
use Shabbat\Http\JsonClient;
use Shabbat\Http\ProviderException;
use Shabbat\Http\SharedJsonClient;
use Shabbat\Time\Clock;

/** Public provider checks only: never opens application storage or starts Telegram. */
final class CalendarDiagnostics
{
    private JsonClient $http;

    public function __construct(private Clock $clock, ?JsonClient $http = null)
    {
        $this->http = new SharedJsonClient($http ?? new AmpJsonClient(), $clock);
    }

    /** @param list<string> $arguments */
    public static function parseArguments(array $arguments): array
    {
        $options = [];
        for ($index = 0; $index < count($arguments); $index++) {
            if (!preg_match('/^--(date|latitude|longitude|timezone|israel|language)(?:=(.*))?$/D', $arguments[$index], $match)) {
                throw new \InvalidArgumentException('Invalid diagnostic option.');
            }
            $name = $match[1];
            $value = $match[2] ?? ($arguments[++$index] ?? null);
            if (isset($options[$name]) || !is_string($value) || $value === '' || str_starts_with($value, '--')) {
                throw new \InvalidArgumentException('Invalid diagnostic option.');
            }
            $options[$name] = $value;
        }
        self::settings($options);
        if (isset($options['date'])) {
            HebcalProvider::date($options['date']);
        }
        return $options;
    }

    /** These operational defaults are checked against the actual migrated group defaults. */
    public static function settings(array $options = []): array
    {
        if (array_diff(array_keys($options), ['date', 'latitude', 'longitude', 'timezone', 'israel', 'language']) !== []) {
            throw new \InvalidArgumentException('Invalid diagnostic option.');
        }
        foreach ($options as $value) {
            if (!is_string($value) || $value === '') {
                throw new \InvalidArgumentException('Invalid diagnostic option.');
            }
        }
        $settings = [
            'location_name' => 'Jerusalem', 'country' => 'IL', 'geoname_id' => 281184,
            'latitude' => 31.76904, 'longitude' => 35.21633, 'timezone_id' => 'Asia/Jerusalem',
            'location_source' => 'default', 'israel' => true, 'language' => 'he', 'candle_minutes' => null,
            'havdalah_method' => 'degrees', 'havdalah_value' => 8.5,
            'close_before_candles_minutes' => 0, 'open_after_havdalah_minutes' => 0,
            'mode' => 'lock', 'shabbat_enabled' => true, 'holidays_enabled' => true,
            'omer_nightfall_method' => 'degrees', 'omer_nightfall_value' => 8.5,
            'omer_nusach' => 'ashkenaz', 'omer_day_completion' => false,
        ];
        if (isset($options['latitude']) !== isset($options['longitude'])) {
            throw new \InvalidArgumentException('Both coordinates are required.');
        }
        if (isset($options['latitude'])) {
            foreach (['latitude' => 90, 'longitude' => 180] as $field => $bound) {
                if (!is_numeric($options[$field]) || !is_finite((float) $options[$field]) || abs((float) $options[$field]) > $bound) {
                    throw new \InvalidArgumentException('Invalid diagnostic coordinates.');
                }
                $settings[$field] = (float) $options[$field];
            }
            if ($settings['latitude'] !== 31.76904 || $settings['longitude'] !== 35.21633) {
                $settings['geoname_id'] = null;
                $settings['location_name'] = 'Custom coordinates';
                $settings['country'] = '';
                $settings['location_source'] = 'coordinates';
            }
        }
        if (isset($options['timezone'])) {
            if (!in_array($options['timezone'], \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
                throw new \InvalidArgumentException('Invalid diagnostic IANA timezone.');
            }
            $settings['timezone_id'] = $options['timezone'];
        }
        if (isset($options['israel'])) {
            $settings['israel'] = match ($options['israel']) {
                'on', 'true', '1' => true,
                'off', 'false', '0' => false,
                default => throw new \InvalidArgumentException('Invalid diagnostic Israel scheme.'),
            };
        }
        if (isset($options['language'])) {
            HebcalProvider::providerLanguage($options['language']);
            $settings['language'] = $options['language'];
        }
        return $settings;
    }

    public function run(array $options = []): array
    {
        $settings = self::settings($options);
        $zone = new \DateTimeZone($settings['timezone_id']);
        if (isset($options['date'])) {
            HebcalProvider::date($options['date']);
            // An explicit date means local noon, making historical/future checks reproducible.
            $instant = new \DateTimeImmutable($options['date'] . ' 12:00:00', $zone);
        } else {
            $instant = $this->clock->now()->setTimezone($zone);
        }
        $date = $instant->format('Y-m-d');
        $start = $instant->modify('first day of this month')->modify('-8 days')->format('Y-m-d');
        $end = $instant->modify('last day of this month')->modify('+40 days')->format('Y-m-d');
        $provider = new HebcalProvider($this->http);
        $service = new CalendarService($provider, $this->clock);
        $stages = [];
        $stages['calendar'] = $this->stage('provider', static function () use ($provider, $start, $end, $settings): array {
            $data = $provider->calendar($start, $end, $settings['israel'], $settings['language']);
            return ['event_count' => count($data['items']), 'range' => $data['range']];
        });
        $stages['solar'] = $this->stage('provider', static function () use ($provider, $start, $end, $settings): array {
            $days = $provider->solar($settings, $start, $end);
            return ['day_count' => count($days)];
        });
        if ($stages['calendar']['status'] === 'ok' && $stages['solar']['status'] === 'ok') {
            $stages['shabbat_holidays'] = $this->stage('calculation', function () use ($service, $settings, $instant, $zone): array {
                $state = $service->snapshot($settings, $instant);
                return [
                    'event_count' => count($state['events']), 'is_shabbat' => $state['is_shabbat'],
                    'is_yom_tov' => $state['is_yom_tov'], 'quiet_now' => $state['quiet_now'],
                    'next_close_at' => $this->localTime($state['next_close_at'], $zone),
                    'next_open_at' => $this->localTime($state['next_open_at'], $zone),
                ];
            });
            $stages['zmanim'] = $this->stage('calculation', function () use ($service, $settings, $date, $zone): array {
                $data = $service->zmanim($settings, $date);
                return [
                    'date' => $data['date'], 'available_time_count' => count(array_filter($data['times'], static fn($value): bool => $value !== null)),
                    'sunrise' => $this->localTime($data['times']['sunrise'] ?? null, $zone),
                    'sunset' => $this->localTime($data['times']['sunset'] ?? null, $zone),
                    'nightfall' => $this->localTime(CalendarEngine::nightfall($data['times'], $settings), $zone),
                ];
            });
            $stages['omer'] = $this->stage('calculation', static function () use ($service, $settings, $instant): array {
                $data = $service->omer($settings, $instant);
                return [
                    'state' => $data['season'] ? 'active_season' : 'valid_inactive',
                    'season' => $data['season'], 'day' => $data['day'], 'phase' => $data['phase'],
                    'eligible' => $data['eligible'], 'date' => $data['date'],
                ];
            });
        } else {
            foreach (['shabbat_holidays', 'zmanim', 'omer'] as $name) {
                $stages[$name] = ['status' => 'blocked', 'reason' => 'provider_stage_failed'];
            }
        }
        // Daf has no dependency on solar data. A distinct one-day query also diagnoses this reported asymmetry.
        $stages['daf'] = $this->stage('calculation', static function () use ($service, $settings, $date): array {
            $data = $service->daf($settings, $date);
            return ['date' => $data['date'], 'page' => $data['page']];
        });
        return [
            'diagnostic' => 'calendar-public-v1', 'read_only' => true, 'credentials_required' => false,
            'persisted_data_used' => false, 'source' => 'Hebcal',
            'input' => [
                'date' => $date, 'instant' => $instant->format(DATE_ATOM),
                'latitude' => $settings['latitude'], 'longitude' => $settings['longitude'],
                'timezone_id' => $settings['timezone_id'], 'israel' => $settings['israel'],
                'language' => $settings['language'], 'candle_minutes' => CalendarEngine::candleMinutes($settings),
            ],
            'window' => ['start' => $start, 'end' => $end], 'stages' => $stages,
            'ok' => count(array_filter($stages, static fn(array $stage): bool => $stage['status'] !== 'ok')) === 0,
        ];
    }

    private function stage(string $kind, \Closure $check): array
    {
        try {
            return ['status' => 'ok'] + $check();
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            return ['status' => 'failed', 'kind' => $kind, 'reason' => self::reason($error, $kind)];
        }
    }

    private static function reason(\Throwable $error, string $kind): string
    {
        if ($error instanceof TimeoutException || $error instanceof \Amp\TimeoutException) {
            return 'provider_transport_timeout';
        }
        if ($error instanceof HttpException) {
            return 'provider_transport_failed';
        }
        if ($error instanceof ProviderException) {
            // Never emit exception text, response bodies, query URLs or traces. Only this fixed vocabulary escapes.
            return match ($error->getMessage()) {
                'Malformed calendar response.' => 'calendar_schema_invalid',
                'Calendar response does not cover the requested date range.', 'Calendar coverage is stale.' => 'calendar_range_incomplete',
                'Malformed calendar event.', 'Invalid provider civil date.', 'Out-of-range calendar event.',
                'Malformed calendar holiday classification.', 'Malformed Omer count metadata.' => 'calendar_event_invalid',
                'Solar response does not cover the requested date range.' => 'solar_range_incomplete',
                'Solar provider timezone mismatch.' => 'solar_timezone_mismatch',
                'Malformed solar time series.' => 'solar_schema_invalid',
                'Missing sunset time series.' => 'solar_required_series_missing',
                'Incomplete solar date coverage.' => 'solar_daily_coverage_incomplete',
                'Invalid solar timestamp.' => 'solar_timestamp_invalid',
                'Solar date/timezone mismatch.' => 'solar_timestamp_timezone_mismatch',
                'Solar midnight is outside its preceding sunset/sunrise night.', 'Nightfall precedes sunset.',
                'Dawn follows sunset.' => 'solar_time_order_invalid',
                'Required sunset/nightfall unavailable. Automatic permissions remain unchanged.',
                'Intermediate festival candle-lighting nightfall is unavailable.',
                'Omer sunset/nightfall/dawn is unavailable.' => 'calculation_required_times_unavailable',
                'Invalid provider JSON.', 'Provider did not return an object.' => 'provider_json_invalid',
                'Daf Yomi is unavailable for this date.' => 'daf_unavailable',
                'Untrusted study link.', 'Malformed Daf Yomi tractate/page.' => 'daf_invalid',
                default => preg_match('/^Provider HTTP status [1-5][0-9]{2}$/D', $error->getMessage()) ? 'provider_http_error' : 'provider_validation_failed',
            };
        }
        return $kind === 'provider' ? 'provider_internal_error' : 'calculation_internal_error';
    }

    private function localTime(?int $timestamp, \DateTimeZone $zone): ?string
    {
        return $timestamp === null ? null : (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format(DATE_ATOM);
    }
}
