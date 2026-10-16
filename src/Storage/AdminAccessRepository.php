<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Storage;

use PDO;
use Shabbat\Time\Clock;

/** Local bot managers; Telegram group administrator status is never consulted. */
final class AdminAccessRepository
{
    public const PERMISSIONS = ['statistics', 'subscribers', 'broadcast', 'commands', 'system_links', 'tracking_links', 'add_admins', 'manage_admins', 'group_operations', 'system_operations'];
    private \Closure $owners;
    /** $owners returns configured recovery administrator IDs, never dynamic/group IDs. */
    public function __construct(private Database $db, private Clock $clock, callable $owners)
    {
        $this->owners = \Closure::fromCallable($owners);
    }
    public function isOwner(string $id): bool
    {
        return in_array($id, $this->ownerIds(), true);
    }
    public function ownerIds(): array
    {
        $out = [];
        foreach (($this->owners)() as $id) {
            $id = (string) $id;
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }
    public function profile(string $id): ?array
    {
        if ($this->isOwner($id)) {
            return ['id' => $id, 'role' => 'full', 'view_only' => false, 'permissions' => array_fill_keys(self::PERMISSIONS, true), 'revision' => 0, 'source' => 'environment'];
        }
        $q = $this->db->pdo()->prepare('SELECT * FROM bot_admins WHERE user_id=?');
        $q->execute([$id]);
        $row = $q->fetch();
        if ($row === false) {
            return null;
        }
        $p = json_decode($row['permissions_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($p) || !in_array($row['role'], ['full', 'limited'], true)) {
            throw new \RuntimeException('Invalid administrator access record.');
        }
        return array_replace($row, ['id' => $id, 'permissions' => $p, 'view_only' => (bool) $row['view_only'], 'revision' => (int) $row['revision'], 'source' => 'dynamic']);
    }
    public function isAdmin(string $id): bool
    {
        return $this->profile($id) !== null;
    }
    public function adminIds(): array
    {
        return array_values(array_unique(array_merge($this->ownerIds(), $this->db->pdo()->query('SELECT user_id FROM bot_admins ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN))));
    }
    public function canView(string $id, string $permission): bool
    {
        if (!in_array($permission, self::PERMISSIONS, true)) {
            return false;
        }
        $p = $this->profile($id);
        return $p !== null && ($p['role'] === 'full' || ($p['permissions'][$permission] ?? false) === true);
    }
    public function canMutate(string $id, string $permission): bool
    {
        return $this->canView($id, $permission) && !$this->profile($id)['view_only'];
    }
    public function require(string $id, string $permission, bool $mutation = false): void
    {
        if (!($mutation ? $this->canMutate($id, $permission) : $this->canView($id, $permission))) {
            throw new \RuntimeException('Administrator permission is required.');
        }
    }
    /** Existing dynamic administrators are imported once and remain removable afterwards. */
    public function importLegacyOnce(array $ids): void
    {
        $this->db->transaction(function (PDO $pdo) use ($ids): void {
            if ($pdo->query("SELECT 1 FROM admin_meta WHERE meta_key='legacy_admin_import'")->fetchColumn()) {
                return;
            }
            foreach ($ids as $id) {
                $id = (string) $id;
                if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id) || $this->isOwner($id)) {
                    continue;
                }
                $now = $this->clock->now()->getTimestamp();
                $pdo->prepare("INSERT OR IGNORE INTO bot_admins VALUES(?,'full',0,?,1,?,'legacy',?,'legacy')")
                    ->execute([$id, Database::encode(array_fill_keys(self::PERMISSIONS, true)), $now, $now]);
            }
            $pdo->prepare("INSERT INTO admin_meta VALUES('legacy_admin_import',?)")->execute([(string) $this->clock->now()->getTimestamp()]);
        });
    }
    public function add(string $actor, string $target, string $role, bool $viewOnly, array $permissions): array
    {
        return $this->save($actor, $target, $role, $viewOnly, $permissions, null);
    }
    public function update(string $actor, string $target, string $role, bool $viewOnly, array $permissions, int $revision): array
    {
        return $this->save($actor, $target, $role, $viewOnly, $permissions, $revision);
    }
    private function save(string $actor, string $target, string $role, bool $viewOnly, array $permissions, ?int $revision): array
    {
        $selection = $this->selection($role, $viewOnly, $permissions);
        $this->db->transaction(function (PDO $pdo) use ($actor, $target, $selection, $revision): void {
            $this->require($actor, $revision === null ? 'add_admins' : 'manage_admins', true);
            $this->assertTarget($actor, $target);
            $current = $this->profile($target);
            if (($revision === null && $current !== null) || ($revision !== null && ($current === null || $current['revision'] !== $revision))) {
                throw new StaleSettings('Administrator changed; reopen the profile.');
            }
            $this->assertDelegation($actor, $selection);
            if ($current !== null) {
                $this->assertDelegation($actor, $current);
            }
            $now = $this->clock->now()->getTimestamp();
            if ($revision === null) {
                $pdo->prepare('INSERT INTO bot_admins VALUES(?,?,?,?,1,?,?,?,?)')->execute([$target, $selection['role'], (int) $selection['view_only'], Database::encode($selection['permissions']), $now, $actor, $now, $actor]);
            } else {
                $pdo->prepare('UPDATE bot_admins SET role=?,view_only=?,permissions_json=?,revision=revision+1,updated_at=?,updated_by=? WHERE user_id=? AND revision=?')->execute([$selection['role'], (int) $selection['view_only'], Database::encode($selection['permissions']), $now, $actor, $target, $revision]);
            }
            $this->audit($pdo, $actor, $revision === null ? 'add' : 'update', ['target' => $target, 'selection' => $selection]);
        });
        return $this->profile($target) ?? throw new \RuntimeException('Administrator save failed.');
    }
    public function remove(string $actor, string $target, int $revision): void
    {
        $this->db->transaction(function (PDO $pdo) use ($actor, $target, $revision): void {
            $this->require($actor, 'manage_admins', true);
            $this->assertTarget($actor, $target);
            $current = $this->profile($target);
            if ($current === null || $current['revision'] !== $revision) {
                throw new StaleSettings('Administrator changed; reopen the profile.');
            }
            $this->assertDelegation($actor, $current);
            $pdo->prepare('DELETE FROM bot_admins WHERE user_id=? AND revision=?')->execute([$target, $revision]);
            $this->audit($pdo, $actor, 'remove', ['target' => $target]);
        });
    }
    private function assertTarget(string $actor, string $target): void
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $target) || $actor === $target || $this->isOwner($target)) {
            throw new \RuntimeException('Recovery administrators and your own access cannot be changed.');
        }
    }
    private function assertDelegation(string $actor, array $selection): void
    {
        $profile = $this->profile($actor) ?? throw new \RuntimeException('Administrator access was revoked.');
        if ($profile['role'] === 'full') {
            return;
        }
        if ($selection['role'] === 'full') {
            throw new \RuntimeException('A limited manager cannot grant or manage full access.');
        }
        foreach ($selection['permissions'] as $permission => $on) {
            if ($on && !($profile['permissions'][$permission] ?? false)) {
                throw new \RuntimeException('Cannot delegate or manage permissions beyond your authority.');
            }
        }
    }
    private function selection(string $role, bool $viewOnly, array $permissions): array
    {
        if (!in_array($role, ['full', 'limited'], true) || array_diff(array_keys($permissions), self::PERMISSIONS) !== []) {
            throw new \InvalidArgumentException('Invalid administrator selection.');
        }
        $out = [];
        foreach (self::PERMISSIONS as $key) {
            if (isset($permissions[$key]) && !is_bool($permissions[$key])) {
                throw new \InvalidArgumentException('Permissions must be booleans.');
            }
            $out[$key] = $role === 'full' || ($permissions[$key] ?? false);
        }
        return ['role' => $role, 'view_only' => $role === 'full' ? false : $viewOnly, 'permissions' => $out];
    }
    private function audit(PDO $pdo, string $actor, string $action, array $details): void
    {
        $pdo->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,NULL,?,?,?)')->execute([$actor, 'operator.admin.' . $action, Database::encode($details), $this->clock->now()->getTimestamp()]);
    }
}
