<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\{BotIntegration, CommandMenus};
use Shabbat\UI\{Lang, OperatorController};
use Shabbat\Storage\{AdminAccessRepository, Database, SettingsRepository};
use Shabbat\Time\FrozenClock;

final class ConfiguredOwnersTest extends TestCase
{
    public function testResolvedConfiguredUserRemainsRecoveryOwnerAndDelegatedAdminRemainsRemovable(): void
    {
        // SDK setReportPeers has resolved @configured_owner before dispatch.
        $sdk = new class {
            public array $reportAdminIds = [123];

            public function getAdminIds(): array
            {
                return $this->reportAdminIds;
            }
        };
        $bridge = new class ($sdk) {
            use BotIntegration;

            public function __construct(private object $sdk)
            {
            }

            public function getAdminIds(): array
            {
                return $this->sdk->getAdminIds();
            }

            public function getReportPeers(): array
            {
                throw new \LogicException('Raw configured usernames are not authorization IDs.');
            }

            public function configuredOwners(): array
            {
                return $this->v3ConfiguredOwners();
            }
        };
        $db = Database::open(':memory:');
        $db->migrate();
        $access = new AdminAccessRepository($db, new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z')), $bridge->configuredOwners(...));
        $access->importLegacyOnce([123]);
        self::assertSame(['123'], $access->ownerIds());
        self::assertSame('environment', $access->profile('123')['source']);
        self::assertSame(0, (int)$db->pdo()->query('SELECT COUNT(*) FROM bot_admins')->fetchColumn());
        $access->add('123', '456', 'full', false, []);
        self::assertSame(['123'], $access->ownerIds());
        self::assertFalse($access->isOwner('456'));
        $access->remove('123', '456', 1);
        self::assertNull($access->profile('456'));
        try {
            $access->remove('123', '123', 0);
            self::fail('Configured recovery owner became removable.');
        } catch (\RuntimeException) {
            self::assertTrue($access->isOwner('123'));
        }
        // Read the current configured SDK list, rather than a cached role union.
        $sdk->reportAdminIds = [];
        self::assertSame([], $access->ownerIds());
        self::assertNull($access->profile('123'));
    }

    public function testRestartMenusAndPanelMatchConfiguredOwnersWhileViewOnlyHidesMutationCommands(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z'));
        $access = new AdminAccessRepository($db, $clock, static fn(): array => ['1']);
        $access->add('1', '2', 'full', false, []);
        $access->add('1', '3', 'limited', true, ['statistics' => true, 'commands' => true, 'group_operations' => true]);
        $settings = new SettingsRepository($db, $clock);
        $panel = new OperatorController($settings, new Lang(), $access->isAdmin(...), $access);
        $menus = new CommandMenus(
            new Lang(),
            static fn(): bool => true,
            static fn(): bool => true,
            static function (string $user, string $command) use ($access): bool {
                if ($command === 'restart') {
                    return $access->isOwner($user);
                }
                $capability = match ($command) {
                    'setcommands' => 'commands', 'closegroups', 'opengroups' => 'group_operations', default => null,
                };
                return $capability === null || $access->canMutate($user, $capability);
            }
        );
        foreach (['he', 'en', 'ru', 'fr', 'es', 'pt-br'] as $language) {
            foreach (['1', '2', '3'] as $id) {
                $current = $settings->getUser($id);
                $settings->updateUser($id, $current['revision'], ['language' => $language]);
                $text = $panel->main($id)['text'];
                self::assertSame($id === '1', str_contains($text, '<code>/restart</code>'));
                foreach (['setcommands', 'closegroups', 'opengroups'] as $command) {
                    self::assertSame($id !== '3', str_contains($text, '<code>/' . $command . '</code>'));
                }
                $commands = array_column($menus->plan([$id], $language, 'operators')[0]['commands'], 'command');
                self::assertSame($id === '1', in_array('restart', $commands, true));
                foreach (['setcommands', 'closegroups', 'opengroups'] as $command) {
                    self::assertSame($id !== '3', in_array($command, $commands, true));
                }
                self::assertContains('preferences', $commands);
            }
        }
    }
}
