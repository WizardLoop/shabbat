<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Calendar;

use Shabbat\Http\ProviderException;

final class CalendarEngine
{
    public static function candleMinutes(array $settings): int
    {
        if (isset($settings['candle_minutes'])) {
            return (int)$settings['candle_minutes'];
        }
        return match ((int)($settings['geoname_id'] ?? 0)) {
            281184=>40, 294801, 293067=>30, default=>18
        };
    }
    public static function nightfall(array $sun, array $settings, string $prefix = 'havdalah'): ?int
    {
        $method = $settings[$prefix . '_method'] ?? 'degrees';
        $value = (float)($settings[$prefix . '_value'] ?? 8.5);
        if ($method === 'degrees') {
            $key = match ($value) {
                8.5=>'tzeit85deg', 7.083=>'tzeit7083deg', default=>throw new \InvalidArgumentException('Unsupported nightfall angle.')
            };
            return $sun[$key] ?? null;
        }
        if ($method !== 'minutes' || $value < 1 || $value > 180 || floor($value) !== $value) {
            throw new \InvalidArgumentException('Invalid nightfall method/minutes.');
        }
        return isset($sun['sunset']) ? $sun['sunset'] + (int)$value * 60 : null;
    }
    public function evaluate(array $settings, array $calendar, array $solar, \DateTimeImmutable $now): array
    {
        $zone = new \DateTimeZone($settings['timezone_id']);
        $local = $now->setTimezone($zone);
        $dateKey = $local->format('Y-m-d');
        if (($calendar['range']['start'] ?? '9999') > $local->modify('-1 day')->format('Y-m-d') || ($calendar['range']['end'] ?? '0000') < $local->modify('+1 day')->format('Y-m-d')) {
            throw new ProviderException('Calendar coverage is stale.');
        }
        $byDay = [];
        foreach ($calendar['items'] as $item) {
            $byDay[substr($item['date'], 0, 10)][] = $item;
        }
        $intervals = [];
        foreach ($solar as $day => $sun) {
            $dayDate = new \DateTimeImmutable($day, $zone);
            $saturday = $dayDate->format('N') === '6';
            $holidays = array_values(array_filter($byDay[$day] ?? [], fn(array $item): bool => ($item['yomtov'] ?? false) === true));
            if (!$saturday && $holidays === []) {
                continue;
            }
            $prev = $dayDate->modify('-1 day')->format('Y-m-d');
            if (!isset($solar[$prev])) {
                continue;
            }
            $sunset = $solar[$prev]['sunset'] ?? null;
            $havdalah = self::nightfall($sun, $settings);
            if ($sunset === null || $havdalah === null) {
                throw new ProviderException('Required sunset/nightfall unavailable. Automatic permissions remain unchanged.');
            }
            $candles = $sunset - self::candleMinutes($settings) * 60;
            $startAt = $candles;
            $previousSaturday = $dayDate->modify('-1 day')->format('N') === '6';
            $previousFestival = array_filter($byDay[$prev] ?? [], static fn(array $item): bool=>($item['yomtov'] ?? false) === true) !== [];
            if (!$saturday && ($previousSaturday || $previousFestival)) {
                // Lighting for a second festival day or after Shabbat waits for the previous day's nightfall.
                $candles = self::nightfall($solar[$prev], $settings);
                if ($candles === null) {
                    throw new ProviderException('Intermediate festival candle-lighting nightfall is unavailable.');
                }
                $startAt = $sunset;
            }
            $enabled = ($saturday && (bool)($settings['shabbat_enabled'] ?? true)) || ($holidays !== [] && (bool)($settings['holidays_enabled'] ?? true));
            $intervals[] = [
                'event_key' => $day, 'start_at' => $startAt,'end_at' => $havdalah,'candles_at' => $candles,'havdalah_at' => $havdalah,
                'close_at' => $candles - (int)($settings['close_before_candles_minutes'] ?? 0) * 60,
                'open_at' => $havdalah + (int)($settings['open_after_havdalah_minutes'] ?? 0) * 60,
                'is_shabbat' => $saturday,'is_yom_tov' => $holidays !== [], 'enabled' => $enabled,
                'title' => $holidays !== [] ? implode(' / ', array_column($holidays, ($settings['language'] ?? 'he') === 'he' ? 'hebrew' : 'title')) : (($settings['language'] ?? 'he') === 'he' ? 'שבת' : 'Shabbat'),
                'days' => [$day], 'intermediate' => [],
            ];
        }
        usort($intervals, fn(array $a, array $b): int=>$a['start_at'] <=> $b['start_at']);
        $all = $this->merge($intervals);
        $active = null;
        $next = null;
        $stamp = $now->getTimestamp();
        foreach ($all as $event) {
            if ($event['start_at'] <= $stamp && $event['end_at'] > $stamp) {
                $active = $event;
            } elseif ($event['start_at'] > $stamp && $next === null) {
                $next = $event;
            }
        }
        $operational = $this->merge(array_values(array_filter($intervals, fn(array $e): bool=>$e['enabled'])));
        $lockEvent = null;
        $nextLock = null;
        foreach ($operational as $event) {
            if ($event['close_at'] <= $stamp && $event['open_at'] > $stamp) {
                $lockEvent = $event;
            } elseif ($event['close_at'] > $stamp && $nextLock === null) {
                $nextLock = $event;
            }
        }
        // Sequence metadata stays merged, but current-day flags come from individual active segments.
        $activeShabbat = false;
        $activeYomTov = false;
        foreach ($intervals as $segment) {
            if ($segment['start_at'] <= $stamp && $segment['end_at'] > $stamp) {
                $activeShabbat = $activeShabbat || $segment['is_shabbat'];
                $activeYomTov = $activeYomTov || $segment['is_yom_tov'];
            }
        }
        $metadataDate = $dateKey;
        if (isset($solar[$dateKey]['sunset']) && $stamp >= $solar[$dateKey]['sunset']) {
            $metadataDate = $local->modify('+1 day')->format('Y-m-d');
        }
        $localItems = $byDay[$metadataDate] ?? [];
        $chol = false;
        $tisha = false;
        foreach ($localItems as $item) {
            // Structured original event names supplied by Hebcal distinguish intermediate festival/fast days.
            $original = (string)($item['title_orig'] ?? $item['title'] ?? '');
            $chol = $chol || str_contains($original, "(CH'M)") || str_contains($original, "(CH''M)");
            // Fast dates supplied by Hebcal include observed/postponed days. Do not infer 9 Av yourself.
            $tisha = $tisha || (str_starts_with($original, "Tish'a B'Av") && !str_starts_with($original, "Erev"));
        }
        // A current civil day's fast continues through twilight until the chosen nightfall.
        foreach ($byDay[$dateKey] ?? [] as $item) {
            if (str_starts_with((string)($item['title_orig'] ?? $item['title'] ?? ''), "Tish'a B'Av")) {
                $fastEnd = self::nightfall($solar[$dateKey] ?? [], $settings);
                $tisha = $tisha || ($fastEnd !== null && $stamp < $fastEnd);
            }
        }
        return [
            'current_event' => $active, 'next_event' => $next,'lock_event' => $lockEvent,
            'is_shabbat' => $activeShabbat,'is_yom_tov' => $activeYomTov,
            'is_chol_hamoed' => $chol,'is_tisha_bav' => $tisha,'is_work_prohibited_now' => $active !== null,
            'should_group_be_locked' => $lockEvent !== null && in_array($settings['mode'] ?? 'off', ['lock','lock_messages'], true),
            'quiet_now' => $lockEvent !== null || $active !== null,
            'next_close_at' => $nextLock['close_at'] ?? null,'next_open_at' => $lockEvent['open_at'] ?? $nextLock['open_at'] ?? null,
            'current_dawn_at' => $solar[$dateKey]['alotHaShachar'] ?? null,'current_sunset_at' => $solar[$dateKey]['sunset'] ?? null,
            'current_nightfall_at' => self::nightfall($solar[$dateKey] ?? [], $settings),
            'next_dawn_at' => $solar[$local->modify('+1 day')->format('Y-m-d')]['alotHaShachar'] ?? null,
            'events' => $all,'operational_events' => $operational,'coverage' => $calendar['range'],
        ];
    }
    private function merge(array $intervals): array
    {
        $merged = [];
        foreach ($intervals as $event) {
            $last = count($merged) - 1;
            // Adjacent prohibited civil days form one continuous sequence even when candles are at nightfall.
            if ($last >= 0 && (end($merged[$last]['days']) === (new \DateTimeImmutable($event['days'][0], new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d') || $merged[$last]['open_at'] >= $event['close_at'])) {
                $merged[$last]['intermediate'][] = $event;
                $merged[$last]['days'] = array_merge($merged[$last]['days'], $event['days']);
                $merged[$last]['end_at'] = max($merged[$last]['end_at'], $event['end_at']);
                $merged[$last]['havdalah_at'] = $event['havdalah_at'];
                $merged[$last]['open_at'] = max($merged[$last]['open_at'], $event['open_at']);
                $merged[$last]['is_shabbat'] = $merged[$last]['is_shabbat'] || $event['is_shabbat'];
                $merged[$last]['is_yom_tov'] = $merged[$last]['is_yom_tov'] || $event['is_yom_tov'];
                $merged[$last]['event_key'] = implode('+', $merged[$last]['days']);
                $merged[$last]['title'] .= ' / ' . $event['title'];
            } else {
                $merged[] = $event;
            }
        }
        return $merged;
    }
}
