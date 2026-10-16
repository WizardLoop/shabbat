<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Storage;

use PDO;
use Shabbat\Time\Clock;

/** Durable deletion work is independent of the already-confirmed sending job. */
final class DeletionRepository
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }
    public function enqueue(string $group, int $messageId, string $purpose, array $metadata, int $dueAt, ?int $expiresAt = null): int
    {
        if ($purpose !== 'holy_message') {
            throw new \InvalidArgumentException('Previous reminders require confirmed module-delivery bookkeeping.');
        }
        return $this->db->transaction(fn(PDO $pdo): int => $this->insert($pdo, $group, $messageId, $purpose, $metadata, $dueAt, $expiresAt));
    }
    private function insert(PDO $pdo, string $group, int $messageId, string $purpose, array $metadata, int $dueAt, ?int $expiresAt): int
    {
        SettingsRepository::identifier($group);
        self::messageIds([$messageId]);
        if (
            !in_array($purpose, ['holy_message','tefillin_previous'], true) || $dueAt < 0 || ($expiresAt !== null && $expiresAt < $dueAt)
            || ($purpose === 'holy_message' && $expiresAt === null)
        ) {
            throw new \InvalidArgumentException('Invalid deletion purpose or deadline.');
        }
        $timestamp = $metadata['original_timestamp'] ?? null;
        $bot = $metadata['sender_is_bot'] ?? null;
        $self = $metadata['sender_is_self'] ?? false;
        $sender = $metadata['sender_id'] ?? null;
        $revision = $metadata['settings_revision'] ?? null;
        $delivery = $metadata['origin_delivery_key'] ?? null;
        if (
            !is_int($timestamp) || $timestamp < 0 || !is_bool($bot) || !is_bool($self) || ($self && !$bot)
            || ($revision !== null && (!is_int($revision) || $revision < 1))
        ) {
            throw new \InvalidArgumentException('Deletion requires typed original message metadata.');
        }
        if ($sender !== null) {
            if (!is_string($sender)) {
                throw new \InvalidArgumentException('Deletion sender must retain string identity.');
            }
            SettingsRepository::identifier($sender);
        }
        if ($delivery !== null) {
            self::deliveryKey($delivery);
        }
        if ($purpose === 'tefillin_previous' && (!$bot || !$self || $delivery === null)) {
            throw new \InvalidArgumentException('Only stored bot-owned reminders can be replaced.');
        }
        $now = $this->now();
        $pdo->prepare('INSERT OR IGNORE INTO message_deletions(group_id,message_id,purpose,original_timestamp,sender_id,sender_is_bot,sender_is_self,settings_revision,origin_delivery_key,due_at,expires_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$group,$messageId,$purpose,$timestamp,$sender,(int)$bot,(int)$self,$revision,$delivery,$dueAt,$expiresAt,$now,$now]);
        $query = $pdo->prepare('SELECT deletion_id FROM message_deletions WHERE group_id=? AND message_id=? AND purpose=?');
        $query->execute([$group,$messageId,$purpose]);
        return (int)$query->fetchColumn();
    }
    public function get(int $id): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM message_deletions WHERE deletion_id=?');
        $query->execute([$id]);
        $row = $query->fetch();
        return $row === false ? null : self::decode($row);
    }
    public function list(string $status = 'pending', int $limit = 50, int $offset = 0): array
    {
        if (!in_array($status, ['pending','leased','deleted','cancelled','failed','expired'], true) || $limit < 1 || $limit > 100 || $offset < 0) {
            throw new \InvalidArgumentException('Invalid deletion page.');
        }
        $query = $this->db->pdo()->prepare('SELECT * FROM message_deletions WHERE status=? ORDER BY due_at,deletion_id LIMIT ? OFFSET ?');
        $query->bindValue(1, $status);
        $query->bindValue(2, $limit, PDO::PARAM_INT);
        $query->bindValue(3, $offset, PDO::PARAM_INT);
        $query->execute();
        return array_map(self::decode(...), $query->fetchAll());
    }
    public function claim(string $owner, int $leaseSeconds = 120): ?array
    {
        self::lease($owner, $leaseSeconds);
        $now = $this->now();
        return $this->db->transaction(function (PDO $pdo) use ($owner, $leaseSeconds, $now): ?array {
            $pdo->prepare("UPDATE message_deletions SET status='expired',last_error='deletion deadline elapsed',lease_owner=NULL,lease_until=NULL,updated_at=? WHERE status IN ('pending','leased') AND expires_at<=? AND (lease_until IS NULL OR lease_until<=?)")
                ->execute([$now,$now,$now]);
            $pdo->prepare("UPDATE message_deletions SET status='cancelled',last_error='current reminder protected',lease_owner=NULL,lease_until=NULL,updated_at=? WHERE purpose='tefillin_previous' AND (status='pending' OR (status='leased' AND lease_until<=?)) AND EXISTS(SELECT 1 FROM module_last_messages m,json_each(m.message_ids_json) v WHERE m.group_id=message_deletions.group_id AND m.module='tefillin' AND v.value=message_deletions.message_id)")
                ->execute([$now,$now]);
            $pdo->prepare("UPDATE message_deletions SET status='cancelled',last_error='group consent or settings revision changed',lease_owner=NULL,lease_until=NULL,updated_at=? WHERE (status='pending' OR (status='leased' AND lease_until<=?)) AND (NOT EXISTS(SELECT 1 FROM groups g WHERE g.group_id=message_deletions.group_id AND g.active=1) OR (purpose='holy_message' AND settings_revision IS NOT NULL AND NOT EXISTS(SELECT 1 FROM group_settings s WHERE s.group_id=message_deletions.group_id AND s.revision=message_deletions.settings_revision)) OR (purpose='tefillin_previous' AND NOT EXISTS(SELECT 1 FROM group_settings s WHERE s.group_id=message_deletions.group_id AND s.tefillin_delete_previous=1)))")
                ->execute([$now,$now]);
            $pdo->prepare("UPDATE message_deletions SET status='failed',last_error='cleanup retry budget exhausted',lease_owner=NULL,lease_until=NULL,updated_at=? WHERE attempts>=5 AND (status='pending' OR (status='leased' AND lease_until<=?))")
                ->execute([$now,$now]);
            $query = $pdo->prepare("SELECT * FROM message_deletions WHERE due_at<=? AND (expires_at IS NULL OR expires_at>?) AND (retry_at IS NULL OR retry_at<=?) AND (status='pending' OR (status='leased' AND lease_until<=?)) ORDER BY due_at,deletion_id LIMIT 1");
            $query->execute([$now,$now,$now,$now]);
            $row = $query->fetch();
            if ($row === false) {
                return null;
            }
            $until = $row['expires_at'] === null ? $now + $leaseSeconds : min($now + $leaseSeconds, (int)$row['expires_at']);
            $pdo->prepare("UPDATE message_deletions SET status='leased',attempts=attempts+1,lease_owner=?,lease_until=?,updated_at=? WHERE deletion_id=?")
                ->execute([$owner,$until,$now,$row['deletion_id']]);
            return $this->get((int)$row['deletion_id']);
        });
    }
    /** @phpstan-impure Lease, consent and clock state can change across asynchronous RPC. */
    public function owns(int $id, string $owner): bool
    {
        $row = $this->get($id);
        if (
            $row === null || $row['status'] !== 'leased' || $row['lease_owner'] !== $owner || $row['lease_until'] <= $this->now()
            || ($row['expires_at'] !== null && $row['expires_at'] <= $this->now())
        ) {
            return false;
        }
        $query = $this->db->pdo()->prepare('SELECT s.revision,s.tefillin_delete_previous,g.active FROM group_settings s JOIN groups g USING(group_id) WHERE s.group_id=?');
        $query->execute([$row['group_id']]);
        $settings = $query->fetch();
        if (
            $settings === false || !$settings['active'] || ($row['purpose'] === 'holy_message' && $row['settings_revision'] !== null && (int)$settings['revision'] !== $row['settings_revision'])
            || ($row['purpose'] === 'tefillin_previous' && !$settings['tefillin_delete_previous'])
        ) {
            return false;
        }
        return $row['purpose'] !== 'tefillin_previous' || !in_array($row['message_id'], $this->getLastModuleMessages($row['group_id'], 'tefillin'), true);
    }
    public function renew(int $id, string $owner, int $leaseSeconds = 120): void
    {
        self::lease($owner, $leaseSeconds);
        if (!$this->owns($id, $owner)) {
            throw new \RuntimeException('Deletion ownership or deadline was lost.');
        }
        $row = $this->get($id);
        $until = $row['expires_at'] === null ? $this->now() + $leaseSeconds : min($this->now() + $leaseSeconds, $row['expires_at']);
        $this->db->pdo()->prepare("UPDATE message_deletions SET lease_until=?,updated_at=? WHERE deletion_id=? AND status='leased' AND lease_owner=? AND lease_until>?")
            ->execute([$until,$this->now(),$id,$owner,$this->now()]);
    }
    public function complete(int $id, string $owner): void
    {
        $this->finish($id, $owner, 'deleted', null);
    }
    public function cancel(int $id, string $owner, string $reason): void
    {
        $this->finish($id, $owner, 'cancelled', $reason);
    }
    private function finish(int $id, string $owner, string $status, ?string $error): void
    {
        $this->db->transaction(function (PDO $pdo) use ($id, $owner, $status, $error): void {
            $owned = $this->get($id);
            if (
                $owned === null || $owned['status'] !== 'leased' || $owned['lease_owner'] !== $owner || $owned['lease_until'] <= $this->now()
                || ($status === 'deleted' && !$this->owns($id, $owner))
            ) {
                throw new \RuntimeException('Deletion ownership or deadline was lost before confirmation.');
            }
            $query = $pdo->prepare("UPDATE message_deletions SET status=?,last_error=?,completed_at=?,updated_at=?,lease_owner=NULL,lease_until=NULL WHERE deletion_id=? AND status='leased' AND lease_owner=? AND lease_until>?");
            $query->execute([$status,$error === null ? null : mb_strcut($error, 0, 1024, 'UTF-8'),$this->now(),$this->now(),$id,$owner,$this->now()]);
            if ($query->rowCount() !== 1) {
                throw new \RuntimeException('Deletion ownership was lost during confirmation.');
            }
        });
    }
    public function retry(int $id, string $owner, string $error, int $waitSeconds = 30): void
    {
        if ($waitSeconds < 1) {
            throw new \InvalidArgumentException('Deletion retries require a positive wait.');
        }
        $this->db->transaction(function (PDO $pdo) use ($id, $owner, $error, $waitSeconds): void {
            if (!$this->owns($id, $owner)) {
                throw new \RuntimeException('Deletion ownership was lost before retry persistence.');
            }
            $row = $this->get($id);
            $now = $this->now();
            $retryAt = $waitSeconds > PHP_INT_MAX - $now ? PHP_INT_MAX : $now + $waitSeconds;
            $status = $row['attempts'] >= 5 || $waitSeconds > 86400 ? 'failed' : (($row['expires_at'] !== null && $retryAt >= $row['expires_at']) ? 'expired' : 'pending');
            $pdo->prepare('UPDATE message_deletions SET status=?,retry_at=?,last_error=?,updated_at=?,lease_owner=NULL,lease_until=NULL WHERE deletion_id=? AND lease_owner=?')
                ->execute([$status,$retryAt,mb_strcut($error, 0, 1024, 'UTF-8'),$now,$id,$owner]);
        });
    }
    public function getLastModuleMessages(string $group, string $module): array
    {
        $row = $this->last($this->db->pdo(), $group, $module);
        return $row === null ? [] : self::messageIds(json_decode($row['message_ids_json'], true, 32, JSON_THROW_ON_ERROR));
    }
    private function last(PDO $pdo, string $group, string $module): ?array
    {
        SettingsRepository::identifier($group);
        self::module($module);
        $query = $pdo->prepare('SELECT * FROM module_last_messages WHERE group_id=? AND module=?');
        $query->execute([$group,$module]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }
    public function recordModuleDelivery(string $group, string $module, array $messageIds, string $deliveryKey, bool $deletePrevious = false, ?int $originalTimestamp = null): array
    {
        return $this->db->transaction(fn(PDO $pdo): array => $this->recordModuleDeliveryInTransaction($pdo, $group, $module, $messageIds, $deliveryKey, $deletePrevious, $originalTimestamp));
    }
    public function recordModuleDeliveryInTransaction(PDO $pdo, string $group, string $module, array $messageIds, string $deliveryKey, bool $deletePrevious = false, ?int $originalTimestamp = null, ?int $sourceJobId = null, ?int $completedAt = null): array
    {
        if (!$this->db->ownsTransaction($pdo)) {
            throw new \LogicException('Module delivery bookkeeping requires this database transaction.');
        }
        SettingsRepository::identifier($group);
        self::module($module);
        self::deliveryKey($deliveryKey);
        $ids = self::messageIds($messageIds);
        $timestamp = $originalTimestamp ?? $this->now();
        $completedAt ??= $this->now();
        if ($timestamp < 0 || $completedAt < 0 || ($deletePrevious && $module !== 'tefillin')) {
            throw new \InvalidArgumentException('Invalid confirmed module delivery.');
        }
        $previous = $this->last($pdo, $group, $module);
        $old = $previous === null ? [] : self::messageIds(json_decode($previous['message_ids_json'], true, 32, JSON_THROW_ON_ERROR));
        if ($previous !== null && $previous['delivery_key'] === $deliveryKey) {
            if ($old !== $ids) {
                throw new \RuntimeException('A confirmed delivery key changed message identity.');
            }
            return ['status' => 'duplicate','queued_deletions' => [],'current_message_ids' => $old];
        }
        if (
            $previous !== null && ($completedAt < $previous['delivery_completed_at'] || max($ids) <= $previous['max_message_id']
            || ($completedAt === (int)$previous['delivery_completed_at'] && $sourceJobId !== null && $previous['source_job_id'] !== null && $sourceJobId < $previous['source_job_id']))
        ) {
            return ['status' => 'stale','queued_deletions' => [],'current_message_ids' => $old];
        }
        $pdo->prepare('INSERT INTO module_last_messages(group_id,module,message_ids_json,max_message_id,delivery_key,original_timestamp,delivery_completed_at,source_job_id,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(group_id,module) DO UPDATE SET message_ids_json=excluded.message_ids_json,max_message_id=excluded.max_message_id,delivery_key=excluded.delivery_key,original_timestamp=excluded.original_timestamp,delivery_completed_at=excluded.delivery_completed_at,source_job_id=excluded.source_job_id,updated_at=excluded.updated_at')
            ->execute([$group,$module,Database::encode($ids),max($ids),$deliveryKey,$timestamp,$completedAt,$sourceJobId,$this->now()]);
        $setting = $pdo->prepare('SELECT tefillin_delete_previous,revision FROM group_settings WHERE group_id=?');
        $setting->execute([$group]);
        $settings = $setting->fetch();
        $queued = [];
        if ($deletePrevious && $settings !== false && $settings['tefillin_delete_previous']) {
            foreach (array_diff($old, $ids) as $messageId) {
                $queued[] = $this->insert($pdo, $group, $messageId, 'tefillin_previous', [
                    'original_timestamp' => (int)$previous['original_timestamp'],'sender_is_bot' => true,'sender_is_self' => true,
                    'settings_revision' => (int)$settings['revision'],'origin_delivery_key' => $previous['delivery_key'],
                ], $this->now(), null);
            }
        }
        return ['status' => 'recorded','queued_deletions' => $queued,'current_message_ids' => $ids];
    }
    public function recordSentJobDelivery(int $jobId, array $messageIds, bool $deletePrevious): array
    {
        return $this->db->transaction(function (PDO $pdo) use ($jobId, $messageIds, $deletePrevious): array {
            $query = $pdo->prepare("SELECT j.*,d.response_json FROM scheduled_jobs j JOIN delivery_log d ON d.job_id=j.job_id AND d.attempt=j.attempts AND d.outcome='sent' WHERE j.job_id=? AND j.status='sent'");
            $query->execute([$jobId]);
            $job = $query->fetch();
            if ($job === false || !is_string($job['response_json']) || !is_array(json_decode($job['response_json'], true, 64, JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Previous-reminder bookkeeping requires a persisted confirmed tefillin response.');
            }
            if ($job['module'] !== 'tefillin') {
                $pdo->prepare("UPDATE scheduled_jobs SET module_delivery_processed_at=? WHERE job_id=? AND status='sent' AND module_delivery_processed_at IS NULL")->execute([$this->now(),$jobId]);
                return ['status' => 'ignored','queued_deletions' => [],'current_message_ids' => []];
            }
            if ($job['module_delivery_processed_at'] !== null) {
                return ['status' => 'already_processed','queued_deletions' => [],'current_message_ids' => $this->getLastModuleMessages($job['group_id'], 'tefillin')];
            }
            $ids = self::messageIds($messageIds);
            $response = json_decode($job['response_json'], true, 64, JSON_THROW_ON_ERROR);
            if (array_diff($ids, \Shabbat\Telegram\SentMessageIds::extract($response, $job['random_id'], $job['group_id'])) !== []) {
                throw new \RuntimeException('Reminder identifiers are absent from the persisted successful response.');
            }
            $result = $this->recordModuleDeliveryInTransaction($pdo, $job['group_id'], 'tefillin', $ids, 'job:' . $jobId, $deletePrevious, (int)$job['completed_at'], $jobId, (int)$job['completed_at']);
            $pdo->prepare("UPDATE scheduled_jobs SET module_delivery_processed_at=? WHERE job_id=? AND status='sent' AND module_delivery_processed_at IS NULL")
                ->execute([$this->now(),$jobId]);
            return $result;
        });
    }
    private static function messageIds(array $ids): array
    {
        if (!array_is_list($ids) || $ids === [] || count($ids) > 100) {
            throw new \InvalidArgumentException('A bounded nonempty list of message identifiers is required.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || $id > 2147483647) {
                throw new \InvalidArgumentException('Message identifiers must be positive native integers.');
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
    private static function module(string $module): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $module)) {
            throw new \InvalidArgumentException('Invalid module identity.');
        }
    }
    private static function deliveryKey(mixed $key): void
    {
        if (!is_string($key) || $key === '' || strlen($key) > 128 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw new \InvalidArgumentException('Invalid delivery identity.');
        }
    }
    private static function lease(string $owner, int $seconds): void
    {
        if ($owner === '' || strlen($owner) > 128 || $seconds < 1 || $seconds > 300) {
            throw new \InvalidArgumentException('Invalid deletion lease.');
        }
    }
    private static function decode(array $row): array
    {
        foreach (['deletion_id','message_id','original_timestamp','due_at','attempts','created_at','updated_at'] as $field) {
            $row[$field] = (int)$row[$field];
        }
        foreach (['settings_revision','expires_at','retry_at','lease_until','completed_at'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int)$row[$field];
        }
        $row['sender_is_bot'] = (bool)$row['sender_is_bot'];
        $row['sender_is_self'] = (bool)$row['sender_is_self'];
        return $row;
    }
    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
