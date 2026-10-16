<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\{Logger, MTProto};
use danog\MadelineProto\Settings\TLSchema;
use danog\MadelineProto\TL\TL;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Shabbat\Runtime\{MadelineGateway, Runner};
use Shabbat\Scheduling\{JobRepository, TefillinAcknowledgements};
use Shabbat\Storage\{Database, SettingsRepository};
use Shabbat\Time\FrozenClock;

final class TefillinAckGatewayTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }
    public static function labels(): array
    {
        return [[null, 'he', 'הנחתי'], ['✅ My custom label', 'en', '✅ My custom label']];
    }
    private function markup(?string $custom, string $language): array
    {
        (new ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T09:00:00Z'));
        $settings = new SettingsRepository($db, $clock);
        $group = $settings->ensureGroup('-1001', 'Offline markup', ['tefillin_enabled' => true, 'language' => $language, 'tefillin_ack_label' => $custom]);
        $jobs = new JobRepository($db, $clock);
        $id = $jobs->enqueue('-1001', 'tefillin', 'wire', $clock->now()->getTimestamp(), $clock->now()->getTimestamp() + 300, $group['revision'], []);
        $job = $jobs->lease('fixture');
        $ack = new TefillinAcknowledgements($db, $settings, $clock);
        $payload = $ack->preparePayload($job, $group, $clock->now(), ['text' => 'Caption stays', 'entities' => [['_' => 'messageEntityBold', 'offset' => 0, 'length' => 7]], 'buttons_mode' => 'inherit']);
        $jobs->preparePayload($id, 'fixture', $payload);
        $jobs->sent($id, 'fixture', ['_' => 'updateShortSentMessage', 'id' => 77]);
        $ack->registerDelivery($id, [77]);
        foreach (['1', '2', '3'] as $user) {
            $ack->toggle($user, '-1001', 77, $payload['_tefillin_ack_token'], 'query-' . $user);
        }
        return $ack->snapshot('-1001', 77)['markup'];
    }
    #[DataProvider('labels')]
    public function testActualGatewayFallbackAndLockedNativeMarkupKeepCurrentCountAndCustomLabel(?string $custom, string $language, string $label): void
    {
        $markup = $this->markup($custom, $language);
        $transport = new EmojiFixtureTransport();
        $rpc = (new ReflectionClass(\danog\MadelineProto\RPCErrorException::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($rpc, 'rpc'))->setValue($rpc, 'PREMIUM_ACCOUNT_REQUIRED');
        $transport->failures['messages.editMessage'] = [$rpc];
        $gateway = new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives());
        $gateway->editGroupMarkup('-1001', 77, $markup);
        self::assertCount(2, $transport->calls);
        $native = $transport->calls[0][1];
        $fallback = $transport->calls[1][1];
        foreach ([$native, $fallback] as $parameters) {
            self::assertSame('-1001', $parameters['peer']);
            self::assertSame(77, $parameters['id']);
            self::assertArrayNotHasKey('message', $parameters);
            self::assertArrayNotHasKey('media', $parameters);
            self::assertArrayNotHasKey('entities', $parameters);
        }
        $first = $native['reply_markup']['rows'][0]['buttons'][0];
        $second = $fallback['reply_markup']['rows'][0]['buttons'][0];
        self::assertSame($label . ' (3)', $first['text']);
        self::assertSame('🔔 ' . $label . ' (3)', $second['text']);
        self::assertSame(5944970388252402183, $first['style']['icon']);
        self::assertArrayNotHasKey('icon', $second['style']);
        self::assertTrue($first['style']['bg_primary']);
        self::assertTrue($second['style']['bg_primary']);
        self::assertSame($first['data'], $second['data']);

        $previousLogger = Logger::$default;
        try {
            $adapter = (new ReflectionClass(MTProto::class))->newInstanceWithoutConstructor();
            $adapter->settings = new \danog\MadelineProto\Settings();
            $adapter->logger = new Logger((new \danog\MadelineProto\Settings\Logger())->setType(Logger::CALLABLE_LOGGER)->setExtra(static function (): void {
            }));
            $tl = new TL($adapter);
            $tl->init(new TLSchema());
            foreach ([$native, $fallback] as $index => $parameters) {
                $wire = $tl->serializeObject(['type' => 'ReplyMarkup'], $parameters['reply_markup'], 'reply_markup');
                $stream = fopen('php://memory', 'w+b');
                fwrite($stream, $wire);
                rewind($stream);
                $decoded = $tl->deserialize($stream, ['type' => 'ReplyMarkup', 'connection' => null, 'encrypted' => false]);
                self::assertSame(strlen($wire), ftell($stream));
                fclose($stream);
                $button = $decoded['rows'][0]['buttons'][0];
                self::assertSame(($index === 0 ? '' : '🔔 ') . $label . ' (3)', $button['text']);
                self::assertInstanceOf(\danog\MadelineProto\TL\Types\Bytes::class, $button['data']);
                self::assertSame($first['data'], (string)$button['data']);
                self::assertTrue($button['style']['bg_primary']);
                if ($index === 0) {
                    self::assertSame(5944970388252402183, $button['style']['icon']);
                } else {
                    self::assertArrayNotHasKey('icon', $button['style']);
                }
                self::assertArrayNotHasKey('_presentation_fallback_text', $button);
            }
        } finally {
            $tl = null;
            $adapter = null;
            Logger::$default = $previousLogger;
        }
        $gateway->editGroupMarkup('-1001', 77, $markup);
        self::assertCount(3, $transport->calls);
        self::assertSame('🔔 ' . $label . ' (3)', $transport->calls[2][1]['reply_markup']['rows'][0]['buttons'][0]['text']);
    }
}
