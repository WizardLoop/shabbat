<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\Settings;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\FreshPeerReader;
use Shabbat\Runtime\Runner;

final class FreshPeerReaderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Initialize the SDK logger before PHPUnit snapshots global handlers.
        Runner::settings();
    }

    public function testOnlyTheRawImmediateReplyIsUsedEvenWhenBothCacheRecordsAreStale(): void
    {
        $harness = new NativePeerReadHarness();
        $harness->cached['default_banned_rights'] = ['_' => 'chatBannedRights', 'until_date' => 2147483647, 'edit_rank' => true];
        $harness->peerDatabase->full['full']['about'] = 'stale full cache';
        $harness->keepStaleCache = true;
        $fresh = FreshPeerReader::read($harness->peer, Runner::settings(), $harness->getFullInfo(...));
        self::assertTrue($fresh['Chat']['default_banned_rights']['send_photos']);
        self::assertTrue($fresh['Chat']['default_banned_rights']['invite_users']);
        self::assertSame('Offline fixture', $fresh['full']['about']);
        self::assertSame(['channels.getFullChannel'], $harness->calls);
        self::assertArrayNotHasKey('send_photos', $harness->cached['default_banned_rights']);
    }

    public function testOfficialMinPeerRefreshIsFixedButRawFullReplyStillRequired(): void
    {
        $harness = new NativePeerReadHarness();
        $harness->cached['min'] = true;
        unset($harness->cached['default_banned_rights']);
        $result = $harness->getInfo($harness->peer);
        self::assertFalse($result['Chat']['min'] ?? false);
        self::assertTrue($result['Chat']['default_banned_rights']['invite_users']);
        self::assertSame(['channels.getChannels'], $harness->calls);
        self::assertArrayNotHasKey(FreshPeerReader::EVIDENCE_KEY, $result);
        // getInfo now re-reads its min-peer cache. getFullInfo still needs the
        // immediate full RPC reply to prove the origin of saved permissions.
        $harness->cached['min'] = true;
        unset($harness->cached['default_banned_rights']);
        $fresh = FreshPeerReader::read($harness->peer, Runner::settings(), $harness->getFullInfo(...));
        self::assertFalse($fresh['Chat']['min'] ?? false);
        self::assertTrue($fresh['Chat']['default_banned_rights']['invite_users']);
        self::assertSame(['channels.getChannels', 'channels.getChannels', 'channels.getFullChannel'], $harness->calls);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('peerTypes')]
    public function testFreshReadsBypassSameSecondAndFutureCacheTimes(string $peer, string $type, string $rpc): void
    {
        $harness = new NativePeerReadHarness($peer, $type);
        foreach ([time(), time() + 86400] as $inserted) {
            $harness->peerDatabase->full['inserted'] = $inserted;
            $fresh = FreshPeerReader::read($peer, Runner::settings(), $harness->getFullInfo(...));
            self::assertSame($peer, (string)$fresh['Chat']['id']);
            self::assertSame($harness->server['Chat']['default_banned_rights'], $fresh['Chat']['default_banned_rights']);
        }
        self::assertSame([$rpc, $rpc], $harness->calls);
    }

    public static function peerTypes(): array
    {
        return [['-123', 'chat', 'messages.getFullChat'], ['-1000000000123', 'supergroup', 'channels.getFullChannel']];
    }

    public function testNormalPublicHelperCacheBehaviorAndSignatureArePreserved(): void
    {
        $harness = new NativePeerReadHarness();
        $harness->cacheLifetime = 3600;
        $cached = $harness->getFullInfo($harness->peer);
        self::assertSame([], $harness->calls);
        self::assertArrayNotHasKey(FreshPeerReader::EVIDENCE_KEY, $cached);
        self::assertCount(1, (new \ReflectionMethod(\danog\MadelineProto\MTProto::class, 'getFullInfo'))->getParameters());
        self::assertCount(1, (new \ReflectionMethod(\danog\MadelineProto\SimpleEventHandler::class, 'getFullInfo'))->getParameters());
    }

    public function testRemovedDefaultsInCompleteReplyDoNotResurrectCachedRestrictions(): void
    {
        $harness = new NativePeerReadHarness();
        $harness->reply = FreshPeerFixture::info($harness->peer, null)[FreshPeerReader::EVIDENCE_KEY];
        $harness->keepStaleCache = true;
        $fresh = FreshPeerReader::read($harness->peer, Runner::settings(), $harness->getFullInfo(...));
        self::assertArrayNotHasKey('default_banned_rights', $fresh['Chat']);
        self::assertTrue($harness->cached['default_banned_rights']['invite_users']);
    }

    public static function invalidEvidence(): iterable
    {
        $base = FreshPeerFixture::info('-1000000000123', ['_' => 'chatBannedRights', 'until_date' => 0]);
        $key = FreshPeerReader::EVIDENCE_KEY;
        $withoutMarker = $base;
        unset($withoutMarker[$key]);
        yield 'no per-call marker' => [$withoutMarker];
        foreach (['min' => true, 'deactivated' => true, 'left' => true, 'monoforum' => true, 'migrated_to' => ['_' => 'inputChannel']] as $name => $value) {
            $candidate = $base;
            $candidate[$key]['chats'][0][$name] = $value;
            yield $name => [$candidate];
        }
        foreach (['channelForbidden', 'chat', 'chatForbidden'] as $constructor) {
            $candidate = $base;
            $candidate[$key]['chats'][0]['_'] = $constructor;
            yield $constructor => [$candidate];
        }
        foreach (['title', 'date', 'photo'] as $name) {
            $candidate = $base;
            unset($candidate[$key]['chats'][0][$name]);
            unset($candidate[$key]['chats'][0]['default_banned_rights']);
            yield 'missing Chat ' . $name => [$candidate];
        }
        foreach (['users', 'full_chat', 'chats'] as $name) {
            $candidate = $base;
            unset($candidate[$key][$name]);
            yield 'missing reply ' . $name => [$candidate];
        }
        $candidate = $base;
        $candidate[$key]['chats'][] = $candidate[$key]['chats'][0];
        yield 'duplicate target' => [$candidate];
        $candidate = $base;
        $candidate[$key]['chats'][0]['id'] = -1000000000124;
        yield 'wrong target' => [$candidate];
        $candidate = $base;
        $candidate[$key]['full_chat']['id'] = -1000000000124;
        yield 'wrong full target' => [$candidate];
        $candidate = $base;
        $candidate[$key]['full_chat']['_'] = 'chatFull';
        yield 'wrong full type' => [$candidate];
        foreach ([null, [], ['_' => 'chatBannedRights'], ['_' => 'chatBannedRights', 'until_date' => '0'], ['_' => 'chatBannedRights', 'until_date' => 0, 'send_photos' => 'true']] as $rights) {
            $candidate = $base;
            $candidate[$key]['chats'][0]['default_banned_rights'] = $rights;
            yield 'malformed rights ' . json_encode($rights) => [$candidate];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEvidence')]
    public function testUnverifiableReplyFailsWithoutUsingOtherwiseCompleteCaches(array $reply): void
    {
        $this->expectException(\RuntimeException::class);
        FreshPeerReader::read('-1000000000123', Runner::settings(), static fn (): array => $reply);
    }

    public function testIntentionalCancellationAndTimeoutAreNotConvertedIntoEmptyDefaults(): void
    {
        foreach ([new \Amp\CancelledException(), new \Amp\TimeoutException()] as $failure) {
            $harness = new NativePeerReadHarness();
            $harness->failure = $failure;
            try {
                FreshPeerReader::read($harness->peer, Runner::settings(), $harness->getFullInfo(...));
                self::fail('RPC failure became a permissions snapshot');
            } catch (\Throwable $actual) {
                self::assertSame($failure, $actual);
            }
        }
    }

    public function testForumStateDoesNotRewriteTheNativeManageTopicsRestriction(): void
    {
        foreach ([false, true] as $forum) {
            $harness = new NativePeerReadHarness();
            $harness->reply = $harness->server[FreshPeerReader::EVIDENCE_KEY];
            $harness->reply['chats'][0]['forum'] = $forum;
            $harness->reply['chats'][0]['default_banned_rights']['manage_topics'] = true;
            $fresh = FreshPeerReader::read($harness->peer, Runner::settings(), $harness->getFullInfo(...));
            self::assertSame($forum, $fresh['Chat']['forum']);
            self::assertTrue($fresh['Chat']['default_banned_rights']['manage_topics']);
        }
    }
    public function testUnknownCachePolicyFailsBeforeAnyRead(): void
    {
        $calls = 0;
        $reader = static function () use (&$calls): array {
            $calls++;
            return [];
        };
        try {
            FreshPeerReader::read('-100123', new Settings(), $reader);
            self::fail('Cached settings accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('zero full-info cache', $error->getMessage());
            self::assertSame(0, $calls);
        }
    }

    public function testStartupSettingsUseVerifiedPublicRefreshPolicy(): void
    {
        $settings = Runner::settings();
        self::assertSame(0, $settings->getPeer()->getFullInfoCacheTime());
        self::assertSame(5, $settings->getRPC()->getFloodTimeout());
        $method = new \ReflectionMethod(\danog\MadelineProto\MTProto::class, 'getFullInfo');
        $lines = file($method->getFileName());
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringContainsString('getFullInfoCacheTime()', $source);
        self::assertStringContainsString("methodCallAsyncRead('channels.getFullChannel'", $source);
        self::assertStringContainsString("methodCallAsyncRead('messages.getFullChat'", $source);
        foreach (['getSettings', 'getFullInfo', 'getInfo'] as $name) {
            self::assertTrue((new \ReflectionMethod(\danog\MadelineProto\SimpleEventHandler::class, $name))->isPublic());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('peerTypes')]
    public function testPinnedNativeTlDecodesMatchingSignedPeerIdsAndDefaultRights(string $peer, string $type, string $rpc): void
    {
        $previousLogger = \danog\MadelineProto\Logger::$default;
        $adapter = (new \ReflectionClass(\danog\MadelineProto\MTProto::class))->newInstanceWithoutConstructor();
        $adapter->settings = new \danog\MadelineProto\Settings();
        $adapter->logger = new \danog\MadelineProto\Logger((new \danog\MadelineProto\Settings\Logger())->setType(\danog\MadelineProto\Logger::CALLABLE_LOGGER)->setExtra(static function (): void {
        }));
        try {
            $tl = new \danog\MadelineProto\TL\TL($adapter);
            $tl->init(new \danog\MadelineProto\Settings\TLSchema());
            foreach ([null, ['_' => 'chatBannedRights', 'until_date' => 2147483647, 'invite_users' => true, 'send_photos' => true, 'edit_rank' => true]] as $rights) {
                $reply = FreshPeerFixture::info($peer, $rights, $type)[FreshPeerReader::EVIDENCE_KEY];
                // Server wire IDs are positive; the SDK converts both native constructors.
                $reply['full_chat']['id'] = 123;
                $reply['chats'][0]['id'] = 123;
                if ($type === 'chat') {
                    $reply['full_chat']['participants']['chat_id'] = 123;
                }
                $wire = $tl->serializeObject(['type' => 'messages.ChatFull'], $reply, 'fresh-peer-fixture');
                $stream = fopen('php://memory', 'w+b');
                fwrite($stream, $wire);
                rewind($stream);
                $parsed = $tl->deserialize($stream, ['type' => 'messages.ChatFull', 'connection' => null, 'encrypted' => false]);
                fclose($stream);
                $fresh = FreshPeerReader::verified($peer, [FreshPeerReader::EVIDENCE_KEY => $parsed]);
                self::assertSame($peer, (string)$fresh['Chat']['id']);
                self::assertSame($peer, (string)$fresh['full']['id']);
                if ($rights === null) {
                    self::assertArrayNotHasKey('default_banned_rights', $fresh['Chat']);
                } else {
                    self::assertTrue($fresh['Chat']['default_banned_rights']['invite_users']);
                    self::assertTrue($fresh['Chat']['default_banned_rights']['send_photos']);
                    self::assertTrue($fresh['Chat']['default_banned_rights']['edit_rank']);
                    self::assertFalse($fresh['Chat']['default_banned_rights']['manage_topics']);
                    self::assertSame(2147483647, $fresh['Chat']['default_banned_rights']['until_date']);
                }
            }
        } finally {
            $tl = null;
            $adapter = null;
            \danog\MadelineProto\Logger::$default = $previousLogger;
        }
    }
    public function testEventLoopBindingRemainsEffectiveAfterHandlerAttachment(): void
    {
        $previous = \Revolt\EventLoop::getErrorHandler();
        $handler = (new \ReflectionClass(ReplyScopeFixture::class))->newInstanceWithoutConstructor();
        try {
            \Revolt\EventLoop::setErrorHandler(static function (): void {
            });
            Runner::attachHandler($handler);
            $callback = \Revolt\EventLoop::getErrorHandler();
            self::assertNotNull($callback);
            $error = new \RuntimeException('fixture unknown error');
            try {
                $callback($error);
                self::fail('Unknown event-loop error swallowed');
            } catch (\RuntimeException $actual) {
                self::assertSame($error, $actual);
            }
        } finally {
            \Revolt\EventLoop::setErrorHandler($previous);
            $property = new \ReflectionProperty(Runner::class, 'handler');
            $property->setValue(null, null);
        }
    }
}
