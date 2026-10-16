<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\CancelledException;
use Amp\TimeoutException;
use danog\MadelineProto\RPCError\FloodPremiumWaitError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\Runner;
use Shabbat\Telegram\AdminRights;
use Shabbat\Telegram\RetryableTelegramException;

final class GatewayMaintenanceTest extends TestCase
{
    private bool $previousStopping;

    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(Runner::class, 'stopping');
        $this->previousStopping = $property->getValue();
        $property->setValue(null, false);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Runner::class, 'stopping'))->setValue(null, $this->previousStopping);
    }

    public function testSupergroupDeleteUsesNativeChannelAndIdempotentAck(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['channels.deleteMessages'] = ['_' => 'messages.affectedMessages', 'pts' => 0, 'pts_count' => 0];
        (new MadelineGateway($transport))->deleteMessages('-100123', [11, 12, 11]);
        self::assertSame([['channels.deleteMessages', ['channel' => '-100123', 'id' => [11, 12], 'floodWaitLimit' => 0]]], $transport->calls);
    }

    public function testBasicChatDeleteUsesRevokeAndGlobalNativeIds(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->peerInfo['type'] = 'chat';
        $transport->responses['messages.deleteMessages'] = ['_' => 'messages.affectedMessages', 'pts' => 51, 'pts_count' => 2];
        (new MadelineGateway($transport))->deleteMessages('-123', [11, 12]);
        self::assertSame([['messages.deleteMessages', ['revoke' => true, 'id' => [11, 12], 'floodWaitLimit' => 0]]], $transport->calls);
    }

    public static function invalidIds(): array
    {
        return [[[0]], [[-1]], [['11']], [[11.0]], [[2147483648]], [range(1, 101)], [[2 => 11]]];
    }

    #[DataProvider('invalidIds')]
    public function testInvalidIdsCannotStartDeletionRpc(array $ids): void
    {
        $transport = new GatewayFixtureTransport();
        try {
            (new MadelineGateway($transport))->deleteMessages('-100123', $ids);
            self::fail('Invalid message IDs accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->calls);
        }
    }

    public static function failedAcknowledgements(): array
    {
        return [[false], [[]], [['_' => 'updates']], [['_' => 'messages.affectedMessages', 'pts' => 1, 'pts_count' => -1]], [['_' => 'messages.affectedMessages', 'pts' => '1', 'pts_count' => 0]]];
    }

    #[DataProvider('failedAcknowledgements')]
    public function testUnconfirmedDeletionFailsExplicitly(mixed $response): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['channels.deleteMessages'] = $response;
        $this->expectException(\RuntimeException::class);
        (new MadelineGateway($transport))->deleteMessages('-100123', [11]);
    }

    public function testUnknownTargetAndEmptyDeletionNeverMutate(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->peerInfo['type'] = 'user';
        $gateway = new MadelineGateway($transport);
        $gateway->deleteMessages('777', []);
        try {
            $gateway->deleteMessages('777', [11]);
            self::fail('Private peer accepted as group');
        } catch (\RuntimeException) {
            self::assertSame([], $transport->calls);
        }
    }

    public function testShutdownDuringPeerLookupDoesNotStartDelete(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->onInfo = Runner::requestShutdown(...);
        try {
            (new MadelineGateway($transport))->deleteMessages('-100123', [11]);
            self::fail('Delete started after shutdown');
        } catch (CancelledException) {
            self::assertSame([], $transport->calls);
        }
    }

    public function testRateLimitsAndTimeoutsRetainTypedRetryDeadlines(): void
    {
        foreach ([new FloodPremiumWaitError('FLOOD_PREMIUM_WAIT_12', 12, 420, 'channels.deleteMessages'), new TimeoutException()] as $native) {
            $transport = new GatewayFixtureTransport();
            $transport->error = $native;
            try {
                (new MadelineGateway($transport))->deleteMessages('-100123', [11]);
                self::fail('Retry deadline lost');
            } catch (RetryableTelegramException $error) {
                self::assertSame($native, $error->getPrevious());
                self::assertGreaterThanOrEqual(1, $error->retryAfterSeconds);
                self::assertLessThanOrEqual(30, $error->retryAfterSeconds);
            }
        }
    }

    public function testIntentionalCancellationPropagates(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->error = new CancelledException();
        $this->expectException(CancelledException::class);
        (new MadelineGateway($transport))->deleteMessages('-100123', [11]);
    }

    public function testSenderBotFlagUsesVerifiedUserAndUnknownNeverMeansHuman(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->peerInfos['888'] = ['type' => 'bot', 'User' => ['_' => 'user', 'id' => 888, 'bot' => true]];
        $transport->peerInfos['999'] = ['type' => 'user', 'User' => ['_' => 'user', 'id' => 999]];
        $gateway = new MadelineGateway($transport);
        self::assertTrue($gateway->senderIsBot('-100123', '888'));
        self::assertFalse($gateway->senderIsBot('-100123', '999'));
        foreach ([['_' => 'user', 'id' => 888, 'min' => true], ['_' => 'userEmpty', 'id' => 888], ['_' => 'user', 'id' => 999, 'bot' => true], ['_' => 'user', 'id' => 888, 'bot' => 'true'], ['_' => 'user', 'id' => 888, 'bot' => false], ['_' => 'user', 'id' => 888, 'min' => 'false', 'bot' => true]] as $unknown) {
            $transport->peerInfos['888']['User'] = $unknown;
            self::assertNull($gateway->senderIsBot('-100123', '888'));
        }
        self::assertNull($gateway->senderIsBot('-100123', '-100456'));
        self::assertNull($gateway->senderIsBot('-100123', '0'));
        $transport->infoError = new \RuntimeException('Unknown peer');
        self::assertNull($gateway->senderIsBot('-100123', '888'));
    }

    public function testSenderLookupQuotaAndCancellationPropagate(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->infoError = new FloodPremiumWaitError('FLOOD_PREMIUM_WAIT_12', 12, 420, 'contacts.resolveUsername');
        try {
            (new MadelineGateway($transport))->senderIsBot('-100123', '888');
            self::fail('Sender quota guessed as human');
        } catch (RetryableTelegramException) {
            self::assertSame([], $transport->calls);
        }
        $transport->infoError = new CancelledException();
        $this->expectException(CancelledException::class);
        (new MadelineGateway($transport))->senderIsBot('-100123', '888');
    }

    public function testCurrentCapabilitiesExposeAllNativeFlagsWithoutPromoting(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['getFullInfo'] = ['type' => 'supergroup', 'Chat' => ['_' => 'channel', 'id' => 123, 'admin_rights' => ['_' => 'chatAdminRights', 'delete_messages' => true, 'ban_users' => true]]];
        $gateway = new MadelineGateway($transport);
        $capabilities = $gateway->botCapabilities('-100123');
        self::assertSame(AdminRights::FLAGS, array_keys($capabilities['rights']));
        self::assertTrue($capabilities['can_delete_messages']);
        self::assertTrue($capabilities['rights']['ban_users']);
        self::assertFalse($capabilities['rights']['add_admins']);
        $transport->responses['getFullInfo']['Chat']['admin_rights']['delete_messages'] = false;
        self::assertFalse($gateway->botCanDeleteMessages('-100123'));
        self::assertSame(['getFullInfo', 'getFullInfo'], array_column($transport->calls, 0));
    }

    public function testCreatorDeletionIsEffectiveWithoutInventingNativeFlags(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['getFullInfo'] = ['type' => 'supergroup', 'Chat' => ['_' => 'channel', 'id' => 123, 'creator' => true]];
        $capabilities = (new MadelineGateway($transport))->botCapabilities('-100123');
        self::assertTrue($capabilities['creator']);
        self::assertTrue($capabilities['can_delete_messages']);
        self::assertSame(array_fill_keys(AdminRights::FLAGS, false), $capabilities['rights']);
    }

    public function testMalformedCapabilitiesFailClosed(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['getFullInfo'] = ['type' => 'supergroup', 'Chat' => ['_' => 'channel', 'id' => 999, 'admin_rights' => ['_' => 'chatAdminRights', 'delete_messages' => true]]];
        $this->expectException(\RuntimeException::class);
        (new MadelineGateway($transport))->botCapabilities('-100123');
    }

    public function testSelfIdentityComesFromVerifiedSelf(): void
    {
        self::assertSame('777', (new MadelineGateway(new GatewayFixtureTransport()))->selfUserId());
    }
}
