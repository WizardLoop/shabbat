<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use DateTimeImmutable;
use Fiber;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Storage\Database;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Storage\StaleSettings;
use Shabbat\Time\FrozenClock;

final class FlowParentRaceTest extends TestCase
{
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private FlowTokenRepository $flows;

    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-14T12:00:00Z'));
        $this->settings = new SettingsRepository($db, $this->clock);
        $this->flows = new FlowTokenRepository($db, $this->clock);
        $this->settings->ensureGroup('-1001', 'First group');
        $this->settings->ensureGroup('-1002', 'Second group');
    }

    private function parent(string $user = '1', string $scope = 'group', ?string $group = '-1001'): string
    {
        return $this->flows->create($user, $scope, $group, 1, 'location_input', 600);
    }

    private function save(mixed $parent): string
    {
        return $this->flows->create('1', 'group', '-1001', 1, 'save', 3600, ['source_input_token' => $parent]);
    }

    public static function promptChanges(): array
    {
        return [['replacement'], ['cancellation'], ['expiry']];
    }

    #[DataProvider('promptChanges')]
    public function testParentIsRevalidatedAfterAuthorizationAwaitBeforeAnyWrite(string $change): void
    {
        $parent = $this->parent();
        $save = $this->save($parent);
        $error = null;
        $saved = null;
        $mutations = 0;
        $authorizationCalls = 0;
        $fiber = new Fiber(function () use (
            $save,
            &$error,
            &$saved,
            &$mutations,
            &$authorizationCalls
        ): void {
            try {
                $saved = $this->flows->confirmAndApply(
                    $save,
                    '1',
                    'save',
                    static function (string $group, string $user) use (&$authorizationCalls): bool {
                        self::assertSame('-1001', $group);
                        self::assertSame('1', $user);
                        $authorizationCalls++;
                        Fiber::suspend('authorization-await');
                        return true;
                    },
                    ['timezone_id' => 'Europe/London'],
                    static function (PDO $pdo) use (&$mutations): void {
                        $mutations++;
                    }
                );
            } catch (StaleSettings $failure) {
                $error = $failure;
            }
        });
        self::assertSame('authorization-await', $fiber->start());
        self::assertTrue($fiber->isSuspended());
        if ($change === 'replacement') {
            $this->flows->revokeAction('1', 'location_input');
            $replacement = $this->parent(group: '-1002');
            self::assertNotNull($this->flows->peek($replacement, '1', 'location_input'));
        } elseif ($change === 'cancellation') {
            $this->flows->revoke($parent, '1', 'location_input');
        } else {
            $this->clock->set(new DateTimeImmutable('2026-10-14T12:10:01Z'));
        }
        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
        self::assertInstanceOf(StaleSettings::class, $error);
        self::assertNull($saved);
        self::assertSame(1, $authorizationCalls);
        self::assertSame(0, $mutations);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        self::assertSame('Asia/Jerusalem', $this->settings->getGroup('-1001')['timezone_id']);
        self::assertSame(1, $this->settings->getGroup('-1002')['revision']);
        self::assertNotNull($this->flows->peek($save, '1', 'save'));
        self::assertNull($this->flows->peek($parent, '1', 'location_input'));
    }

    public function testMatchingActiveParentStillAllowsExactlyOneConfirmation(): void
    {
        $save = $this->save($this->parent());
        $saved = $this->flows->confirm(
            $save,
            '1',
            'save',
            static fn(string $group, string $user): bool => $group === '-1001' && $user === '1',
            ['timezone_id' => 'Europe/London']
        );
        self::assertSame(2, $saved['revision']);
        self::assertSame('Europe/London', $saved['timezone_id']);
        self::assertNull($this->flows->peek($save, '1', 'save'));
        $this->expectException(StaleSettings::class);
        $this->flows->confirm($save, '1', 'save', static fn(): bool => true, ['candle_minutes' => 30]);
    }

    public static function mismatchedParents(): array
    {
        return [['user'], ['scope'], ['group'], ['revision'], ['action'], ['null'], ['invalid']];
    }

    #[DataProvider('mismatchedParents')]
    public function testParentMustMatchUserActionScopeGroupAndRevision(string $kind): void
    {
        $parent = match ($kind) {
            'user' => $this->parent(user: '2'),
            'scope' => $this->parent(scope: 'user', group: null),
            'group' => $this->parent(group: '-1002'),
            'revision' => $this->flows->create('1', 'group', '-1001', 2, 'location_input'),
            'action' => $this->flows->create('1', 'group', '-1001', 1, 'panel'),
            'null' => null,
            'invalid' => ['not-a-token'],
        };
        $save = $this->save($parent);
        try {
            $this->flows->confirm($save, '1', 'save', static fn(): bool => true, ['candle_minutes' => 30]);
            self::fail('A mismatched original prompt was allowed to save.');
        } catch (StaleSettings) {
            self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
            self::assertNull($this->settings->getGroup('-1001')['candle_minutes']);
            self::assertNotNull($this->flows->peek($save, '1', 'save'));
        }
    }
    public static function exitActions(): array
    {
        return [['/start'], ['/groups'], ['/preferences'], ['sb:start'], ['sb:groups'], ['sb:personal']];
    }

    #[DataProvider('exitActions')]
    public function testExplicitExitRevokesNaturalPromptAndPreservesIndependentLegacyFlow(string $exit): void
    {
        $gateway = new UiNavigationFakeGateway();
        $http = new class implements \Shabbat\Http\JsonClient {
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('No network in prompt exit tests.');
            }
        };
        $ui = new \Shabbat\UI\BotController(
            $this->settings,
            $this->flows,
            new \Shabbat\Calendar\CalendarService(new \Shabbat\Calendar\HebcalProvider($http), $this->clock),
            $gateway,
            new \Shabbat\Location\GeoNames($http, $this->clock, ''),
            $this->clock
        );
        $panel = $this->flows->create('1', 'group', '-1001', 1, 'panel');
        $ui->handleCallback([
            'user_id' => '1', 'private' => true, 'chat_id' => '1', 'message_id' => 707,
            'query_id' => 'fixture', 'data' => 'sb:lookup:' . $panel . ':coordinates',
        ]);
        $natural = $this->flows->latest('1', 'location_input');
        self::assertNotNull($natural);
        $legacy = $this->flows->create('1', 'group', '-1002', 1, 'location');
        $save = $this->save($natural['payload']['token']);
        if (str_starts_with($exit, '/')) {
            self::assertTrue($ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => $exit]));
        } else {
            self::assertTrue($ui->handleCallback([
                'user_id' => '1', 'private' => true, 'chat_id' => '1', 'message_id' => 707,
                'query_id' => 'fixture', 'data' => $exit,
            ]));
        }
        self::assertNull($this->flows->latest('1', 'location_input'));
        self::assertNotNull($this->flows->peek($legacy, '1', 'location'));
        $edits = count($gateway->edited);
        $sent = count($gateway->sent);
        self::assertFalse($ui->handleMessage([
            'user_id' => '1', 'private' => true, 'text' => '51.5, -0.1 Europe/London',
        ]));
        self::assertCount($edits, $gateway->edited);
        self::assertCount($sent, $gateway->sent);
        $this->expectException(StaleSettings::class);
        $this->flows->confirm($save, '1', 'save', static fn(): bool => true, ['timezone_id' => 'Europe/London']);
    }
}
