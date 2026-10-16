<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService,HebcalProvider};
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\{Application,PrivatePeer};
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;

final class GpsBridgeTest extends TestCase
{
    private const SELF = 9000;
    private Application $app;
    private ReplyScopeFixture $bridge;
    private GpsBridgeFakeGateway $gateway;
    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-13T12:00:00Z'));
        $http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('No provider network in native GPS tests.');
            }
        };
        $this->gateway = new GpsBridgeFakeGateway();
        $this->app = new Application($this->gateway, $clock, $db, new CalendarService(new HebcalProvider($http), $clock), new GeoNames($http, $clock, ''));
        $this->app->settings->ensureGroup('-1001', 'Group One');
        $this->app->settings->ensureGroup('-1002', 'Group Two');
        $this->app->settings->getUser('42');
        $this->bridge = ReplyScopeFixture::create($this->app);
    }
    private function arm(string $group, string $zone): string
    {
        $g = $this->app->settings->getGroup($group);
        $parent = $this->app->flows->create('42', 'group', $group, $g['revision'], 'location', 600);
        $this->app->ui->handleMessage(['user_id' => '42','private' => true,'text' => '/location ' . $parent . ' ' . $zone]);
        return $this->last()['text'];
    }
    private function last(): array
    {
        return $this->gateway->messages[array_key_last($this->gateway->messages)];
    }
    private function packet(int $user = 42, ?int $peer = null, float $latitude = 31.778, float $longitude = 35.235): array
    {
        return ['_' => 'updateNewMessage','message' => ['_' => 'message','id' => 222,'out' => false,'from_id' => $user,'peer_id' => $peer ?? self::SELF,'reply_to' => ['_' => 'messageReplyHeader','reply_to_msg_id' => 111],'media' => ['_' => 'messageMediaGeo','geo' => ['_' => 'geoPoint','lat' => $latitude,'long' => $longitude]]]];
    }
    private function reply(string $text, int $user = 42): array
    {
        return ['_' => 'message','id' => 111,'from_id' => self::SELF,'peer_id' => $user,'out' => true,'message' => $text];
    }
    private function accept(array $packet, array $reply): bool
    {
        return $this->bridge->rawLocation($packet, self::SELF, static fn(int $id): array=>['messages' => [$reply]]);
    }
    private function save(): void
    {
        $this->app->ui->handleCallback(['user_id' => '42','private' => true,'query_id' => 'fixture','message_id' => 123,'data' => $this->last()['markup']['rows'][0]['buttons'][0]['data']]);
    }
    public function testActualNativeIntegerBridgePreservesFloatCoordinatesAndRequiresPrivateConfirmation(): void
    {
        $prompt = $this->arm('-1001', 'Asia/Jerusalem');
        $before = $this->app->settings->getGroup('-1001');
        $personal = $this->app->settings->getUser('42');
        self::assertTrue($this->accept($this->packet(), $this->reply($prompt)));
        self::assertStringContainsString('Group One', $this->last()['text']);
        self::assertStringContainsString('31.778', $this->last()['text']);
        self::assertStringContainsString('35.235', $this->last()['text']);
        self::assertSame($before['revision'], $this->app->settings->getGroup('-1001')['revision']);
        $this->save();
        $after = $this->app->settings->getGroup('-1001');
        self::assertSame(31.778, $after['latitude']);
        self::assertSame(35.235, $after['longitude']);
        self::assertSame('Asia/Jerusalem', $after['timezone_id']);
        self::assertSame($personal['latitude'], $this->app->settings->getUser('42')['latitude']);
    }
    public function testTwoNativePrivatePeerShapesKeepReversedGpsFlowsBoundToTheirGroups(): void
    {
        $one = $this->arm('-1001', 'Asia/Jerusalem');
        $two = $this->arm('-1002', 'Europe/London');
        self::assertTrue($this->accept($this->packet(42, 42, 51.5085, -0.1257), $this->reply($two)));
        self::assertStringContainsString('Group Two', $this->last()['text']);
        $this->save();
        self::assertTrue($this->accept($this->packet(), $this->reply($one)));
        self::assertStringContainsString('Group One', $this->last()['text']);
        $this->save();
        self::assertSame(51.5085, $this->app->settings->getGroup('-1002')['latitude']);
        self::assertSame('Europe/London', $this->app->settings->getGroup('-1002')['timezone_id']);
        self::assertSame(31.778, $this->app->settings->getGroup('-1001')['latitude']);
    }
    public function testGroupsOutgoingSelfAndMismatchedRecipientsNeverLookupRepliesOrEnterGpsFlow(): void
    {
        $calls = 0;
        $lookup = static function (int $id) use (&$calls): array {
            $calls++;
            return ['messages' => []];
        };
        foreach ([['peer_id' => -1001],['peer_id' => 99],['out' => true],['from_id' => self::SELF],['from_id' => 0],['from_id' => '42'],['peer_id' => ['_' => 'peerUser','user_id' => 42]],['from_id' => -1001]] as $bad) {
            $packet = $this->packet();
            $packet['message'] = array_replace($packet['message'], $bad);
            self::assertTrue($this->bridge->rawLocation($packet, self::SELF, $lookup));
        }
        self::assertSame(0, $calls);
        self::assertSame([], $this->gateway->messages);
        self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
        $ordinary = $this->packet();
        unset($ordinary['message']['media']);
        self::assertFalse($this->bridge->rawLocation($ordinary, self::SELF, $lookup));
    }
    public function testReplySenderRecipientIdAndForwardedPromptMustMatchThePrivateBotDialog(): void
    {
        $prompt = $this->arm('-1001', 'Asia/Jerusalem');
        $valid = $this->reply($prompt);
        foreach ([['from_id' => 42,'fwd_from' => ['from_id' => self::SELF]],['from_id' => 999],['peer_id' => 99],['peer_id' => -1001],['peer_id' => self::SELF],['id' => 112],['message' => ['invalid']],['from_id' => ['_' => 'peerUser','user_id' => self::SELF]]] as $bad) {
            self::assertTrue($this->accept($this->packet(), array_replace($valid, $bad)));
            self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
            self::assertSame([], $this->last()['markup']);
        }
    }
    public function testValidBotReplyDoesNotBypassFlowOwnerOrFreshGroupAdminPermission(): void
    {
        $prompt = $this->arm('-1001', 'Asia/Jerusalem');
        self::assertTrue($this->accept($this->packet(99), $this->reply($prompt, 99)));
        self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
        self::assertSame([], $this->last()['markup']);
        $this->gateway->admin = false;
        self::assertTrue($this->accept($this->packet(), $this->reply($prompt)));
        self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
        self::assertSame([], $this->last()['markup']);
    }
    public function testNativeBotReplyAndPeerHelperRequirePositiveIntegerAuthenticatedIds(): void
    {
        self::assertSame(42, PrivatePeer::sender($this->packet()['message'], self::SELF));
        self::assertSame(42, PrivatePeer::sender($this->packet(42, 42)['message'], self::SELF));
        self::assertNull(PrivatePeer::sender($this->packet()['message'], 0));
        self::assertFalse(PrivatePeer::botReply($this->reply('GPS token'), self::SELF, 42, 0));
        self::assertTrue(PrivatePeer::botReply($this->reply('GPS token'), self::SELF, 42, 111));
    }
}
