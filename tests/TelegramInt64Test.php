<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\TelegramInt64;

final class TelegramInt64Test extends TestCase
{
    public static function decimalValues(): array
    {
        return [['12345678', 12345678], ['-1234567', -1234567], [(string) PHP_INT_MAX, PHP_INT_MAX], [(string) PHP_INT_MIN, PHP_INT_MIN], ['0', 0]];
    }

    #[DataProvider('decimalValues')]
    public function testConvertedValueMatchesRealLockedTlWireEncoding(string $decimal, int $expected): void
    {
        $serializer = (new \ReflectionClass(\danog\MadelineProto\TL\TL::class))->newInstanceWithoutConstructor();
        $converted = TelegramInt64::fromDecimal($decimal);
        self::assertSame($expected, $converted);
        self::assertSame($serializer->serializeObject(['type' => 'long'], $expected, 'fixture'), $serializer->serializeObject(['type' => 'long'], $converted, 'fixture'));
        if (strlen($decimal) === 8) {
            self::assertNotSame($serializer->serializeObject(['type' => 'long'], $decimal, 'fixture'), $serializer->serializeObject(['type' => 'long'], $converted, 'fixture'));
        }
    }

    public static function rejectedValues(): array
    {
        return [['9223372036854775808'], ['-9223372036854775809'], ['01'], ['+1'], ['1.0'], [1.0], [true], ['fixture'], ['1e8']];
    }

    #[DataProvider('rejectedValues')]
    public function testNonCanonicalAndOutOfRangeValuesFailBeforeRpc(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TelegramInt64::fromDecimal($value);
    }

    public function testGatewayConvertsRandomQueryAndEmojiLongsAtBoundary(): void
    {
        $transport = new GatewayFixtureTransport();
        $gateway = new MadelineGateway($transport);
        $gateway->send('-100123', ['text' => 'fixture'], '12345678');
        $gateway->answerCallback('12345678', 'fixture');
        $gateway->answerInline('-1234567', []);
        self::assertSame(12345678, $transport->calls[0][1]['random_id']);
        self::assertSame(12345678, $transport->calls[1][1]['query_id']);
        self::assertSame(-1234567, $transport->calls[2][1]['query_id']);
        $button = ['text' => 'fixture', 'callback_data' => 'fixture', 'style' => ['_' => 'keyboardButtonStyle', 'icon' => '12345678']];
        $markup = MadelineGateway::normalizeMarkup(['inline_keyboard' => [[$button]]]);
        self::assertSame(12345678, $markup['rows'][0]['buttons'][0]['style']['icon']);
        $native = ['_' => 'replyInlineMarkup', 'rows' => [['_' => 'keyboardButtonRow', 'buttons' => [['_' => 'keyboardButtonCallback', 'text' => 'fixture', 'data' => 'fixture', 'style' => ['_' => 'keyboardButtonStyle', 'icon' => '12345678']]]]]];
        self::assertSame(12345678, MadelineGateway::normalizeMarkup($native)['rows'][0]['buttons'][0]['style']['icon']);
    }

    public function testOutOfRangeRandomIdNeverCallsTelegram(): void
    {
        $transport = new GatewayFixtureTransport();
        try {
            (new MadelineGateway($transport))->send('-100123', ['text' => 'fixture'], '9223372036854775808');
            self::fail('Invalid int64 sent');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->calls);
        }
    }
}
