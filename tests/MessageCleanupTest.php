<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use Amp\CancelledException;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService,HebcalProvider};
use Shabbat\Runtime\{Application,Runner};
use Shabbat\Scheduling\{MessageCleanup,JobRepository,OutboxWorker};
use Shabbat\Storage\{Database,SettingsRepository,DeletionRepository};
use Shabbat\Telegram\RetryableTelegramException;
use Shabbat\Time\FrozenClock;

final class MessageCleanupTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private DeletionRepository $deletions;
    private CleanupFakeGateway $gateway;
    private CalendarService $calendar;
    private MessageCleanup $worker;
    private bool $stopping = false;
    protected function setUp(): void
    {
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-16T17:45:00+03:00'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->deletions = new DeletionRepository($this->db, $this->clock);
        $this->gateway = new CleanupFakeGateway();
        $this->calendar = new CalendarService(new HebcalProvider(new RuntimeFakeJson()), $this->clock);
        $this->worker = new MessageCleanup($this->deletions, $this->settings, $this->gateway, $this->calendar, $this->clock, fn(): bool => $this->stopping);
        $this->settings->ensureGroup('-1001', 'Example');
        $this->gateway->rights['-1001'] = ['_' => 'chatBannedRights', 'until_date' => 0, 'send_messages' => true];
    }
    private function configure(array $changes): array
    {
        $group = $this->settings->getGroup('-1001');
        return $this->settings->updateGroup('-1001', $group['revision'], $changes, '42');
    }
    private function receive(int $id, string $sender = '42', ?int $date = null, bool $outgoing = false): bool
    {
        return $this->worker->receive('-1001', $id, $date ?? $this->clock->now()->getTimestamp(), $sender, $outgoing);
    }
    public function testBothDefaultsAreOptInAndNoIdentityLookupOccurs(): void
    {
        self::assertFalse($this->receive(1));
        self::assertFalse($this->receive(2, '43'));
        self::assertSame(0, $this->gateway->classifications);
        self::assertNull($this->worker->runOnce('worker'));
    }
    public function testHumanAndBotPreferencesAreIndependentAndDuplicatesDeleteOnce(): void
    {
        $this->configure(['delete_holy_user_messages' => true]);
        self::assertTrue($this->receive(1));
        self::assertTrue($this->receive(1));
        self::assertFalse($this->receive(2, '43'));
        self::assertSame('deleted', $this->worker->runOnce('worker')['status']);
        self::assertNull($this->worker->runOnce('worker'));
        self::assertSame([['-1001', [1]]], $this->gateway->deleted);
        $this->configure(['delete_holy_user_messages' => false, 'delete_holy_bot_messages' => true]);
        self::assertFalse($this->receive(3));
        self::assertTrue($this->receive(4, '43'));
        self::assertSame('deleted', $this->worker->runOnce('worker')['status']);
        self::assertSame([['-1001', [1]], ['-1001', [4]]], $this->gateway->deleted);
    }
    public function testEarlyClosurePreservesBeforeEntryMessagesAndDelayedOldMessages(): void
    {
        $g = $this->configure(['delete_holy_user_messages' => true]);
        $event = $this->calendar->snapshot($g)['current_event'];
        $this->clock->set(new \DateTimeImmutable('@' . ($event['start_at'] - 60)));
        self::assertFalse($this->receive(1));
        $this->clock->set(new \DateTimeImmutable('@' . ($event['start_at'] + 60)));
        self::assertFalse($this->receive(2, '42', $event['start_at'] - 1));
        self::assertTrue($this->receive(3, '42', $event['start_at']));
        self::assertSame('deleted', $this->worker->runOnce('worker')['status']);
        self::assertSame([['-1001', [3]]], $this->gateway->deleted);
    }
    public function testOwnOutgoingUnknownAnonymousFutureAndInvalidIdsAreNeverDeleted(): void
    {
        $this->configure(['delete_holy_user_messages' => true, 'delete_holy_bot_messages' => true]);
        foreach ([['9000', false], ['99', false], ['-1001', false], ['42', true]] as [$sender, $out]) {
            self::assertFalse($this->receive(1, $sender, null, $out));
        }
        self::assertFalse($this->receive(0));
        self::assertFalse($this->receive(2, '42', $this->clock->now()->getTimestamp() + 1));
        self::assertNull($this->worker->runOnce('worker'));
        self::assertSame([], $this->gateway->deleted);
    }
    public function testActuallyOpenGroupAndChangedPreferencesCancelQueuedDeletion(): void
    {
        $this->configure(['delete_holy_user_messages' => true]);
        self::assertTrue($this->receive(1));
        $this->gateway->rights['-1001']['send_messages'] = false;
        self::assertSame('cancelled', $this->worker->runOnce('worker')['status']);
        $this->gateway->rights['-1001']['send_messages'] = true;
        self::assertTrue($this->receive(2));
        $this->configure(['delete_holy_user_messages' => false]);
        self::assertNull($this->worker->runOnce('worker'));
        self::assertSame('cancelled', $this->deletions->get(2)['status']);
        self::assertSame([], $this->gateway->deleted);
    }
    public function testManualOpeningOffModeAndDisabledReligiousSwitchSuppressModeration(): void
    {
        $this->configure(['delete_holy_user_messages' => true, 'manual_override_mode' => 'open', 'manual_override_until' => $this->clock->now()->getTimestamp() + 3600]);
        self::assertFalse($this->receive(1));
        $this->configure(['manual_override_mode' => 'none', 'mode' => 'off']);
        self::assertFalse($this->receive(2));
        $this->configure(['mode' => 'lock', 'shabbat_enabled' => false]);
        self::assertFalse($this->receive(3));
    }
    public function testCrossingExitDuringPermissionReadAndLeaseExpiryNeverDeletes(): void
    {
        $g = $this->configure(['delete_holy_user_messages' => true]);
        $event = $this->calendar->snapshot($g)['current_event'];
        self::assertTrue($this->receive(1));
        $this->gateway->beforePermissions = function () use ($event): void {
            $this->clock->set(new \DateTimeImmutable('@' . $event['end_at']));
        };
        self::assertSame('lease_lost', $this->worker->runOnce('worker')['status']);
        self::assertSame([], $this->gateway->deleted);
        $this->clock->set(new \DateTimeImmutable('2026-10-16T17:45:00+03:00'));
        $this->gateway->beforePermissions = null;
        self::assertTrue($this->receive(2));
        $this->gateway->beforeCapabilities = function (): void {
            $this->clock->set($this->clock->now()->modify('+121 seconds'));
        };
        self::assertSame('lease_lost', $this->worker->runOnce('worker')['status']);
        self::assertSame([], $this->gateway->deleted);
    }
    public function testNoCapabilityRetriesIndependentlyAndFloodWaitDoesNotSpin(): void
    {
        $this->configure(['delete_holy_user_messages' => true]);
        self::assertTrue($this->receive(1));
        $this->gateway->canDelete = false;
        self::assertSame('retry', $this->worker->runOnce('worker')['status']);
        self::assertNull($this->worker->runOnce('worker'));
        $this->clock->set($this->clock->now()->modify('+30 seconds'));
        $this->gateway->canDelete = true;
        $this->gateway->deleteError = new RetryableTelegramException('flood', 90);
        self::assertSame('retry', $this->worker->runOnce('worker')['status']);
        self::assertNull($this->worker->runOnce('worker'));
        $this->clock->set($this->clock->now()->modify('+90 seconds'));
        $this->gateway->deleteError = null;
        self::assertSame('deleted', $this->worker->runOnce('worker')['status']);
        self::assertCount(1, $this->gateway->deleted);
    }
    public function testShutdownAfterNativeReadStopsAndIntentionalCancellationPropagates(): void
    {
        $this->configure(['delete_holy_user_messages' => true]);
        self::assertTrue($this->receive(1));
        $this->gateway->beforePermissions = function (): void {
            $this->stopping = true;
        };
        self::assertSame('cancelled', $this->worker->runOnce('worker')['status']);
        self::assertFalse($this->receive(2));
        self::assertSame([], $this->gateway->deleted);
        $this->stopping = false;
        $this->gateway->beforePermissions = null;
        self::assertTrue($this->receive(3));
        $this->gateway->deleteError = new CancelledException(new \RuntimeException('intentional'));
        $this->expectException(CancelledException::class);
        $this->worker->runOnce('worker');
    }
    public function testTefillinReplacementDeletesOnlyConfirmedPreviousDeliveryAndKeepsNewest(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-10-15T09:00:00+03:00'));
        $this->configure(['tefillin_enabled' => true, 'tefillin_delete_previous' => true, 'tefillin_fixed_times' => ['09:00','10:00','11:00']]);
        $app = new Application($this->gateway, $this->clock, $this->db, $this->calendar);
        $heartbeat = sys_get_temp_dir() . '/cleanup-heartbeat-' . bin2hex(random_bytes(6)) . '.json';
        putenv('HEARTBEAT_PATH=' . $heartbeat);
        try {
            $app->tick();
            self::assertCount(1, $this->gateway->sent);
            self::assertSame([], $this->gateway->deleted);
            $this->clock->set($this->clock->now()->modify('+1 hour'));
            $app = new Application($this->gateway, $this->clock, $this->db, $this->calendar);
            $app->tick();
            self::assertCount(2, $this->gateway->sent);
            self::assertSame([['-1001', [100]]], $this->gateway->deleted);
            self::assertSame([101], $this->deletions->getLastModuleMessages('-1001', 'tefillin'));
            $this->clock->set($this->clock->now()->modify('+1 hour'));
            $this->gateway->sendError = new \RuntimeException('Transport failed before third send.');
            $app->tick();
            self::assertCount(2, $this->gateway->sent);
            self::assertCount(1, $this->gateway->deleted);
            self::assertSame([101], $this->deletions->getLastModuleMessages('-1001', 'tefillin'));
        } finally {
            putenv('HEARTBEAT_PATH');
            if (is_file($heartbeat)) {
                unlink($heartbeat);
            }
        }
    }
    public function testBookkeepingFailureCannotResendAcknowledgedReminder(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-10-15T09:00:00+03:00'));
        $g = $this->configure(['tefillin_enabled' => true, 'tefillin_delete_previous' => true]);
        $jobs = new JobRepository($this->db, $this->clock);
        $id = $jobs->enqueue('-1001', 'tefillin', 'fixture', $this->clock->now()->getTimestamp(), $this->clock->now()->getTimestamp() + 3600, $g['revision'], ['text' => 'reminder']);
        $worker = new OutboxWorker($jobs, $this->settings, $this->gateway, $this->clock, static fn(): bool=>true, null, static function (): void {
            throw new \RuntimeException('Bookkeeping unavailable.');
        });
        $sent = $worker->runOnce('outbox');
        self::assertSame('sent', $sent['status']);
        self::assertTrue($sent['cleanup_pending']);
        self::assertSame('sent', $jobs->get($id)['status']);
        self::assertNull($worker->runOnce('outbox'));
        $pending = $jobs->pendingDeliveryBookkeeping(20);
        self::assertCount(1, $pending);
        $this->deletions->recordSentJobDelivery($id, $this->gateway->extractSentMessageIds($pending[0]['response']), true);
        self::assertSame([], $jobs->pendingDeliveryBookkeeping(20));
        self::assertSame([100], $this->deletions->getLastModuleMessages('-1001', 'tefillin'));
        self::assertCount(1, $this->gateway->sent);
    }
    public static function holySequences(): array
    {
        return [
            'same-day Shabbat festival' => [['2026-10-17'], '2026-10-16T17:45:00+03:00', '2026-10-17T18:35:00+03:00'],
            'festival then Shabbat' => [['2026-10-16'], '2026-10-15T17:45:00+03:00', '2026-10-17T18:35:00+03:00'],
            'Shabbat then festival' => [['2026-10-18'], '2026-10-16T17:45:00+03:00', '2026-10-18T18:35:00+03:00'],
            'two festival days then Shabbat' => [['2026-10-15','2026-10-16'], '2026-10-14T17:45:00+03:00', '2026-10-17T18:35:00+03:00'],
        ];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('holySequences')]
    public function testDeletionCoversTheEntireContinuousHolySequence(array $holidayDays, string $first, string $last): void
    {
        $http = new class ($holidayDays) implements \Shabbat\Http\JsonClient {
            public function __construct(private array $holidayDays)
            {
            }
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                if (str_ends_with($url, '/hebcal')) {
                    return ['range' => ['start' => $query['start'],'end' => $query['end']], 'items' => array_map(static fn(string $date): array=>['date' => $date,'category' => 'holiday','yomtov' => true,'title' => 'Fixture festival','hebrew' => 'חג בדיקה'], $this->holidayDays)];
                }
                return (new RuntimeFakeJson())->get($url, $query, $cancellation);
            }
        };
        $calendar = new CalendarService(new HebcalProvider($http), $this->clock);
        $worker = new MessageCleanup($this->deletions, $this->settings, $this->gateway, $calendar, $this->clock);
        $this->configure(['delete_holy_user_messages' => true]);
        $this->clock->set(new \DateTimeImmutable($first));
        self::assertTrue($worker->receive('-1001', 1, $this->clock->now()->getTimestamp(), '42'));
        self::assertSame('deleted', $worker->runOnce('worker')['status']);
        $this->clock->set(new \DateTimeImmutable($last));
        self::assertTrue($worker->receive('-1001', 2, $this->clock->now()->getTimestamp(), '42'));
        self::assertSame('deleted', $worker->runOnce('worker')['status']);
        $this->clock->set($this->clock->now()->modify('+10 minutes'));
        self::assertFalse($worker->receive('-1001', 3, $this->clock->now()->getTimestamp(), '42'));
        self::assertSame([['-1001',[1]],['-1001',[2]]], $this->gateway->deleted);
    }
    public function testActualNativeGroupBridgeForBothGroupTypesUsesOriginalMessageDate(): void
    {
        $this->configure(['delete_holy_user_messages' => true]);
        $this->settings->ensureGroup('-12', 'Basic group');
        $basic = $this->settings->getGroup('-12');
        $this->settings->updateGroup('-12', $basic['revision'], ['delete_holy_user_messages' => true], '42');
        $this->gateway->rights['-12'] = ['_' => 'chatBannedRights','send_messages' => true,'until_date' => 0];
        $app = new Application($this->gateway, $this->clock, $this->db, $this->calendar);
        $bridge = ReplyScopeFixture::create($app);
        foreach ([-1001,-12] as $group) {
            $message = (new \ReflectionClass(\danog\MadelineProto\EventHandler\Message\GroupMessage::class))->newInstanceWithoutConstructor();
            foreach (['chatId' => $group,'senderId' => 42,'id' => 7,'date' => $this->clock->now()->getTimestamp(),'out' => false] as $field => $value) {
                (new \ReflectionProperty($message, $field))->setValue($message, $value);
            }
            $bridge->groupMessageCleanup($message);
        }
        self::assertSame([['-1001',[7]],['-12',[7]]], $this->gateway->deleted);
    }
    public function testDeletionFailureNeverRetriesTheSuccessfulReplacementSend(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-10-15T09:00:00+03:00'));
        $this->configure(['tefillin_enabled' => true,'tefillin_delete_previous' => true]);
        $this->deletions->recordModuleDelivery('-1001', 'tefillin', [10], 'first', true);
        $this->deletions->recordModuleDelivery('-1001', 'tefillin', [11], 'second', true);
        $this->gateway->deleteError = new RetryableTelegramException('rate limit', 60);
        self::assertSame('retry', $this->worker->runOnce('worker')['status']);
        self::assertSame([], $this->gateway->sent);
        self::assertSame([11], $this->deletions->getLastModuleMessages('-1001', 'tefillin'));
        $this->configure(['tefillin_delete_previous' => false]);
        $this->clock->set($this->clock->now()->modify('+60 seconds'));
        self::assertNull($this->worker->runOnce('worker'));
        self::assertSame('cancelled', $this->deletions->get(1)['status']);
        self::assertSame([], $this->gateway->deleted);
    }
    public static function announcementPresets(): array
    {
        return array_map(static fn(string $time): array => [$time], ['10:00','11:00','12:00','12:30','13:00','13:30','14:00']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('announcementPresets')]
    public function testAnnouncementPresetIsScheduledInTheGroupsOwnTimezone(string $time): void
    {
        $g = $this->configure([
            'times_alert_enabled' => true, 'times_alert_time' => $time, 'timezone_id' => 'America/New_York',
            'location_name' => 'New York', 'latitude' => 40.7128, 'longitude' => -74.006, 'geoname_id' => 5128581, 'israel' => false, 'language' => 'en',
        ]);
        $this->clock->set(new \DateTimeImmutable('2026-10-16T' . $time . ':00-04:00'));
        $app = new Application($this->gateway, $this->clock, $this->db, $this->calendar);
        $heartbeat = sys_get_temp_dir() . '/announcement-heartbeat-' . bin2hex(random_bytes(6)) . '.json';
        putenv('HEARTBEAT_PATH=' . $heartbeat);
        try {
            $app->tick();
            self::assertCount(1, $this->gateway->sent);
            $payload = $this->gateway->sent[0][1];
            self::assertStringContainsString('New York', $payload['text']);
            self::assertStringContainsString('America/New_York', $payload['text']);
            self::assertStringContainsString('2026-10-16 17:42', $payload['text']);
            self::assertStringContainsString('2026-10-17 18:40', $payload['text']);
            self::assertDoesNotMatchRegularExpression('/\([+-]\d{2}:\d{2}\)/', $payload['text']);
            self::assertStringNotContainsString('Jerusalem', $payload['text']);
            $job = $this->db->pdo()->query("SELECT * FROM scheduled_jobs WHERE module='times_alert'")->fetch();
            self::assertSame($this->clock->now()->getTimestamp(), $job['run_at']);
            self::assertSame('sent', $job['status']);
        } finally {
            putenv('HEARTBEAT_PATH');
            if (is_file($heartbeat)) {
                unlink($heartbeat);
            }
        }
    }
    public function testAnnouncementDesignAndTefillinRichButtonsAreIndependentFromDailyTimes(): void
    {
        $app = new Application($this->gateway, $this->clock, $this->db, $this->calendar);
        $g = $this->settings->getGroup('-1001');
        $instant = new \DateTimeImmutable('2026-10-16T13:30:00+03:00');
        $app->templates->save('-1001', 'zmanim', ['text' => 'daily custom'], '42');
        $app->templates->save('-1001', 'times_alert', ['text' => 'announcement {{location}}','buttons' => [[['text' => 'Open','url' => 'https://example.com','style' => ['_' => 'keyboardButtonStyle','bg_success' => true]]]]], '42');
        $app->templates->save('-1001', 'tefillin', ['text' => 'tefillin {{sunset}}','buttons' => [[['text' => 'Ready','callback_data' => 'notice','style' => ['_' => 'keyboardButtonStyle','bg_primary' => true]]]]], '42');
        $announcement = $app->payload($g, 'times_alert', $instant);
        $tefillin = $app->payload($g, 'tefillin', $instant);
        self::assertStringContainsString($g['location_name'], $announcement['text']);
        self::assertTrue($announcement['buttons'][0][0]['style']['bg_success']);
        self::assertSame('tefillin 18:00', $tefillin['text']);
        self::assertTrue($tefillin['buttons'][0][0]['style']['bg_primary']);
        self::assertSame('daily custom', $app->payload($g, 'zmanim', $instant)['text']);
    }
}
