<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\Logger;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\Settings\TLSchema;
use danog\MadelineProto\TL\TL;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\FreshPeerReader;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\Runner;
use Shabbat\Scheduling\LockCoordinator;
use Shabbat\Scheduling\Permissions;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Telegram\AdminRights;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\Lang;

final class PermissionLayerCompatibilityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }

    public static function linkedMasks(): iterable
    {
        foreach ([false, true] as $original) {
            foreach ([false, true] as $selected) {
                yield ($original ? 'restricted' : 'allowed') . ($selected ? '-selected' : '-untouched') => [$original, $selected];
            }
        }
    }

    #[DataProvider('linkedMasks')]
    public function testFreshNativeRightsCloseAndRestoreEveryNewBitWithoutRewritingSnapshot(bool $originalLinked, bool $selected): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-21T12:00:00Z'));
        $settings = new SettingsRepository($db, $clock);
        $group = $settings->ensureGroup('-1001', 'Layer 231');
        $mask = ['send_plain'];
        if ($selected) {
            $mask[] = 'manage_linked_peers';
        }
        $settings->updateGroup('-1001', $group['revision'], ['lock_block_flags' => $mask], '42');
        $original = ['_' => 'chatBannedRights', 'until_date' => 2000000000, 'send_messages' => false, 'send_media' => false,
            'send_plain' => false, 'send_polls' => true, 'manage_linked_peers' => $originalLinked];
        $transport = new CanonicalPermissionsTransport();
        $transport->rights = $original;
        $gateway = new MadelineGateway($transport, false);
        $verified = FreshPeerReader::verified('-1001', FreshPeerFixture::info('-1001', $original));
        self::assertSame($original, $verified['Chat']['default_banned_rights']);
        $locks = new LockCoordinator($db, $clock, $gateway);
        self::assertSame('locked', $locks->reconcile('-1001', 'layer-231', true)['status']);
        $snapshot = $locks->state('-1001')['snapshot_json'];
        self::assertSame(Database::encode($original), $snapshot);
        self::assertSame($originalLinked || $selected, $transport->rights['manage_linked_peers']);
        self::assertTrue($transport->rights['send_polls']);
        $wire = $this->wire('ChatBannedRights', $transport->writes[0]);
        self::assertSame($originalLinked || $selected, $wire['manage_linked_peers']);
        self::assertSame('restored', $locks->reconcile('-1001', 'layer-231', false)['status']);
        self::assertSame($original, $transport->writes[1], 'Restore must send the complete original native snapshot.');
        self::assertTrue(Permissions::equal($original, $transport->rights));
        self::assertSame($snapshot, $locks->state('-1001')['snapshot_json']);
        self::assertSame($originalLinked, $this->wire('ChatBannedRights', $transport->writes[1])['manage_linked_peers']);
    }

    public function testNewRestrictionIsExplicitAndExistingDefaultMasksNeverGainItOnRead(): void
    {
        self::assertContains('manage_linked_peers', Permissions::FLAGS);
        self::assertContains('manage_linked_peers', Permissions::BLOCK_FLAGS);
        self::assertNotContains('manage_linked_peers', Permissions::DEFAULT_BLOCK_FLAGS);
        self::assertCount(21, Permissions::DEFAULT_BLOCK_FLAGS);
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-21T12:00:00Z'));
        $settings = new SettingsRepository($db, $clock);
        $group = $settings->ensureGroup('-1001');
        $before = $db->pdo()->query("SELECT lock_block_flags,revision FROM group_settings WHERE group_id='-1001'")->fetch();
        $settings->ensureGroup('-1001');
        self::assertSame($before, $db->pdo()->query("SELECT lock_block_flags,revision FROM group_settings WHERE group_id='-1001'")->fetch());
        self::assertSame(Permissions::DEFAULT_BLOCK_FLAGS, $group['lock_block_flags']);
        $changed = $settings->updateGroup('-1001', 1, ['lock_block_flags' => ['manage_linked_peers']], '42');
        self::assertSame(['manage_linked_peers'], $changed['lock_block_flags']);
        self::assertTrue(Permissions::locked(['until_date' => 0], $changed['lock_block_flags'])['manage_linked_peers']);
    }

    public function testNewAdministratorRightsAreAcceptedOnlyAsVerifiedBooleansAndSerialize(): void
    {
        $rights = ['_' => 'chatAdminRights', 'delete_messages' => true, 'manage_ranks' => true, 'manage_linked_peers' => true, 'manage_welcome_messages' => true];
        $normalized = AdminRights::normalize($rights);
        self::assertCount(19, $normalized);
        $decoded = $this->wire('ChatAdminRights', $rights);
        foreach (['manage_ranks', 'manage_linked_peers', 'manage_welcome_messages'] as $flag) {
            self::assertTrue($normalized[$flag]);
            self::assertTrue($decoded[$flag]);
        }
        self::assertFalse(AdminRights::normalize(null)['manage_linked_peers']);
        $this->expectException(\RuntimeException::class);
        AdminRights::normalize(['_' => 'chatAdminRights', 'manage_welcome_messages' => 'true']);
    }

    public function testEveryNewPermissionHasAnAccurateLocalizedPanelLabel(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            foreach (['permission_manage_linked_peers', 'admin_right_manage_linked_peers', 'admin_right_manage_welcome_messages'] as $key) {
                $label = $lang->text($language, $key);
                self::assertNotSame($key, $label);
                self::assertNotSame('', trim($label));
                self::assertTrue(mb_check_encoding($label, 'UTF-8'));
            }
        }
    }

    public function testHistoricalSnapshotMissingNewBitRestoresAfterRestartAndExplicitFalseReadback(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shabbat-historical-rights-');
        self::assertIsString($path);
        try {
            $db = Database::open($path);
            $db->migrate();
            $clock = new FrozenClock(new \DateTimeImmutable('2026-10-21T12:00:00Z'));
            $settings = new SettingsRepository($db, $clock);
            $settings->ensureGroup('-1001', 'Historical permissions');
            $transport = new CanonicalPermissionsTransport();
            $original = $transport->rights;
            self::assertArrayNotHasKey('manage_linked_peers', $original);
            $gateway = new MadelineGateway($transport, false);
            $locks = new LockCoordinator($db, $clock, $gateway);
            self::assertSame('locked', $locks->reconcile('-1001', 'historic-owned-lock', true)['status']);
            $before = $locks->state('-1001');
            self::assertArrayNotHasKey('manage_linked_peers', json_decode($before['snapshot_json'], true, flags: JSON_THROW_ON_ERROR));
            self::assertArrayNotHasKey('manage_linked_peers', json_decode($before['applied_json'], true, flags: JSON_THROW_ON_ERROR));

            // A current SDK decodes absent flag bits as explicit false. The old
            // persisted ownership evidence must remain usable without being rewritten.
            $transport->rights = $this->wire('ChatBannedRights', $transport->rights);
            self::assertArrayHasKey('manage_linked_peers', $transport->rights);
            self::assertFalse($transport->rights['manage_linked_peers']);
            $transport->afterWrite = static function (CanonicalPermissionsTransport $backend): void {
                $backend->rights['manage_linked_peers'] = false;
            };
            $reopened = Database::open($path);
            $restarted = new LockCoordinator($reopened, $clock, $gateway);
            self::assertSame('restored', $restarted->reconcile('-1001', 'historic-owned-lock', false)['status']);
            self::assertCount(2, $transport->writes);
            self::assertSame($original, $transport->writes[1], 'Send the exact historical native snapshot, without inventing a new field.');
            self::assertArrayNotHasKey('manage_linked_peers', $transport->writes[1]);
            self::assertTrue(Permissions::equal($original, $gateway->getPermissions('-1001')));
            self::assertFalse($transport->rights['manage_linked_peers']);
            $after = $restarted->state('-1001');
            self::assertSame($before['snapshot_json'], $after['snapshot_json']);
            self::assertSame($before['snapshot_sha256'], $after['snapshot_sha256']);
        } finally {
            unset($locks, $restarted, $reopened, $settings, $db);
            gc_collect_cycles();
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
    private function wire(string $type, array $value): array
    {
        $previous = Logger::$default;
        try {
            $api = (new \ReflectionClass(MTProto::class))->newInstanceWithoutConstructor();
            $api->settings = new \danog\MadelineProto\Settings();
            $api->logger = new Logger((new \danog\MadelineProto\Settings\Logger())->setType(Logger::CALLABLE_LOGGER)->setExtra(static function (): void {
            }));
            $tl = new TL($api);
            $tl->init(new TLSchema());
            $wire = $tl->serializeObject(['type' => $type], $value, 'rights');
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $wire);
            rewind($stream);
            $decoded = $tl->deserialize($stream, ['type' => $type, 'connection' => null, 'encrypted' => false]);
            self::assertSame(strlen($wire), ftell($stream));
            fclose($stream);
            return $decoded;
        } finally {
            $tl = null;
            $api = null;
            Logger::$default = $previous;
        }
    }
}
