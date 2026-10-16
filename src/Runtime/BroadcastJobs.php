<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Runtime;

use PDO;
use Shabbat\Storage\{AdminAccessRepository, Database};
use Shabbat\Time\Clock;

/** Checks native persisted broadcasts against their recorded creator before each scheduler tick. */
final class BroadcastJobs
{
    private bool $running = false;
    private bool $stopped = false;
    private ?\Amp\DeferredCancellation $shutdown = null;
    private array $activeActors = [];
    /** @var array<int,\Amp\DeferredFuture<null>> */
    private array $admissionQueue = [];
    private int $nextAdmission = 0;
    private ?int $admitted = null;

    /** One native operation at a time across scheduled and manual managers. */
    public function runExclusive(callable $operation): mixed
    {
        $cancellation = $this->cancellation();
        $cancellation->throwIfRequested();
        $ticket = ++$this->nextAdmission;
        $ready = new \Amp\DeferredFuture();
        $this->admissionQueue[$ticket] = $ready;
        $this->promoteAdmission();
        try {
            $ready->getFuture()->await($cancellation);
            $cancellation->throwIfRequested();
            return $operation();
        } finally {
            unset($this->admissionQueue[$ticket]);
            if ($this->admitted === $ticket) {
                $this->admitted = null;
                $this->promoteAdmission();
            }
        }
    }
    private function promoteAdmission(): void
    {
        if ($this->admitted !== null || $this->stopped || $this->admissionQueue === []) {
            return;
        }
        $ticket = array_key_first($this->admissionQueue);
        $ready = $this->admissionQueue[$ticket];
        unset($this->admissionQueue[$ticket]);
        $this->admitted = $ticket;
        $ready->complete();
    }
    public function cancellation(): \Amp\Cancellation
    {
        $this->shutdown ??= new \Amp\DeferredCancellation();
        if ($this->stopped) {
            $this->shutdown->cancel();
        }
        return $this->shutdown->getCancellation();
    }
    public function stop(): void
    {
        $this->stopped = true;
        $this->shutdown?->cancel();
    }
    /** Local-only guard for each native worker; no provider or Telegram requests. */
    public function canContinue(): bool
    {
        if ($this->stopped) {
            return false;
        }
        foreach ($this->activeActors as $actor) {
            if (!$this->access->canMutate($actor, 'broadcast')) {
                return false;
            }
        }
        return true;
    }
    public function isRunning(): bool
    {
        return $this->running;
    }
    public function __construct(private Database $db, private Clock $clock, private AdminAccessRepository $access)
    {
    }
    public function recordScheduled(string $actor, string $id): void
    {
        self::validateId($id);
        $this->db->transaction(function (PDO $pdo) use ($actor, $id): void {
            $this->access->require($actor, 'broadcast', true);
            $existing = $this->actorForSchedule($id);
            if ($existing !== null) {
                if (!hash_equals($existing, $actor)) {
                    throw new \RuntimeException('Broadcast schedule already belongs to another actor.');
                }
                return;
            }
            $pdo->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,NULL,?,?,?)')->execute([$actor, 'operator.broadcast.scheduled', Database::encode(['id' => $id]), $this->clock->now()->getTimestamp()]);
        });
    }
    public function actorForSchedule(string $id): ?string
    {
        self::validateId($id);
        $q = $this->db->pdo()->prepare("SELECT actor_id,details_json FROM audit_log WHERE action='operator.broadcast.scheduled' AND CASE WHEN json_valid(details_json) THEN json_extract(details_json,'$.id') END=? ORDER BY audit_id DESC");
        $q->execute([$id]);
        $found = null;
        foreach ($q->fetchAll() as $row) {
            try {
                $details = json_decode($row['details_json'], true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($details) || ($details['id'] ?? null) !== $id) {
                continue;
            }
            $actor = $row['actor_id'];
            if (!is_string($actor) || !preg_match('/^[1-9][0-9]{0,18}$/D', $actor) || ($found !== null && !hash_equals($found, $actor))) {
                return null;
            }
            $found = $actor;
        }
        return $found;
    }
    /**
     * Callbacks invoke the pinned native manager; they may yield, so no DB transaction surrounds them.
     * A failed cancellation blocks every native runner. The next tick rechecks all persisted state.
     */
    public function runDue(callable $list, callable $cancel, callable $runSchedules, callable $runDestruction, callable $priority, callable $stopping, ?callable $listDestruction = null): array
    {
        if ($this->running) {
            return ['ran' => false, 'cancelled' => [], 'reason' => 'running'];
        }
        $this->running = true;
        $cancelled = [];
        $ran = false;
        try {
            if ($this->shouldStop($stopping)) {
                return ['ran' => false, 'cancelled' => [], 'reason' => 'shutdown'];
            }
            $scheduled = $this->scheduled($list());
            foreach ($scheduled as $job) {
                if ($this->shouldStop($stopping)) {
                    return ['ran' => false, 'cancelled' => $cancelled, 'reason' => 'shutdown'];
                }
                $actor = $this->actorForSchedule($job['id']);
                if ($actor !== null && $this->access->canMutate($actor, 'broadcast')) {
                    continue;
                }
                if ($job['scheduledAt'] > $this->clock->now()->getTimestamp()) {
                    return ['ran' => false, 'cancelled' => $cancelled, 'reason' => 'unattributed_future'];
                }
                if ($cancel($job['id']) !== true) {
                    return ['ran' => false, 'cancelled' => $cancelled, 'reason' => 'cancellation_unconfirmed'];
                }
                $cancelled[] = $job['id'];
            }
            // Local preflight: an empty/future queue must not trigger full permission scans.
            $scheduled = $this->scheduled($list());
            $sendDue = $this->hasDueSchedule($scheduled);
            $deleteDue = $listDestruction === null || $this->hasDueDestruction($listDestruction());
            if (!$sendDue && !$deleteDue) {
                return ['ran' => false, 'cancelled' => $cancelled, 'reason' => 'idle'];
            }
            if (!$this->priorityReady($priority)) {
                return ['ran' => false, 'cancelled' => $cancelled, 'reason' => 'permission_priority'];
            }
            if ($this->shouldStop($stopping)) {
                return ['ran' => false, 'cancelled' => $cancelled, 'reason' => 'shutdown'];
            }
            // The priority pass can yield. Recheck snapshot and authority afterwards.
            $scheduled = $this->scheduled($list());
            foreach ($scheduled as $job) {
                $actor = $this->actorForSchedule($job['id']);
                if ($actor === null || !$this->access->canMutate($actor, 'broadcast')) {
                    return ['ran' => false, 'cancelled' => $cancelled, 'reason' => 'authorization_changed'];
                }
                $this->activeActors[$actor] = $actor;
            }
            if ($this->hasDueSchedule($scheduled)) {
                $runSchedules();
                $ran = true;
            }
            if ($this->shouldStop($stopping)) {
                return ['ran' => $ran, 'cancelled' => $cancelled, 'reason' => 'shutdown'];
            }
            $deleteDue = $listDestruction === null || $this->hasDueDestruction($listDestruction());
            if ($deleteDue) {
                if ($ran && !$this->priorityReady($priority)) {
                    return ['ran' => true, 'cancelled' => $cancelled, 'reason' => 'permission_priority'];
                }
                if ($this->shouldStop($stopping)) {
                    return ['ran' => $ran, 'cancelled' => $cancelled, 'reason' => 'shutdown'];
                }
                $runDestruction();
                $ran = true;
            }
            return ['ran' => $ran, 'cancelled' => $cancelled, 'reason' => 'ok'];
        } finally {
            $this->activeActors = [];
            // Intentional cancellation propagates after native children have drained.
            $this->running = false;
        }
    }
    private function hasDueSchedule(array $jobs): bool
    {
        foreach ($jobs as $job) {
            if ($job['scheduledAt'] <= $this->clock->now()->getTimestamp()) {
                return true;
            }
        }
        return false;
    }
    private function hasDueDestruction(mixed $items): bool
    {
        if (!is_array($items)) {
            throw new \RuntimeException('Invalid native destruction list.');
        }
        $due = false;
        foreach ($items as $key => $item) {
            if (!is_array($item) || !is_string($item['status'] ?? null)) {
                throw new \RuntimeException('Invalid native destruction record.');
            }
            if ($item['status'] !== 'scheduled') {
                continue;
            }
            if (
                !is_string($item['id'] ?? null) || !preg_match('/^selfdestruct_[a-f0-9]{16}$/D', $item['id'])
                || $key !== $item['id'] || !is_int($item['deleteAt'] ?? null) || $item['deleteAt'] < 1
            ) {
                throw new \RuntimeException('Invalid native destruction identity or deadline.');
            }
            $due = $due || $item['deleteAt'] <= $this->clock->now()->getTimestamp();
        }
        return $due;
    }
    /** @phpstan-impure Shutdown can change while native callbacks await work. */
    private function shouldStop(callable $stopping): bool
    {
        return $this->stopped || $stopping() === true;
    }
    /** @phpstan-impure Pending permission work can change while a native callback awaits. */
    private function priorityReady(callable $priority): bool
    {
        return $priority() === true;
    }
    private function scheduled(mixed $items): array
    {
        if (!is_array($items)) {
            throw new \RuntimeException('Invalid native broadcast schedule list.');
        }
        $out = [];
        foreach ($items as $key => $item) {
            if (!is_array($item) || !is_string($item['status'] ?? null)) {
                throw new \RuntimeException('Invalid native broadcast schedule record.');
            }
            if ($item['status'] !== 'scheduled') {
                continue;
            }
            if (!is_string($item['id'] ?? null) || !is_int($item['scheduledAt'] ?? null) || $item['scheduledAt'] < 1) {
                throw new \RuntimeException('Invalid scheduled broadcast metadata.');
            }
            self::validateId($item['id']);
            if (!is_string($key) || !hash_equals($key, $item['id'])) {
                throw new \RuntimeException('Native broadcast schedule identity mismatch.');
            }
            $out[] = $item;
        }
        return $out;
    }
    private static function validateId(string $id): void
    {
        if (!preg_match('/^schedule_[a-f0-9]{16}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid native broadcast schedule ID.');
        }
    }
}
