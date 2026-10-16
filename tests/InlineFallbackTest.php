<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use DateTimeImmutable;
use danog\MadelineProto\RPCErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\MadelineTransport;
use Shabbat\Storage\Database;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\BotController;

final class InlineFallbackTest extends TestCase
{
    public static function fallbackOutcomes(): array
    {
        return [['success'], ['failure']];
    }

    #[DataProvider('fallbackOutcomes')]
    public function testRejectedResultIsReplacedWithOneButtonlessErrorAndNoRetryLoop(string $outcome): void
    {
        $error = (new ReflectionClass(RPCErrorException::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(RPCErrorException::class, 'rpc'))->setValue($error, 'REPLY_MARKUP_INVALID');
        (new ReflectionProperty(\Exception::class, 'code'))->setValue($error, 400);
        $fixture = new GatewayFixtureTransport();
        $fixture->responses['channels.getParticipant'] = ['participant' => ['_' => 'channelParticipantAdmin']];
        $transport = new class ($fixture, $error, $outcome) implements MadelineTransport {
            public array $inline = [];
            public function __construct(
                private GatewayFixtureTransport $fixture,
                private RPCErrorException $error,
                private string $outcome
            ) {
            }
            public function call(string $method, array $parameters): mixed
            {
                if ($method === 'messages.setInlineBotResults') {
                    $this->inline[] = $parameters;
                    if (count($this->inline) === 1 || $this->outcome === 'failure') {
                        throw $this->error;
                    }
                    return true;
                }
                return $this->fixture->call($method, $parameters);
            }
            public function info(string $peer): array
            {
                return $this->fixture->info($peer);
            }
            public function fullInfo(string $peer): array
            {
                return $this->fixture->fullInfo($peer);
            }
            public function fileInfo(string $fileId): array
            {
                return $this->fixture->fileInfo($fileId);
            }
            public function self(): array
            {
                return $this->fixture->self();
            }
        };
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-14T12:00:00Z'));
        $db = Database::open(':memory:');
        $db->migrate();
        $settings = new SettingsRepository($db, $clock);
        $flows = new FlowTokenRepository($db, $clock);
        $settings->ensureGroup('-1001');
        $http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                return ['totalResultsCount' => 1, 'geonames' => [[
                    'geonameId' => 281184, 'name' => 'Jerusalem', 'lat' => '31.76904', 'lng' => '35.21633',
                ]]];
            }
        };
        $ui = new BotController(
            $settings,
            $flows,
            new CalendarService(new HebcalProvider($http), $clock),
            new MadelineGateway($transport),
            new GeoNames($http, $clock, 'offline-fixture'),
            $clock
        );
        $token = $flows->create('1', 'group', '-1001', 1, 'location');
        $failure = null;
        try {
            self::assertTrue($ui->handleInline([
                'user_id' => '1', 'query_id' => '123', 'query' => 'loc ' . $token . ' Jerusalem',
            ]));
        } catch (RPCErrorException $caught) {
            $failure = $caught;
        }
        self::assertCount(2, $transport->inline);
        self::assertNotEmpty($transport->inline[0]['results'][0]['send_message']['reply_markup']['rows']);
        $fallback = $transport->inline[1]['results'];
        self::assertCount(1, $fallback);
        self::assertSame('location-unavailable', $fallback[0]['id']);
        self::assertArrayNotHasKey('reply_markup', $fallback[0]['send_message']);
        self::assertSame(1, $settings->getGroup('-1001')['revision']);
        if ($outcome === 'success') {
            self::assertNull($failure);
        } else {
            self::assertSame($error, $failure);
            self::assertSame(400, $failure->getCode());
        }
    }
}
