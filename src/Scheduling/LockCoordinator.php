<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Scheduling;

use PDO;
use Shabbat\Storage\Database;
use Shabbat\Telegram\TelegramGateway;
use Shabbat\Telegram\RetryableTelegramException;
use Shabbat\Time\Clock;

final class LockCoordinator
{
    private readonly string $owner;
    private readonly ?\Closure $freshDesired;
    private readonly ?\Closure $localDesired;
    private readonly PermissionRecoveryJournal $journal;
    public function __construct(private readonly Database $db, private readonly Clock $clock, private readonly TelegramGateway $gateway, ?callable $freshDesired = null, ?callable $localDesired = null)
    {
        $this->owner = bin2hex(random_bytes(16));
        $this->journal = new PermissionRecoveryJournal($db, $clock);
        $this->freshDesired = $freshDesired === null ? null : \Closure::fromCallable($freshDesired);
        $this->localDesired = $localDesired === null ? null : \Closure::fromCallable($localDesired);
    }
    public function state(string $group): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM group_lock_state WHERE group_id=?');
        $query->execute([$group]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }
    /** Desired state wins over an earlier event. Permission retries survive process restarts. */
    public function reconcile(string $group, string $eventKey, bool $shouldLock): array
    {
        $existing = $this->state($group);
        if (!$shouldLock && $existing === null) {
            return ['status' => 'idle','changed' => false];
        }
        if ($existing !== null && $existing['retry_at'] !== null && $existing['retry_at'] > $this->now()) {
            return ['status' => 'retry_wait','changed' => false,'retry_at' => $existing['retry_at']];
        }
        if (!$this->acquire($group, $eventKey, $shouldLock)) {
            $state = $this->state($group);
            if ($state !== null && $state['retry_at'] !== null && $state['retry_at'] > $this->now()) {
                return ['status' => 'retry_wait','changed' => false,'retry_at' => $state['retry_at']];
            }
            return ['status' => 'busy','changed' => false];
        }
        try {
            $state = $this->state($group);
            if ($state === null) {
                return ['status' => 'idle','changed' => false];
            }
            $this->assertOwnership($group);
            if (
                $state['snapshot_json'] !== null && !in_array($state['status'], ['restored','prelocked'], true)
                && !PermissionRecoveryProvenance::verified($state)
            ) {
                return $this->unverifiedSnapshot($group, $state);
            }
            // A completed cycle has no restoration debt. Read again only at the next close;
            // weekday administrator changes belong to that next fresh baseline.
            if (!$shouldLock && in_array($state['status'], ['restored','prelocked'], true)) {
                $this->confirmed($group, 'retry_at=NULL,last_error=NULL', []);
                return ['status' => $state['status'],'changed' => false];
            }
            $current = $this->gateway->getPermissions($group);
            $this->assertOwnership($group);
            Permissions::validate($current);
            $this->journal->observeKnownResult($group, $current);
            $state = $this->retireUnwritten($group, $state, $current, $shouldLock);
            if ($state === null) {
                return ['status' => 'deferred','changed' => false];
            }
            return $shouldLock ? $this->close($group, $eventKey, $state, $current) : $this->restore($group, $state, $current);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (RetryableTelegramException $error) {
            return $this->retry($group, $error);
        } catch (\Throwable $error) {
            $code = \Shabbat\Runtime\PermissionFailure::code($error);
            if (($existing['status'] ?? null) !== 'error' || ($existing['last_error'] ?? null) !== $error->getMessage()) {
                error_log('Group permission operation failed: group=' . $group . ' code=' . $code);
            }
            return $this->record($group, 'error', $error->getMessage()) + ['error_code' => $code];
        } finally {
            $this->db->pdo()->prepare('UPDATE group_lock_state SET lease_owner=NULL,lease_until=NULL WHERE group_id=? AND lease_owner=?')->execute([$group,$this->owner]);
        }
    }
    private function unverifiedSnapshot(string $group, array $state): array
    {
        $message = 'Original permission snapshot has no verified read provenance; configure intended rights and explicitly adopt a fresh verified state.';
        if ($state['status'] === 'recovery_required' && $state['last_error'] === $message) {
            return ['status' => 'recovery_required','changed' => false,'error' => $message,
                'error_code' => 'SNAPSHOT_UNVERIFIED','recovery_reason' => 'unverified_snapshot'];
        }
        return $this->db->transaction(function (PDO $pdo) use ($group, $state, $message): array {
            if ($state['status'] !== 'recovery_required' || $state['last_error'] !== $message) {
                $evidence = $state;
                unset($evidence['lease_owner'], $evidence['lease_until']);
                $pdo->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(NULL,?,?,?,?)')
                    ->execute([$group,'permissions.unverified_original',Database::encode($evidence),$this->now()]);
            }
            return $this->record($group, 'recovery_required', $message)
                + ['error_code' => 'SNAPSHOT_UNVERIFIED','recovery_reason' => 'unverified_snapshot'];
        });
    }
    private function acquire(string $group, string $eventKey, bool $shouldLock): bool
    {
        $now = $this->now();
        return $this->db->transaction(function (PDO $pdo) use ($group, $eventKey, $shouldLock, $now): bool {
            if ($shouldLock) {
                $pdo->prepare("INSERT OR IGNORE INTO group_lock_state(group_id,event_key,status,prepared_at) VALUES(?,?,'prepared',?)")->execute([$group,$eventKey,$now]);
            }
            $query = $pdo->prepare('UPDATE group_lock_state SET lease_owner=?,lease_until=? WHERE group_id=? AND (lease_owner IS NULL OR lease_until<=?) AND (retry_at IS NULL OR retry_at<=?)');
            $query->execute([$this->owner,$now + 120,$group,$now,$now]);
            return $query->rowCount() === 1;
        });
    }
    private function close(string $group, string $eventKey, array $state, array $current): array
    {
        if (in_array($state['status'], ['conflict','recovery_required'], true) && $state['snapshot_json'] === null) {
            return $this->record($group, 'recovery_required', 'Missing original permission snapshot; administrator action required.');
        }
        $flags = $this->configuredFlags($group);
        $hasDebt = $state['snapshot_json'] !== null && !in_array($state['status'], ['restored','prelocked'], true);
        if ($hasDebt) {
            [$snapshot,$owned,$previous] = $this->snapshot($state);
            if (
                !Permissions::equal($current, $snapshot) && !Permissions::equal($current, $owned)
                && ($previous === null || !Permissions::equal($current, $previous))
            ) {
                $recovered = Permissions::externalBaseline($snapshot, $owned, $current);
                if ($recovered === null) {
                    return $this->conflict($group, $current, $owned);
                }
                $snapshot = $recovered;
                $this->baseline($group, $snapshot);
            }
        } else {
            if (Permissions::alreadyLocked($current)) {
                $this->confirmed($group, "status='prelocked',event_key=?,snapshot_json=NULL,applied_json=NULL,previous_applied_json=NULL,snapshot_sha256=NULL,snapshot_provenance='legacy_unverified',snapshot_evidence_json=NULL,recovery_baseline_json=NULL,recovery_baseline_sha256=NULL,last_error=NULL,retry_at=NULL", [$eventKey]);
                return ['status' => 'prelocked','changed' => false];
            }
            $snapshot = $current;
        }
        $applied = Permissions::locked($snapshot, $flags);
        if ($hasDebt && Permissions::equal($current, $applied)) {
            $this->confirmed($group, "status='locked',event_key=?,applied_json=?,previous_applied_json=NULL,last_error=NULL,retry_at=NULL", [$eventKey,Database::encode($applied)]);
            return ['status' => 'locked','changed' => false];
        }
        $serialized = Database::encode($snapshot);
        $capturedAt = $this->now();
        $evidence = PermissionRecoveryProvenance::capture($group, $serialized, $capturedAt);
        $previous = $hasDebt && !Permissions::equal($current, $snapshot) ? Database::encode($current) : null;
        $this->db->transaction(function (PDO $pdo) use ($group, $eventKey, $serialized, $applied, $previous, $hasDebt, $capturedAt, $evidence): void {
            $snapshotColumns = $hasDebt ? '' : "snapshot_json=?,snapshot_sha256=?,prepared_at=?,snapshot_provenance='verified_native_v1',snapshot_evidence_json=?,recovery_baseline_json=NULL,recovery_baseline_sha256=NULL,";
            $values = [$eventKey,Database::encode($applied),$previous];
            if (!$hasDebt) {
                array_push($values, $serialized, hash('sha256', $serialized), $capturedAt, $evidence);
            }
            $query = $pdo->prepare("UPDATE group_lock_state SET event_key=?,status='prepared',applied_json=?,previous_applied_json=?," . $snapshotColumns . "restored_at=NULL,last_error=NULL,revision=revision+1 WHERE group_id=? AND lease_owner=? AND lease_until>?");
            $query->execute([...$values,$group,$this->owner,$this->now()]);
            if ($query->rowCount() !== 1) {
                throw new \RuntimeException('Lost group lock ownership before snapshot persistence.');
            }
        });
        $operation = $this->journal->begin($group, 'lock', $current, $applied, $this->state($group) ?? []);
        if (!$this->stillDesired($group, true) || $flags !== $this->configuredFlags($group)) {
            $this->journal->update($operation, 'deferred', 'not_dispatched', $current);
            return ['status' => 'deferred','changed' => false];
        }
        if (!$this->readyToWrite($group, $current, true, $operation)) {
            return ['status' => 'deferred','changed' => false];
        }
        $this->journal->update($operation, 'dispatched', 'unknown');
        $this->gateway->setPermissions($group, $applied);
        $this->assertOwnership($group);
        $observed = $this->gateway->getPermissions($group);
        $this->assertOwnership($group);
        $this->journal->update($operation, 'verified', Permissions::equal($observed, $applied) ? 'confirmed' : 'conflict', $observed);
        if (!Permissions::equal($observed, $applied)) {
            return $this->conflict($group, $observed, $applied, true);
        }
        $this->confirmed($group, "status='locked',locked_at=?,previous_applied_json=NULL,last_error=NULL,retry_at=NULL", [$this->now()]);
        if ($hasDebt && !Permissions::equal($current, $snapshot)) {
            return ['status' => 'locked','changed' => false,'permissions_updated' => true];
        }
        return ['status' => 'locked','changed' => true];
    }
    /** A deferred first attempt is provably unowned; elapsed time alone is never evidence. */
    private function retireUnwritten(string $group, array $state, array $current, bool $shouldLock): ?array
    {
        if (
            !in_array($state['status'], ['prepared','error'], true) || Permissions::alreadyLocked($current)
            || $state['previous_applied_json'] !== null || $state['recovery_baseline_json'] !== null
            || !is_string($state['snapshot_json']) || !is_string($state['snapshot_sha256'])
            || !hash_equals($state['snapshot_sha256'], hash('sha256', $state['snapshot_json']))
        ) {
            return $state;
        }
        $latest = $this->journal->latest($group);
        if (
            $latest === null || $latest['action'] !== 'lock' || $latest['outcome'] !== 'not_dispatched'
            || $latest['stage'] !== 'deferred' || $latest['before_json'] !== $state['snapshot_json']
            || $latest['original_sha256'] !== $state['snapshot_sha256'] || $latest['requested_json'] !== $state['applied_json']
        ) {
            return $state;
        }
        if (!$this->stillDesired($group, $shouldLock)) {
            return null;
        }
        $latestRights = $this->gateway->getPermissions($group);
        $this->assertOwnership($group);
        if (!Permissions::equal($current, $latestRights) || !$this->locallyDesired($group, $shouldLock)) {
            return null;
        }
        $this->confirmed($group, "status='restored',restored_at=?,last_error=NULL,retry_at=NULL", [$this->now()]);
        $this->journal->update($latest['operation_id'], 'retired_unwritten', 'not_dispatched', $current);
        return $this->state($group) ?? throw new \RuntimeException('Recovery state disappeared.');
    }
    private function baseline(string $group, array $baseline): void
    {
        $json = Database::encode($baseline);
        $this->confirmed($group, 'recovery_baseline_json=?,recovery_baseline_sha256=?', [$json,hash('sha256', $json)]);
    }
    /** Explicit recovery shares the normal lock lease; no remote calls run inside a transaction. */
    public function recover(string $group, string $actor, string $action, array $binding, callable $guard, callable $localGuard): array
    {
        if (!in_array($action, ['adopt','restore'], true)) {
            throw new \InvalidArgumentException('Unknown permission recovery action.');
        }
        if (($denied = $this->recoveryDenial($guard)) !== null) {
            return ['status' => $denied,'changed' => false];
        }
        if (!$this->acquire($group, 'recovery', false)) {
            $state = $this->state($group);
            if ($state !== null && $state['retry_at'] !== null && $state['retry_at'] > $this->now()) {
                return ['status' => 'retry_wait','changed' => false,'retry_at' => $state['retry_at']];
            }
            return ['status' => 'busy','changed' => false];
        }
        try {
            $state = $this->state($group);
            if ($state === null || !hash_equals($binding['state_sha256'], PermissionRecovery::stateFingerprint($state))) {
                return ['status' => 'stale','changed' => false];
            }
            $current = $this->gateway->getPermissions($group);
            $this->assertOwnership($group);
            Permissions::validate($current);
            if (!hash_equals($binding['remote_sha256'], hash('sha256', Permissions::canonical($current)))) {
                return ['status' => 'stale','changed' => false];
            }
            if (($denied = $this->recoveryDenial($guard)) !== null) {
                return ['status' => $denied,'changed' => false];
            }
            $this->assertOwnership($group);
            if ($action === 'adopt') {
                if (Permissions::alreadyLocked($current)) {
                    return ['status' => 'locked_current','changed' => false];
                }
                $target = $current;
            } else {
                if (!PermissionRecoveryProvenance::verified($state)) {
                    return ['status' => 'unverified_snapshot','changed' => false];
                }
                if (
                    !is_string($state['snapshot_json']) || !is_string($state['snapshot_sha256'])
                    || !hash_equals($state['snapshot_sha256'], hash('sha256', $state['snapshot_json']))
                ) {
                    return ['status' => 'missing_snapshot','changed' => false];
                }
                $target = json_decode($state['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($target)) {
                    return ['status' => 'missing_snapshot','changed' => false];
                }
                Permissions::validate($target);
            }
            if (($denied = $this->recoveryDenial($guard)) !== null) {
                return ['status' => $denied,'changed' => false];
            }
            // No asynchronous guard follows this read: only local fencing may precede mutation.
            $latest = $this->gateway->getPermissions($group);
            $this->assertOwnership($group);
            if (!Permissions::equal($current, $latest)) {
                return ['status' => 'stale','changed' => false];
            }
            if (($denied = $this->recoveryDenial($localGuard)) !== null) {
                return ['status' => $denied,'changed' => false];
            }
            if (!hash_equals($binding['state_sha256'], PermissionRecovery::stateFingerprint($this->state($group)))) {
                return ['status' => 'stale','changed' => false];
            }
            $operation = $this->journal->begin($group, 'administrator_' . $action, $current, $target, $state);
            $changed = $action === 'restore' && !Permissions::equal($current, $target);
            if ($changed) {
                $this->baseline($group, $target);
                $this->confirmed($group, "status='restoring'", []);
                $this->journal->update($operation, 'dispatched', 'unknown');
                $this->gateway->setPermissions($group, $target);
                $this->assertOwnership($group);
                $observed = $this->gateway->getPermissions($group);
                $this->assertOwnership($group);
                $this->journal->update($operation, 'verified', Permissions::equal($observed, $target) ? 'confirmed' : 'conflict', $observed);
                if (!Permissions::equal($observed, $target)) {
                    return $this->conflict($group, $observed, $target, true);
                }
            }
            $commitFingerprint = PermissionRecovery::stateFingerprint($this->state($group));
            if (($denied = $this->recoveryDenial($guard)) !== null) {
                return ['status' => $denied,'changed' => $changed];
            }
            $final = $this->gateway->getPermissions($group);
            $this->assertOwnership($group);
            if (!Permissions::equal($final, $target)) {
                $this->journal->update($operation, 'verified', 'conflict', $final);
                return $this->conflict($group, $final, $target, $changed);
            }
            if (($denied = $this->recoveryDenial($localGuard)) !== null) {
                return ['status' => $denied,'changed' => $changed];
            }
            if (!hash_equals($commitFingerprint, PermissionRecovery::stateFingerprint($this->state($group)))) {
                return ['status' => 'stale','changed' => $changed];
            }
            $this->assertOwnership($group);
            $this->db->transaction(function (PDO $pdo) use ($group, $actor, $action, $state, $current, $target, $operation): void {
                $this->confirmed($group, "status='restored',restored_at=?,previous_applied_json=NULL,recovery_baseline_json=NULL,recovery_baseline_sha256=NULL,last_error=NULL,retry_at=NULL,revision=revision+1", [$this->now()]);
                // Preserve the old original and the administrator's choice across later cycles.
                $pdo->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,?,?,?,?)')
                    ->execute([$actor,$group,'permissions.recovery.' . $action,Database::encode(['operation_id' => $operation,'original_json' => $state['snapshot_json'],'original_sha256' => $state['snapshot_sha256'],'original_provenance' => $state['snapshot_provenance'],'original_evidence_json' => $state['snapshot_evidence_json'],'before' => $current,'normal' => $target]),$this->now()]);
                $this->journal->update($operation, 'completed', 'confirmed', $target);
            });
            return ['status' => $action === 'adopt' ? 'adopted' : 'restored','changed' => $changed];
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (RetryableTelegramException $error) {
            return $this->retry($group, $error);
        } catch (\Throwable $error) {
            return $this->record($group, 'error', $error->getMessage());
        } finally {
            $this->db->pdo()->prepare('UPDATE group_lock_state SET lease_owner=NULL,lease_until=NULL WHERE group_id=? AND lease_owner=?')->execute([$group,$this->owner]);
        }
    }
    /** @phpstan-impure Each guard refreshes authority, calendar, settings and shutdown state. */
    private function recoveryDenial(callable $guard): ?string
    {
        return $guard();
    }
    private function configuredFlags(string $group): array
    {
        $query = $this->db->pdo()->prepare('SELECT lock_block_flags FROM group_settings WHERE group_id=?');
        $query->execute([$group]);
        $json = $query->fetchColumn();
        if (!is_string($json)) {
            throw new \RuntimeException('Group lock configuration is missing.');
        }
        $flags = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($flags)) {
            throw new \RuntimeException('Group lock configuration is malformed.');
        }
        return Permissions::validateBlockFlags($flags);
    }
    private function restore(string $group, array $state, array $current): array
    {
        if (in_array($state['status'], ['restored','prelocked'], true)) {
            $this->confirmed($group, 'retry_at=NULL,last_error=NULL', []);
            return ['status' => $state['status'],'changed' => false];
        }
        if ($state['snapshot_json'] === null) {
            return $this->record($group, 'recovery_required', 'Missing original permission snapshot; permissions were not guessed.');
        }
        [$snapshot,$applied,$previous] = $this->snapshot($state);
        if (Permissions::equal($current, $snapshot)) {
            $this->confirmed($group, "status='restored',restored_at=?,previous_applied_json=NULL,last_error=NULL,retry_at=NULL", [$this->now()]);
            return ['status' => 'restored','changed' => false];
        }
        if (!Permissions::equal($current, $applied) && ($previous === null || !Permissions::equal($current, $previous))) {
            $recovered = Permissions::externalBaseline($snapshot, $applied, $current);
            if ($recovered === null) {
                return $this->conflict($group, $current, $applied);
            }
            $snapshot = $recovered;
            $this->baseline($group, $snapshot);
        }
        $this->confirmed($group, "status='restoring'", []);
        $operation = $this->journal->begin($group, 'restore', $current, $snapshot, $state);
        if (!$this->stillDesired($group, false)) {
            $this->journal->update($operation, 'deferred', 'not_dispatched', $current);
            return ['status' => 'deferred','changed' => false];
        }
        if (!$this->readyToWrite($group, $current, false, $operation)) {
            return ['status' => 'deferred','changed' => false];
        }
        $this->journal->update($operation, 'dispatched', 'unknown');
        $this->gateway->setPermissions($group, $snapshot);
        $this->assertOwnership($group);
        $observed = $this->gateway->getPermissions($group);
        $this->assertOwnership($group);
        $this->journal->update($operation, 'verified', Permissions::equal($observed, $snapshot) ? 'confirmed' : 'conflict', $observed);
        if (!Permissions::equal($observed, $snapshot)) {
            return $this->conflict($group, $observed, $snapshot, true);
        }
        $this->confirmed($group, "status='restored',restored_at=?,previous_applied_json=NULL,last_error=NULL,retry_at=NULL", [$this->now()]);
        return ['status' => 'restored','changed' => true];
    }
    private function snapshot(array $state): array
    {
        if (!is_string($state['snapshot_sha256']) || !hash_equals($state['snapshot_sha256'], hash('sha256', $state['snapshot_json']))) {
            throw new \RuntimeException('Permission snapshot checksum mismatch.');
        }
        $snapshot = json_decode($state['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
        $applied = json_decode($state['applied_json'] ?? '', true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || !is_array($applied)) {
            throw new \RuntimeException('Invalid permission snapshot.');
        }
        Permissions::validate($snapshot);
        Permissions::validate($applied);
        $previous = $state['previous_applied_json'] === null ? null : json_decode($state['previous_applied_json'], true, 32, JSON_THROW_ON_ERROR);
        if ($previous !== null) {
            if (!is_array($previous)) {
                throw new \RuntimeException('Invalid previous owned permission mask.');
            }
            Permissions::validate($previous);
        }
        if ($state['recovery_baseline_json'] !== null) {
            if (!is_string($state['recovery_baseline_sha256']) || !hash_equals($state['recovery_baseline_sha256'], hash('sha256', $state['recovery_baseline_json']))) {
                throw new \RuntimeException('Recovery baseline checksum mismatch.');
            }
            $snapshot = json_decode($state['recovery_baseline_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot)) {
                throw new \RuntimeException('Invalid recovery baseline.');
            }
            Permissions::validate($snapshot);
        }
        return [$snapshot,$applied,$previous];
    }
    /** Final current-rights read follows every asynchronous intent evaluation. */
    private function readyToWrite(string $group, array $current, bool $expected, int $operation): bool
    {
        $latest = $this->gateway->getPermissions($group);
        $this->assertOwnership($group);
        Permissions::validate($latest);
        if (!Permissions::equal($current, $latest) || !$this->locallyDesired($group, $expected)) {
            $this->journal->update($operation, 'deferred', 'not_dispatched', $latest);
            return false;
        }
        return true;
    }
    /** No provider or RPC is allowed in this callback after the final remote read. */
    private function locallyDesired(string $group, bool $expected): bool
    {
        $this->assertOwnership($group);
        return $this->localDesired === null || ($this->localDesired)($group, $expected) === true;
    }
    /** Provider/current-settings evaluation can suspend; no transaction or stale lease crosses it. */
    private function stillDesired(string $group, bool $expected): bool
    {
        if ($this->freshDesired !== null) {
            $desired = ($this->freshDesired)($group);
            if (!is_bool($desired)) {
                throw new \UnexpectedValueException('Current desired permission state is not a boolean.');
            }
            if ($desired !== $expected) {
                return false;
            }
        }
        $this->assertOwnership($group);
        return true;
    }
    private function assertOwnership(string $group): void
    {
        $state = $this->state($group);
        if ($state === null || $state['lease_owner'] !== $this->owner || $state['lease_until'] <= $this->now()) {
            throw new \RuntimeException('Group ownership expired during Telegram request.');
        }
        if ($state['retry_at'] !== null && $state['retry_at'] > $this->now()) {
            throw new RetryableTelegramException('Permission request deferred to its persisted quota deadline.', $state['retry_at'] - $this->now());
        }
    }
    /** Confirmed success is fenced by both lease identity and deadline, including the final UPDATE. */
    private function confirmed(string $group, string $assignments, array $values): void
    {
        $query = $this->db->pdo()->prepare('UPDATE group_lock_state SET ' . $assignments . ' WHERE group_id=? AND lease_owner=? AND lease_until>? AND (retry_at IS NULL OR retry_at<=?)');
        $query->execute([...$values,$group,$this->owner,$this->now(),$this->now()]);
        if ($query->rowCount() !== 1) {
            throw new \RuntimeException('Group lock ownership was lost before confirmation.');
        }
    }
    private function retry(string $group, RetryableTelegramException $error): array
    {
        $now = $this->now();
        $after = max(1, $error->retryAfterSeconds);
        $retryAt = $after > PHP_INT_MAX - $now ? PHP_INT_MAX : $now + $after;
        // A known quota deadline remains relevant even if this request outlived its lease.
        // It never overwrites another owner's status, permissions or original snapshot.
        $this->db->pdo()->prepare('UPDATE group_lock_state SET retry_at=max(coalesce(retry_at,0),?) WHERE group_id=?')->execute([$retryAt,$group]);
        $this->db->pdo()->prepare("UPDATE group_lock_state SET last_error=? WHERE group_id=? AND lease_owner=? AND lease_until>?")->execute([substr($error->getMessage(), 0, 1024),$group,$this->owner,$now]);
        return ['status' => 'retry_wait','changed' => false,'retry_at' => $this->state($group)['retry_at']];
    }
    private function conflict(string $group, array $current, array $expected, bool $mutationAttempted = false): array
    {
        $fields = Permissions::differentFields($current, $expected);
        $latest = $this->journal->latest($group);
        if ($latest === null || $latest['observed_json'] !== Database::encode($current) || $latest['requested_json'] !== Database::encode($expected)) {
            $operation = $this->journal->begin($group, 'inspect_conflict', $current, $expected, $this->state($group) ?? []);
            $this->journal->update($operation, 'verified', 'conflict', $current);
        }
        return $this->record($group, 'conflict', 'Current permissions differ from the saved state; original snapshot retained. Fields: ' . implode(', ', $fields))
            + ['different_fields' => $fields,'mutation_attempted' => $mutationAttempted,'verification_failed' => $mutationAttempted];
    }
    private function record(string $group, string $status, string $error): array
    {
        $query = $this->db->pdo()->prepare('UPDATE group_lock_state SET status=?,last_error=? WHERE group_id=? AND lease_owner=? AND lease_until>?');
        $query->execute([$status,substr($error, 0, 1024),$group,$this->owner,$this->now()]);
        if ($query->rowCount() !== 1) {
            return ['status' => 'busy','changed' => false,'error' => 'Group lock ownership was lost; permission result remains unconfirmed.'];
        }
        return ['status' => $status,'changed' => false,'error' => $error];
    }
    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
