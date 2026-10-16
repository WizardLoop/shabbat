<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\{Cancellation, CancelledException};
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Filter\Filter;
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\EventHandler\Query\{ButtonQuery, ChatButtonQuery, InlineButtonQuery};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService, HebcalProvider};
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\{Application, BotIntegration};
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{AdminDirectoryText, Lang};

/** Regressions must enter the SDK handler, not bypass its outer dispatch guard. */
final class CallbackRoutingContractTest extends TestCase
{
    private Application $app;
    private CallbackRoutingFixture $bridge;
    private Filter $filter;
    private array $sent = [];
    private array $edited = [];
    private array $answers = [];
    private ?\Throwable $editFailure = null;

    protected function setUp(): void
    {
        // Importing the guarded entrypoint defines the real bot without starting it.
        require_once dirname(__DIR__) . '/bot.php';
        $method = new \ReflectionMethod(\Shabbat::class, 'upgradedCallbacks');
        $trait = new \ReflectionMethod(BotIntegration::class, 'upgradedCallbacks');
        self::assertSame($trait->getFileName(), $method->getFileName());
        self::assertSame($trait->getStartLine(), $method->getStartLine());
        self::assertCount(1, $method->getAttributes(Handler::class));
        $type = $method->getParameters()[0]->getType();
        self::assertNotNull($type);
        $this->filter = Filter::fromReflectionType($type);

        $gateway = $this->createMock(CallbackRoutingGateway::class);
        $gateway->method('botUsername')->willReturn('FixtureBot');
        $gateway->method('isGroupAdmin')->willReturn(false);
        foreach (['getPermissions', 'setPermissions', 'send', 'answerInline'] as $name) {
            $gateway->expects(self::never())->method($name);
        }
        $gateway->method('sendPrivate')->willReturnCallback(function (string $user, string $text, array $markup = []): void {
            $this->sent[] = compact('user', 'text', 'markup');
        });
        $gateway->method('editPrivate')->willReturnCallback(function (string $user, int $id, string $text, array $markup = []): void {
            if ($this->editFailure !== null) {
                throw $this->editFailure;
            }
            $this->edited[] = compact('user', 'id', 'text', 'markup');
        });
        $gateway->method('answerCallback')->willReturnCallback(function (string $id, string $text): void {
            $this->answers[] = compact('id', 'text');
        });
        $http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \LogicException('Callback navigation must not request provider data.');
            }
        };
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z'));
        $db = Database::open(':memory:');
        $db->migrate();
        $this->app = new Application(
            $gateway,
            $clock,
            $db,
            new CalendarService(new HebcalProvider($http), $clock),
            new GeoNames($http, $clock, ''),
            rpc: static fn (): never => throw new \LogicException('No RPC during callback navigation.'),
            configuredOwners: static fn (): array => []
        );
        $this->app->settings->ensureGroup('-1001', 'Private group identity');
        $this->bridge = new CallbackRoutingFixture($this->app);
    }

    public static function languages(): array
    {
        return array_map(static fn (string $code): array => [$code], Lang::CODES);
    }

    #[DataProvider('languages')]
    public function testGeneratedHomeStatisticsTabsRefreshAndBackUseActualSdkHandler(string $language): void
    {
        $user = $this->app->settings->getUser('2');
        $this->app->settings->updateUser('2', $user['revision'], ['language' => $language]);
        self::assertFalse($this->app->adminAccess->isAdmin('2'));
        $this->app->ui->start('2');
        $home = $this->sent[0];
        $this->sent = [];
        self::assertContains('ps:overview', $this->callbacks($home['markup']));
        $this->dispatch('ps:overview');
        $overview = $this->edited[0];
        self::assertStringContainsString(AdminDirectoryText::text($language, 'stats_overview'), $overview['text']);
        self::assertContains('ps:modules', $this->callbacks($overview['markup']));
        $this->dispatch('ps:modules');
        $modules = $this->edited[1];
        self::assertStringContainsString(AdminDirectoryText::text($language, 'stats_modules'), $modules['text']);
        self::assertContains('ps:overview', $this->callbacks($modules['markup']));
        self::assertContains('ps:modules', $this->callbacks($modules['markup']), 'Refresh retains the current tab.');
        $this->dispatch('ps:modules');
        $this->dispatch('ps:overview');
        self::assertSame($overview['text'], $this->edited[3]['text']);
        self::assertContains('sb:start', $this->callbacks($this->edited[3]['markup']));
        $this->dispatch('sb:start');
        self::assertSame($home['text'], $this->edited[4]['text']);
        self::assertCount(5, $this->edited);
        self::assertCount(5, $this->answers);
        self::assertSame([], $this->sent, 'Each click must edit its own existing panel.');
        foreach ($this->edited as $edit) {
            self::assertSame('2', $edit['user']);
            self::assertSame(200, $edit['id']);
            self::assertStringNotContainsString('Private group identity', $edit['text']);
            self::assertStringNotContainsString('-1001', $edit['text']);
        }
        foreach ($this->answers as $answer) {
            self::assertSame(['id' => '99', 'text' => ''], $answer);
        }
    }

    public static function invalidStatistics(): array
    {
        return [['ps:'], ['ps:admin'], ['ps:overview:extra'], ["ps:modules\0"], ['ps:OVERVIEW']];
    }

    #[DataProvider('invalidStatistics')]
    public function testMalformedStatisticsCallbackIsDeniedWithoutEditing(string $data): void
    {
        $this->dispatch($data);
        self::assertSame([], $this->edited);
        self::assertSame([], $this->sent);
        self::assertSame([['id' => '99', 'text' => (new Lang())->text('he', 'denied')]], $this->answers);
    }

    public static function tabs(): array
    {
        return [['ps:overview'], ['ps:modules']];
    }

    #[DataProvider('tabs')]
    public function testGroupStatisticsCallbackCannotEditOrSendPrivatePanels(string $data): void
    {
        $this->dispatch($data, chat: -1001);
        self::assertSame([], $this->edited);
        self::assertSame([], $this->sent);
        self::assertSame([['id' => '99', 'text' => (new Lang())->text('he', 'denied')]], $this->answers);
    }

    #[DataProvider('tabs')]
    public function testInlineStatisticsCallbackIsRejectedBeforePrivateRouting(string $data): void
    {
        $query = $this->query($data, inline: true);
        self::assertTrue($this->filter->apply($query));
        $this->bridge->upgradedCallbacks($query);
        self::assertSame([], $this->edited);
        self::assertSame([], $this->sent);
        self::assertSame([], $this->answers);
    }

    #[DataProvider('tabs')]
    public function testDeletedStatisticsPanelGetsOneErrorAnswerWithoutReplacement(string $data): void
    {
        $this->editFailure = new \RuntimeException('MESSAGE_ID_INVALID');
        $this->dispatch($data);
        self::assertSame([], $this->edited);
        self::assertSame([], $this->sent);
        self::assertSame([['id' => '99', 'text' => (new Lang())->text('he', 'callback_panel_unavailable')]], $this->answers);
    }

    public function testStatisticsCancellationIsNotSwallowedOrRetried(): void
    {
        $this->editFailure = new CancelledException();
        try {
            $this->dispatch('ps:overview');
            self::fail('Intentional cancellation must propagate to the event loop.');
        } catch (CancelledException $error) {
            self::assertSame($this->editFailure, $error);
        }
        self::assertSame([], $this->edited);
        self::assertSame([], $this->sent);
        self::assertSame([], $this->answers);
    }

    public static function publicDestinations(): array
    {
        return [['sb:personal'], ['sb:help'], ['v3:source'], ['v3:donate']];
    }

    #[DataProvider('publicDestinations')]
    public function testExistingPublicCallbackFamiliesStillReachTheirRealHandlers(string $data): void
    {
        $this->dispatch(data: $data, user: 3, panel: 701);
        self::assertSame([], $this->sent);
        self::assertCount(1, $this->edited);
        self::assertSame('3', $this->edited[0]['user']);
        self::assertSame(701, $this->edited[0]['id']);
        self::assertSame([['id' => '99', 'text' => '']], $this->answers);
    }

    public function testSdkParameterFilterAndOuterGuardIgnoreOtherUpdates(): void
    {
        $message = (new \ReflectionClass(PrivateMessage::class))->newInstanceWithoutConstructor();
        self::assertFalse($this->filter->apply($message));
        $this->dispatch('unrecognized:overview');
        self::assertSame([], $this->edited);
        self::assertSame([], $this->sent);
        self::assertSame([], $this->answers);
    }

    private function dispatch(string $data, int $user = 2, ?int $chat = null, int $panel = 200): void
    {
        $query = $this->query($data, $user, $chat, $panel);
        self::assertTrue($this->filter->apply($query), 'The real SDK parameter filter must accept the callback.');
        $this->bridge->upgradedCallbacks($query);
    }

    private function query(string $data, int $user = 2, ?int $chat = null, int $panel = 200, bool $inline = false): ButtonQuery
    {
        $class = new \ReflectionClass($inline ? InlineButtonQuery::class : ChatButtonQuery::class);
        $query = $class->newInstanceWithoutConstructor();
        $fields = ['data' => $data, 'userId' => $user, 'queryId' => 99, 'chatInstance' => 777];
        if (!$inline) {
            $fields += ['chatId' => $chat ?? $user, 'messageId' => $panel];
        }
        foreach ($fields as $key => $value) {
            $class->getProperty($key)->setValue($query, $value);
        }
        return $query;
    }

    private function callbacks(array $markup): array
    {
        $callbacks = [];
        foreach ($markup['rows'] ?? [] as $row) {
            foreach ($row['buttons'] as $button) {
                if (isset($button['data'])) {
                    $callbacks[] = $button['data'];
                }
            }
        }
        return $callbacks;
    }
}
