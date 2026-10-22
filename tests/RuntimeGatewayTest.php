<?php

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp;
use danog;
use DateTimeImmutable;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
// SPDX-License-Identifier: AGPL-3.0-only
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\MadelineTransport;
use Shabbat\Telegram\RetryableTelegramException;

final class RuntimeGatewayTest extends TestCase
{
    public function testPermissionsUseFreshRpcRatherThanCachedInfo(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->peerInfo['Chat']['default_banned_rights'] = ['_' => 'chatBannedRights', 'until_date' => 0, 'send_messages' => true];
        $current = ['_' => 'chatBannedRights', 'until_date' => 0, 'send_messages' => false];
        $transport->responses['getFullInfo'] = FreshPeerFixture::info('-100123', $current);
        self::assertSame($current, (new MadelineGateway($transport))->getPermissions('-100123'));
        self::assertSame('getFullInfo', $transport->calls[0][0]);
    }

    public function testBasicChatPermissionsAndAdministratorLookup(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->peerInfo['type'] = 'chat';
        $transport->responses['getFullInfo'] = FreshPeerFixture::info('-123', null, 'chat');
        $transport->responses['getFullInfo']['full']['participants']['participants'] = [['_' => 'chatParticipantAdmin', 'user_id' => 7]];
        $gateway = new MadelineGateway($transport);
        self::assertTrue($gateway->isGroupAdmin('7', '-123'));
        self::assertFalse($gateway->isGroupAdmin('8', '-123'));
        self::assertSame(['_' => 'chatBannedRights', 'until_date' => 0], $gateway->getPermissions('-123'));
    }

    public function testMissingCurrentChatFailsClosed(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['getFullInfo'] = ['Chat' => ['_' => 'channel','id' => 999]];
        $this->expectException(RuntimeException::class);
        (new MadelineGateway($transport))->getPermissions('-100123');
    }

    public function testAdminAuthorizationIsCheckedAgainByRpc(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['channels.getParticipant'] = ['participant' => ['_' => 'channelParticipantAdmin']];
        $gateway = new MadelineGateway($transport);
        self::assertTrue($gateway->isGroupAdmin('7', '-100123'));
        $transport->responses['channels.getParticipant'] = ['participant' => ['_' => 'channelParticipant']];
        self::assertFalse($gateway->isGroupAdmin('7', '-100123'));
        self::assertCount(2, $transport->calls);
    }

    public function testMediaEntitiesMarkupAndStableRandomIdArePreserved(): void
    {
        $transport = new GatewayFixtureTransport();
        $gateway = new MadelineGateway($transport);
        $payload = ['text' => 'fixture', 'media' => 'fixture-file-id', 'entities' => [['_' => 'messageEntityBold','offset' => 0,'length' => 7]], 'buttons' => [[['text' => 'Button','callback_data' => 'fixture:callback','style' => 'primary']]]];
        $gateway->send('-100123', $payload, '91234567');
        $gateway->send('-100123', $payload, '91234567');
        [$method, $args] = $transport->calls[0];
        self::assertSame('messages.sendMedia', $method);
        self::assertSame($transport->mediaInfo['InputMedia'], $args['media']);
        self::assertSame($payload['entities'], $args['entities']);
        self::assertArrayNotHasKey('parse_mode', $args);
        self::assertSame(91234567, $args['random_id']);
        self::assertSame($args['random_id'], $transport->calls[1][1]['random_id']);
        self::assertSame(['_' => 'keyboardButtonStyle','bg_primary' => true], $args['reply_markup']['rows'][0]['buttons'][0]['style']);
        self::assertSame(0, $args['floodWaitLimit']);
        self::assertArrayNotHasKey('allow_paid_stars', $args);
        self::assertArrayNotHasKey('no_webpage', $args);
    }

    public function testInlineSwitchUsesMtprotoSamePeer(): void
    {
        $markup = MadelineGateway::normalizeMarkup(['inline_keyboard' => [[['text' => 'Search','switch_inline_query_current_chat' => 'loc fixturetoken ']]]]);
        self::assertSame('keyboardInlineButton', $markup['rows'][0]['buttons'][0]['_']);
        self::assertSame('inlineButtonTypeSwitchInline', $markup['rows'][0]['buttons'][0]['type']['_']);
        self::assertTrue($markup['rows'][0]['buttons'][0]['type']['same_peer']);
        self::assertSame('loc fixturetoken ', $markup['rows'][0]['buttons'][0]['type']['query']);
    }

    public function testClosedTopicNeverFallsBackToGeneral(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['messages.getForumTopicsByID'] = ['topics' => [['id' => 7,'closed' => true]]];
        try {
            (new MadelineGateway($transport))->send('-100123', ['text' => 'fixture','topic_id' => 7], '42');
            self::fail('Closed topic sent');
        } catch (RuntimeException) {
            self::assertCount(1, $transport->calls);
        }
    }

    public function testOpenTopicUsesTheAvailableNativeMessagesApi(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['messages.getForumTopicsByID'] = ['topics' => [['id' => 7, 'closed' => false]]];
        (new MadelineGateway($transport))->send('-100123', ['text' => 'fixture', 'topic_id' => 7], '42');
        self::assertSame('messages.getForumTopicsByID', $transport->calls[0][0]);
        self::assertSame('-100123', $transport->calls[0][1]['peer']);
        self::assertSame([7], $transport->calls[0][1]['topics']);
        self::assertSame('messages.sendMessage', $transport->calls[1][0]);
        self::assertSame(7, $transport->calls[1][1]['reply_to']['top_msg_id']);
    }

    public function testFreshHelperFloodWaitIsReturnedToScheduler(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->error = new danog\MadelineProto\RPCError\FloodWaitError('FLOOD_WAIT_12', 12, 420, 'channels.getFullChannel');
        $this->expectException(RetryableTelegramException::class);
        (new MadelineGateway($transport))->getPermissions('-100123');
    }

    public function testFloodWaitIsReturnedToScheduler(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->error = new danog\MadelineProto\RPCError\FloodWaitError('FLOOD_WAIT_12', 12, 420, 'messages.sendMessage');
        try {
            (new MadelineGateway($transport))->send('-100123', ['text' => 'fixture'], '42');
            self::fail('Flood wait ignored');
        } catch (RetryableTelegramException $error) {
            self::assertGreaterThan(0, $error->retryAfterSeconds);
            self::assertLessThanOrEqual(12, $error->retryAfterSeconds);
            self::assertCount(1, $transport->calls);
        }
    }

    public function testAdministratorLookupPreservesIntentionalCancellation(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->error = new Amp\CancelledException();
        $this->expectException(Amp\CancelledException::class);
        (new MadelineGateway($transport))->isGroupAdmin('7', '-100123');
    }

    public function testAllNativeRateLimitFamiliesReturnTheRemainingDeadline(): void
    {
        foreach ([danog\MadelineProto\RPCError\RateLimitError::class, danog\MadelineProto\RPCError\FloodPremiumWaitError::class] as $class) {
            $transport = new GatewayFixtureTransport();
            $native = new $class('FLOOD_PREMIUM_WAIT_12', 12, 420, 'messages.sendMessage');
            $transport->error = $native;
            try {
                (new MadelineGateway($transport))->send('-100123', ['text' => 'fixture'], '42');
                self::fail('Native rate limit escaped deadline conversion');
            } catch (RetryableTelegramException $error) {
                self::assertSame($native, $error->getPrevious());
                self::assertGreaterThanOrEqual(1, $error->retryAfterSeconds);
                self::assertLessThanOrEqual(12, $error->retryAfterSeconds);
                self::assertCount(1, $transport->calls);
            }
        }
    }

    public function testFalsePermissionAcknowledgementFails(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['messages.editChatDefaultBannedRights'] = false;
        $this->expectException(RuntimeException::class);
        (new MadelineGateway($transport))->setPermissions('-100123', ['_' => 'chatBannedRights','until_date' => 0]);
    }
}
