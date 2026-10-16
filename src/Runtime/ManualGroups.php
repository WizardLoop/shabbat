<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Preserves WizardLoop's existing manual close/open capability; legacy MIT notices remain in the project.
declare(strict_types=1);

namespace Shabbat\Runtime;

use Closure;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Storage\StaleSettings;
use Shabbat\Scheduling\LockCoordinator;
use Shabbat\Time\Clock;

final class ManualGroups
{
    private readonly Closure $authorizeOperator;
    public function __construct(
        private readonly Database $db,
        private readonly SettingsRepository $settings,
        private readonly LockCoordinator $locks,
        private readonly Clock $clock,
        callable $authorizeOperator
    ) {
        $this->authorizeOperator = Closure::fromCallable($authorizeOperator);
    }
    /** Explicit temporary operator override. It does not change calendar/religious time calculations. */
    public function apply(bool $close, string $operatorId, int $ttl = 3600): array
    {
        SettingsRepository::identifier($operatorId);
        if (!$this->authorized($operatorId)) {
            throw new \RuntimeException('Configured operator authorization is required.');
        }
        if ($ttl < 1 || $ttl > 86400) {
            throw new \InvalidArgumentException('Manual override TTL must be 1..86400 seconds.');
        }
        $now = $this->clock->now()->getTimestamp();
        $until = $now + $ttl;
        $results = [];
        for ($offset = 0;; $offset += 100) {
            $groups = $this->settings->listGroups($offset, 100);
            foreach ($groups as $index => $group) {
                if ($index > 0 && $index % 20 === 0) {
                    \Amp\delay(0);
                }
                if (Runner::isStopping()) {
                    throw new \Amp\CancelledException(new \RuntimeException('Manual permission batch stopped during shutdown.'));
                }
                if (!$this->authorized($operatorId)) {
                    throw new \RuntimeException('Operator permission was revoked during the batch.');
                }
                try {
                    $saved = $this->settings->updateGroup(
                        $group['group_id'],
                        $group['revision'],
                        ['manual_override_mode' => $close ? 'lock' : 'open','manual_override_until' => $until],
                        $operatorId
                    );
                    $result = $this->locks->reconcile($group['group_id'], 'manual:' . $saved['revision'] . ':' . $now, $close);
                    $results[] = ['group_id' => $group['group_id'],'title' => $group['title'],'revision' => $saved['revision']] + $result;
                } catch (\Amp\CancelledException $error) {
                    throw $error;
                } catch (StaleSettings $error) {
                    $results[] = ['group_id' => $group['group_id'],'title' => $group['title'],'status' => 'stale','changed' => false,'error' => $error->getMessage(),'error_code' => PermissionFailure::code($error)];
                } catch (\Throwable $error) {
                    $results[] = ['group_id' => $group['group_id'],'title' => $group['title'],'status' => 'error','changed' => false,'error' => $error->getMessage(),'error_code' => PermissionFailure::code($error)];
                }
            }
            if (count($groups) < 100) {
                break;
            }
        }
        $summary = ['action' => $close ? 'lock' : 'open','operator_id' => $operatorId,'expires_at' => $until,
            'warning' => 'Temporary operator override: ' . ($close ? 'groups are held closed' : 'bot-owned locks are opened, including during an active festival') . '; calendar policy resumes automatically at expiry.',
            'groups' => $results,'changed' => count(array_filter($results, static fn(array $row): bool=>$row['changed'])),
            'failed' => count(array_filter($results, static fn(array $row): bool=>in_array($row['status'], ['error','stale','conflict','recovery_required'], true)))];
        $this->db->pdo()->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,NULL,?,?,?)')
            ->execute([$operatorId,'operator.manual_override',Database::encode(['mode' => $summary['action'],'expires_at' => $until,'groups' => count($results),'changed' => $summary['changed'],'failed' => $summary['failed']]),$now]);
        return $summary;
    }
    /** @phpstan-impure Permissions can change while a previous group RPC yields. */
    private function authorized(string $operator): bool
    {
        return ($this->authorizeOperator)($operator) === true;
    }
    public static function desired(array $group, bool $calendarDesired, int $now): bool
    {
        if (($group['manual_override_until'] ?? 0) <= $now) {
            return $calendarDesired;
        }
        return match ($group['manual_override_mode'] ?? 'none') {
            'lock'=>true,'open'=>false,default=>$calendarDesired
        };
    }
}
