<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\CancelledException;
use Amp\TimeoutException;
use danog\MadelineProto\RPCErrorException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Shabbat\Runtime\Application;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\MadelineTransport;
use Shabbat\Runtime\Runner;
use Shabbat\Storage\Database;
use Shabbat\Telegram\PrivateChatUnavailableException;
use Shabbat\Telegram\RetryableTelegramException;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\Lang;

final class PrivateChatUnavailableTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }

    protected function setUp(): void
    {
        (new ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
    }

    private function rpc(string $name): RPCErrorException
    {
        $error = (new ReflectionClass(RPCErrorException::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(RPCErrorException::class, 'rpc'))->setValue($error, $name);
        return $error;
    }

    private function transport(\Throwable $error): MadelineTransport
    {
        return new class ($error) implements MadelineTransport {
            public array $calls = [];
            private GatewayFixtureTransport $base;
            public function __construct(private readonly \Throwable $error)
            {
                $this->base = new GatewayFixtureTransport();
                $this->base->responses['channels.getParticipant'] = ['participant' => ['_' => 'channelParticipantAdmin']];
            }
            public function call(string $method, array $parameters): mixed
            {
                $this->calls[] = [$method, $parameters];
                if ($method === 'messages.sendMessage') {
                    throw $this->error;
                }
                return $this->base->call($method, $parameters);
            }
            public function info(string $peer): array
            {
                return $this->base->info($peer);
            }
            public function fullInfo(string $peer): array
            {
                return $this->base->fullInfo($peer);
            }
            public function fileInfo(string $fileId): array
            {
                return $this->base->fileInfo($fileId);
            }
            public function self(): array
            {
                return $this->base->self();
            }
        };
    }

    private function app(MadelineTransport $transport): Application
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-24T20:00:00+03:00'));
        $http = new class implements \Shabbat\Http\JsonClient {
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('Remote providers are forbidden in private-chat fixtures.');
            }
        };
        $calendar = new \Shabbat\Calendar\CalendarService(new \Shabbat\Calendar\HebcalProvider($http), $clock);
        $app = new Application(new MadelineGateway($transport, false), $clock, $db, $calendar, new \Shabbat\Location\GeoNames($http, $clock, ''));
        $app->settings->ensureGroup('-1001', 'Private settings must stay private', ['language' => 'en']);
        return $app;
    }

    private function bridge(Application $app, array $event, callable $reply): void
    {
        (new ReflectionMethod(CallbackRoutingFixture::class, 'v3HandleUiMessage'))->invoke(new CallbackRoutingFixture($app), $event, $reply);
    }

    private function sendCount(MadelineTransport $transport): int
    {
        return count(array_filter($transport->calls, static fn(array $call): bool => $call[0] === 'messages.sendMessage'));
    }

    public function testGroupSettingsDoesNotSendSecondPrivateError(): void
    {
        $original = $this->rpc('PEER_ID_INVALID');
        $transport = $this->transport($original);
        $app = $this->app($transport);
        try {
            $app->ui->handleMessage(['user_id' => '1', 'group_id' => '-1001', 'private' => false, 'text' => '/settings']);
            self::fail('The private-chat boundary must reach the message bridge.');
        } catch (PrivateChatUnavailableException $error) {
            self::assertSame('1', $error->userId);
            self::assertSame($original, $error->getPrevious());
        }
        self::assertSame(1, $this->sendCount($transport));
    }

    public function testGroupBridgeOffersOneLocalizedStartLinkWithoutExposingSettings(): void
    {
        foreach (Lang::CODES as $language) {
            $transport = $this->transport($this->rpc('PEER_ID_INVALID'));
            $app = $this->app($transport);
            $group = $app->settings->getGroup('-1001');
            $app->settings->updateGroup('-1001', $group['revision'], ['language' => $language], '1');
            $replies = [];
            $this->bridge($app, ['user_id' => '1', 'group_id' => '-1001', 'private' => false, 'text' => '/settings'], static function (string $text, array $markup) use (&$replies): void {
                $replies[] = compact('text', 'markup');
            });
            self::assertSame(1, $this->sendCount($transport));
            self::assertCount(1, $replies);
            $lang = new Lang();
            self::assertSame($lang->text($language, 'not_private'), $replies[0]['text']);
            self::assertSame([['text' => $lang->text($language, 'select_private'), 'url' => 'https://t.me/FixtureShabbatBot?start=g_-1001']], $replies[0]['markup']['inline_keyboard'][0]);
            self::assertStringNotContainsString('Private settings must stay private', $replies[0]['text']);
        }
    }

    public function testPrivateAndInvalidGroupContextsDoNotSendPublicFallback(): void
    {
        foreach (
            [
            ['user_id' => '1', 'private' => true, 'text' => '/preferences'],
            ['user_id' => '1', 'private' => false, 'group_id' => '123', 'text' => '/settings'],
            ['user_id' => '1', 'private' => false, 'text' => '/settings'],
            ] as $event
        ) {
            $transport = $this->transport($this->rpc('PEER_ID_INVALID'));
            $this->bridge($this->app($transport), $event, static fn() => self::fail('No valid public group context exists.'));
            self::assertSame(1, $this->sendCount($transport));
        }
    }

    public function testFailedGroupFallbackPropagatesWithoutAnotherPrivateReply(): void
    {
        $transport = $this->transport($this->rpc('PEER_ID_INVALID'));
        $app = $this->app($transport);
        $failure = new \RuntimeException('Group reply failed');
        try {
            $this->bridge($app, ['user_id' => '1', 'group_id' => '-1001', 'private' => false, 'text' => '/settings'], static fn() => throw $failure);
            self::fail('A group reply failure must remain visible.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertSame(1, $this->sendCount($transport));
    }

    public function testGroupSendWithSameRpcIsNotReclassified(): void
    {
        $original = $this->rpc('PEER_ID_INVALID');
        $transport = $this->transport($original);
        try {
            (new MadelineGateway($transport, false))->send('-1001', ['text' => 'Group send'], '123');
            self::fail('A group send must preserve its original RPC failure.');
        } catch (RPCErrorException $error) {
            self::assertSame($original, $error);
        }
        self::assertSame(1, $this->sendCount($transport));
    }

    public function testUnrelatedRpcAndTextLookalikeExceptionsRemainUnchanged(): void
    {
        foreach ([$this->rpc('REPLY_MARKUP_INVALID'), new \RuntimeException('PEER_ID_INVALID'), new CancelledException()] as $original) {
            $transport = $this->transport($original);
            try {
                (new MadelineGateway($transport, false))->sendPrivate('1', 'Private text');
                self::fail('An unrelated error must propagate.');
            } catch (\Throwable $error) {
                self::assertSame($original, $error);
            }
            self::assertSame(1, $this->sendCount($transport));
        }
    }

    public function testTimeoutAndFloodKeepExistingRetryTranslation(): void
    {
        foreach (
            [
            [new TimeoutException('Timeout'), 30],
            [new CancelledException(new TimeoutException('Timeout')), 30],
            [new \danog\MadelineProto\RPCError\FloodWaitError('FLOOD_WAIT_12', 12, 420, 'messages.sendMessage'), 12],
            ] as [$original, $wait]
        ) {
            $transport = $this->transport($original);
            try {
                (new MadelineGateway($transport, false))->sendPrivate('1', 'Private text');
                self::fail('A transient error must retain its retry contract.');
            } catch (RetryableTelegramException $error) {
                self::assertSame($original, $error->getPrevious());
                if ($wait === 30) {
                    self::assertSame($wait, $error->retryAfterSeconds);
                } else {
                    self::assertGreaterThanOrEqual(1, $error->retryAfterSeconds);
                    self::assertLessThanOrEqual($wait, $error->retryAfterSeconds);
                }
            }
            self::assertSame(1, $this->sendCount($transport));
        }
    }
}
