<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Content;

use Shabbat\Calendar\CalendarEngine;
use Shabbat\Location\Place;
use Shabbat\UI\Lang;

/** Pure rendering of verified group-specific calendar data; never fetches or guesses times. */
final class ShabbatTimesFormatter
{
    public function __construct(private ?Lang $lang = null)
    {
        $this->lang ??= new Lang();
    }

    public function format(array $settings, array $snapshot, ?array $event = null): array
    {
        return $this->period($settings, $snapshot, $event);
    }

    /** Metadata may be shortened to keep verified Shabbat and Yom Tov boundaries visible. */
    private function period(array $settings, array $snapshot, ?array $event, int $metadataLimit = 1200): array
    {
        $language = Lang::canonical($settings['language'] ?? 'he');
        $t = fn(string $key, array $values = []): string => $this->lang->text($language, $key, $values);
        $zone = new \DateTimeZone($settings['timezone_id']);
        $time = static fn(mixed $stamp): string => is_int($stamp) ? (new \DateTimeImmutable('@' . $stamp))->setTimezone($zone)->format('Y-m-d H:i') : $t('unavailable');
        $event ??= $snapshot['current_event'] ?? $snapshot['next_event'] ?? null;
        $rows = ['<b>' . $t('times_heading') . '</b>', $t('times_location', ['location' => self::locationName($settings), 'timezone' => $zone->getName()])];
        if ($event === null || isset($snapshot['error'])) {
            $rows[] = $t('times_data_missing');
            return $this->payload($rows);
        }
        $days = array_values(array_filter($event['days'] ?? [], static fn(mixed $day): bool => is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) === 1));
        if ($days === [] && is_string($event['event_key'] ?? null)) {
            $days = [$event['event_key']];
        }
        $rows[] = '';
        $title = ($event['is_shabbat'] ?? false) === true && ($event['is_yom_tov'] ?? false) !== true
            ? $t('times_shabbat_title') : ($event['title'] ?? null);
        if (is_string($title) && $title !== '') {
            $rows[] = '<b>' . htmlspecialchars(mb_substr($title, 0, 180), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        }
        $rows[] = $t('times_dates', ['dates' => implode(' / ', $days) ?: $t('unavailable')]);
        if (count($days) > 1) {
            $rows[] = $t(in_array($settings['mode'] ?? 'off', ['lock','lock_messages'], true) ? 'times_sequence' : 'times_sequence_calendar');
        }
        $metadata = [];
        $calendarItems = $snapshot['calendar_items'] ?? [];
        foreach ($days as $day) {
            $hdate = null;
            foreach ($calendarItems as $item) {
                if (($item['date'] ?? '') !== $day) {
                    continue;
                }
                if ($hdate === null && is_string($item['hdate'] ?? null)) {
                    $hdate = $item['hdate'];
                }
                if (($item['category'] ?? '') === 'hebdate' && $language === 'he' && is_string($item['hebrew'] ?? null)) {
                    $hdate = $item['hebrew'];
                }
                $category = $item['category'] ?? '';
                $key = match ($category) {
                    'parashat' => 'times_parasha', 'mevarchim' => 'times_mevarchim', 'holiday','roshchodesh','molad' => 'times_holiday',
                    default => null,
                };
                if ($key === null) {
                    continue;
                }
                $title = $language === 'he' ? ($item['hebrew'] ?? $item['title'] ?? '') : ($item['title'] ?? '');
                if (!is_string($title) || $title === '') {
                    continue;
                }
                $line = $t($key, ['title' => mb_substr($title, 0, 180)]);
                if (!in_array($line, $metadata, true)) {
                    $metadata[] = $line;
                }
            }
            $rows[] = $t('times_hebrew_date', ['date' => $day, 'hdate' => $hdate === null ? $t('unavailable') : mb_substr($hdate, 0, 80)]);
        }
        $metadataUnits = 0;
        foreach (array_slice($metadata, 0, 12) as $line) {
            $units = self::units(html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($metadataUnits + $units > $metadataLimit) {
                break;
            }
            $rows[] = $line;
            $metadataUnits += $units;
        }
        $rows[] = '';
        $rows[] = '<b>' . $t('times_section_schedule') . '</b>';
        $segments = [$event, ...($event['intermediate'] ?? [])];
        foreach ($segments as $index => $segment) {
            $day = is_int($segment['candles_at'] ?? null)
                ? (new \DateTimeImmutable('@' . $segment['candles_at']))->setTimezone($zone)->format('Y-m-d')
                : ($segment['days'][0] ?? ($segment['event_key'] ?? ''));
            $dayLabel = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) ? $t('times_day_name_' . (new \DateTimeImmutable($day, $zone))->format('w')) : $day;
            $rows[] = $t('times_candles', ['date' => $dayLabel, 'time' => $time($segment['candles_at'] ?? null)]);
            if ($index > 0 && ($segment['is_yom_tov'] ?? false) && !($segment['is_shabbat'] ?? false)) {
                $rows[] = '<i>' . $t('times_lighting_after') . '</i>';
            }
        }
        $rows[] = $t('times_havdalah', ['time' => $time($event['havdalah_at'] ?? null)]);
        if (in_array($settings['mode'] ?? 'off', ['lock','lock_messages'], true)) {
            foreach (['close_at' => 'candles_at', 'open_at' => 'havdalah_at'] as $groupTime => $calendarTime) {
                if (is_int($event[$groupTime] ?? null) && $event[$groupTime] !== ($event[$calendarTime] ?? null)) {
                    $rows[] = $t($groupTime === 'close_at' ? 'times_close' : 'times_open', ['time' => $time($event[$groupTime])]);
                }
            }
        }
        $rows[] = $t('times_methods', ['minutes' => CalendarEngine::candleMinutes($settings), 'method' => $t('method_' . $settings['havdalah_method']), 'value' => $settings['havdalah_value']]);
        if (array_filter($segments, static fn(array $segment): bool => !is_int($segment['candles_at'] ?? null)) !== [] || !is_int($event['havdalah_at'] ?? null)) {
            $rows[] = $t('times_data_missing');
        }
        if (($snapshot['stale'] ?? false) === true) {
            $rows[] = '';
            $rows[] = $t('times_stale');
        }
        return $this->payload($rows);
    }

    /**
     * A single current/upcoming overview. Legacy page offsets are ignored, so
     * already-delivered buttons refresh this view instead of exposing old dates.
     */
    public function overview(array $settings, array $snapshot, int $offset = 0): array
    {
        $language = Lang::canonical($settings['language'] ?? 'he');
        $t = fn(string $key, array $values = []): string => $this->lang->text($language, $key, $values);
        $reference = $snapshot['current_event'] ?? $snapshot['next_event'] ?? null;
        $evaluatedAt = is_int($snapshot['evaluated_at'] ?? null) ? $snapshot['evaluated_at'] : null;
        $allEvents = $snapshot['events'] ?? ($reference === null ? [] : [$reference]);
        $events = array_values(array_filter($allEvents, static fn(array $event): bool =>
            self::periodHasCompleteCoverage($event, $snapshot)
            && ($evaluatedAt !== null
                ? ($event['havdalah_at'] ?? $event['end_at'] ?? 0) > $evaluatedAt
                : ($event['start_at'] ?? $event['candles_at'] ?? 0) >= ($reference['start_at'] ?? $reference['candles_at'] ?? 0))));
        usort($events, static fn(array $a, array $b): int => ($a['start_at'] ?? $a['candles_at'] ?? 0) <=> ($b['start_at'] ?? $b['candles_at'] ?? 0));
        $anchor = null;
        foreach ($events as $event) {
            if (($event['is_shabbat'] ?? false) === true) {
                $anchor = $event;
                break;
            }
        }
        // Old explicit snapshots without a period list retain deterministic rendering.
        if ($anchor === null && !array_key_exists('events', $snapshot)) {
            $anchor = $reference;
        }
        $primarySnapshot = array_replace($snapshot, ['current_event' => $anchor, 'next_event' => $anchor]);
        $payload = $this->format($settings, $primarySnapshot, $anchor);
        if ($anchor === null || isset($snapshot['error'])) {
            return $payload + ['offset' => 0, 'total' => 0];
        }
        $zone = new \DateTimeZone($settings['timezone_id']);
        $time = static fn(int $stamp): string => (new \DateTimeImmutable('@' . $stamp))->setTimezone($zone)->format('Y-m-d H:i');
        // Evaluation time, never cache-fetch time or the process clock, defines "upcoming".
        $firstDate = $evaluatedAt !== null
            ? (new \DateTimeImmutable('@' . $evaluatedAt))->setTimezone($zone)->format('Y-m-d')
            : ($snapshot['local_date'] ?? $reference['days'][0] ?? $anchor['days'][0] ?? '');
        $rows = [$payload['text']];
        $calendarBlocks = [];
        $protectedFestival = false;
        $shownPeriods = [(string)($anchor['event_key'] ?? '') => true];
        $holidays = array_values(array_filter($snapshot['calendar_items'] ?? [], static fn(array $item): bool =>
            in_array($item['category'] ?? '', ['holiday','roshchodesh'], true)
            && (($item['date'] ?? '') >= $firstDate
                || (($item['yomtov'] ?? false) === true && in_array($item['date'] ?? '', $snapshot['current_event']['days'] ?? [], true)))
            && !in_array($item['date'] ?? '', $anchor['days'] ?? [], true)));
        usort($holidays, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
        $seen = [];
        foreach ($holidays as $item) {
            $title = $language === 'he' ? ($item['hebrew'] ?? $item['title'] ?? '') : ($item['title'] ?? '');
            if (!is_string($title) || $title === '') {
                continue;
            }
            $identity = $item['date'] . ':' . $title;
            if (isset($seen[$identity])) {
                continue;
            }
            $period = null;
            // Only strict provider classification gives a holiday verified boundaries.
            if (($item['yomtov'] ?? false) === true) {
                foreach ($allEvents as $candidate) {
                    if (($candidate['is_yom_tov'] ?? false) === true && in_array($item['date'], $candidate['days'] ?? [], true)) {
                        $period = $candidate;
                        break;
                    }
                }
                if ($period !== null && $evaluatedAt !== null && ($period['havdalah_at'] ?? $period['end_at'] ?? 0) <= $evaluatedAt) {
                    continue;
                }
                if ($period !== null && !self::periodHasCompleteCoverage($period, $snapshot)) {
                    $period = null;
                }
            }
            $seen[$identity] = true;
            $itemRows = [$t('times_event_title', ['title' => mb_substr($title, 0, 120), 'dates' => $item['date']])];
            $required = false;
            if (($item['yomtov'] ?? false) === true) {
                if ($period === null) {
                    $itemRows[] = '<i>' . $t('times_yomtov_unavailable') . '</i>';
                } elseif (!isset($shownPeriods[$period['event_key']])) {
                    array_push($itemRows, ...$this->periodRows($period, $t, $time));
                    $required = !$protectedFestival;
                    $protectedFestival = true;
                    $shownPeriods[$period['event_key']] = true;
                }
            }
            $calendarBlocks[] = ['rows' => $itemRows, 'required' => $required];
            if (count($seen) >= 5) {
                break;
            }
        }
        if ($calendarBlocks !== []) {
            $heading = '<b>' . $t('times_upcoming_calendar') . '</b>';
            $flatten = static fn(array $blocks): array => array_merge(...array_column($blocks, 'rows'));
            if (!$this->appendSection($rows, $heading, $flatten($calendarBlocks))) {
                // Preserve the nearest Shabbat and nearest/current verified Yom Tov.
                // Optional metadata and farther observances yield before their boundaries.
                $rows = [$this->period($settings, $primarySnapshot, $anchor, metadataLimit: 0)['text']];
                while (!$this->appendSection($rows, $heading, $flatten($calendarBlocks))) {
                    $removable = array_keys(array_filter($calendarBlocks, static fn(array $block): bool => !$block['required']));
                    if ($removable === []) {
                        throw new \LengthException('Verified calendar boundaries exceed the safe message length.');
                    }
                    unset($calendarBlocks[$removable[count($removable) - 1]]);
                    if ($calendarBlocks === []) {
                        break;
                    }
                }
            }
        }
        $payload['text'] = implode("\n", $rows);
        return $payload + ['offset' => 0, 'total' => count($events)];
    }

    /** Use a known bilingual label only for the matching curated Jerusalem location. */
    private static function locationName(array $settings): string
    {
        $name = (string)($settings['location_name'] ?? '');
        $jerusalem = Place::common()['jerusalem'];
        if (
            (int)($settings['geoname_id'] ?? 0) === $jerusalem['geoname_id']
            && ($settings['timezone_id'] ?? '') === $jerusalem['timezone_id']
            && (float)($settings['latitude'] ?? 0) === $jerusalem['latitude']
            && (float)($settings['longitude'] ?? 0) === $jerusalem['longitude']
            && in_array($name, ['Jerusalem', 'ירושלים', $jerusalem['location_name']], true)
        ) {
            return $jerusalem['location_name'];
        }
        return $name;
    }

    /** A final exit needs evidence for the following date, which may continue the festival. */
    private static function periodHasCompleteCoverage(array $event, array $snapshot): bool
    {
        $coverageEnd = $snapshot['coverage']['end'] ?? null;
        $days = $event['days'] ?? [];
        $lastDay = $days[count($days) - 1] ?? null;
        return !is_string($coverageEnd) || (is_string($lastDay) && $lastDay < $coverageEnd);
    }

    /** Only a complete continuous period is rendered; intermediate exits are never implied. */
    private function periodRows(array $event, callable $t, callable $time): array
    {
        $rows = [];
        foreach ([$event, ...($event['intermediate'] ?? [])] as $index => $segment) {
            if (is_int($segment['candles_at'] ?? null)) {
                $rows[] = $t('times_period_enter', ['time' => $time($segment['candles_at'])]);
                if ($index > 0 && ($segment['is_yom_tov'] ?? false) && !($segment['is_shabbat'] ?? false)) {
                    $rows[] = '<i>' . $t('times_lighting_after') . '</i>';
                }
            }
        }
        if (is_int($event['havdalah_at'] ?? null)) {
            $rows[] = $t('times_period_exit', ['time' => $time($event['havdalah_at'])]);
        }
        return $rows;
    }

    /** Preserve the full primary period if optional calendar metadata is unusually long. */
    private function appendSection(array &$rows, string $heading, array $section): bool
    {
        $candidate = implode("\n", [...$rows, '', $heading, ...$section]);
        if (self::units(html_entity_decode(strip_tags($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8')) <= 3900) {
            array_push($rows, '', $heading, ...$section);
            return true;
        }
        return false;
    }

    private static function units(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
    private function payload(array $rows): array
    {
        $text = implode("\n", $rows);
        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strlen(mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8')) > 8000) {
            $short = '';
            $units = 0;
            foreach (mb_str_split($plain) as $character) {
                $length = strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')) / 2;
                if ($units + $length > 3998) {
                    break;
                }
                $short .= $character;
                $units += $length;
            }
            $text = htmlspecialchars($short . '…', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return ['text' => $text,'media' => null,'buttons' => []];
    }
}
