<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Storage;

use PDO;
use Shabbat\Time\Clock;

final class FlowTokenRepository
{
    private readonly SettingsRepository $settings;
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
        $this->settings = new SettingsRepository($db, $clock);
    }
    public function create(string $user, string $scope, ?string $group, int $revision, string $action, int $ttl = 600, array $payload = []): string
    {
        $this->settings->getUser($user);
        if ($ttl < 1 || $ttl > 3600 || !in_array($scope, ['user','group','share'], true) || strlen($action) > 64) {
            throw new \InvalidArgumentException('Invalid selection flow.');
        }
        if (($scope === 'user') !== ($group === null)) {
            throw new \InvalidArgumentException('Selection scope mismatch.');
        }
        if ($group !== null && $this->settings->getGroup($group) === null) {
            throw new \InvalidArgumentException('Unknown group.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $now = $this->clock->now()->getTimestamp();
        $this->db->pdo()->prepare('INSERT INTO conversation_states(token_hash,user_id,scope,group_id,settings_revision,action,payload_json,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([hash('sha256', $token),$user,$scope,$group,$revision,$action,Database::encode($payload),$now,$now + $ttl]);
        return $token;
    }
    public function peek(string $token, string $user, string $action): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{24}$/D', $token)) {
            return null;
        }
        $query = $this->db->pdo()->prepare('SELECT * FROM conversation_states WHERE token_hash=? AND user_id=? AND action=? AND expires_at>? AND consumed_at IS NULL AND revoked_at IS NULL');
        $query->execute([hash('sha256', $token),$user,$action,$this->clock->now()->getTimestamp()]);
        $row = $query->fetch();
        if ($row === false) {
            return null;
        }
        $row['payload'] = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        $row['revision'] = (int)$row['settings_revision'];
        unset($row['payload_json'], $row['token_hash']);
        return $row;
    }
    /** An explicit prompt may resolve its hidden token; arbitrary messages cannot create a flow. */
    public function latest(string $user, string $action): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM conversation_states WHERE user_id=? AND action=? AND expires_at>? AND consumed_at IS NULL AND revoked_at IS NULL ORDER BY created_at DESC,rowid DESC LIMIT 1');
        $query->execute([$user,$action,$this->clock->now()->getTimestamp()]);
        $row = $query->fetch();
        if ($row === false) {
            return null;
        }
        $row['payload'] = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        $row['revision'] = (int)$row['settings_revision'];
        unset($row['payload_json'], $row['token_hash']);
        return $row;
    }
    public function revokeAction(string $user, string $action): void
    {
        $this->db->pdo()->prepare('UPDATE conversation_states SET revoked_at=? WHERE user_id=? AND action=? AND consumed_at IS NULL AND revoked_at IS NULL')
            ->execute([$this->clock->now()->getTimestamp(),$user,$action]);
    }
    public function updatePayload(string $token, string $user, string $action, array $payload): void
    {
        $row = $this->peek($token, $user, $action);
        if ($row === null) {
            throw new StaleSettings('Selection expired or invalid.');
        }
        $query = $this->db->pdo()->prepare('UPDATE conversation_states SET payload_json=? WHERE token_hash=? AND user_id=? AND action=? AND expires_at>? AND consumed_at IS NULL AND revoked_at IS NULL');
        $query->execute([Database::encode($payload),hash('sha256', $token),$user,$action,$this->clock->now()->getTimestamp()]);
        if ($query->rowCount() !== 1) {
            throw new StaleSettings('Selection expired or invalid.');
        }
    }
    /** Authorization runs immediately before the transaction and must recheck Telegram admin rights. */
    public function confirm(string $token, string $user, string $action, callable $authorize, array $changes = []): array
    {
        return $this->confirmAndApply($token, $user, $action, $authorize, $changes, static fn(): null=>null);
    }
    /** localMutation(PDO, saved settings, flow) must only do local database work. */
    public function confirmAndApply(string $token, string $user, string $action, callable $authorize, array $changes, callable $localMutation): array
    {
        $flow = $this->peek($token, $user, $action);
        if ($flow === null) {
            throw new StaleSettings('Selection expired, consumed, revoked, or belongs to another user.');
        }
        if ($flow['scope'] === 'share') {
            throw new \RuntimeException('A sharing flow cannot write settings.');
        }
        if ($flow['scope'] === 'group' && !$authorize($flow['group_id'], $user)) {
            throw new \RuntimeException('Group administrator permission is required.');
        }
        return $this->db->transaction(function (PDO $pdo) use ($token, $user, $action, $changes, $localMutation): array {
            $flow = $this->peek($token, $user, $action);
            if ($flow === null) {
                throw new StaleSettings('Selection is no longer valid.');
            }
            // Authorization can await Telegram. Revalidate the original prompt after
            // that wait, in the same transaction as consuming and applying its child.
            if (array_key_exists('source_input_token', $flow['payload'])) {
                $source = $flow['payload']['source_input_token'];
                $parent = is_string($source) ? $this->peek($source, $user, 'location_input') : null;
                if (
                    $parent === null || $parent['scope'] !== $flow['scope']
                    || $parent['group_id'] !== $flow['group_id'] || $parent['revision'] !== $flow['revision']
                ) {
                    throw new StaleSettings('The original location prompt expired, was replaced, or no longer matches.');
                }
            }
            if (array_key_exists('source_wizard_token', $flow['payload'])) {
                $source = $flow['payload']['source_wizard_token'];
                $parent = is_string($source) ? $this->peek($source, $user, 'settings_wizard') : null;
                if (
                    $parent === null || $parent['scope'] !== $flow['scope']
                    || $parent['group_id'] !== $flow['group_id'] || $parent['revision'] !== $flow['revision']
                ) {
                    throw new StaleSettings('The original settings question expired, was replaced, or no longer matches.');
                }
            }
            if (array_key_exists('source_template_input_token', $flow['payload'])) {
                $source = $flow['payload']['source_template_input_token'];
                $parent = is_string($source) ? $this->peek($source, $user, 'template_input') : null;
                if (
                    $parent === null || $parent['scope'] !== $flow['scope']
                    || $parent['group_id'] !== $flow['group_id'] || $parent['revision'] !== $flow['revision']
                ) {
                    throw new StaleSettings('The original template input expired, was replaced, or no longer matches.');
                }
            }
            $now = $this->clock->now()->getTimestamp();
            $query = $pdo->prepare('UPDATE conversation_states SET consumed_at=? WHERE token_hash=? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>?');
            $query->execute([$now,hash('sha256', $token),$now]);
            if ($query->rowCount() !== 1) {
                throw new StaleSettings('Selection was already confirmed.');
            }
            $saved = $flow['scope'] === 'group'
                ? $this->settings->updateGroupInTransaction($pdo, $flow['group_id'], $flow['revision'], $changes, $user)
                : $this->settings->updateUserInTransaction($pdo, $user, $flow['revision'], $changes);
            $localMutation($pdo, $saved, $flow);
            return $saved;
        });
    }
    public function revoke(string $token, string $user, string $action): void
    {
        $this->db->pdo()->prepare('UPDATE conversation_states SET revoked_at=? WHERE token_hash=? AND user_id=? AND action=? AND consumed_at IS NULL')
            ->execute([$this->clock->now()->getTimestamp(),hash('sha256', $token),$user,$action]);
    }
}
