<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService,HebcalProvider};
use Shabbat\Runtime\{Application,MadelineGateway,ManualGroupReport,Runner};
use Shabbat\Scheduling\{LockCoordinator,Permissions};
use Shabbat\Storage\{Database,SettingsRepository};
use Shabbat\Time\FrozenClock;

final class PermissionCanonicalizationTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private CanonicalPermissionsTransport $transport;
    private MadelineGateway $gateway;
    private string $path;
    private string $heartbeat;
    private array $adminState = [];
    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }
    protected function setUp(): void
    {
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
        foreach (['database','clock','root'] as $name) {
            $this->adminState[$name] = (new \ReflectionProperty(\Shabbat\Storage\AdminStateFiles::class, $name))->getValue();
        }
        $path = tempnam(sys_get_temp_dir(), 'shabbat-default-rights-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create isolated permission database.');
        }
        $this->path = $path;
        $this->heartbeat = $path . '.heartbeat';
        putenv('HEARTBEAT_PATH=' . $this->heartbeat);
        $this->db = Database::open($path);
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T09:00:00+03:00'));
        $this->transport = new CanonicalPermissionsTransport();
        $this->gateway = new MadelineGateway($this->transport, false);
        (new SettingsRepository($this->db, $this->clock))->ensureGroup('-1001', 'Offline fixture');
    }
    protected function tearDown(): void
    {
        foreach ($this->adminState as $name => $value) {
            (new \ReflectionProperty(\Shabbat\Storage\AdminStateFiles::class, $name))->setValue(null, $value);
        }
        $this->adminState = [];
        unset($this->db);
        gc_collect_cycles();
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm', $this->heartbeat] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        putenv('HEARTBEAT_PATH');
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
    }
    private function app(?Database $db = null): Application
    {
        return new Application(
            $this->gateway,
            $this->clock,
            $db ?? $this->db,
            new CalendarService(new HebcalProvider(new RuntimeFakeJson()), $this->clock),
            authorizeOperator: static fn(string $actor): bool => $actor === '42'
        );
    }
    public function testForeverSentinelsAreEquivalentButFiniteExpiryAndUnknownRestrictionsAreNot(): void
    {
        $request = ['_' => 'chatBannedRights', 'send_polls' => true, 'until_date' => 0];
        self::assertTrue(Permissions::equal($request, array_replace($request, ['until_date' => 2147483647])));
        self::assertTrue(Permissions::equal($request, ['send_polls' => true, 'send_photos' => false]));
        foreach ([1, 1792069200, 2000000000, 2147483646] as $expiry) {
            self::assertFalse(Permissions::equal($request, array_replace($request, ['until_date' => $expiry])));
        }
        self::assertFalse(Permissions::equal($request, $request + ['future_permission' => true]));
        self::assertSame(['unknown'], Permissions::differentFields($request, $request + ['secret-untrusted-key' => true]));
    }
    public function testAggregateEquivalenceRequiresEveryGranularRestrictionOnBothSides(): void
    {
        $all = Permissions::locked($this->transport->rights);
        $observed = CanonicalPermissionsTransport::readback($all);
        self::assertTrue(Permissions::equal($all, $observed));
        $media = ['send_photos','send_videos','send_roundvideos','send_audios','send_voices','send_docs'];
        $sends = [...$media,'send_plain','send_stickers','send_gifs','send_games','send_inline','embed_links','send_polls'];
        foreach ($sends as $field) {
            $changed = array_replace($observed, [$field => false]);
            self::assertFalse(Permissions::equal($all, $changed), $field);
            self::assertContains($field, Permissions::differentFields($all, $changed));
        }
        self::assertFalse(Permissions::equal(['send_media' => true], []));
        self::assertFalse(Permissions::equal(['send_messages' => true], []));
        self::assertFalse(Permissions::equal(['send_messages' => true], ['send_plain' => true]));
        $mediaRequest = Permissions::locked(['until_date' => 0], ['send_media']);
        foreach ($media as $field) {
            self::assertTrue($mediaRequest[$field], $field);
        }
        self::assertArrayNotHasKey('send_plain', $mediaRequest);
        self::assertTrue(Permissions::equal($mediaRequest, CanonicalPermissionsTransport::readback($mediaRequest)));
        $messageRequest = Permissions::locked(['until_date' => 0], ['send_messages']);
        foreach ($sends as $field) {
            self::assertTrue($messageRequest[$field], $field);
        }
        self::assertArrayNotHasKey('send_reactions', $messageRequest);
        self::assertArrayNotHasKey('edit_rank', $messageRequest);
    }
    public static function groupTypes(): array
    {
        return ['basic group' => ['chat'], 'supergroup' => ['supergroup']];
    }
    #[DataProvider('groupTypes')]
    public function testManualCloseRepeatAndOpenUseFreshNativeGatewayReadback(string $type): void
    {
        $this->transport->type = $type;
        $app = $this->app();
        $original = $this->transport->rights;
        $closed = $app->manualGroups->apply(true, '42');
        self::assertSame(1, $closed['changed']);
        self::assertSame(0, $closed['failed']);
        self::assertTrue($this->transport->rights['send_plain']);
        self::assertTrue($this->transport->rights['send_reactions']);
        self::assertTrue($this->transport->rights['edit_rank']);
        self::assertFalse($this->transport->rights['send_messages']);
        self::assertSame(2147483647, $this->transport->rights['until_date']);
        $snapshot = $app->locks->state('-1001')['snapshot_json'];
        self::assertSame(Database::encode($original), $snapshot);
        self::assertSame(0, $app->manualGroups->apply(true, '42')['failed']);
        self::assertCount(1, $this->transport->writes);
        $opened = $app->manualGroups->apply(false, '42');
        self::assertSame(1, $opened['changed']);
        self::assertSame(0, $opened['failed']);
        self::assertSame($original, $this->transport->writes[1]);
        self::assertTrue(Permissions::equal($original, $this->transport->rights));
        self::assertSame($snapshot, $app->locks->state('-1001')['snapshot_json']);
        self::assertSame(hash('sha256', $snapshot), $app->locks->state('-1001')['snapshot_sha256']);
    }
    public function testAutomaticCloseAndOpenAfterRestartPreserveExactOriginalSnapshot(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-10-16T17:45:00+03:00'));
        $app = $this->app();
        $original = $this->transport->rights;
        $app->tick();
        self::assertSame('locked', $app->locks->state('-1001')['status']);
        $snapshot = $app->locks->state('-1001')['snapshot_json'];
        $app->tick();
        self::assertCount(1, $this->transport->writes);
        $this->clock->set(new \DateTimeImmutable('2026-10-18T10:00:00+03:00'));
        $reopened = Database::open($this->path);
        $restarted = $this->app($reopened);
        $restarted->tick();
        self::assertSame('restored', $restarted->locks->state('-1001')['status']);
        self::assertSame($original, $this->transport->writes[1]);
        self::assertSame($snapshot, $restarted->locks->state('-1001')['snapshot_json']);
    }
    public static function oldDebtCases(): array
    {
        $cases = [];
        foreach (['prepared','error','conflict','restoring'] as $status) {
            foreach ([true,false] as $close) {
                foreach (['manual','automatic'] as $entry) {
                    $cases[$status . '-' . ($close ? 'close' : 'open') . '-' . $entry] = [$status, $close, $entry];
                }
            }
        }
        return $cases;
    }
    #[DataProvider('oldDebtCases')]
    public function testUnverifiedLegacyDebtRequiresReviewEvenWhenCurrentRightsMatchKnownMask(string $status, bool $close, string $entry): void
    {
        $original = $this->transport->rights;
        $snapshot = Database::encode($original);
        $applied = Permissions::locked($original);
        $this->transport->rights = CanonicalPermissionsTransport::readback($applied);
        $this->db->pdo()->prepare('INSERT INTO group_lock_state(group_id,event_key,status,snapshot_json,applied_json,snapshot_sha256,prepared_at,last_error) VALUES(?,?,?,?,?,?,?,?)')
            ->execute(['-1001','dev5-owned-lock',$status,$snapshot,Database::encode($applied),hash('sha256', $snapshot),1,'Old unnormalized readback mismatch']);
        if ($entry === 'automatic') {
            $this->clock->set(new \DateTimeImmutable($close ? '2026-10-16T17:45:00+03:00' : '2026-10-18T10:00:00+03:00'));
        }
        $reopened = Database::open($this->path);
        $app = $this->app($reopened);
        if ($entry === 'manual') {
            self::assertSame(1, $app->manualGroups->apply($close, '42')['failed']);
        } else {
            $app->tick();
        }
        $state = $app->locks->state('-1001');
        self::assertSame('recovery_required', $state['status']);
        self::assertSame($snapshot, $state['snapshot_json']);
        self::assertSame(hash('sha256', $snapshot), $state['snapshot_sha256']);
        self::assertNotNull($state['last_error']);
        self::assertSame('legacy_unverified', $state['snapshot_provenance']);
        self::assertCount(0, $this->transport->writes);
    }
    public static function meaningfulDifferences(): array
    {
        $cases = [];
        foreach (['send_photos','send_plain','change_info','invite_users','pin_messages','manage_topics','edit_rank','send_reactions'] as $field) {
            $cases[$field] = [$field,false];
        }
        $cases['finite expiry'] = ['until_date',2000000000];
        $cases['unknown restriction'] = ['future_unrecognized_permission',true];
        return $cases;
    }
    #[DataProvider('meaningfulDifferences')]
    public function testRealChangesBlockBothManualDirectionsAndRetainSnapshot(string $field, bool|int $value): void
    {
        $app = $this->app();
        $app->manualGroups->apply(true, '42');
        $snapshot = $app->locks->state('-1001')['snapshot_json'];
        $this->transport->rights[$field] = $value;
        foreach ([false,true] as $close) {
            $result = $app->manualGroups->apply($close, '42');
            self::assertSame(1, $result['failed']);
            self::assertSame('conflict', $result['groups'][0]['status']);
            self::assertContains(in_array($field, Permissions::FLAGS, true) || $field === 'until_date' ? $field : 'unknown', $result['groups'][0]['different_fields']);
            self::assertCount(1, $this->transport->writes);
            self::assertSame($snapshot, $app->locks->state('-1001')['snapshot_json']);
            self::assertSame(hash('sha256', $snapshot), $app->locks->state('-1001')['snapshot_sha256']);
        }
    }
    public function testPostWriteMismatchIsImmediatelyAConflictAndNeverGuessedAway(): void
    {
        $this->transport->afterWrite = static function (CanonicalPermissionsTransport $transport): void {
            $transport->rights['send_reactions'] = false;
        };
        $result = $this->app()->manualGroups->apply(true, '42');
        self::assertSame('conflict', $result['groups'][0]['status']);
        self::assertContains('send_reactions', $result['groups'][0]['different_fields']);
        self::assertSame(1, $result['failed']);
        self::assertCount(1, $this->transport->writes);
    }
    public function testLostLockAndRestoreAcknowledgementsRecoverWithoutDuplicateMutation(): void
    {
        $app = $this->app();
        $original = $this->transport->rights;
        $this->transport->afterWrite = static fn() => throw new \RuntimeException('Lost RPC response');
        self::assertSame(1, $app->manualGroups->apply(true, '42')['failed']);
        $snapshot = $app->locks->state('-1001')['snapshot_json'];
        $this->transport->afterWrite = null;
        $app = $this->app(Database::open($this->path));
        self::assertSame(0, $app->manualGroups->apply(true, '42')['failed']);
        self::assertCount(1, $this->transport->writes);
        $this->transport->afterWrite = static fn() => throw new \RuntimeException('Lost restore response');
        self::assertSame(1, $app->manualGroups->apply(false, '42')['failed']);
        $this->transport->afterWrite = null;
        $app = $this->app(Database::open($this->path));
        self::assertSame(0, $app->manualGroups->apply(false, '42')['failed']);
        self::assertCount(2, $this->transport->writes);
        self::assertSame($original, $this->transport->writes[1]);
        self::assertSame($snapshot, $app->locks->state('-1001')['snapshot_json']);
        self::assertSame('restored', $app->locks->state('-1001')['status']);
    }
    public function testCorruptOrMissingOriginalSnapshotCannotBeRecoveredByNormalization(): void
    {
        $app = $this->app();
        $app->manualGroups->apply(true, '42');
        $this->db->pdo()->exec("UPDATE group_lock_state SET snapshot_json='{}' WHERE group_id='-1001'");
        self::assertSame(1, $app->manualGroups->apply(false, '42')['failed']);
        self::assertCount(1, $this->transport->writes);
        $this->db->pdo()->exec("UPDATE group_lock_state SET status='conflict',snapshot_json=NULL WHERE group_id='-1001'");
        foreach ([true,false] as $close) {
            $result = $app->manualGroups->apply($close, '42');
            self::assertSame('recovery_required', $result['groups'][0]['status']);
            self::assertCount(1, $this->transport->writes);
        }
    }
    public function testLocalizedMismatchDetailsAllowlistFieldsAndNeverPrintArbitraryPayloads(): void
    {
        $app = $this->app();
        $app->manualGroups->apply(true, '42');
        $this->transport->rights['send_reactions'] = false;
        $result = $app->manualGroups->apply(false, '42');
        $result['groups'][0]['different_fields'][] = '<secret>never-print</secret>';
        $result['groups'][0]['error'] = 'credential-like arbitrary failure text';
        $lang = new \Shabbat\UI\Lang();
        foreach ($lang->languages() as $language) {
            $text = ManualGroupReport::format($result, $language, 'Asia/Jerusalem');
            self::assertStringContainsString($lang->text($language, 'permission_send_reactions'), $text);
            self::assertStringNotContainsString('never-print', $text);
            self::assertStringNotContainsString('credential-like', $text);
        }
    }
    #[DataProvider('groupTypes')]
    public function testPinnedSdkRefreshBypassesSameSecondFullCacheAndRereadsPostResponsePeer(string $type): void
    {
        $harness = new PinnedPeerRefreshHarness($type, $this->transport->rights);
        $harness->server['Chat']['default_banned_rights'] = CanonicalPermissionsTransport::readback(Permissions::locked($this->transport->rights));
        $peer = (string)$harness->cached['bot_api_id'];
        // The actual SDK method refreshes its DB but returns its pre-request partial Chat.
        $stale = $harness->getFullInfo($peer);
        self::assertFalse($stale['Chat']['default_banned_rights']['send_plain']);
        $fresh = \Shabbat\Runtime\FreshPeerReader::read($peer, Runner::settings(), $harness->getFullInfo(...));
        self::assertTrue($fresh['Chat']['default_banned_rights']['send_plain']);
        self::assertTrue($fresh['Chat']['default_banned_rights']['send_reactions']);
        $harness->server['Chat']['default_banned_rights'] = $this->transport->rights;
        $restored = \Shabbat\Runtime\FreshPeerReader::read($peer, Runner::settings(), $harness->getFullInfo(...));
        self::assertSame($this->transport->rights, $restored['Chat']['default_banned_rights']);
        self::assertSame(array_fill(0, 3, $type === 'chat' ? 'messages.getFullChat' : 'channels.getFullChannel'), $harness->calls);
    }

    public function testModeledReadbackRoundTripsThroughPinnedNativeTlAndKeepsNewRights(): void
    {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/Fixtures/telegram-default-permissions.json'), true, 32, JSON_THROW_ON_ERROR);
        $previousLogger = \danog\MadelineProto\Logger::$default;
        $adapter = (new \ReflectionClass(\danog\MadelineProto\MTProto::class))->newInstanceWithoutConstructor();
        $adapter->settings = new \danog\MadelineProto\Settings();
        $adapter->logger = new \danog\MadelineProto\Logger((new \danog\MadelineProto\Settings\Logger())->setType(\danog\MadelineProto\Logger::CALLABLE_LOGGER)->setExtra(static function (): void {
        }));
        try {
            $tl = new \danog\MadelineProto\TL\TL($adapter);
            $tl->init(new \danog\MadelineProto\Settings\TLSchema());
            $wire = $tl->serializeObject(['type' => 'ChatBannedRights'], $fixture['locked_readback'], 'default-rights-fixture');
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $wire);
            rewind($stream);
            $actual = $tl->deserialize($stream, ['type' => 'ChatBannedRights', 'connection' => null, 'encrypted' => false]);
            fclose($stream);
            self::assertTrue(Permissions::equal(Permissions::locked($fixture['original']), $actual));
            self::assertSame(2147483647, $actual['until_date']);
            self::assertTrue($actual['send_reactions']);
            self::assertTrue($actual['edit_rank']);
            self::assertFalse($actual['send_messages']);
            self::assertFalse($actual['send_media']);
        } finally {
            $tl = null;
            $adapter = null;
            \danog\MadelineProto\Logger::$default = $previousLogger;
        }
    }
}
