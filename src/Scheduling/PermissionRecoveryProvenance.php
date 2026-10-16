<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Scheduling;

use Shabbat\Storage\Database;

/** Origin evidence is separate from the existing checksum of the original native mask. */
final class PermissionRecoveryProvenance
{
    public const VERIFIED = 'verified_native_v1';
    public static function capture(string $group, string $snapshot, int $capturedAt): string
    {
        return Database::encode(['schema' => 1,'reader_contract' => 'strict-fresh-default-rights-v1',
            'group_id' => $group,'snapshot_sha256' => hash('sha256', $snapshot),'captured_at' => $capturedAt]);
    }
    public static function verified(?array $state): bool
    {
        if (
            $state === null || ($state['snapshot_provenance'] ?? null) !== self::VERIFIED
            || !is_string($state['snapshot_evidence_json'] ?? null) || !is_string($state['snapshot_sha256'] ?? null)
        ) {
            return false;
        }
        try {
            $evidence = json_decode($state['snapshot_evidence_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        return is_array($evidence) && ($evidence['schema'] ?? null) === 1
            && ($evidence['reader_contract'] ?? null) === 'strict-fresh-default-rights-v1'
            && ($evidence['group_id'] ?? null) === $state['group_id']
            && ($evidence['snapshot_sha256'] ?? null) === $state['snapshot_sha256']
            && is_int($evidence['captured_at'] ?? null) && $evidence['captured_at'] >= 0
            && $evidence['captured_at'] === $state['prepared_at'];
    }
}
