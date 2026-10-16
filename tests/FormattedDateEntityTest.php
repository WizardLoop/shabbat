<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\EventHandler\Message\Entities\FormattedDate;
use danog\MadelineProto\EventHandler\Message\Entities\MessageEntity;
use danog\MadelineProto\Settings\TLSchema;
use danog\MadelineProto\StrTools;
use danog\MadelineProto\TL\TL;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Content\TemplateEditor;
use Shabbat\Content\TemplateRenderer;
use Shabbat\Runtime\Application;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\Runner;
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;

final class FormattedDateEntityTest extends TestCase
{
    private static TL $tl;
    private bool $previousStopping;

    public static function setUpBeforeClass(): void
    {
        Runner::settings();
        self::$tl = new TL();
        self::$tl->init(new TLSchema());
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

    public static function validFormats(): array
    {
        $cases = [];
        for ($mask = 0; $mask < 64; $mask++) {
            if (($mask & 1) !== 0 && $mask !== 1 || ($mask & 6) === 6 || ($mask & 24) === 24) {
                continue;
            }
            $format = $mask === 1 ? 'r' : (($mask & 32) !== 0 ? 'w' : '')
                . (($mask & 8) !== 0 ? 'd' : (($mask & 16) !== 0 ? 'D' : ''))
                . (($mask & 2) !== 0 ? 't' : (($mask & 4) !== 0 ? 'T' : ''));
            $cases['mask-' . $mask] = [$mask, $format];
        }
        return $cases;
    }

    private static function raw(int $mask, int $offset = 2, int $length = 8, int $date = 1792000000): array
    {
        return ['_' => 'messageEntityFormattedDate', 'offset' => $offset, 'length' => $length, 'date' => $date,
            'relative' => ($mask & 1) !== 0, 'short_time' => ($mask & 2) !== 0,
            'long_time' => ($mask & 4) !== 0, 'short_date' => ($mask & 8) !== 0,
            'long_date' => ($mask & 16) !== 0, 'day_of_week' => ($mask & 32) !== 0];
    }

    #[DataProvider('validFormats')]
    public function testAllNineteenNativeFlagsBotApiAndLockedDecoderRoundTrip(int $mask, string $format): void
    {
        self::assertCount(19, self::validFormats());
        $raw = self::raw($mask);
        $entity = MessageEntity::fromRawEntity($raw);
        self::assertInstanceOf(FormattedDate::class, $entity);
        self::assertSame($raw, $entity->toMTProto());
        self::assertSame(['type' => 'date_time', 'offset' => 2, 'length' => 8,
            'unix_time' => 1792000000, 'date_time_format' => $format], $entity->toBotAPI());
        self::assertSame($raw, MessageEntity::fromRawEntity($entity->toBotAPI())->toMTProto());
        // Genuine locked decoder; schema-assembled wire uses native primitive int encoding.
        // TL::serializeParams needs a real API adapter and is deliberately not claimed here.
        $descriptor = self::$tl->getConstructors()->findByPredicate('messageEntityFormattedDate');
        $wire = $descriptor['id'];
        foreach ([$mask, $raw['offset'], $raw['length'], $raw['date']] as $integer) {
            $wire .= self::$tl->serializeObject(['type' => 'int'], $integer, 'date_entity_fixture');
        }
        $decoded = self::$tl->deserialize($wire, ['type' => 'MessageEntity', 'encrypted' => false, 'connection' => null]);
        foreach ($raw as $field => $value) {
            self::assertSame($value, $decoded[$field]);
        }
        self::assertSame($raw, MessageEntity::fromRawEntity($decoded)->toMTProto());
    }

    public static function invalidMasks(): array
    {
        $valid = self::validFormats();
        $cases = [];
        for ($mask = 0; $mask < 64; $mask++) {
            if (!isset($valid['mask-' . $mask])) {
                $cases[] = [$mask];
            }
        }
        return $cases;
    }

    #[DataProvider('invalidMasks')]
    public function testEveryContradictoryNativeFlagCombinationIsRejected(int $mask): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MessageEntity::fromRawEntity(self::raw($mask));
    }

    public static function invalidNativeFields(): array
    {
        return [['offset', '2'], ['offset', -1], ['length', 1.0], ['length', null], ['date', '1792000000'],
            ['date', 1792000000.0], ['date', -1], ['date', 2147483648], ['date', true],
            ['relative', 1], ['short_time', 'true'], ['long_date', null]];
    }

    #[DataProvider('invalidNativeFields')]
    public function testNativeFieldsAreNotSilentlyCoerced(string $field, mixed $value): void
    {
        $raw = self::raw(0);
        $raw[$field] = $value;
        $this->expectException(\InvalidArgumentException::class);
        MessageEntity::fromRawEntity($raw);
    }

    public static function invalidBotApiFields(): array
    {
        return [['unix_time', '1792000000'], ['unix_time', 1792000000.5], ['offset', '2'], ['length', false],
            ['date_time_format', 'rw'], ['date_time_format', 'td'], ['date_time_format', 'dD'],
            ['date_time_format', 'tT'], ['date_time_format', 'W'], ['date_time_format', null], ['date_time_format', []]];
    }

    #[DataProvider('invalidBotApiFields')]
    public function testBotApiFieldsAndFormatGrammarAreStrict(string $field, mixed $value): void
    {
        $raw = ['type' => 'date_time', 'offset' => 2, 'length' => 8, 'unix_time' => 1792000000, 'date_time_format' => ''];
        $raw[$field] = $value;
        $this->expectException(\InvalidArgumentException::class);
        MessageEntity::fromRawEntity($raw);
    }

    public function testOmittedFlagsEmptyFormatEpochAndReadonlyUtf16Fields(): void
    {
        $raw = ['_' => 'messageEntityFormattedDate', 'offset' => 2, 'length' => 4, 'date' => 0];
        $entity = MessageEntity::fromRawEntity($raw);
        self::assertSame(self::raw(0, 2, 4, 0), $entity->toMTProto());
        self::assertSame('', $entity->toBotAPI()['date_time_format']);
        $bot = ['type' => 'date_time', 'offset' => 2, 'length' => 4, 'unix_time' => 0];
        self::assertSame(self::raw(0, 2, 4, 0), MessageEntity::fromRawEntity($bot)->toMTProto());
        $reflection = new \ReflectionObject($entity);
        foreach (['offset', 'length', 'date', 'relative', 'shortTime', 'longTime', 'shortDate', 'longDate', 'dayOfWeek'] as $field) {
            self::assertTrue($reflection->getProperty($field)->isReadOnly());
        }
        self::assertSame(2, $entity->offset);
    }

    public function testActualStrToolsPreservesTextWithoutInventingDateHtmlTags(): void
    {
        foreach ([false, true] as $allowTags) {
            self::assertSame('😀date&lt;&gt;', StrTools::entitiesToHtml('😀date<>', [self::raw(50, 2, 4)], $allowTags));
            self::assertSame('😀<b>date</b>&lt;&gt;', StrTools::entitiesToHtml('😀date<>', [
                self::raw(50, 2, 4), ['_' => 'messageEntityBold', 'offset' => 2, 'length' => 4],
            ], $allowTags));
        }
    }

    public function testUnknownNativeEntityStillFailsAtTheOfficialFactory(): void
    {
        $this->expectException(\AssertionError::class);
        $this->expectExceptionMessage('Unknown entity type: messageEntityDeliberatelyUnknown');
        MessageEntity::fromRawEntity(['_' => 'messageEntityDeliberatelyUnknown', 'offset' => 0, 'length' => 1]);
    }

    public function testActualTemplateCaptureStorageUtf16ExpansionAndNativeSendRetainDateFields(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['channels.getParticipant'] = ['participant' => ['_' => 'channelParticipantAdmin']];
        $gateway = new MadelineGateway($transport);
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-14T12:00:00Z'));
        $http = new RuntimeFakeJson();
        $app = new Application($gateway, $clock, $db, new CalendarService(new HebcalProvider($http), $clock));
        $app->settings->ensureGroup('-1001', 'Date Entity Fixture');
        $editor = new TemplateEditor($app);
        self::assertTrue($editor->callback(['user_id' => '1', 'private' => true, 'query_id' => '42', 'message_id' => 900, 'data' => 'v3:templates:-1001']));
        $messages = array_values(array_filter($transport->calls, static fn (array $call): bool => $call[0] === 'messages.editMessage'
            && isset($call[1]['reply_markup']['rows'][0]['buttons'][0]['data'])));
        self::assertNotEmpty($messages);
        self::assertSame('1', $messages[array_key_last($messages)][1]['peer']);
        self::assertSame(900, $messages[array_key_last($messages)][1]['id']);
        $token = substr($messages[array_key_last($messages)][1]['reply_markup']['rows'][0]['buttons'][0]['data'], 9);
        $prefix = '/template ' . $token . "\n";
        $prefixLength = intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2);
        $captured = MessageEntity::fromRawEntity(self::raw(50, $prefixLength + 2));
        self::assertTrue($editor->message(['user_id' => '1', 'private' => true,
            'text' => $prefix . '😀{{date}}', 'entities' => [$captured->toMTProto()]]));
        $stored = $app->templates->get('-1001', 'close');
        self::assertNotNull($stored);
        self::assertSame([self::raw(50)], $stored['entities']);
        $rendered = (new TemplateRenderer())->renderPayload($stored, ['date' => '2026-10-14']);
        self::assertSame('😀2026-10-14', $rendered['text']);
        self::assertSame([self::raw(50, 2, 10)], $rendered['entities']);
        $before = count($transport->calls);
        $gateway->send('-1001', $rendered, '78901234');
        self::assertCount($before + 1, $transport->calls);
        $sent = $transport->calls[array_key_last($transport->calls)];
        self::assertSame('messages.sendMessage', $sent[0]);
        self::assertSame([self::raw(50, 2, 10)], $sent[1]['entities']);
        self::assertArrayNotHasKey('parse_mode', $sent[1]);
        self::assertSame(78901234, $sent[1]['random_id']);
        // Reopen the saved template and preview by editing the same private bot panel.
        self::assertTrue($editor->callback(['user_id' => '1', 'private' => true, 'query_id' => '43', 'message_id' => 900, 'data' => 'v3:templates:-1001:close']));
        $panels = array_values(array_filter($transport->calls, static fn(array $call): bool => $call[0] === 'messages.editMessage'
            && isset($call[1]['reply_markup']['rows'][0]['buttons'][0]['data'])));
        $previewToken = substr($panels[array_key_last($panels)][1]['reply_markup']['rows'][0]['buttons'][0]['data'], 9);
        $newSendsBefore = count(array_filter($transport->calls, static fn(array $call): bool => in_array($call[0], ['messages.sendMessage', 'messages.sendMedia'], true)));
        self::assertTrue($editor->callback(['user_id' => '1', 'private' => true, 'query_id' => '44', 'message_id' => 900, 'data' => 'tpl:preview:' . $previewToken]));
        $edits = array_values(array_filter($transport->calls, static fn(array $call): bool => $call[0] === 'messages.editMessage'));
        $preview = $edits[array_key_last($edits)][1];
        self::assertSame('1', $preview['peer']);
        self::assertSame(900, $preview['id']);
        self::assertSame('😀2026-10-14', $preview['message']);
        self::assertSame([self::raw(50, 2, 10)], $preview['entities']);
        self::assertArrayNotHasKey('parse_mode', $preview);
        self::assertArrayNotHasKey('random_id', $preview);
        self::assertSame($newSendsBefore, count(array_filter($transport->calls, static fn(array $call): bool => in_array($call[0], ['messages.sendMessage', 'messages.sendMedia'], true))));
    }
}
