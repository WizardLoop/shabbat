<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use DateTimeImmutable;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Shabbat\Storage\Database;
use Shabbat\Storage\DeletionRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class DatabaseTransactionTest extends TestCase
{
    private Database $db;
    private DeletionRepository $deletions;

    protected function setUp(): void
    {
        // Match PHP 8.3 PDO_SQLite reporting while retaining real SQLite transactions.
        $connection = new class ('sqlite::memory:') extends PDO {
            public function inTransaction(): bool
            {
                return false;
            }
        };
        $this->db = new Database($connection);
        $this->db->migrate();
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-14T09:00:00Z'));
        (new SettingsRepository($this->db, $clock))->ensureGroup('-1', '', ['tefillin_delete_previous' => true]);
        $this->deletions = new DeletionRepository($this->db, $clock);
    }

    public function testOwnedImmediateTransactionCommitsWhenPdoReportsFalse(): void
    {
        $this->db->transaction(function (PDO $pdo): void {
            self::assertFalse($pdo->inTransaction());
            self::assertTrue($this->db->ownsTransaction($pdo));
            $result = $this->deletions->recordModuleDeliveryInTransaction($pdo, '-1', 'tefillin', [100], 'first');
            self::assertSame('recorded', $result['status']);
        });
        self::assertFalse($this->db->ownsTransaction($this->db->pdo()));
        $this->deletions->recordModuleDelivery('-1', 'tefillin', [101], 'second', true);
        self::assertSame([101], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
        self::assertSame([100], array_column($this->deletions->list(), 'message_id'));
    }

    public function testBookkeepingRejectsTheSameConnectionOutsideAnOwnedTransaction(): void
    {
        foreach ([false, true] as $externalTransaction) {
            if ($externalTransaction) {
                $this->db->pdo()->beginTransaction();
            }
            try {
                $this->deletions->recordModuleDeliveryInTransaction($this->db->pdo(), '-1', 'tefillin', [100], 'outside');
                self::fail('Unowned bookkeeping was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Module delivery bookkeeping requires this database transaction.', $error->getMessage());
            } finally {
                if ($externalTransaction) {
                    $this->db->pdo()->rollBack();
                }
            }
            self::assertSame([], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
        }
    }

    public function testBookkeepingRejectsAnotherActiveConnection(): void
    {
        $other = new PDO('sqlite::memory:');
        $other->beginTransaction();
        try {
            $this->db->transaction(function (PDO $owned) use ($other): void {
                self::assertTrue($other->inTransaction());
                self::assertTrue($this->db->ownsTransaction($owned));
                self::assertFalse($this->db->ownsTransaction($other));
                $this->deletions->recordModuleDeliveryInTransaction($other, '-1', 'tefillin', [100], 'wrong-connection');
            });
            self::fail('A different active connection was accepted.');
        } catch (LogicException $error) {
            self::assertSame('Module delivery bookkeeping requires this database transaction.', $error->getMessage());
        } finally {
            $other->rollBack();
        }
        self::assertFalse($this->db->ownsTransaction($this->db->pdo()));
        self::assertSame([], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
    }

    public function testCleanupFailureRollsBackPointerAndEarlierQueuedDeletionThenAllowsRetry(): void
    {
        $this->deletions->recordModuleDelivery('-1', 'tefillin', [100,101], 'first', true);
        $this->db->pdo()->exec("CREATE TRIGGER reject_second_cleanup BEFORE INSERT ON message_deletions WHEN NEW.message_id=101 BEGIN SELECT RAISE(FAIL,'second cleanup failed'); END");
        try {
            $this->deletions->recordModuleDelivery('-1', 'tefillin', [102], 'replacement', true);
            self::fail('Partial deletion bookkeeping was committed.');
        } catch (PDOException $error) {
            self::assertStringContainsString('second cleanup failed', $error->getMessage());
        }
        self::assertFalse($this->db->ownsTransaction($this->db->pdo()));
        self::assertSame([100,101], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
        self::assertSame([], $this->deletions->list());
        $this->db->pdo()->exec('DROP TRIGGER reject_second_cleanup');
        $this->deletions->recordModuleDelivery('-1', 'tefillin', [102], 'replacement', true);
        self::assertSame([102], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
        self::assertSame([100,101], array_column($this->deletions->list(), 'message_id'));
    }

    public function testFailedBeginDoesNotClaimAnExternalTransaction(): void
    {
        $this->db->pdo()->exec('BEGIN IMMEDIATE');
        try {
            $this->db->transaction(static fn (): bool => true);
            self::fail('A second transaction began on the same connection.');
        } catch (PDOException) {
            self::assertFalse($this->db->ownsTransaction($this->db->pdo()));
        } finally {
            $this->db->pdo()->exec('ROLLBACK');
        }
        self::assertSame('recorded', $this->deletions->recordModuleDelivery('-1', 'tefillin', [100], 'after-begin-failure')['status']);
    }

    public function testRejectedNestedTransactionLeavesTheOuterOwnerIntact(): void
    {
        $this->db->transaction(function (PDO $pdo): void {
            try {
                $this->db->transaction(static fn (): bool => true);
                self::fail('A nested transaction was accepted.');
            } catch (LogicException $error) {
                self::assertSame('Nested/shared transactions are forbidden.', $error->getMessage());
            }
            self::assertTrue($this->db->ownsTransaction($pdo));
            $this->deletions->recordModuleDeliveryInTransaction($pdo, '-1', 'tefillin', [100], 'outer');
        });
        self::assertFalse($this->db->ownsTransaction($this->db->pdo()));
        self::assertSame([100], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
    }

    public function testFailedCommitRollsBackAndClearsOwnership(): void
    {
        $this->db->pdo()->exec('CREATE TABLE transaction_parent(id INTEGER PRIMARY KEY)');
        $this->db->pdo()->exec('CREATE TABLE transaction_child(parent_id INTEGER REFERENCES transaction_parent(id) DEFERRABLE INITIALLY DEFERRED)');
        try {
            $this->db->transaction(function (PDO $pdo): void {
                $pdo->exec('INSERT INTO transaction_child VALUES(99)');
                $this->deletions->recordModuleDeliveryInTransaction($pdo, '-1', 'tefillin', [100], 'uncommitted');
            });
            self::fail('A deferred foreign-key violation committed.');
        } catch (PDOException $error) {
            self::assertStringContainsString('FOREIGN KEY constraint failed', $error->getMessage());
        }
        self::assertFalse($this->db->ownsTransaction($this->db->pdo()));
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM transaction_child')->fetchColumn());
        self::assertSame([], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
        self::assertSame('recorded', $this->deletions->recordModuleDelivery('-1', 'tefillin', [100], 'after-commit-failure')['status']);
    }
}
