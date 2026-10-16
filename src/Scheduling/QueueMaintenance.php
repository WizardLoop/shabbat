<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Scheduling;

use PDO;
use Shabbat\Storage\Database;
use Shabbat\Time\Clock;

final class QueueMaintenance
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }
    /** Counts are observed backlog, not a throughput or availability promise. */
    public function metrics(): array
    {
        $now = $this->clock->now()->getTimestamp();
        $pdo = $this->db->pdo();
        $result = [
            'active' => (int)$pdo->query("SELECT COUNT(*) FROM scheduled_jobs WHERE status IN ('pending','leased')")->fetchColumn(),
            'uncertain' => (int)$pdo->query("SELECT COUNT(*) FROM scheduled_jobs WHERE status<>'sent' AND dispatch_started_at IS NOT NULL")->fetchColumn(),
        ];
        $query = $pdo->prepare("SELECT COUNT(*) AS due,MIN(run_at) AS oldest_due_at FROM scheduled_jobs WHERE status IN ('pending','leased') AND run_at<=? AND expires_at>?");
        $query->execute([$now,$now]);
        $row = $query->fetch();
        $result['due'] = (int)$row['due'];
        $result['oldest_due_age_seconds'] = $row['oldest_due_at'] === null ? 0 : max(0, $now - (int)$row['oldest_due_at']);
        $query = $pdo->prepare("SELECT COUNT(*) AS sent,MAX(MAX(0,completed_at-run_at)) AS delay FROM scheduled_jobs WHERE status='sent' AND completed_at>=?");
        $query->execute([$now - 86400]);
        $row = $query->fetch();
        $result['sent_last_24h'] = (int)$row['sent'];
        $result['max_delivery_delay_last_24h'] = (int)$row['delay'];
        $query = $pdo->prepare("SELECT COUNT(*) FROM scheduled_jobs WHERE status='expired' AND expires_at>=?");
        $query->execute([$now - 86400]);
        $result['expired_last_24h'] = (int)$query->fetchColumn();
        $result['observed_at'] = $now;
        return $result;
    }
    /** Never touches permission operations/snapshots, uncertain deliveries, active jobs, or current message pointers. */
    public function prune(int $retentionDays = 90, int $batch = 200): array
    {
        if (($retentionDays !== 0 && ($retentionDays < 30 || $retentionDays > 3650)) || $batch < 1 || $batch > 1000) {
            throw new \InvalidArgumentException('Retention must be disabled or 30..3650 days; batch must be 1..1000.');
        }
        $empty = ['jobs' => 0,'delivery_log' => 0,'calendar_cache' => 0,'audit_log' => 0,'message_deletions' => 0,'conversation_states' => 0];
        if ($retentionDays === 0) {
            return $empty;
        }
        $cutoff = $this->clock->now()->getTimestamp() - $retentionDays * 86400;
        return $this->db->transaction(function (PDO $pdo) use ($cutoff, $batch, $empty): array {
            $result = $empty;
            // Sent acknowledgement rounds are retained, so old group buttons/vote totals stay usable.
            // Current previous-message pointers and their pending cleanup must also survive pruning.
            $query = $pdo->prepare("SELECT j.job_id FROM scheduled_jobs j WHERE j.status IN ('sent','cancelled','expired','failed')
                AND j.expires_at<? AND COALESCE(j.completed_at,j.created_at)<?
                AND (j.status='sent' OR j.dispatch_started_at IS NULL)
                AND (j.module<>'tefillin' OR j.status<>'sent' OR j.module_delivery_processed_at IS NOT NULL)
                AND NOT EXISTS(SELECT 1 FROM delivery_log d WHERE d.job_id=j.job_id AND d.outcome IN ('attempting','uncertain'))
                AND NOT EXISTS(SELECT 1 FROM module_last_messages m WHERE m.source_job_id=j.job_id)
                AND NOT EXISTS(SELECT 1 FROM tefillin_ack_rounds a WHERE a.job_id=j.job_id)
                ORDER BY j.expires_at,j.job_id LIMIT ?");
            $query->bindValue(1, $cutoff, PDO::PARAM_INT);
            $query->bindValue(2, $cutoff, PDO::PARAM_INT);
            $query->bindValue(3, $batch, PDO::PARAM_INT);
            $query->execute();
            $ids = $query->fetchAll(PDO::FETCH_COLUMN);
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $delete = $pdo->prepare('DELETE FROM delivery_log WHERE delivery_id IN (SELECT delivery_id FROM delivery_log WHERE job_id IN (' . $placeholders . ') ORDER BY delivery_id LIMIT ?)');
                foreach ($ids as $index => $id) {
                    $delete->bindValue($index + 1, (int)$id, PDO::PARAM_INT);
                }
                $delete->bindValue(count($ids) + 1, $batch, PDO::PARAM_INT);
                $delete->execute();
                $result['delivery_log'] = $delete->rowCount();
                $delete = $pdo->prepare('DELETE FROM scheduled_jobs WHERE job_id IN (' . $placeholders . ') AND NOT EXISTS(SELECT 1 FROM delivery_log d WHERE d.job_id=scheduled_jobs.job_id)');
                $delete->execute($ids);
                $result['jobs'] = $delete->rowCount();
            }
            $result['calendar_cache'] = $this->deleteBatch($pdo, 'calendar_cache', 'cache_key', 'expires_at<?', $cutoff, $batch);
            $result['conversation_states'] = $this->deleteBatch($pdo, 'conversation_states', 'token_hash', 'expires_at<?', $cutoff, $batch);
            // Allowlist routine history only. Operator permissions, broadcasts, recovery and unknown actions remain evidence.
            $result['audit_log'] = $this->deleteBatch($pdo, 'audit_log', 'audit_id', "created_at<? AND action IN ('settings.update','template.update','template.test') AND NOT EXISTS(SELECT 1 FROM group_lock_state l WHERE l.group_id=audit_log.group_id AND l.status NOT IN ('restored','prelocked'))", $cutoff, $batch);
            $result['message_deletions'] = $this->deleteBatch($pdo, 'message_deletions', 'deletion_id', "completed_at<? AND status IN ('deleted','cancelled','expired','failed') AND lease_owner IS NULL", $cutoff, $batch);
            return $result;
        });
    }
    private function deleteBatch(PDO $pdo, string $table, string $id, string $condition, int $cutoff, int $limit): int
    {
        // All identifiers and conditions originate from this fixed source allowlist, never user input.
        $query = $pdo->prepare("DELETE FROM $table WHERE $id IN (SELECT $id FROM $table WHERE $condition ORDER BY $id LIMIT ?)");
        $query->bindValue(1, $cutoff, PDO::PARAM_INT);
        $query->bindValue(2, $limit, PDO::PARAM_INT);
        $query->execute();
        return $query->rowCount();
    }
}
