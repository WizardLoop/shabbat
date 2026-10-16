<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Scheduling;

use Shabbat\Storage\Database;
use Shabbat\Time\Clock;

/** Bounded durable evidence. An interrupted dispatched operation has an unknown outcome. */
final class PermissionRecoveryJournal
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }
    public function begin(string $group, string $action, array $before, array $requested, array $state): int
    {
        $now = $this->clock->now()->getTimestamp();
        $this->db->pdo()->prepare('INSERT INTO permission_operations(group_id,action,stage,outcome,before_json,requested_json,original_json,original_sha256,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$group,$action,'prepared','not_dispatched',Database::encode($before),Database::encode($requested),$state['snapshot_json'] ?? null,$state['snapshot_sha256'] ?? null,$now,$now]);
        $id = (int)$this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare('DELETE FROM permission_operations WHERE group_id=? AND operation_id NOT IN (SELECT operation_id FROM permission_operations WHERE group_id=? ORDER BY operation_id DESC LIMIT 20)')->execute([$group,$group]);
        return $id;
    }
    public function update(int $id, string $stage, string $outcome, ?array $observed = null): void
    {
        $this->db->pdo()->prepare('UPDATE permission_operations SET stage=?,outcome=?,observed_json=coalesce(?,observed_json),updated_at=? WHERE operation_id=?')
            ->execute([$stage,$outcome,$observed === null ? null : Database::encode($observed),$this->clock->now()->getTimestamp(),$id]);
    }
    public function observeKnownResult(string $group, array $current): void
    {
        $latest = $this->latest($group);
        if ($latest === null || $latest['outcome'] !== 'unknown' || !is_string($latest['requested_json'])) {
            return;
        }
        $requested = json_decode($latest['requested_json'], true, 32, JSON_THROW_ON_ERROR);
        if (is_array($requested) && Permissions::equal($current, $requested)) {
            $this->update($latest['operation_id'], 'recovered_readback', 'confirmed', $current);
        }
    }
    public function latest(string $group): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM permission_operations WHERE group_id=? ORDER BY operation_id DESC LIMIT 1');
        $query->execute([$group]);
        return $query->fetch() ?: null;
    }
}
