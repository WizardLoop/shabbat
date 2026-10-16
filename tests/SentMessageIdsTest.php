<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Telegram\SentMessageIds;

final class SentMessageIdsTest extends TestCase
{
    public function testAuthoritativeMappingWinsOverUnrelatedMessageUpdates(): void
    {
        $response = ['_' => 'updatesCombined', 'updates' => [
            ['_' => 'updateMessageID', 'id' => 11, 'random_id' => 42],
            ['_' => 'updateMessageID', 'id' => 11, 'random_id' => 42],
            ['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 999, 'out' => false]],
            ['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 998, 'out' => true]],
        ]];
        self::assertSame([11], SentMessageIds::extract($response));
    }

    public function testKnownJobMappingRejectsUnrelatedConcurrentAcknowledgements(): void
    {
        $response = ['_' => 'updates', 'updates' => [
            ['_' => 'updateMessageID', 'id' => 10, 'random_id' => 41],
            ['_' => 'updateMessageID', 'id' => 11, 'random_id' => 42],
            ['_' => 'updateNewChannelMessage', 'message' => ['_' => 'message', 'id' => 10, 'out' => true, 'peer_id' => -100123]],
        ]];
        self::assertSame([11], SentMessageIds::extract($response, '42', '-100123'));
        self::assertSame([], SentMessageIds::extract($response, '43', '-100123'));
    }

    public function testPackedWireLongMatchesTheCanonicalJobId(): void
    {
        $tl = (new \ReflectionClass(\danog\MadelineProto\TL\TL::class))->newInstanceWithoutConstructor();
        $native = $tl->serializeObject(['type' => 'long'], 12345678, 'random_id');
        self::assertSame(pack('P', 12345678), $native);
        $response = ['_' => 'updates', 'updates' => [['_' => 'updateMessageID', 'id' => 11, 'random_id' => $native]]];
        self::assertSame([11], SentMessageIds::extract($response, '12345678', '-100123'));
        self::assertSame([], SentMessageIds::extract($response, '12345679', '-100123'));
        $response['updates'][0]['random_id'] = '12345678';
        self::assertSame([], SentMessageIds::extract($response, '12345678', '-100123'));
    }

    public static function contextualPeers(): array
    {
        return [
            [-100123, '-100123', [11]],
            [-100124, '-100123', []],
            [null, '-100123', []],
            ['-100123', '-100123', []],
            [['_' => 'peerChat','chat_id' => 123], '-123', [11]],
            [['_' => 'peerChat','chat_id' => 124], '-123', []],
            [['_' => 'peerChannel','channel_id' => 123], '-1000000000123', [11]],
            [['_' => 'peerChannel','channel_id' => 124], '-1000000000123', []],
            [['_' => 'peerUser','user_id' => 123], '123', [11]],
            [['_' => 'peerUser','user_id' => '123'], '123', []],
            [['_' => 'inputPeerChannel','channel_id' => 123], '-1000000000123', []],
        ];
    }

    #[DataProvider('contextualPeers')]
    public function testOutgoingFallbackRequiresExactNativePeer(mixed $nativePeer, string $expectedPeer, array $ids): void
    {
        $response = ['_' => 'updates', 'updates' => [['_' => 'updateNewChannelMessage', 'message' => [
            '_' => 'message', 'id' => 11, 'out' => true, 'peer_id' => $nativePeer,
        ]]]];
        self::assertSame($ids, SentMessageIds::extract($response, '42', $expectedPeer));
    }

    public function testTrustedShortAcknowledgementDoesNotInventPeerMetadata(): void
    {
        self::assertSame([11], SentMessageIds::extract(['_' => 'updateShortSentMessage', 'id' => 11], '42', '-100123'));
        self::assertSame([], SentMessageIds::extract(['_' => 'updateShortSentMessage', 'id' => 11], '0', '-100123'));
        self::assertSame([], SentMessageIds::extract(['_' => 'updateShortSentMessage', 'id' => 11], '42', '0'));
        self::assertSame([], SentMessageIds::extract(['_' => 'updateShortSentMessage', 'id' => 11], 'not-an-id', '-100123'));
    }
    public static function nativeResponses(): array
    {
        return [
            [['_' => 'updateShortSentMessage', 'id' => 11], [11]],
            [['_' => 'updateShort', 'update' => ['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 12, 'out' => true]]], [12]],
            [['_' => 'updates', 'updates' => [['_' => 'updateNewChannelMessage', 'message' => ['_' => 'message', 'id' => 13, 'out' => true]]]], [13]],
            [['_' => 'updates', 'updates' => [['_' => 'updateNewChannelMessage', 'message' => ['_' => 'messageService', 'id' => 13, 'out' => true]]]], []],
            [['_' => 'updates', 'updates' => [['_' => 'updateMessageID', 'id' => 14, 'random_id' => "12345678"]]], [14]],
            [['_' => 'updates', 'updates' => [['_' => 'updateMessageID', 'id' => 14, 'random_id' => []]]], []],
            [['id' => 11], []],
            [['_' => 'updateShortSentMessage', 'id' => '11'], []],
            [['_' => 'updateShortSentMessage', 'id' => 0], []],
            [['_' => 'updateShortSentMessage', 'id' => 2147483648], []],
            [['_' => 'updatesTooLong'], []],
        ];
    }

    #[DataProvider('nativeResponses')]
    public function testSupportedNativeShapesAndUnknownResponses(array $response, array $ids): void
    {
        self::assertSame($ids, SentMessageIds::extract($response));
    }
}
