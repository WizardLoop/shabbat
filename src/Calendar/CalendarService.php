<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Calendar;

use Shabbat\Time\Clock;
use Shabbat\Http\ProviderException;
use Shabbat\Content\OmerText;

final class CalendarService
{
    private array $cache = [];
    public function __construct(private HebcalProvider $provider, private Clock $clock, private ?CalendarEngine $engine = null)
    {
        $this->engine ??= new CalendarEngine();
    }
    public function snapshot(array $settings, ?\DateTimeImmutable $now = null): array
    {
        $now ??= $this->clock->now();
        $data = $this->data($settings, $now);
        return $this->engine->evaluate($settings, $data['calendar'], $data['solar'], $now) + [
            'evaluated_at' => $now->getTimestamp(), 'local_date' => $now->setTimezone(new \DateTimeZone($settings['timezone_id']))->format('Y-m-d'),
            'calendar_items' => $data['calendar']['items'],
            'fetched_at' => $data['fetched_at'],'stale' => $data['stale'],'source' => 'Hebcal','calculation_version' => 'shabbat-calendar-2'];
    }
    /** Evaluate after provider I/O; explicit snapshot timestamps remain available for planning. */
    public function snapshotNow(array $settings): array
    {
        $data = $this->data($settings, $this->clock->now());
        $now = $this->clock->now();
        return $this->engine->evaluate($settings, $data['calendar'], $data['solar'], $now) + [
            'evaluated_at' => $now->getTimestamp(),
            'local_date' => $now->setTimezone(new \DateTimeZone($settings['timezone_id']))->format('Y-m-d'),
            'calendar_items' => $data['calendar']['items'],
            'fetched_at' => $data['fetched_at'], 'stale' => $data['stale'], 'source' => 'Hebcal',
            'calculation_version' => 'shabbat-calendar-2',
        ];
    }
    public function zmanim(array $settings, string $date): array
    {
        HebcalProvider::date($date);
        $local = new \DateTimeImmutable($date, new \DateTimeZone($settings['timezone_id']));
        $data = $this->data($settings, $local);
        $hdate = null;
        foreach ($data['calendar']['items'] as $item) {
            if (($item['category'] ?? '') === 'hebdate' && $item['date'] === $date) {
                $hdate = $item['hebrew'] ?? $item['hdate'] ?? null;
                break;
            }
        }
        return ['date' => $date,'hdate' => $hdate,'location' => $settings['location_name'],'timezone_id' => $settings['timezone_id'],
            'times' => $data['solar'][$date] ?? [],'next_day_times' => $data['solar'][$local->modify('+1 day')->format('Y-m-d')] ?? [],
            'source' => 'Hebcal','fetched_at' => $data['fetched_at'],'stale' => $data['stale'],
            'methods' => ['candle_minutes' => CalendarEngine::candleMinutes($settings),'havdalah_method' => $settings['havdalah_method'],'havdalah_value' => $settings['havdalah_value']]];
    }
    public function daf(array $settings, string $date): array
    {
        HebcalProvider::date($date);
        $data = $this->provider->calendar($date, $date, (bool)$settings['israel'], $settings['language'] ?? 'he');
        foreach ($data['items'] as $item) {
            if ($item['category'] !== 'dafyomi' || $item['date'] !== $date) {
                continue;
            }
            $url = $item['link'] ?? '';
            if (!is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array(parse_url($url, PHP_URL_HOST), ['www.sefaria.org','sefaria.org','www.hebcal.com','hebcal.com'], true)) {
                throw new ProviderException('Untrusted study link.');
            }
            $title = $item['title_orig'] ?? $item['title'] ?? '';
            if (!is_string($title) || !preg_match('/^(.+?)\s+([0-9]{1,3})$/uD', $title, $parts) || (int)$parts[2] < 2) {
                throw new ProviderException('Malformed Daf Yomi tractate/page.');
            }
            return $item + ['tractate' => $parts[1],'page' => (int)$parts[2],'source' => 'Hebcal','fetched_at' => $data['__shabbat_cache']['fetched_at'] ?? $this->clock->now()->getTimestamp()];
        }
        throw new ProviderException('Daf Yomi is unavailable for this date.');
    }
    public function omer(array $settings, ?\DateTimeImmutable $now = null): array
    {
        $now ??= $this->clock->now();
        $local = $now->setTimezone(new \DateTimeZone($settings['timezone_id']));
        $data = $this->data($settings, $now);
        $day = $local->format('Y-m-d');
        $sun = $data['solar'][$day] ?? [];
        $sunset = $sun['sunset'] ?? null;
        $dawn = $sun['alotHaShachar'] ?? null;
        $stamp = $now->getTimestamp();
        $night = CalendarEngine::nightfall($sun, $settings, 'omer_nightfall');
        if ($sunset === null || $dawn === null || $night === null) {
            throw new ProviderException('Omer sunset/nightfall/dawn is unavailable.');
        }
        $evening = $stamp >= $sunset;
        $beforeDawn = $stamp < $dawn;
        $studyDate = $evening ? $local->modify('+1 day')->format('Y-m-d') : $day;
        $count = null;
        $item = null;
        foreach ($data['calendar']['items'] as $candidate) {
            if ($candidate['category'] === 'omer' && $candidate['date'] === $studyDate && preg_match('/^Omer ([0-9]+)$/D', $candidate['title_orig'] ?? '', $match)) {
                $count = (int)$match[1];
                $item = $candidate;
                if ($count < 1 || $count > 49) {
                    throw new ProviderException('Provider Omer count is outside 1..49.');
                }
                break;
            }
        }
        $previousDate = $local->modify('-1 day')->format('Y-m-d');
        $previousNight = CalendarEngine::nightfall($data['solar'][$previousDate] ?? [], $settings, 'omer_nightfall');
        $applicableNight = $evening ? $night : $previousNight;
        $phase = $evening ? ($stamp < $night ? 'twilight' : 'night') : ($beforeDawn ? 'night' : 'day');
        $nightEligible = $count !== null && $applicableNight !== null && $phase === 'night' && $stamp >= $applicableNight;
        $state = $this->snapshot($settings, $now);
        $quiet = $state['quiet_now'];
        $completion = $count !== null && $phase === 'day' && (bool)($settings['omer_day_completion'] ?? false) && !$quiet;
        $nextDawn = $data['solar'][$local->modify('+1 day')->format('Y-m-d')]['alotHaShachar'] ?? null;
        return ['season' => $count !== null,'day' => $count,'date' => $studyDate,'hdate' => $item['hdate'] ?? null,
            'count_text' => $count !== null ? OmerText::count($count, $settings['omer_nusach'] ?? 'ashkenaz', $settings['language'] ?? 'he') : null,
            'nightfall' => $applicableNight,'next_nightfall' => $night,'sunset' => $sunset,'dawn' => $dawn,
            'night_date' => $evening ? $day : $previousDate,'phase' => $phase,'eligible' => $nightEligible && !$quiet,'day_completion' => $completion,
            'expires_at' => $evening ? $nextDawn : $dawn,'source' => 'Hebcal; traditional counting text','quiet_now' => $quiet,
            'fetched_at' => $data['fetched_at'],'stale' => $data['stale']];
    }
    private function data(array $settings, \DateTimeImmutable $now): array
    {
        $local = $now->setTimezone(new \DateTimeZone($settings['timezone_id']));
        $key = hash('sha256', json_encode([$settings['latitude'],$settings['longitude'],$settings['timezone_id'],(bool)$settings['israel'],$settings['language'] ?? 'he',$local->format('Y-m')], JSON_THROW_ON_ERROR));
        $start = $local->modify('first day of this month')->modify('-8 days')->format('Y-m-d');
        $end = $local->modify('last day of this month')->modify('+40 days')->format('Y-m-d');
        if (
            isset($this->cache[$key]) && $this->cache[$key]['fetched_at'] + 21600 > $this->clock->now()->getTimestamp()
            && $this->cache[$key]['calendar']['range']['start'] <= $start && $this->cache[$key]['calendar']['range']['end'] >= $end
        ) {
            return $this->cache[$key];
        }
        $calendar = $this->provider->calendar($start, $end, (bool)$settings['israel'], $settings['language'] ?? 'he');
        $solar = $this->provider->solar($settings, $start, $end);
        $meta = $this->provider->lastSolarMetadata();
        $calendarMeta = $calendar['__shabbat_cache'] ?? [];
        $fetched = min($meta['fetched_at'] ?? $this->clock->now()->getTimestamp(), $calendarMeta['fetched_at'] ?? $this->clock->now()->getTimestamp());
        $stale = (bool)($meta['stale'] ?? false) || (bool)($calendarMeta['stale'] ?? false);
        if (count($this->cache) >= 64) {
            array_shift($this->cache);
        }
        return $this->cache[$key] = ['calendar' => $calendar,'solar' => $solar,'fetched_at' => $fetched,'stale' => $stale];
    }
}
