<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Storage;

use Shabbat\Time\Clock;

/** One bounded, short-lived diagnostic session per operator, with fenced claims. */
final class DiagnosticsRepository
{
    public const TTL = 900;
    public const MAX_BYTES = 1048576;

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function get(string $user, ?string $token = null): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT * FROM diagnostic_panels WHERE viewer_id=? AND expires_at>?');
        $q->execute([$user, $this->now()]);
        $row = $q->fetch();
        if ($row === false || ($token !== null && !hash_equals($row['token'], $token))) {
            return null;
        }
        $row['report'] = json_decode($row['report_json'], true, 32, JSON_THROW_ON_ERROR);
        unset($row['report_json']);
        return $row;
    }

    /** Reserve before provider I/O. The initial claim owner is the new session token. */
    public function begin(string $user, ?string $group): string
    {
        return $this->db->transaction(function (\PDO $pdo) use ($user, $group): string {
            $q = $pdo->prepare('SELECT group_id,created_at,busy_until,export_at FROM diagnostic_panels WHERE viewer_id=?');
            $q->execute([$user]);
            $previous = $q->fetch();
            $now = $this->now();
            $cooldown = $previous !== false && $previous['group_id'] !== $group ? 2 : 30;
            if ($previous !== false && ((int)$previous['created_at'] + $cooldown > $now || (int)$previous['busy_until'] > $now)) {
                throw new DiagnosticThrottled();
            }
            $pdo->prepare('DELETE FROM diagnostic_panels WHERE expires_at<=? AND busy_until<=?')->execute([$now, $now]);
            $token = bin2hex(random_bytes(12));
            $pdo->prepare('INSERT INTO diagnostic_panels(viewer_id,token,group_id,created_at,expires_at,busy_until,busy_owner,export_at) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(viewer_id) DO UPDATE SET token=excluded.token,group_id=excluded.group_id,report_json=\'{}\',page=0,category=\'all\',search=\'\',prompt=NULL,created_at=excluded.created_at,expires_at=excluded.expires_at,busy_until=excluded.busy_until,busy_owner=excluded.busy_owner')
                ->execute([$user, $token, $group, $now, $now + self::TTL, $now + 120, $token, $previous === false ? 0 : $previous['export_at']]);
            return $token;
        });
    }

    public function saveReport(string $user, string $token, array $report, string $owner): void
    {
        $encoded = Database::encode($report);
        if (strlen($encoded) > self::MAX_BYTES || count($report['records'] ?? []) > 500) {
            throw new \LengthException('Diagnostic snapshot exceeds the bounded limit.');
        }
        $q = $this->db->pdo()->prepare('UPDATE diagnostic_panels SET report_json=? WHERE viewer_id=? AND token=? AND expires_at>? AND busy_owner=? AND busy_until>?');
        $q->execute([$encoded, $user, $token, $this->now(), $owner, $this->now()]);
        if ($q->rowCount() !== 1) {
            throw new \RuntimeException('Diagnostic ownership expired.');
        }
    }

    public function update(string $user, string $token, array $values, string $owner): void
    {
        $allowed = ['message_id', 'page', 'category', 'search', 'prompt'];
        $sets = $args = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Invalid diagnostic view field.');
            }
            $sets[] = $key . '=?';
            $args[] = $value;
        }
        if ($sets !== []) {
            $q = $this->db->pdo()->prepare('UPDATE diagnostic_panels SET ' . implode(',', $sets) . ' WHERE viewer_id=? AND token=? AND expires_at>? AND busy_owner=? AND busy_until>?');
            $q->execute([...$args, $user, $token, $this->now(), $owner, $this->now()]);
            if ($q->rowCount() !== 1) {
                throw new \RuntimeException('Diagnostic ownership expired.');
            }
        }
    }

    public function claim(string $user, string $token): ?string
    {
        $owner = bin2hex(random_bytes(12));
        $q = $this->db->pdo()->prepare('UPDATE diagnostic_panels SET busy_until=?,busy_owner=? WHERE viewer_id=? AND token=? AND expires_at>? AND busy_until<=?');
        $q->execute([$this->now() + 120, $owner, $user, $token, $this->now(), $this->now()]);
        return $q->rowCount() === 1 ? $owner : null;
    }

    public function assertOwnership(string $user, string $token, string $owner): void
    {
        $q = $this->db->pdo()->prepare('SELECT 1 FROM diagnostic_panels WHERE viewer_id=? AND token=? AND expires_at>? AND busy_owner=? AND busy_until>?');
        $q->execute([$user, $token, $this->now(), $owner, $this->now()]);
        if ($q->fetchColumn() === false) {
            throw new \RuntimeException('Diagnostic ownership expired.');
        }
    }

    /** Persist query identity before upload; uncertain sends are never automatically replayed. */
    public function claimExport(string $user, string $token, string $owner, string $queryId): bool
    {
        if (!preg_match('/^-?[0-9]{1,20}$/D', $queryId)) {
            return false;
        }
        return $this->db->transaction(function (\PDO $pdo) use ($user, $token, $owner, $queryId): bool {
            $this->assertOwnership($user, $token, $owner);
            $row = $this->get($user, $token);
            $queries = json_decode($row['export_queries_json'], true, 8, JSON_THROW_ON_ERROR);
            if ((int)$row['export_at'] + 60 > $this->now() || in_array($queryId, $queries, true)) {
                return false;
            }
            $queries[] = $queryId;
            $pdo->prepare('UPDATE diagnostic_panels SET export_at=?,export_queries_json=? WHERE viewer_id=? AND token=? AND busy_owner=?')
                ->execute([$this->now(), Database::encode(array_slice($queries, -64)), $user, $token, $owner]);
            return true;
        });
    }

    public function release(string $user, string $token, string $owner): void
    {
        $this->db->pdo()->prepare('UPDATE diagnostic_panels SET busy_until=0,busy_owner=NULL WHERE viewer_id=? AND token=? AND busy_owner=?')->execute([$user, $token, $owner]);
    }

    public function cancelInput(string $user): void
    {
        $this->db->pdo()->prepare('UPDATE diagnostic_panels SET prompt=NULL WHERE viewer_id=?')->execute([$user]);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
