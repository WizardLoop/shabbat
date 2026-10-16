<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Storage\{AdminAccessRepository, Database, SettingsRepository, TrackingLinkRepository};
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{AdminController, AdminText, Lang, OperatorController, SystemLinks};

final class AdminRolesLinksTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private AdminAccessRepository $access;
    private SettingsRepository $settings;
    private TrackingLinkRepository $tracking;
    private AdminController $ui;
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->access = new AdminAccessRepository($this->db, $this->clock, static fn(): array => ['1']);
        $this->tracking = new TrackingLinkRepository($this->db, $this->clock, $this->access);
        $this->ui = new AdminController($this->db, $this->clock, $this->settings, $this->access, $this->tracking, 'FixtureBot');
    }
    public function testImmutableOwnersAndOneTimeLegacyImport(): void
    {
        $this->access->importLegacyOnce(['1', '2', '-100123', 'invalid']);
        self::assertSame(['1', '2'], $this->access->adminIds());
        self::assertTrue($this->access->canMutate('2', 'broadcast'));
        $this->access->remove('1', '2', 1);
        $this->access->importLegacyOnce(['2', '3']);
        self::assertSame(['1'], $this->access->adminIds());
        foreach (['1', '0', '-1001'] as $id) {
            try {
                $this->access->add('1', $id, 'full', false, []);
                self::fail('Protected or invalid ID added');
            } catch (\RuntimeException) {
                self::assertNull($id === '1' ? null : $this->access->profile($id));
            }
        }
        self::assertSame('environment', $this->access->profile('1')['source']);
    }
    public function testViewOnlyAndUnknownPermissionsFailClosed(): void
    {
        $this->access->add('1', '2', 'limited', true, ['statistics' => true, 'broadcast' => true]);
        self::assertTrue($this->access->canView('2', 'statistics'));
        self::assertFalse($this->access->canMutate('2', 'broadcast'));
        self::assertFalse($this->access->canView('2', 'manage_admins'));
        self::assertFalse($this->access->canView('1', 'unknown'));
        self::assertFalse($this->access->isAdmin('99'));
        $this->expectException(\RuntimeException::class);
        $this->access->add('2', '3', 'limited', false, []);
    }
    public function testLimitedManagerCannotGrantOrManageWiderAuthority(): void
    {
        $this->access->add('1', '2', 'limited', false, ['statistics' => true, 'add_admins' => true, 'manage_admins' => true]);
        $this->access->add('2', '3', 'limited', true, ['statistics' => true]);
        self::assertTrue($this->access->canView('3', 'statistics'));
        foreach (['full', 'limited'] as $role) {
            try {
                $this->access->add('2', '4', $role, false, ['broadcast' => true]);
                self::fail('Privilege escalation accepted');
            } catch (\RuntimeException) {
                self::assertNull($this->access->profile('4'));
            }
        }
        $this->access->add('1', '4', 'full', false, []);
        $this->expectException(\RuntimeException::class);
        $this->access->remove('2', '4', 1);
    }
    public function testTargetRevisionAndCurrentActorRoleAreCheckedAtMutation(): void
    {
        $this->access->add('1', '2', 'full', false, []);
        $this->access->add('1', '3', 'limited', false, ['statistics' => true]);
        $this->access->update('1', '3', 'limited', true, ['statistics' => true], 1);
        try {
            $this->access->update('2', '3', 'full', false, [], 1);
            self::fail('Stale administrator save accepted');
        } catch (\RuntimeException) {
            self::assertSame(2, $this->access->profile('3')['revision']);
        }
        $this->access->remove('1', '2', 1);
        $this->expectException(\RuntimeException::class);
        $this->access->update('2', '3', 'full', false, [], 2);
    }
    public function testManagerCannotChangeSelfEvenWithFullRole(): void
    {
        $this->access->add('1', '2', 'full', false, []);
        $this->expectException(\RuntimeException::class);
        $this->access->remove('2', '2', 1);
    }
    public function testAclAndAuditRollBackTogetherOnFailure(): void
    {
        $this->db->pdo()->exec("CREATE TRIGGER reject_admin_audit BEFORE INSERT ON audit_log WHEN NEW.action='operator.admin.add' BEGIN SELECT RAISE(ABORT,'fixture'); END");
        try {
            $this->access->add('1', '2', 'full', false, []);
            self::fail('Failed transaction succeeded');
        } catch (\PDOException) {
            self::assertNull($this->access->profile('2'));
        }
    }
    public function testAdministratorEditorIsPrivateBoundExpiringAndOneUse(): void
    {
        $this->ui->callback('1', 'adm:add');
        $editor = $this->ui->capture('1', '2');
        $save = $this->find($editor, 'adm:save:');
        self::assertLessThanOrEqual(64, strlen($save));
        self::assertFalse($this->access->isAdmin('2'));
        try {
            $this->ui->callback('2', $save);
            self::fail('Other actor used editor');
        } catch (\RuntimeException) {
            self::assertFalse($this->access->isAdmin('2'));
        }
        $this->ui->callback('1', $save);
        self::assertTrue($this->access->profile('2')['view_only']);
        try {
            $this->ui->callback('1', $save);
            self::fail('Editor replay accepted');
        } catch (\RuntimeException) {
            self::assertSame(1, $this->access->profile('2')['revision']);
        }
        $this->ui->callback('1', 'adm:add');
        $editor = $this->ui->capture('1', '', '3');
        $this->clock->set(new \DateTimeImmutable('2026-10-15T12:11:00Z'));
        $this->expectException(\RuntimeException::class);
        $this->ui->callback('1', $this->find($editor, 'adm:save:'));
    }
    public function testPrivateGroupBoundaryAndCommandsDoNotBecomeAdminInput(): void
    {
        $this->ui->callback('1', 'adm:add');
        self::assertNull($this->ui->capture('1', '/help'));
        self::assertNull($this->ui->capture('1', '2', null, false));
        self::assertFalse($this->access->isAdmin('2'));
        $this->expectException(\RuntimeException::class);
        $this->ui->callback('1', 'adm:list:0', false);
    }
    public function testReplacingDraftRevokesOldButtonsAndRevokingRolePreventsSave(): void
    {
        $this->access->add('1', '2', 'full', false, []);
        $this->ui->callback('2', 'adm:add');
        $editor = $this->ui->capture('2', '3');
        $this->ui->callback('2', 'adm:add');
        try {
            $this->ui->callback('2', $this->find($editor, 'adm:save:'));
            self::fail('Replaced draft accepted');
        } catch (\RuntimeException) {
            self::assertNull($this->access->profile('3'));
        }
        $editor = $this->ui->capture('2', '3');
        $this->access->remove('1', '2', 1);
        $this->expectException(\RuntimeException::class);
        $this->ui->callback('2', $this->find($editor, 'adm:save:'));
    }
    public function testTrackingCountsUniqueRepeatsDaysAndDeduplicatesRedelivery(): void
    {
        $p = $this->tracking->create('1', 'Community', 'Neighborhood', 'shabbat');
        $id = (int) $p['id'];
        self::assertSame('shabbat', $this->tracking->recordStart('community', '123456789', 10));
        self::assertSame('shabbat', $this->tracking->recordStart('COMMUNITY', '123456789', 10));
        $this->tracking->recordStart('community', '123456789', 11);
        $this->tracking->recordStart('community', '987654321', 12);
        $p = $this->tracking->get('1', $id);
        self::assertSame(3, (int) $p['total_clicks']);
        self::assertSame(2, (int) $p['unique_users']);
        self::assertSame(1, $p['repeat_clicks']);
        self::assertSame(3, (int) $p['days'][0]['clicks']);
        $raw = $this->db->pdo()->query('SELECT * FROM tracking_link_users')->fetchAll();
        self::assertCount(2, $raw);
        self::assertStringNotContainsString('123456789', Database::encode($raw));
        self::assertStringNotContainsString('987654321', Database::encode($raw));
        self::assertSame(64, strlen($raw[0]['user_hash']));
    }
    public function testUnknownMalformedGroupAndMissingMessageStartsAreNotCounted(): void
    {
        $p = $this->tracking->create('1', 'campaign');
        foreach ([['campaign', '2', 0, true], ['campaign', '-1001', 1, true], ['campaign', '2', 1, false], ['unknown', '2', 1, true], ['bad!', '2', 1, true], ['admin', '2', 1, true]] as $args) {
            self::assertNull($this->tracking->recordStart(...$args));
        }
        self::assertSame(0, (int) $this->tracking->get('1', (int) $p['id'])['total_clicks']);
    }
    public function testTrackersCannotShadowRoutesOrTargetPrivilegedActions(): void
    {
        foreach (['admin','g_123','loc_abc','preferences','admin_broadcast','a!','-wrong','bad_'] as $param) {
            try {
                $this->tracking->create('1', $param);
                self::fail('Reserved or malformed tracking link created');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $this->tracking->page('1')['total']);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->tracking->create('1', 'campaign', '', 'admin_broadcast');
    }
    public function testTrackingManagementHonorsViewOnlyAndResetRevision(): void
    {
        $p = $this->tracking->create('1', 'campaign');
        $id = (int) $p['id'];
        $this->tracking->recordStart('campaign', '2', 1);
        $this->access->add('1', '3', 'limited', true, ['tracking_links' => true]);
        self::assertSame(1, $this->tracking->page('3')['total']);
        try {
            $this->tracking->reset('3', $id, 1);
            self::fail('View-only reset accepted');
        } catch (\RuntimeException) {
            self::assertSame(1, (int) $this->tracking->get('1', $id)['total_clicks']);
        }
        $this->tracking->reset('1', $id, 1);
        self::assertSame(0, (int) $this->tracking->get('1', $id)['total_clicks']);
        self::assertSame([], $this->tracking->get('1', $id)['days']);
        try {
            $this->tracking->delete('1', $id, 1);
            self::fail('Stale tracker deletion accepted');
        } catch (\RuntimeException) {
            self::assertNotNull($this->tracking->get('1', $id));
        }
        $this->tracking->delete('1', $id, 2);
        self::assertNull($this->tracking->get('1', $id));
    }
    public function testTrackingCreationPreviewDestinationAndExplicitSave(): void
    {
        $this->ui->callback('1', 'trk:add');
        $frame = $this->ui->capture('1', 'community <Neighborhood>');
        self::assertStringContainsString('&lt;Neighborhood&gt;', $frame['text']);
        self::assertSame(0, $this->tracking->page('1')['total']);
        $destination = $this->find($frame, 'trk:destination:', ':shabbat');
        $frame = $this->ui->callback('1', $destination);
        $this->ui->callback('1', $this->find($frame, 'trk:save:'));
        $p = $this->tracking->page('1')['items'][0];
        self::assertSame('shabbat', $p['destination']);
        $ask = $this->ui->callback('1', 'trk:delete:' . $p['id']);
        self::assertSame(1, $this->tracking->page('1')['total']);
        $this->ui->callback('1', $this->find($ask, 'trk:save:'));
        self::assertSame(0, $this->tracking->page('1')['total']);
    }
    public function testSystemLinksNeverBypassPrivateAdminCapabilities(): void
    {
        self::assertSame('shabbat', SystemLinks::resolve('shabbat', '99', true, $this->access));
        self::assertNull(SystemLinks::resolve('admin', '1', false, $this->access));
        $this->access->add('1', '2', 'limited', true, ['statistics' => true]);
        self::assertSame('statistics', SystemLinks::resolve('admin_stats', '2', true, $this->access));
        $this->expectException(\RuntimeException::class);
        SystemLinks::resolve('admin_broadcast', '2', true, $this->access);
    }
    public function testAllMenusHaveCompleteSixLanguageTextAndBoundedNativeButtons(): void
    {
        foreach (Lang::CODES as $language) {
            $u = $this->settings->getUser('1');
            $this->settings->updateUser('1', $u['revision'], ['language' => $language]);
            foreach (AdminText::keys() as $key) {
                self::assertNotSame('', AdminText::text($language, $key));
                self::assertStringNotContainsString('—', AdminText::text($language, $key));
            }
            foreach ([$this->ui->managers('1'), $this->ui->systemLinks('1'), $this->ui->trackers('1')] as $frame) {
                self::assertStringNotContainsString('\\n', $frame['text']);
                self::assertLessThan(4096, mb_strlen($frame['text']));
                foreach ($frame['markup']['rows'] as $row) {
                    foreach ($row['buttons'] as $button) {
                        self::assertLessThanOrEqual(64, strlen($button['data'] ?? ''));
                    }
                }
            }
        }
    }
    public function testOperatorMenuOnlyExposesCurrentCapabilities(): void
    {
        $this->access->add('1', '2', 'limited', true, ['statistics' => true]);
        $ui = new OperatorController($this->settings, new Lang(), static fn(): bool => true, $this->access);
        $frame = $ui->main('2');
        self::assertSame('sd:stats', $this->find($frame, 'sd:'));
        self::assertStringNotContainsString('/restart', $frame['text']);
        self::assertStringNotContainsString('Broadcast', Database::encode($frame));
        $this->expectException(\RuntimeException::class);
        $ui->broadcast('2');
    }

    public function testSelfBotCannotBeAddedAndAddOnlyManagerCanSaveWithoutListingOtherManagers(): void
    {
        $this->access->add('1', '2', 'limited', false, ['add_admins' => true]);
        $ui = new AdminController($this->db, $this->clock, $this->settings, $this->access, $this->tracking, 'FixtureBot', '999');
        $ui->callback('2', 'adm:add');
        try {
            $ui->capture('2', '999');
            self::fail('Bot itself accepted as manager');
        } catch (\InvalidArgumentException) {
            self::assertFalse($this->access->isAdmin('999'));
        }
        $editor = $ui->capture('2', '3');
        self::assertSame('חזרהמנהל', $this->find($editor, 'חזרהמנהל'));
        $frame = $ui->callback('2', $this->find($editor, 'adm:save:'));
        self::assertTrue($this->access->isAdmin('3'));
        self::assertStringNotContainsString('adm:view:1', Database::encode($frame));
        self::assertSame('חזרהמנהל', $this->find($frame, 'חזרהמנהל'));
    }
    private function find(array $frame, string $prefix, string $suffix = ''): string
    {
        foreach ($frame['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $b) {
                if (str_starts_with($b['data'] ?? '', $prefix) && ($suffix === '' || str_ends_with($b['data'], $suffix))) {
                    return $b['data'];
                }
            }
        }
        self::fail('Expected callback not found: ' . $prefix);
    }
}
