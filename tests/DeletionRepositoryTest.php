<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shabbat\Scheduling\JobRepository;
use Shabbat\Storage\Database;
use Shabbat\Storage\DeletionRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class DeletionRepositoryTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private DeletionRepository $deletions;
    private JobRepository $jobs;
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-14T09:00:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->settings->ensureGroup('-1', '', ['tefillin_delete_previous' => true]);
        $this->deletions = new DeletionRepository($this->db, $this->clock);
        $this->jobs = new JobRepository($this->db, $this->clock);
    }
    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
    private function metadata(): array
    {
        return ['original_timestamp' => $this->now() - 60,'sender_id' => '42','sender_is_bot' => false,'settings_revision' => 1];
    }
    private function sentJob(int $messageId, string $event, string $module = 'tefillin'): int
    {
        $id = $this->jobs->enqueue('-1', $module, $event, $this->now(), $this->now() + 3600, 1, ['text' => 'Fixture']);
        $leased = $this->jobs->lease('sender');
        self::assertSame($id, $leased['job_id']);
        $this->jobs->sent($id, 'sender', ['_' => 'updateShortSentMessage','id' => $messageId]);
        return $id;
    }
    public function testOnlyReplacedStoredIdsAreQueuedAfterConfirmedReplacement(): void
    {
        $first = $this->deletions->recordModuleDelivery('-1', 'tefillin', [100,101], 'first', true);
        self::assertSame([], $first['queued_deletions']);
        $second = $this->deletions->recordModuleDelivery('-1', 'tefillin', [102,103], 'second', true);
        self::assertCount(2, $second['queued_deletions']);
        self::assertSame([102,103], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
        self::assertSame('duplicate', $this->deletions->recordModuleDelivery('-1', 'tefillin', [102,103], 'second', true)['status']);
        self::assertCount(2, $this->deletions->list());
        $row = $this->deletions->claim('cleanup');
        self::assertSame(100, $row['message_id']);
        self::assertTrue($row['sender_is_bot']);
        self::assertTrue($row['sender_is_self']);
        self::assertSame('first', $row['origin_delivery_key']);
        self::assertNull($row['expires_at']);
        $this->deletions->complete($row['deletion_id'], 'cleanup');
        self::assertSame('deleted', $this->deletions->get($row['deletion_id'])['status']);
    }
    public function testDisabledConsentRetainsPointerWithoutRetrospectiveBulkCleanup(): void
    {
        $this->settings->updateGroup('-1', 1, ['tefillin_delete_previous' => false], '42');
        $this->deletions->recordModuleDelivery('-1', 'tefillin', [100], 'first', true);
        $this->deletions->recordModuleDelivery('-1', 'tefillin', [101], 'second', true);
        self::assertSame([], $this->deletions->list());
        self::assertSame([101], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
        $this->settings->updateGroup('-1', 2, ['tefillin_delete_previous' => true], '42');
        $this->deletions->recordModuleDelivery('-1', 'tefillin', [102], 'third', true);
        self::assertSame([101], array_column($this->deletions->list(), 'message_id'));
    }
    public function testHolyDeadlineIsMandatoryAndReplayDoesNotExtendIt(): void
    {
        $id = $this->deletions->enqueue('-1', 30, 'holy_message', $this->metadata(), $this->now(), $this->now() + 10);
        self::assertSame($id, $this->deletions->enqueue('-1', 30, 'holy_message', $this->metadata(), $this->now(), $this->now() + 100));
        self::assertSame($this->now() + 10, $this->deletions->get($id)['expires_at']);
        $row = $this->deletions->claim('a');
        self::assertTrue($this->deletions->owns($row['deletion_id'], 'a'));
        $this->clock->set($this->clock->now()->modify('+10 seconds'));
        self::assertFalse($this->deletions->owns($id, 'a'));
        self::assertNull($this->deletions->claim('b'));
        self::assertSame('expired', $this->deletions->get($id)['status']);
        $this->expectException(InvalidArgumentException::class);
        $this->deletions->enqueue('-1', 31, 'holy_message', $this->metadata(), $this->now());
    }
    public function testLeasesAreFencedAndRetryWaitIsPersistentAndBounded(): void
    {
        $id = $this->deletions->enqueue('-1', 30, 'holy_message', $this->metadata(), $this->now(), $this->now() + 3600);
        self::assertNotNull($this->deletions->claim('a', 10));
        self::assertNull($this->deletions->claim('b'));
        $this->clock->set($this->clock->now()->modify('+11 seconds'));
        self::assertNotNull($this->deletions->claim('b'));
        self::assertFalse($this->deletions->owns($id, 'a'));
        $this->deletions->retry($id, 'b', 'FLOOD_WAIT_30', 30);
        self::assertNull($this->deletions->claim('c'));
        $this->clock->set($this->clock->now()->modify('+31 seconds'));
        self::assertNotNull($this->deletions->claim('c'));
        $this->deletions->renew($id, 'c', 200);
        self::assertTrue($this->deletions->owns($id, 'c'));
        $this->deletions->retry($id, 'c', 'quota wait exceeds cleanup window', 172800);
        self::assertSame('failed', $this->deletions->get($id)['status']);
    }
    public function testCurrentReminderIsProtectedEvenIfAnOldQueueRowExists(): void
    {
        $this->deletions->recordModuleDelivery('-1', 'tefillin', [100], 'first', true);
        $this->deletions->recordModuleDelivery('-1', 'tefillin', [101], 'second', true);
        $this->db->pdo()->exec("UPDATE module_last_messages SET message_ids_json='[100]',max_message_id=100 WHERE group_id='-1'");
        self::assertNull($this->deletions->claim('worker'));
        self::assertSame('cancelled', $this->deletions->get(1)['status']);
    }
    public function testFailedBookkeepingRollsBackPointerAndMarkerWithoutChangingSentJob(): void
    {
        $first = $this->sentJob(100, 'first');
        $this->deletions->recordSentJobDelivery($first, [100], true);
        $second = $this->sentJob(101, 'second');
        $this->db->pdo()->exec("CREATE TRIGGER reject_cleanup BEFORE INSERT ON message_deletions BEGIN SELECT RAISE(FAIL,'disk failure'); END");
        try {
            $this->deletions->recordSentJobDelivery($second, [101], true);
            self::fail('Cleanup persistence failure was swallowed.');
        } catch (PDOException $error) {
            self::assertStringContainsString('disk failure', $error->getMessage());
        }
        self::assertSame('sent', $this->jobs->get($second)['status']);
        self::assertNull($this->jobs->get($second)['module_delivery_processed_at']);
        self::assertSame([100], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
        self::assertSame([$second], array_column($this->jobs->pendingDeliveryBookkeeping(), 'job_id'));
        self::assertSame(101, $this->jobs->pendingDeliveryBookkeeping()[0]['response']['id']);
        $this->db->pdo()->exec('DROP TRIGGER reject_cleanup');
        self::assertSame('recorded', $this->deletions->recordSentJobDelivery($second, [101], true)['status']);
        self::assertSame('already_processed', $this->deletions->recordSentJobDelivery($second, [101], true)['status']);
        self::assertSame([], $this->jobs->pendingDeliveryBookkeeping());
        self::assertSame('sent', $this->jobs->get($second)['status']);
    }
    public function testReplayedOlderAcknowledgementCannotMovePointerBackwards(): void
    {
        $old = $this->sentJob(100, 'old');
        $this->clock->set($this->clock->now()->modify('+1 second'));
        $new = $this->sentJob(101, 'new');
        $this->deletions->recordSentJobDelivery($new, [101], true);
        self::assertSame('stale', $this->deletions->recordSentJobDelivery($old, [100], true)['status']);
        self::assertSame([101], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
        self::assertSame([], $this->deletions->list());
        self::assertSame([], $this->jobs->pendingDeliveryBookkeeping());
    }
    public function testOnlyPersistedTefillinResponseIdsCanEnterReplacementBookkeeping(): void
    {
        $id = $this->sentJob(100, 'first');
        try {
            $this->deletions->recordSentJobDelivery($id, [999], true);
            self::fail('An unrelated message identifier was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('absent', $error->getMessage());
        }
        self::assertNull($this->jobs->get($id)['module_delivery_processed_at']);
        $other = $this->sentJob(101, 'other', 'daf');
        self::assertSame('ignored', $this->deletions->recordSentJobDelivery($other, [], true)['status']);
        self::assertSame([], $this->deletions->getLastModuleMessages('-1', 'daf'));
        $this->jobs->markDeliveryProcessed($id);
        self::assertSame([], $this->jobs->pendingDeliveryBookkeeping());
        $this->expectException(InvalidArgumentException::class);
        $this->deletions->enqueue('-1', 200, 'tefillin_previous', ['original_timestamp' => $this->now(),'sender_is_bot' => true,'sender_is_self' => true,'origin_delivery_key' => 'forged'], $this->now());
    }
    public function testUntrustedResponseShapesCannotAuthorizeDeletionOfUnrelatedIds(): void
    {
        $job = $this->sentJob(100, 'strict-ack');
        foreach (
            [
            ['_' => 'updates','message_id' => 100], ['_' => 'updates','message_ids' => [100]], ['id' => 100],
            ['_' => 'updates','updates' => [['_' => 'updateNewScheduledMessage','message' => ['_' => 'message','out' => true,'id' => 100]]]],
            ['_' => 'updates','updates' => [['_' => 'updateNewMessage','message' => ['_' => 'message','out' => false,'id' => 100]]]],
            ] as $response
        ) {
            $this->db->pdo()->prepare('UPDATE delivery_log SET response_json=? WHERE job_id=?')->execute([Database::encode($response),$job]);
            try {
                $this->deletions->recordSentJobDelivery($job, [100], true);
                self::fail('An untrusted acknowledgement entered deletion bookkeeping.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('absent', $error->getMessage());
            }
            self::assertNull($this->jobs->get($job)['module_delivery_processed_at']);
            self::assertSame([], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
        }
    }
    public function testConsentRevisionChangesFenceAnAlreadyClaimedHolyDeletion(): void
    {
        $id = $this->deletions->enqueue('-1', 30, 'holy_message', $this->metadata(), $this->now(), $this->now() + 3600);
        $this->deletions->claim('a');
        $this->settings->updateGroup('-1', 1, ['delete_holy_user_messages' => true], '42');
        self::assertFalse($this->deletions->owns($id, 'a'));
        $this->deletions->cancel($id, 'a', 'obsolete settings revision');
        self::assertSame('cancelled', $this->deletions->get($id)['status']);
    }
    public function testFiveAttemptsExhaustCleanupBudgetWithoutSixthClaim(): void
    {
        $id = $this->deletions->enqueue('-1', 30, 'holy_message', $this->metadata(), $this->now(), $this->now() + 3600);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $row = $this->deletions->claim('worker');
            self::assertSame($attempt, $row['attempts']);
            $this->deletions->retry($id, 'worker', 'temporary failure', 1);
            $this->clock->set($this->clock->now()->modify('+2 seconds'));
        }
        self::assertNull($this->deletions->claim('worker'));
        self::assertSame('failed', $this->deletions->get($id)['status']);
    }
    public function testPersistedAcknowledgementsAreBoundToTheSentJobRandomIdAndPeer(): void
    {
        $first = $this->sentJob(100, 'first-context');
        $this->deletions->recordSentJobDelivery($first, [100], true);
        $second = $this->sentJob(101, 'second-context');
        $random = $this->jobs->get($second)['random_id'];
        $differentRandom = $random === '1' ? 2 : 1;
        foreach (
            [
            ['_' => 'updates','updates' => [['_' => 'updateMessageID','id' => 999,'random_id' => $differentRandom]]],
            ['_' => 'updates','updates' => [['_' => 'updateNewChannelMessage','message' => ['_' => 'message','out' => true,'id' => 999,'peer_id' => -2]]]],
            ] as $response
        ) {
            $this->db->pdo()->prepare('UPDATE delivery_log SET response_json=? WHERE job_id=?')->execute([Database::encode($response),$second]);
            try {
                $this->deletions->recordSentJobDelivery($second, [999], true);
                self::fail('An unrelated acknowledgement replaced the bot-owned reminder pointer.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('absent', $error->getMessage());
            }
            self::assertSame([100], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
            self::assertSame([], $this->deletions->list());
            self::assertSame('sent', $this->jobs->get($second)['status']);
            self::assertNull($this->jobs->get($second)['module_delivery_processed_at']);
        }
        $matched = ['_' => 'updates','updates' => [['_' => 'updateMessageID','id' => 101,'random_id' => (int)$random]]];
        $this->db->pdo()->prepare('UPDATE delivery_log SET response_json=? WHERE job_id=?')->execute([Database::encode($matched),$second]);
        self::assertSame('recorded', $this->deletions->recordSentJobDelivery($second, [101], true)['status']);
        self::assertSame([101], $this->deletions->getLastModuleMessages('-1', 'tefillin'));
        self::assertSame([100], array_column($this->deletions->list(), 'message_id'));
    }
}
