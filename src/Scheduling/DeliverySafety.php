<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Scheduling;

use PDO;
use Shabbat\Storage\Database;
use Shabbat\Time\Clock;

final class DeliverySafety
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }
    /** Coalesce only requests proven never dispatched; preserve every uncertain payload and random_id. */
    public static function coalesce(PDO $pdo, int $now, string $group): void
    {
        $query = $pdo->prepare("UPDATE scheduled_jobs AS older SET status='cancelled',completed_at=?,last_error='superseded by newer due tefillin reminder'
            WHERE older.group_id=? AND older.module='tefillin' AND older.status='pending' AND older.dispatch_started_at IS NULL AND older.run_at<=? AND older.expires_at>?
            AND EXISTS(SELECT 1 FROM scheduled_jobs newer WHERE newer.group_id=older.group_id AND newer.module='tefillin'
                AND newer.status='pending' AND newer.run_at<=? AND newer.expires_at>?
                AND (newer.run_at>older.run_at OR (newer.run_at=older.run_at AND newer.job_id>older.job_id)))");
        $query->execute([$now,$group,$now,$now,$now,$now]);
    }
    /** No asynchronous work is permitted between this reservation and the actual network request. */
    public function reserve(array $job, array $settings, string $owner): bool
    {
        $now = $this->clock->now()->getTimestamp();
        return $this->db->transaction(function (PDO $pdo) use ($job, $settings, $owner, $now): bool {
            $current = $this->owned($pdo, (int)$job['job_id'], $owner, $now);
            if ($current === null) {
                return false;
            }
            if ($current['module'] === 'tefillin') {
                // An uncertain retry keeps its existing logical quota slot and frozen payload.
                // A second worker may not spend another slot while a previous request is unresolved.
                $query = $pdo->prepare("SELECT MAX(COALESCE(lease_until,retry_at,?)) FROM scheduled_jobs
                    WHERE group_id=? AND module='tefillin' AND job_id<>? AND dispatch_started_at IS NOT NULL
                    AND status IN ('leased','pending') AND expires_at>?
                    AND (? IS NULL OR dispatch_started_at<? OR (dispatch_started_at=? AND job_id<?) OR (status='leased' AND lease_until>?))");
                $query->execute([$now + 30,$current['group_id'],$current['job_id'],$now,$current['dispatch_started_at'],$current['dispatch_started_at'],$current['dispatch_started_at'],$current['job_id'],$now]);
                $blocking = $query->fetchColumn();
                if ($blocking !== false && $blocking !== null) {
                    throw new DeliveryDeferredException('A previous tefillin delivery remains uncertain.', max($now + 30, (int)$blocking));
                }
                $local = $this->clock->now()->setTimezone(new \DateTimeZone($settings['timezone_id']));
                $start = $local->setTime(0, 0)->getTimestamp();
                $end = $local->setTime(0, 0)->modify('+1 day')->getTimestamp();
                $query = $pdo->prepare("SELECT COUNT(*) FROM scheduled_jobs WHERE group_id=? AND module='tefillin' AND job_id<>?
                    AND ((status='sent' AND completed_at>=? AND completed_at<?)
                        OR (status<>'sent' AND last_dispatch_at>=? AND last_dispatch_at<?))");
                $query->execute([$current['group_id'],$current['job_id'],$start,$end,$start,$end]);
                if ((int)$query->fetchColumn() >= max(1, min(24, (int)($settings['tefillin_max_per_day'] ?? 3)))) {
                    return false;
                }
                // Even an old uncertain identity waits after a different confirmed send; replaying many old uncertainties must not burst.
                $query = $pdo->prepare("SELECT MAX(CASE WHEN status='sent' THEN completed_at ELSE last_dispatch_at END)
                    FROM scheduled_jobs WHERE group_id=? AND module='tefillin' AND job_id<>?
                    AND (status='sent' OR ? IS NULL OR status NOT IN ('pending','leased'))");
                $query->execute([$current['group_id'],$current['job_id'],$current['dispatch_started_at']]);
                $last = $query->fetchColumn();
                $retryAt = (int)$last + max(5, (int)($settings['tefillin_min_spacing_minutes'] ?? 30)) * 60;
                if ($last !== false && $last !== null && $retryAt > $now) {
                    throw new DeliveryDeferredException('Actual tefillin delivery spacing has not elapsed.', $retryAt);
                }
            }
            $this->stamp($pdo, (int)$current['job_id'], $owner, $now);
            return true;
        });
    }
    public function mark(int $id, string $owner): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->db->transaction(function (PDO $pdo) use ($id, $owner, $now): void {
            if ($this->owned($pdo, $id, $owner, $now) === null) {
                throw new \RuntimeException('Delivery ownership or deadline was lost before dispatch.');
            }
            $this->stamp($pdo, $id, $owner, $now);
        });
    }
    private function owned(PDO $pdo, int $id, string $owner, int $now): ?array
    {
        $query = $pdo->prepare("SELECT * FROM scheduled_jobs WHERE job_id=? AND status='leased' AND lease_owner=? AND lease_until>? AND expires_at>?");
        $query->execute([$id,$owner,$now,$now]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }
    private function stamp(PDO $pdo, int $id, string $owner, int $now): void
    {
        $query = $pdo->prepare("UPDATE scheduled_jobs SET dispatch_started_at=COALESCE(dispatch_started_at,?),last_dispatch_at=? WHERE job_id=? AND status='leased' AND lease_owner=?");
        $query->execute([$now,$now,$id,$owner]);
    }
}
