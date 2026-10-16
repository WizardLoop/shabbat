<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Notifications;

use Shabbat\Calendar\CalendarEngine;
use Shabbat\Time\LocalTime;

final class ReminderPlanner
{
    public function tefillin(array $settings, array $rule, string $date, array $sun, array $state): array
    {
        if (!($rule['enabled'] ?? false) || ($state['is_work_prohibited_now'] ?? false)) {
            return [];
        }
        $local = new \DateTimeImmutable($date, new \DateTimeZone($settings['timezone_id']));
        $weekday = (int)$local->format('N');
        if ($weekday === 6 || !in_array($weekday, $this->weekdays($rule, $settings, 'tefillin'), true) || ($rule['pause_date'] ?? $settings['tefillin_suspended_date'] ?? null) === $date) {
            return [];
        }
        if (($state['is_chol_hamoed'] ?? false) && !($rule['chol_hamoed'] ?? false)) {
            return [];
        }
        if (($state['is_tisha_bav'] ?? false) && ($rule['tisha_bav'] ?? 'off') === 'off') {
            return [];
        }
        $start = $sun[$rule['misheyakir_method'] ?? 'misheyakir'] ?? null;
        $sunset = $sun['sunset'] ?? null;
        if ($start === null || $sunset === null) {
            return [];
        }
        if (($state['is_tisha_bav'] ?? false) && ($rule['tisha_bav'] ?? 'off') === 'mincha') {
            if (!isset($sun['minchaGedola'])) {
                return [];
            }
            $start = max($start, $sun['minchaGedola']);
        }
        $end = $sunset - max(1, (int)($rule['sunset_safety_minutes'] ?? 10)) * 60;
        foreach ($state['events'] ?? [] as $event) {
            if ($event['start_at'] >= $start && $event['start_at'] <= $end) {
                $end = $event['start_at'];
            }
        }
        foreach ($state['operational_events'] ?? [] as $event) {
            if ($event['close_at'] >= $start && $event['close_at'] <= $end) {
                $end = $event['close_at'];
            }
        }
        if (isset($rule['window_start'])) {
            $bound = LocalTime::resolve($date, $rule['window_start'], $settings['timezone_id']);
            if ($bound === null) {
                return [];
            } $start = max($start, $bound);
        }
        if (isset($rule['window_end'])) {
            $bound = LocalTime::resolve($date, $rule['window_end'], $settings['timezone_id']);
            if ($bound === null) {
                return [];
            } $end = min($end, $bound);
        }
        if ($end <= $start) {
            return [];
        }
        $times = [];
        foreach ($rule['times'] ?? [] as $time) {
            $stamp = LocalTime::resolve($date, $time, $settings['timezone_id']);
            if ($stamp !== null) {
                $times[] = $stamp;
            }
        }
        $interval = (int)($rule['interval_minutes'] ?? 0);
        if ($interval !== 0) {
            if ($interval < 5 || $interval > 1440) {
                throw new \InvalidArgumentException('Reminder interval must be 5..1440 minutes.');
            }
            for ($at = $start; $at < $end; $at += $interval * 60) {
                $times[] = $at;
            }
        }
        foreach ($rule['before_sunset'] ?? [] as $minutes) {
            if (!is_int($minutes) || $minutes < 1 || $minutes > 360) {
                throw new \InvalidArgumentException('Invalid relative reminder.');
            }
            $times[] = $sunset - $minutes * 60;
        }
        sort($times);
        $result = [];
        $last = null;
        $minimum = max(5, (int)($rule['min_gap_minutes'] ?? 30)) * 60;
        $maximum = min(24, max(1, (int)($rule['max_per_day'] ?? 3)));
        foreach (array_unique($times) as $at) {
            if ($at < $start || $at >= $end || ($last !== null && $at - $last < $minimum) || $this->quiet($at, $settings['timezone_id'], $rule)) {
                continue;
            }
            $result[] = ['run_at' => $at,'expires_at' => $end,'kind' => 'tefillin','event_key' => 'tefillin:' . $date . ':' . $at];
            $last = $at;
            if (count($result) >= $maximum) {
                break;
            }
        }
        return $result;
    }
    public function omer(array $settings, array $rule, string $date, array $sun, array $state): array
    {
        if (!($rule['enabled'] ?? false)) {
            return [];
        }
        $night = CalendarEngine::nightfall($sun, [
            'omer_nightfall_method' => $rule['nightfall_method'] ?? $rule['tzeit_method'] ?? $settings['omer_nightfall_method'] ?? 'degrees',
            'omer_nightfall_value' => $rule['nightfall_value'] ?? $rule['tzeit_value'] ?? $settings['omer_nightfall_value'] ?? 8.5,
        ], 'omer_nightfall');
        if ($night === null) {
            return [];
        }
        $nextDate = (new \DateTimeImmutable($date, new \DateTimeZone($settings['timezone_id'])))->modify('+1 day')->format('Y-m-d');
        $dawn = $sun['next_alotHaShachar'] ?? $state['next_dawn_at'] ?? null;
        if ($dawn === null || $dawn <= $night) {
            return [];
        }
        $schedule = $rule['schedule'] ?? $settings['omer_mode'] ?? 'nightfall';
        if ($schedule === 'fixed') {
            $time = $rule['time'] ?? $settings['omer_time'] ?? '21:00';
            // Midnight belongs to the same counting night that began on the preceding civil date.
            $requested = LocalTime::resolve($time < '12:00' ? $nextDate : $date, $time, $settings['timezone_id']);
        } else {
            $requested = $night + (int)($rule['after_nightfall_minutes'] ?? $rule['after_tzeit_minutes'] ?? $settings['omer_after_nightfall_minutes'] ?? 15) * 60;
        }
        if ($requested === null) {
            return [];
        }
        if ($requested < $night && ($rule['early_policy'] ?? $settings['omer_early_policy'] ?? 'defer') === 'skip') {
            return [];
        }
        $at = max($requested, $night);
        $reason = $requested < $night ? 'delayed_until_nightfall' : null;
        $quiet = [];
        foreach ($state['events'] ?? [] as $event) {
            $quiet[] = ['start' => $event['start_at'],'end' => $event['end_at']];
        }
        foreach ($state['operational_events'] ?? [] as $event) {
            $quiet[] = ['start' => $event['close_at'],'end' => $event['open_at']];
        }
        usort($quiet, static fn(array $a, array $b): int=>$a['start'] <=> $b['start']);
        foreach ($quiet as $event) {
            if ($event['start'] <= $at && $event['end'] > $at) {
                $at = $event['end'] + max(0, (int)($rule['after_open_minutes'] ?? $settings['omer_after_open_minutes'] ?? 5)) * 60;
                $reason = 'delayed_until_group_open';
            }
        }
        if ($at >= $dawn || $this->quiet($at, $settings['timezone_id'], $rule)) {
            return [];
        }
        return [['run_at' => $at,'expires_at' => min($dawn, $at + 7200),'kind' => 'omer','event_key' => 'omer-night:' . $date,'reason' => $reason]];
    }
    public function daily(array $settings, array $rule, string $date, string $kind, array $state): array
    {
        if (!($rule['enabled'] ?? false)) {
            return [];
        }
        $weekday = (int)(new \DateTimeImmutable($date, new \DateTimeZone($settings['timezone_id'])))->format('N');
        if (!in_array($weekday, $this->weekdays($rule, $settings, $kind), true)) {
            return [];
        }
        $at = LocalTime::resolve($date, $rule['time'] ?? '08:00', $settings['timezone_id']);
        if ($at === null || $this->quiet($at, $settings['timezone_id'], $rule)) {
            return [];
        }
        foreach ($state['events'] ?? [] as $event) {
            if ($event['start_at'] <= $at && $event['end_at'] > $at) {
                return [];
            }
        }
        foreach ($state['operational_events'] ?? [] as $event) {
            if ($event['close_at'] <= $at && $event['open_at'] > $at) {
                return [];
            }
        }
        return [['run_at' => $at,'expires_at' => $at + 3600,'kind' => $kind,'event_key' => $kind . ':' . $date]];
    }
    private function weekdays(array $rule, array $settings, string $module): array
    {
        if (isset($rule['weekdays'])) {
            return $rule['weekdays'];
        } // Existing planner API uses ISO N.
        $days = $rule['days'] ?? $settings[$module . '_days'] ?? [0,1,2,3,4,5]; // Repository/UI stores PHP w.
        return array_map(static fn(int $day): int=>$day === 0 ? 7 : $day, $days);
    }
    private function quiet(int $at, string $zone, array $rule): bool
    {
        return \Shabbat\Scheduling\QuietHours::contains($at, $zone, $rule['quiet_start'] ?? null, $rule['quiet_end'] ?? null);
    }
}
