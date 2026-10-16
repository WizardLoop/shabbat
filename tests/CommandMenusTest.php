<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\CommandMenus;
use Shabbat\UI\Lang;

final class CommandMenusTest extends TestCase
{
    private array $calls = [];
    private function service(?callable $authorize = null): CommandMenus
    {
        return new CommandMenus(new Lang(), function (string $method, array $parameters): bool {
                $this->calls[] = [$method,$parameters];
                return true;
        }, $authorize ?? static fn(string $user): bool=>$user === '42');
    }
    public function testAllSixLanguagesHaveCorrectPrivateOperatorAndGroupScopes(): void
    {
        $plans = $this->service()->plan(['42','43']);
        self::assertCount(35, $plans);
        foreach ($plans as $plan) {
            $ids = array_column($plan['commands'], 'command');
            $scope = $plan['scope']['_'];
            if ($scope === 'botCommandScopeUsers') {
                self::assertContains('preferences', $ids);
                self::assertNotContains('setcommands', $ids);
                self::assertNotContains('add', $ids);
            }
            if ($scope === 'botCommandScopeChats') {
                self::assertNotContains('admin', $ids);
                self::assertSame(['settings'], $ids);
            }
            if ($scope === 'botCommandScopeChatAdmins') {
                self::assertContains('settings', $ids);
                self::assertSame(['settings'], $ids);
                self::assertNotContains('setcommands', $ids);
            }
            if ($scope === 'botCommandScopePeer') {
                self::assertContains('setcommands', $ids);
                self::assertContains($plan['scope']['peer'], ['42','43']);
            }
            if ($plan['locale'] === 'pt-br') {
                self::assertSame('pt', $plan['lang_code']);
            }
            foreach ($plan['commands'] as $command) {
                self::assertNotSame('', $command['description']);
                self::assertLessThanOrEqual(256, mb_strlen($command['description']));
            }
        }
        self::assertSame([], $this->calls);
    }
    public function testEveryLocalizedDescriptionHasTheCommandAppropriateEmoji(): void
    {
        $prefixes = [
            'start' => '🏠', 'preferences' => '⚙️', 'lang' => '🌐', 'language' => '🌐', 'groups' => '👥',
            'help' => 'ℹ️', 'shabbat' => '🕯', 'zmanim' => '🕒', 'daf' => '📖',
            'omer' => '🌾', 'source' => '💻', 'license' => '📜', 'version' => '🏷',
            'donate' => '⭐', 'stats' => '📊', 'add' => '➕', 'remove' => '➖',
            'settings' => '⚙️', 'admin' => '🛠', 'adminstats' => '📊', 'subscribers' => '👥', 'broadcast' => '📣', 'managers' => '👥', 'systemlinks' => '⚙️', 'trackinglinks' => 'ℹ️', 'testzmanim' => '🔎',
            'closegroups' => '🔒', 'opengroups' => '🔓', 'setcommands' => '📋', 'restart' => '🔄',
        ];
        foreach ($this->service()->plan(['42']) as $menu) {
            foreach ($menu['commands'] as $command) {
                self::assertMatchesRegularExpression('/^[a-z0-9_]{1,32}$/D', $command['command']);
                self::assertStringStartsWith($prefixes[$command['command']] . ' ', $command['description'], $menu['locale'] . ': ' . $command['command']);
                self::assertGreaterThan(2, mb_strlen($command['description']));
            }
        }
        self::assertSame([], $this->calls);
    }
    public function testPreviewHasNoRpcAndUnauthorizedOperatorCannotWrite(): void
    {
        $service = $this->service();
        self::assertTrue($service->apply('42', ['42'], 'ru', 'group_admins', true)['preview']);
        self::assertSame([], $this->calls);
        $this->expectException(\RuntimeException::class);
        $service->apply('99', ['42']);
    }
    public function testNativeApiUsesLangCodeAndNoGroupReceivesOperatorCommands(): void
    {
        $result = $this->service()->apply('42', ['42'], 'pt-br', 'all');
        self::assertSame(4, $result['applied']);
        foreach ($this->calls as [$method,$parameters]) {
            self::assertSame('bots.setBotCommands', $method);
            self::assertSame('pt', $parameters['lang_code']);
            self::assertArrayNotHasKey('language_code', $parameters);
        }
    }
    public function testInvalidPeerAndLanguageFailBeforeRpc(): void
    {
        $service = $this->service();
        try {
            $service->plan(['-10042']);
            self::fail();
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->calls);
        }
        $this->expectException(\InvalidArgumentException::class);
        $service->plan(['42'], 'pt');
    }
    public function testOperatorRevocationStopsRemainingMenuWrites(): void
    {
        $allowed = true;
        $calls = 0;
        $service = new CommandMenus(new Lang(), function () use (&$allowed, &$calls): bool {
            $calls++;
            $allowed = false;
            return true;
        }, function () use (&$allowed): bool {
            return $allowed;
        });
        $result = $service->apply('42', ['42']);
        self::assertSame(1, $calls);
        self::assertSame(1, $result['applied']);
        self::assertSame(27, $result['skipped']);
    }
    public function testPerOperatorCapabilitiesFilterOnlyOperatorCommandsAndNeverGrantAuthority(): void
    {
        $allowed = [
            '42' => ['admin','adminstats','subscribers','broadcast'],
            '43' => ['admin','adminstats','systemlinks'],
            '44' => [],
        ];
        $service = new CommandMenus(new Lang(), function (string $method, array $parameters): bool {
            $this->calls[] = [$method,$parameters];
            return true;
        }, static fn(string $user): bool => $user === '1',
            static fn(string $target, string $command): bool => in_array($command, $allowed[$target] ?? [], true));
        $plans = $service->plan(['42','43','44'], 'all', 'all');
        foreach ($plans as $plan) {
            $commands = array_column($plan['commands'], 'command');
            if ($plan['scope']['_'] === 'botCommandScopePeer') {
                $target = (string)$plan['scope']['peer'];
                self::assertContains('preferences', $commands);
                self::assertContains('donate', $commands);
                self::assertNotContains('setcommands', $commands);
                self::assertNotContains('closegroups', $commands);
                self::assertNotContains('opengroups', $commands);
                foreach (['admin','adminstats','subscribers','broadcast','managers','systemlinks','trackinglinks','testzmanim'] as $command) {
                    self::assertSame(in_array($command, $allowed[$target], true), in_array($command, $commands, true), $target . ':' . $command);
                }
            } elseif ($plan['scope']['_'] === 'botCommandScopeUsers') {
                self::assertContains('preferences', $commands);
                self::assertNotContains('admin', $commands);
            } elseif ($plan['scope']['_'] === 'botCommandScopeChatAdmins') {
                self::assertContains('settings', $commands);
                self::assertNotContains('setcommands', $commands);
            }
        }
        self::assertSame([], $this->calls, 'Building a menu plan must not call Telegram.');
        self::assertTrue($service->apply('1', ['42'], 'en', 'operators', true)['preview']);
        self::assertSame([], $this->calls);
        $this->expectException(\RuntimeException::class);
        $service->apply('42', ['42'], 'en', 'operators');
    }

    public function testFalseAcknowledgementIsReportedAsFailure(): void
    {
        $service = new CommandMenus(new Lang(), static fn()=>false, static fn()=>true);
        $result = $service->apply('42', ['42'], 'en', 'private');
        self::assertSame(0, $result['applied']);
        self::assertSame(1, $result['failed']);
    }
}
