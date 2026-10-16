<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Scheduling;

use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Telegram\TelegramGateway;
use Shabbat\Time\Clock;

/** Explicit administrator recovery: short-lived, one-use, bound to the exact preview. */
final class PermissionRecovery
{
    private readonly \Closure $authorize;
    private readonly \Closure $safeToRecover;
    private readonly ?\Closure $localSafeToRecover;
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly TelegramGateway $gateway,
        private readonly LockCoordinator $locks,
        callable $authorize,
        callable $safeToRecover,
        ?callable $localSafeToRecover = null
    ) {
        $this->authorize = \Closure::fromCallable($authorize);
        $this->safeToRecover = \Closure::fromCallable($safeToRecover);
        $this->localSafeToRecover = $localSafeToRecover === null ? null : \Closure::fromCallable($localSafeToRecover);
    }
    public function inspect(string $group, string $actor): array
    {
        SettingsRepository::identifier($group);
        SettingsRepository::identifier($actor);
        if (!$this->authorized($actor, $group)) {
            return $this->blocked('forbidden');
        }
        $binding = $this->binding($group);
        $current = $this->gateway->getPermissions($group);
        Permissions::validate($current);
        $safe = ($this->safeToRecover)($group) === true;
        if (!$this->authorized($actor, $group)) {
            return $this->blocked('forbidden');
        }
        $safe = $safe && ($this->safeToRecover)($group) === true;
        if ($binding !== $this->binding($group)) {
            return $this->blocked('stale');
        }
        $state = $this->locks->state($group);
        $original = $this->original($state);
        $problem = $state !== null && !in_array($state['status'], ['restored','prelocked'], true);
        $reason = !$safe ? 'active_lock' : (!$problem ? 'no_problem' : null);
        $canAdopt = $reason === null && !Permissions::alreadyLocked($current);
        $originalVerified = $original !== null && PermissionRecoveryProvenance::verified($state);
        $canRestore = $reason === null && $originalVerified;
        $expected = $state === null ? [] : json_decode($state['applied_json'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        return ['status' => $state['status'] ?? 'idle','group_id' => $group,'current' => $current,'original' => $original,
            'original_verified' => $originalVerified,'snapshot_provenance' => $state['snapshot_provenance'] ?? 'legacy_unverified',
            'different_fields' => Permissions::differentFields($current, is_array($expected) ? $expected : []),
            'can_adopt' => $canAdopt,'can_restore' => $canRestore,'block_reason' => $reason,
            'adopt_block_reason' => $reason ?? ($canAdopt ? null : 'locked_current'),
            'restore_block_reason' => $reason ?? ($canRestore ? null : ($original === null ? 'missing_snapshot' : 'unverified_snapshot')),
            'binding' => $binding,'remote_sha256' => hash('sha256', Permissions::canonical($current)),
            'evidence' => (new PermissionRecoveryJournal($this->db, $this->clock))->latest($group)];
    }
    public function prepare(string $group, string $actor, string $action): array
    {
        if (!in_array($action, ['adopt','restore'], true)) {
            throw new \InvalidArgumentException('Unknown permission recovery action.');
        }
        $preview = $this->inspect($group, $actor);
        if (!($preview['can_' . $action] ?? false)) {
            return $preview + ['token' => null];
        }
        $now = $this->clock->now()->getTimestamp();
        $token = bin2hex(random_bytes(16));
        $binding = $preview['binding'] + ['remote_sha256' => $preview['remote_sha256']];
        $this->db->transaction(function (\PDO $pdo) use ($group, $actor, $action, $binding, $now, $token): void {
            $pdo->prepare('DELETE FROM permission_recovery_confirmations WHERE expires_at<=? OR consumed_at IS NOT NULL')->execute([$now]);
            // A newer preview invalidates older actions for this administrator/group.
            $pdo->prepare('DELETE FROM permission_recovery_confirmations WHERE group_id=? AND actor_id=?')->execute([$group,$actor]);
            $pdo->prepare('INSERT INTO permission_recovery_confirmations VALUES(?,?,?,?,?,?,?,NULL)')
                ->execute([hash('sha256', $token),$group,$actor,$action,Database::encode($binding),$now,$now + 300]);
        });
        return $preview + ['token' => $token,'expires_at' => $now + 300,'action' => $action];
    }
    public function confirm(string $token, string $actor): array
    {
        SettingsRepository::identifier($actor);
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
            return ['status' => 'expired','changed' => false];
        }
        $row = $this->db->transaction(function (\PDO $pdo) use ($token, $actor): ?array {
            $query = $pdo->prepare('SELECT * FROM permission_recovery_confirmations WHERE token_hash=? AND actor_id=? AND consumed_at IS NULL AND expires_at>?');
            $query->execute([hash('sha256', $token),$actor,$this->clock->now()->getTimestamp()]);
            $row = $query->fetch();
            if (!$row) {
                return null;
            }
            $pdo->prepare('UPDATE permission_recovery_confirmations SET consumed_at=? WHERE token_hash=? AND consumed_at IS NULL')->execute([$this->clock->now()->getTimestamp(),$row['token_hash']]);
            return $row;
        });
        if ($row === null) {
            return ['status' => 'expired','changed' => false];
        }
        $group = $row['group_id'];
        $binding = json_decode($row['binding_json'], true, 32, JSON_THROW_ON_ERROR);
        $guardedAt = null;
        $guard = function () use ($actor, $group, $binding, $row, &$guardedAt): ?string {
            if (!$this->authorized($actor, $group)) {
                return 'forbidden';
            }
            if (($this->safeToRecover)($group) !== true) {
                return 'active_lock';
            }
            if (!$this->authorized($actor, $group)) {
                return 'forbidden';
            }
            // The authorization refresh may suspend across a holy/manual-lock boundary.
            if (($this->safeToRecover)($group) !== true) {
                return 'active_lock';
            }
            if ($row['expires_at'] <= $this->clock->now()->getTimestamp()) {
                return 'expired';
            }
            if ($binding['settings_sha256'] !== $this->binding($group)['settings_sha256']) {
                return 'stale';
            }
            $guardedAt = $this->clock->now()->getTimestamp();
            return null;
        };
        // Must not suspend: this fence follows the final fresh Telegram read.
        $localGuard = function () use ($group, $binding, $row, &$guardedAt): ?string {
            if ($row['expires_at'] <= $this->clock->now()->getTimestamp()) {
                return 'expired';
            }
            if ($binding['settings_sha256'] !== $this->binding($group)['settings_sha256']) {
                return 'stale';
            }
            if ($this->localSafeToRecover !== null) {
                return ($this->localSafeToRecover)($group) === true ? null : 'active_lock';
            }
            return $guardedAt === $this->clock->now()->getTimestamp() ? null : 'stale';
        };
        return $this->locks->recover($group, $actor, $row['action'], $binding, $guard, $localGuard);
    }
    public function binding(string $group): array
    {
        $query = $this->db->pdo()->prepare('SELECT s.*,g.active FROM group_settings s JOIN groups g USING(group_id) WHERE group_id=?');
        $query->execute([$group]);
        $settings = $query->fetch();
        if (!$settings) {
            throw new \RuntimeException('Group settings are missing.');
        }
        return ['settings_sha256' => hash('sha256', Database::encode($settings)),
            'state_sha256' => self::stateFingerprint($this->locks->state($group))];
    }
    public static function stateFingerprint(?array $state): string
    {
        if ($state !== null) {
            unset($state['lease_owner'], $state['lease_until']);
        }
        return hash('sha256', Database::encode($state ?? []));
    }
    public function original(?array $state): ?array
    {
        if (
            $state === null || !is_string($state['snapshot_json']) || !is_string($state['snapshot_sha256'])
            || !hash_equals($state['snapshot_sha256'], hash('sha256', $state['snapshot_json']))
        ) {
            return null;
        }
        try {
            $original = json_decode($state['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($original)) {
                return null;
            }
            Permissions::validate($original);
            return $original;
        } catch (\Throwable) {
            return null;
        }
    }
    /** @phpstan-impure Administrator rights can change across every remote await. */
    private function authorized(string $actor, string $group): bool
    {
        return ($this->authorize)($actor, $group) === true;
    }
    private function blocked(string $reason): array
    {
        return ['status' => $reason,'can_adopt' => false,'can_restore' => false,'block_reason' => $reason];
    }
}
