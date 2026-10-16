<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Storage\{Database, SettingsRepository, SubscriberDirectory};
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{AdminDirectoryText, Lang, SubscriberController};

final class SubscriberDirectoryTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SubscriberDirectory $directory;
    private SettingsRepository $settings;
    private SubscriberController $ui;
    private bool $allowed = true;
    private bool $mutate = true;

    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->directory = new SubscriberDirectory($this->db, $this->clock);
        $this->ui = new SubscriberController($this->settings, $this->directory, fn(string $user, string $cap, bool $mutate = false): bool => $this->allowed && (!$mutate || $this->mutate));
    }

    public function testStatisticsOnlyRoleCanNavigateEveryStatisticsSectionButNotTheDirectory(): void
    {
        $ui = new SubscriberController($this->settings, $this->directory, static fn(string $user, string $cap): bool => $cap === 'statistics');
        foreach (Lang::CODES as $code) {
            $settings = $this->settings->getUser('1');
            $this->settings->updateUser('1', $settings['revision'], ['language' => $code]);
            foreach (['audience', 'activity', 'groups', 'delivery'] as $section) {
                $frame = $ui->callback('1', 'sd:stats:' . $section);
                self::assertStringContainsString(AdminDirectoryText::text($code, 'stats_' . $section), $frame['text']);
                self::assertStringNotContainsString('(supergroup)', $frame['text']);
                foreach ($frame['markup']['rows'] as $row) {
                    self::assertLessThanOrEqual(2, count($row['buttons']));
                }
            }
        }
        $this->expectException(\RuntimeException::class);
        $ui->callback('1', 'sd:list:0');
    }

    public function testExistingUsersAndRegisteredGroupsAppearWithoutFabricatedActivity(): void
    {
        $this->settings->getUser('51');
        $this->settings->ensureGroup('-51', 'Imported group');
        $page = $this->directory->page();
        self::assertSame(2, $page['total']);
        self::assertSame(1, $this->directory->page(filter: 'users')['total']);
        self::assertSame(1, $this->directory->page(filter: 'groups')['total']);
        $detail = $this->directory->detail('51');
        self::assertSame([], $detail['profile']);
        self::assertFalse($detail['known_group_admin']);
        self::assertSame([], $detail['group_roles']);
        self::assertSame(0, $this->directory->stats()['activity']);
        self::assertSame(['-51'], $this->directory->targets('groups'));
    }

    public function testMetadataRefreshDoesNotIncrementActivityOrLoseKnownFields(): void
    {
        $this->directory->observe('5', ['type' => 'user', 'first_name' => 'שלום', 'username' => 'hello', 'premium' => true], true, true);
        $first = $this->directory->detail('5');
        $this->clock->set(new \DateTimeImmutable('2026-10-16T12:00:00Z'));
        $this->directory->observe('5', ['premium' => false]);
        $next = $this->directory->detail('5');
        self::assertSame('hello', $next['profile']['username']);
        self::assertSame('user', $next['type']);
        self::assertSame(0, $next['profile']['is_premium']);
        self::assertSame($first['profile']['last_activity_at'], $next['profile']['last_activity_at']);
        self::assertSame(1, $next['profile']['activity_count']);
        self::assertSame(1, $next['profile']['start_count']);
        self::assertNull($next['profile']['is_deleted']);
    }

    public function testAllPeerFiltersAndEightAudiencesUseTypesAndExplicitAuthorization(): void
    {
        foreach (['1' => 'user', '2' => 'bot', '-1' => 'chat', '-1002' => 'supergroup', '-1003' => 'channel', '-1004' => 'unknown'] as $id => $type) {
            if (in_array($type, ['user','bot'], true)) {
                $this->directory->observePrivate((string) $id, ['type' => $type]);
            } else {
                $this->directory->observe((string) $id, ['type' => $type]);
            }
        }
        self::assertSame(6, $this->directory->page()['total']);
        self::assertSame(2, $this->directory->page(filter: 'groups')['total']);
        self::assertSame(['1'], $this->directory->targets('users'));
        self::assertSame(['-1003'], $this->directory->targets('channels'));
        self::assertCount(3, $this->directory->targets('groups_channels'));
        self::assertCount(5, $this->directory->targets('all'));
        foreach (['authorized_groups', 'authorized_channels', 'authorized_groups_channels'] as $audience) {
            self::assertSame([], $this->directory->targets($audience));
        }
        $this->directory->setBroadcastAuthorized('-1002', true);
        $this->directory->setBroadcastAuthorized('-1003', true);
        self::assertSame(['-1002'], $this->directory->targets('authorized_groups'));
        self::assertSame(['-1003'], $this->directory->targets('authorized_channels'));
        self::assertCount(2, $this->directory->targets('authorized_groups_channels'));
        $this->directory->setBroadcastAuthorized('-1003', false);
        self::assertSame([], $this->directory->targets('authorized_channels'));
        $this->expectException(\InvalidArgumentException::class);
        $this->directory->setBroadcastAuthorized('1', true);
    }

    public function testSearchEscapesWildcardsSupportsIdUsernameNameAndStablePaging(): void
    {
        foreach (range(1, 26) as $number) {
            $this->directory->observe((string) $number, ['type' => 'user', 'title' => 'User ' . str_pad((string) $number, 2, '0', STR_PAD_LEFT), 'username' => $number === 1 ? 'special_%name' : 'regular' . $number]);
        }
        self::assertSame(1, $this->directory->page(search: '%')['total']);
        self::assertSame(1, $this->directory->page(search: '_')['total']);
        self::assertSame(1, $this->directory->page(search: '@special_%name')['total']);
        self::assertSame(1, $this->directory->page(search: 'User 26')['total']);
        $one = $this->directory->page(0, 10, sort: 'name');
        $two = $this->directory->page(1, 10, sort: 'name');
        self::assertSame([], array_intersect(array_column($one['items'], 'id'), array_column($two['items'], 'id')));
        self::assertCount(6, $this->directory->page(999999, 10)['items']);
        self::assertSame(2, $this->directory->page(999999, 10)['page']);
        self::assertSame(3, $one['pages']);
    }

    public function testGroupAdministrationIsExplicitObservedAndRecheckedUpdatesRemainHistorical(): void
    {
        $this->settings->ensureGroup('-50', 'Known group');
        $this->directory->observe('5', ['type' => 'user']);
        self::assertSame([], $this->directory->detail('5')['group_roles']);
        $this->directory->recordGroupAdmin('5', '-50', true, 'owner');
        self::assertTrue($this->directory->detail('5')['known_group_admin']);
        $this->clock->set(new \DateTimeImmutable('2026-10-16T12:00:00Z'));
        $this->directory->recordGroupAdmin('5', '-50', false, 'left');
        $detail = $this->directory->detail('5');
        self::assertFalse($detail['known_group_admin']);
        self::assertSame('left', $detail['group_roles'][0]['role']);
        self::assertSame($this->clock->now()->getTimestamp(), $detail['group_roles'][0]['checked_at']);
        self::assertSame(0, $detail['broadcast_authorized']);
    }

    public function testRoleDirectoryRemainsCompleteBeyondProfileSummaryAndUsesPaging(): void
    {
        $this->directory->observe('5', ['type' => 'user']);
        foreach (range(1, 53) as $id) {
            $this->settings->ensureGroup('-' . $id, 'Group ' . $id);
            $this->directory->recordGroupAdmin('5', '-' . $id, true);
        }
        self::assertCount(50, $this->directory->detail('5')['group_roles']);
        self::assertTrue($this->directory->detail('5')['group_roles_truncated']);
        self::assertSame(53, $this->directory->roles('5')['total']);
        self::assertCount(3, $this->directory->roles('5', 5)['items']);
        self::assertSame(6, $this->directory->roles('5')['pages']);
    }

    public function testViewsSearchAndSortAreScopedToEachOperatorAndCommandsBypassPrompts(): void
    {
        $this->directory->observe('101', ['type' => 'user', 'title' => 'Alice']);
        $this->directory->observe('102', ['type' => 'user', 'title' => 'Bob']);
        $this->ui->callback('1', 'sd:search');
        self::assertNull($this->ui->input('1', '/start'));
        self::assertSame('search', $this->directory->view('1')['prompt']);
        $this->ui->input('1', 'Alice');
        self::assertSame('Alice', $this->directory->view('1')['search']);
        self::assertSame('', $this->directory->view('2')['search']);
        $this->ui->callback('1', 'sd:sort');
        self::assertSame('joined', $this->directory->view('1')['sort']);
        self::assertSame('activity', $this->directory->view('2')['sort']);
        $this->ui->callback('1', 'sd:filter:groups');
        self::assertSame('groups', $this->directory->view('1')['filter']);
        $this->ui->callback('1', 'sd:page');
        self::assertStringContainsString('מנויים', $this->ui->input('1', '999999')['text']);
        self::assertSame(0, $this->directory->view('1')['page']);
    }

    public function testSixLanguageFramesEscapeMetadataRetainAllControlsAndFullKnownSettings(): void
    {
        $keys = AdminDirectoryText::keys('he');
        $this->directory->observe('8', ['type' => 'user', 'title' => '<b>Unsafe</b>', 'first_name' => '<script>', 'username' => 'name']);
        $this->settings->getUser('8');
        foreach (Lang::CODES as $language) {
            self::assertSame($keys, AdminDirectoryText::keys($language));
            $settings = $this->settings->getUser('1');
            $this->settings->updateUser('1', $settings['revision'], ['language' => $language]);
            $panel = $this->ui->callback('1', 'sd:list:0');
            self::assertStringContainsString(AdminDirectoryText::text($language, 'directory'), $panel['text']);
            $profile = $this->ui->callback('1', 'sd:view:8:0');
            self::assertStringContainsString('&lt;script&gt;', $profile['text']);
            self::assertStringNotContainsString('<script>', $profile['text']);
            self::assertStringContainsString(AdminDirectoryText::text($language, 'unknown'), $profile['text']);
            $data = $this->ui->callback('1', 'sd:data:8:0');
            $allData = $data['text'];
            foreach (range(1, 4) as $detailPage) {
                $allData .= $this->ui->callback('1', 'sd:data:8:' . $detailPage)['text'];
            }
            self::assertStringContainsString('latitude', $allData);
            self::assertStringContainsString(AdminDirectoryText::text($language, 'stats'), $this->ui->callback('1', 'sd:stats')['text']);
            foreach ([$panel,$profile,$data] as $frame) {
                self::assertStringNotContainsString('—', $frame['text']);
                foreach ($frame['markup']['rows'] as $row) {
                    foreach ($row['buttons'] as $button) {
                        self::assertLessThanOrEqual(64, strlen($button['data']));
                    }
                }
            }
        }
    }

    public function testReadOnlySubscriberRoleCannotAuthorizeAudienceAndAuthorizationIsFresh(): void
    {
        $this->directory->observe('-1002', ['type' => 'channel', 'title' => 'Channel']);
        $this->mutate = false;
        $frame = $this->ui->profile('1', '-1002');
        self::assertStringNotContainsString('sd:auth:', json_encode($frame, JSON_THROW_ON_ERROR));
        try {
            $this->ui->callback('1', 'sd:auth:-1002:1');
            self::fail('View-only broadcast mutation accepted.');
        } catch (\RuntimeException) {
            self::assertSame([], $this->directory->targets('authorized_channels'));
        }
        $this->mutate = true;
        self::assertSame(['action' => 'authorize_peer', 'id' => '-1002', 'authorized' => true], $this->ui->callback('1', 'sd:auth:-1002:1'));
        self::assertSame([], $this->directory->targets('authorized_channels'), 'Returning a confirmation action must not mutate the audience.');
        $this->allowed = false;
        $this->expectException(\RuntimeException::class);
        $this->ui->callback('1', 'sd:list:0');
    }

    public function testStatisticsDistinguishUnknownMetadataActivityAndRegistration(): void
    {
        $this->directory->observe('1', ['type' => 'user'], true, true);
        $this->directory->observe('2', ['type' => 'bot']);
        $this->directory->observe('-1002', ['type' => 'unknown']);
        $this->settings->ensureGroup('-1', 'Group');
        $stats = $this->directory->stats();
        self::assertSame(4, $stats['total']);
        self::assertSame(1, $stats['unknown']);
        self::assertSame(1, $stats['active_24h']);
        self::assertSame(1, $stats['activity']);
        self::assertSame(1, $stats['starts']);
        self::assertSame(1, $stats['registered_groups']);
        $this->clock->set(new \DateTimeImmutable('2026-11-16T12:00:00Z'));
        self::assertSame(0, $this->directory->stats()['active_30d']);
    }

    public function testPrivateEligibilityRequiresIncomingPrivateObservationForNewIdentities(): void
    {
        $this->settings->getUser('41');
        $this->directory->observe('42', ['type' => 'user'], true);
        self::assertSame([], $this->directory->targets('users'));
        self::assertSame([], $this->directory->targets('all'));
        $this->directory->observePrivate('41', ['type' => 'user'], true, true);
        self::assertSame(['41'], $this->directory->targets('users'));
        $this->directory->observe('41', ['first_name' => 'Updated']);
        self::assertSame(['41'], $this->directory->targets('users'));
        self::assertSame(1, $this->directory->detail('41')['profile']['private_chat_access']);
    }

    public function testOnlyPreUpgradePrivateRegistryIsBackfilledForCompatibility(): void
    {
        $legacy = Database::open(':memory:');
        foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $file) {
            if (basename($file) !== '010_subscriber_directory.sql') {
                $legacy->pdo()->exec((string) file_get_contents($file));
            }
        }
        $legacy->pdo()->exec("INSERT INTO users(user_id,created_at,updated_at) VALUES('51',1,1)");
        $legacy->pdo()->exec("INSERT INTO known_broadcast_peers(peer_id,peer_type,title,created_at,updated_at) VALUES('52','user','Legacy',1,1),('-1001','unknown','Unknown channel',1,1)");
        $legacy->pdo()->exec((string) file_get_contents(dirname(__DIR__) . '/migrations/010_subscriber_directory.sql'));
        $directory = new SubscriberDirectory($legacy, $this->clock);
        self::assertSame(['51','52'], $directory->targets('users'));
        (new SettingsRepository($legacy, $this->clock))->getUser('53');
        self::assertSame(['51','52'], $directory->targets('users'));
        self::assertNotContains('-1001', $directory->targets('all'));
    }

    public function testLongObservedFieldsStayReadableAndAreAvailableWithoutTelegramOverflow(): void
    {
        $long = str_repeat('<', 256);
        $this->directory->observePrivate('8', ['type' => 'user', 'title' => $long, 'first_name' => $long, 'last_name' => $long, 'username' => str_repeat('&', 64), 'language_code' => str_repeat('&', 32)]);
        $this->settings->getUser('8');
        $frames = [$this->ui->profile('1', '8')];
        $detail = $this->ui->callback('1', 'sd:data:8:0');
        $frames[] = $detail;
        $valueCallbacks = [];
        foreach ($detail['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                if (str_starts_with($button['data'], 'sd:value:')) {
                    $valueCallbacks[] = $button['data'];
                    $frames[] = $this->ui->callback('1', $button['data']);
                }
            }
        }
        self::assertNotEmpty($valueCallbacks);
        foreach ($frames as $frame) {
            self::assertLessThanOrEqual(4096, mb_strlen($frame['text'], 'UTF-8'));
        }
        self::assertStringContainsString(str_repeat('&lt;', 256), $this->ui->callback('1', 'sd:value:8:0:0')['text']);
    }

    public function testAuthorizedAudienceMutationCanJoinConfirmationTransactionAndRollBack(): void
    {
        $this->directory->observe('-1002', ['type' => 'channel']);
        try {
            $this->db->transaction(function (\PDO $pdo): void {
                $this->directory->setBroadcastAuthorizedInTransaction($pdo, '-1002', true);
                self::assertSame(['-1002'], $this->directory->targets('authorized_channels'));
                throw new \RuntimeException('Fixture rollback.');
            });
            self::fail('Rollback was swallowed.');
        } catch (\RuntimeException) {
            self::assertSame([], $this->directory->targets('authorized_channels'));
        }
        $this->db->transaction(fn(\PDO $pdo) => $this->directory->setBroadcastAuthorizedInTransaction($pdo, '-1002', true));
        self::assertSame(['-1002'], $this->directory->targets('authorized_channels'));
    }

    public function testInvalidMetadataIsRejectedWithoutPartialRows(): void
    {
        foreach (
            [
            ['id' => '0','meta' => []], ['id' => '1','meta' => ['type' => 'channelish']],
            ['id' => '1','meta' => ['title' => "bad\0title"]], ['id' => '1','meta' => ['title' => str_repeat('x', 257)]],
            ['id' => '1','meta' => ['bot' => 1]], ['id' => '1','meta' => ['token' => 'secret']],
            ] as $case
        ) {
            try {
                $this->directory->observe($case['id'], $case['meta']);
                self::fail('Invalid metadata accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $this->directory->page()['total']);
            }
        }
    }
}
