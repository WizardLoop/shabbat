<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Runtime;

use Shabbat\UI\Lang;

final class ManualGroupReport
{
    public static function format(array $result, string $language, string $timezone): string
    {
        $lang = new Lang();
        $text = $lang->text($language, 'operator_manual_result', [
            'action' => $lang->text($language, $result['action'] === 'lock' ? 'operator_manual_lock' : 'operator_manual_open'),
            'changed' => $result['changed'], 'failed' => $result['failed'],
            'until' => (new \DateTimeImmutable('@' . $result['expires_at']))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d H:i'),
        ]);
        $pending = array_filter($result['groups'], static fn(array $row): bool => in_array($row['status'], ['retry_wait','busy','deferred'], true));
        if ($pending !== []) {
            $text .= "\n" . $lang->text($language, 'operator_manual_pending', ['count' => count($pending)]);
        }
        $details = array_values(array_filter($result['groups'], static fn(array $row): bool => !$row['changed']));
        if ($details === []) {
            return $text;
        }
        $text .= "\n\n" . $lang->text($language, 'operator_manual_details');
        foreach (array_slice($details, 0, 10) as $row) {
            $code = $row['error_code'] ?? '';
            $key = match (true) {
                ($row['verification_failed'] ?? false) === true => 'permission_error_readback',
                ($row['recovery_reason'] ?? null) === 'unverified_snapshot' => 'permission_error_unverified',
                $row['status'] === 'conflict' => 'permission_error_conflict',
                $row['status'] === 'recovery_required' => 'permission_error_snapshot',
                $row['status'] === 'retry_wait' => 'permission_status_wait',
                in_array($row['status'], ['busy','deferred'], true) => 'permission_status_busy',
                $row['status'] === 'prelocked' => 'permission_status_prelocked',
                $row['status'] === 'idle' => 'permission_status_idle',
                in_array($row['status'], ['restored','locked'], true) => 'permission_status_unchanged',
                $code === 'CHAT_ADMIN_REQUIRED' => 'permission_error_admin',
                in_array($code, ['CHANNEL_PRIVATE','CHAT_WRITE_FORBIDDEN','CHAT_ID_INVALID','PEER_ID_INVALID','CHANNEL_INVALID'], true) => 'permission_error_access',
                $code === 'BANNED_RIGHTS_INVALID' => 'permission_error_flags',
                $code === 'SNAPSHOT_UNVERIFIED' => 'permission_error_snapshot',
                $code === 'PROVIDER_UNAVAILABLE' => 'permission_error_provider',
                default => 'permission_error_unknown',
            };
            $title = preg_replace('/[\r\n\t]/u', ' ', mb_substr((string)$row['title'], 0, 70));
            $text .= "\n• " . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' (' . $row['group_id'] . '): ' . $lang->text($language, $key);
            if ($code !== '') {
                $text .= ' [' . $code . ']';
            }
            if ($row['status'] === 'conflict' && is_array($row['different_fields'] ?? null)) {
                $fields = array_values(array_intersect([...\Shabbat\Scheduling\Permissions::FLAGS, 'until_date', 'unknown'], $row['different_fields']));
                $labels = array_map(static fn(string $field): string => $lang->text($language, 'permission_' . $field), array_slice($fields, 0, 4));
                if ($labels !== []) {
                    $text .= ' ' . $lang->text($language, 'permission_conflict_fields', ['fields' => implode(', ', $labels)]);
                    if (count($fields) > 4) {
                        $text .= ' (+' . (count($fields) - 4) . ')';
                    }
                }
            }
        }
        if (count($details) > 10) {
            $text .= "\n" . $lang->text($language, 'operator_manual_more', ['count' => count($details) - 10]);
        }
        return $text;
    }
}
