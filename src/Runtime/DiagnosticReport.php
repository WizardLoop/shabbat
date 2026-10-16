<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Runtime;

use Amp\CancelledException;
use Shabbat\Calendar\CalendarEngine;
use Shabbat\Scheduling\Permissions;

/** Read-only, allowlisted projection. No credentials, exception messages or raw provider payloads. */
final class DiagnosticReport
{
    public function __construct(private readonly Application $app)
    {
    }

    public function capture(string $user, ?string $group): array
    {
        $this->app->adminAccess->require($user, 'statistics');
        $settings = $group === null ? $this->app->settings->getUser($user) : $this->app->settings->getGroup($group);
        if ($settings === null) {
            throw new \InvalidArgumentException('Unknown diagnostic group.');
        }
        $records = [];
        $add = static function (string $category, string $label, string $value, string $status = 'ok', array $data = []) use (&$records): void {
            $records[] = ['category' => $category, 'label' => $label, 'value' => mb_substr($value, 0, 500), 'status' => $status, 'data' => $data];
        };
        $add('summary', 'build', Version::current() . ' / ' . Version::commit());
        $add('summary', 'location', $settings['location_name'] . ' / ' . $settings['timezone_id']);
        $add('summary', 'methods', CalendarEngine::candleMinutes($settings) . ' / ' . $settings['havdalah_method'] . ' ' . $settings['havdalah_value'], 'ok', [
            'candle_minutes' => CalendarEngine::candleMinutes($settings), 'havdalah_method' => $settings['havdalah_method'], 'havdalah_value' => $settings['havdalah_value'],
        ]);
        try {
            $state = $this->app->calendar->snapshot($settings);
            $state['lock_now'] = $state['should_group_be_locked'];
            foreach (['lock_now', 'quiet_now', 'is_work_prohibited_now', 'is_shabbat', 'is_yom_tov', 'next_close_at', 'next_open_at', 'stale', 'evaluated_at', 'fetched_at'] as $key) {
                if (array_key_exists($key, $state)) {
                    $value = $state[$key];
                    $add('calendar', $key, is_bool($value) ? ($value ? '✓' : '✗') : ($value === null ? '-' : $this->date((int)$value, $settings['timezone_id'])), $key === 'stale' && $value ? 'warning' : 'ok', [$key => $value]);
                }
            }
            foreach (array_slice($state['events'] ?? [], 0, 80) as $period) {
                $projected = array_intersect_key($period, array_flip(['event_key', 'start_at', 'end_at', 'candles_at', 'havdalah_at', 'close_at', 'open_at', 'is_shabbat', 'is_yom_tov', 'enabled']));
                $add('calendar', 'period', ($period['title'] ?? '') . "\n" . $this->date((int)$period['close_at'], $settings['timezone_id']) . ' → ' . $this->date((int)$period['open_at'], $settings['timezone_id']), 'ok', $projected);
            }
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $add('calendar', 'provider_error', self::errorClass($error), 'error');
        }
        foreach ($this->app->db->pdo()->query('SELECT status,count(*) AS total FROM scheduled_jobs GROUP BY status')->fetchAll() as $row) {
            $add('queue', 'queue', $row['status'] . ': ' . $row['total'], in_array($row['status'], ['failed', 'uncertain'], true) ? 'warning' : 'ok', ['status' => $row['status'], 'total' => (int)$row['total']]);
        }
        if ($group !== null) {
            $this->permissions($group, $add);
        }
        $total = (int)$this->app->db->pdo()->query('SELECT count(*) FROM groups')->fetchColumn();
        $rows = $this->app->db->pdo()->query('SELECT g.group_id,g.title,s.status FROM groups g LEFT JOIN group_lock_state s ON s.group_id=g.group_id ORDER BY g.group_id LIMIT 200')->fetchAll();
        foreach ($rows as $row) {
            $add('groups', 'group', $row['title'] . ' (' . $row['group_id'] . ') / ' . ($row['status'] ?? '-'), in_array($row['status'], ['conflict', 'error', 'recovery_required'], true) ? 'warning' : 'ok', ['group_id' => $row['group_id']]);
        }
        if ($total > count($rows)) {
            $add('groups', 'limited', count($rows) . ' / ' . $total, 'warning');
        }
        $this->app->adminAccess->require($user, 'statistics');
        return ['schema' => 'shabbat-diagnostics-1', 'created_at' => $this->app->clock->now()->getTimestamp(),
            'context' => $group ?? 'personal', 'timezone' => $settings['timezone_id'], 'read_only' => true, 'records' => $records];
    }

    private function permissions(string $group, callable $add): void
    {
        $q = $this->app->db->pdo()->prepare('SELECT group_id,prepared_at,status,snapshot_json,applied_json,previous_applied_json,recovery_baseline_json,recovery_baseline_sha256,snapshot_sha256,snapshot_provenance,snapshot_evidence_json,revision FROM group_lock_state WHERE group_id=?');
        $q->execute([$group]);
        $saved = $q->fetch();
        $add('permissions', 'lock_state', $saved === false ? '-' : $saved['status'], $saved !== false && in_array($saved['status'], ['conflict', 'error', 'recovery_required'], true) ? 'warning' : 'ok');

        $rights = [];
        foreach (['original' => 'snapshot_json', 'requested' => 'applied_json', 'previous' => 'previous_applied_json', 'baseline' => 'recovery_baseline_json'] as $name => $column) {
            try {
                if ($saved !== false && is_string($saved[$column])) {
                    $hashColumn = match ($name) {
                        'original' => 'snapshot_sha256', 'baseline' => 'recovery_baseline_sha256', default => null,
                    };
                    if ($hashColumn !== null && (!is_string($saved[$hashColumn]) || !hash_equals($saved[$hashColumn], hash('sha256', $saved[$column])))) {
                        $add('permissions', $name === 'previous' ? 'previous_rights' : $name, 'checksum_mismatch', 'error', ['integrity' => 'checksum_mismatch']);
                        continue;
                    }
                    $rights[$name] = json_decode($saved[$column], true, 16, JSON_THROW_ON_ERROR);
                    Permissions::validate($rights[$name]);
                }
            } catch (\Throwable $error) {
                unset($rights[$name]);
                $add('permissions', $name === 'previous' ? 'previous_rights' : $name, self::errorClass($error), 'error');
            }
        }
        if ($saved !== false && $saved['snapshot_json'] !== null) {
            $verified = isset($rights['original']) && \Shabbat\Scheduling\PermissionRecoveryProvenance::verified($saved);
            $add('permissions', 'snapshot_verification', $saved['snapshot_provenance'], $verified ? 'ok' : 'warning', ['snapshot_provenance' => $verified ? 'verified_native_v1' : 'legacy_unverified']);
        }
        try {
            $rights['current'] = $this->app->gateway->getPermissions($group);
            Permissions::validate($rights['current']);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            unset($rights['current']);
            $add('permissions', 'current', self::errorClass($error), 'error');
        }
        $q->execute([$group]);
        $after = $q->fetch();
        if (($after['revision'] ?? null) !== ($saved['revision'] ?? null) || $after !== $saved) {
            $add('permissions', 'concurrent_change', '-', 'warning');
            // A live read that crossed a local operation is useful, but not a coherent comparison.
            unset($rights['original'], $rights['requested'], $rights['previous'], $rights['baseline']);
        }
        foreach ($rights as $name => $values) {
            $projected = [];
            foreach ([...Permissions::FLAGS, 'until_date'] as $flag) {
                $projected[$flag] = $values[$flag] ?? ($flag === 'until_date' ? 0 : false);
            }
            $add('permissions', $name === 'previous' ? 'previous_rights' : $name, implode(', ', array_keys(array_filter($projected, static fn(mixed $value): bool => $value === true))) ?: '-', 'ok', ['rights' => $projected]);
        }
        $this->journal($group, $add);
        foreach (['original', 'requested', 'previous', 'baseline'] as $name) {
            if (!isset($rights['current'], $rights[$name])) {
                continue;
            }
            $different = Permissions::differentFields($rights['current'], $rights[$name]);
            $add('permissions', 'compare_' . $name, $different === [] ? '=' : implode(', ', $different), $different === [] ? 'ok' : 'warning', ['different_fields' => $different]);
        }
    }

    private function journal(string $group, callable $add): void
    {
        $q = $this->app->db->pdo()->prepare('SELECT operation_id,action,stage,outcome,before_json,requested_json,observed_json,original_json,original_sha256,created_at,updated_at FROM permission_operations WHERE group_id=? ORDER BY operation_id DESC LIMIT 1');
        $q->execute([$group]);
        $operation = $q->fetch();
        if ($operation === false) {
            return;
        }
        $projection = [];
        foreach (['operation_id', 'created_at', 'updated_at'] as $field) {
            $projection[$field] = (int)$operation[$field];
        }
        foreach (
            [
            'action' => ['lock', 'restore', 'administrator_adopt', 'administrator_restore'],
            'stage' => ['prepared', 'deferred', 'dispatched', 'verified', 'completed', 'recovered_readback'],
            'outcome' => ['not_dispatched', 'unknown', 'confirmed', 'conflict'],
            ] as $field => $allowed
        ) {
            $projection[$field] = in_array($operation[$field], $allowed, true) ? $operation[$field] : 'unknown';
        }
        foreach (['before', 'requested', 'observed', 'original'] as $kind) {
            $json = $operation[$kind . '_json'];
            if (!is_string($json)) {
                $projection[$kind] = null;
                continue;
            }
            try {
                if ($kind === 'original' && (!is_string($operation['original_sha256']) || !hash_equals($operation['original_sha256'], hash('sha256', $json)))) {
                    throw new \RuntimeException('Unverified journal original.');
                }
                $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
                Permissions::validate($value);
                $projection[$kind] = array_intersect_key($value, array_flip([...Permissions::FLAGS, 'until_date']));
            } catch (\Throwable) {
                $projection[$kind] = ['integrity' => 'invalid'];
            }
        }
        $add('permissions', 'operation', $projection['action'] . ' / ' . $projection['stage'] . ' / ' . $projection['outcome'], $projection['outcome'] === 'confirmed' ? 'ok' : 'warning', $projection);
    }
    private function date(int $timestamp, string $timezone): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d H:i');
    }

    private static function errorClass(\Throwable $error): string
    {
        return substr(strrchr('\\' . $error::class, '\\'), 1);
    }
}
