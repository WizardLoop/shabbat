<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Amp;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\ManualGroups;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Scheduling\LockCoordinator;
use Shabbat\Time\FrozenClock;
use Shabbat\Telegram\TelegramGateway;

final class ManualGroupsTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private LockCoordinator $locks;
    private ManualGroupsFakeGateway $gateway;
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-13T09:00:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->settings->ensureGroup('-1');
        $this->settings->ensureGroup('-2');
        $this->gateway = new ManualGroupsFakeGateway();
        $this->locks = new LockCoordinator($this->db, $this->clock, $this->gateway);
    }
    private function service(): ManualGroups
    {
        return new ManualGroups($this->db, $this->settings, $this->locks, $this->clock, static fn(string $id): bool=>$id === '17');
    }
    public function testUnauthorizedOperatorCannotWriteOverridesOrTouchTelegram(): void
    {
        try {
            $this->service()->apply(true, '18');
            self::fail('Unauthorized operation succeeded.');
        } catch (RuntimeException) {
        }
        self::assertSame('none', $this->settings->getGroup('-1')['manual_override_mode']);
        self::assertSame(1, $this->settings->getGroup('-1')['revision']);
        self::assertCount(0, $this->gateway->changes);
    }
    public function testManualCloseUsesDurableSnapshotsAndOpenRestoresOriginalPermissions(): void
    {
        $original = $this->gateway->getPermissions('-1');
        $closed = $this->service()->apply(true, '17', 600);
        self::assertSame(2, $closed['changed']);
        self::assertSame(0, $closed['failed']);
        self::assertSame('lock', $this->settings->getGroup('-1')['manual_override_mode']);
        self::assertNotNull($this->locks->state('-1')['snapshot_sha256']);
        self::assertTrue(ManualGroups::desired($this->settings->getGroup('-1'), false, $this->clock->now()->getTimestamp()));
        $opened = $this->service()->apply(false, '17', 600);
        self::assertSame(2, $opened['changed']);
        self::assertSame($original, $this->gateway->getPermissions('-1'));
        self::assertFalse(ManualGroups::desired($this->settings->getGroup('-1'), true, $this->clock->now()->getTimestamp()));
        self::assertStringContainsString('active festival', $opened['warning']);
        self::assertSame(281184, $this->settings->getGroup('-1')['geoname_id']);
    }
    public function testExpiredOverrideResumesCalendarDesiredStateAtExactCutoff(): void
    {
        $this->service()->apply(false, '17', 10);
        $g = $this->settings->getGroup('-1');
        $now = $this->clock->now()->getTimestamp();
        self::assertFalse(ManualGroups::desired($g, true, $now + 9));
        self::assertTrue(ManualGroups::desired($g, true, $now + 10));
        self::assertFalse(ManualGroups::desired($g, false, $now + 10));
    }
    public function testPartialFailureDoesNotClaimSuccessOrBlockOtherGroup(): void
    {
        $this->gateway->onSet = static function (string $id): void {
            if ($id === '-1') {
                throw new RuntimeException('simulated permissions RPC failure');
            }
        };
        $result = $this->service()->apply(true, '17');
        self::assertSame(1, $result['changed']);
        self::assertSame(1, $result['failed']);
        self::assertSame('error', $this->locks->state('-1')['status']);
        self::assertSame('locked', $this->locks->state('-2')['status']);
        self::assertNotNull($this->locks->state('-1')['snapshot_json']);
    }
    public function testAdministratorPrelockedGroupIsNotOpenedByManualService(): void
    {
        $this->gateway->rights['-1'] = ['_' => 'chatBannedRights','send_messages' => true];
        $closed = $this->service()->apply(true, '17');
        self::assertSame(1, $closed['changed']);
        $opened = $this->service()->apply(false, '17');
        self::assertSame(1, $opened['changed']);
        self::assertTrue($this->gateway->rights['-1']['send_messages']);
        self::assertSame('prelocked', $this->locks->state('-1')['status']);
    }
    public function testFailedOverrideDatabaseWriteCannotStartPermissionsRpc(): void
    {
        $this->db->pdo()->exec("CREATE TRIGGER reject_override BEFORE UPDATE OF manual_override_mode ON group_settings WHEN NEW.group_id='-1' BEGIN SELECT RAISE(FAIL,'simulated disk error'); END");
        $result = $this->service()->apply(true, '17');
        self::assertSame(1, $result['failed']);
        self::assertSame('none', $this->settings->getGroup('-1')['manual_override_mode']);
        self::assertCount(1, $this->gateway->changes);
        self::assertSame('-2', $this->gateway->changes[0][0]);
    }
    public function testCancellationStopsBatchAndKeepsPersistedRecoverySnapshot(): void
    {
        $this->gateway->onSet = static function (): void {
            throw new Amp\CancelledException();
        };
        try {
            $this->service()->apply(true, '17');
            self::fail('Cancellation was swallowed.');
        } catch (Amp\CancelledException) {
        }
        self::assertSame('prepared', $this->locks->state('-1')['status']);
        self::assertNotNull($this->locks->state('-1')['snapshot_sha256']);
        self::assertSame('none', $this->settings->getGroup('-2')['manual_override_mode']);
    }
}
