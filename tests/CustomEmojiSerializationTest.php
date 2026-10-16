<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\Logger;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\Settings\TLSchema;
use danog\MadelineProto\TL\TL;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\Runner;
use Shabbat\UI\Presentation;

final class CustomEmojiSerializationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }

    public function testLockedWirePreservesTwelveIconsColorsAndUtf16Entities(): void
    {
        $previousLogger = Logger::$default;
        try {
            $adapter = (new \ReflectionClass(MTProto::class))->newInstanceWithoutConstructor();
            $adapter->settings = new \danog\MadelineProto\Settings();
            $adapter->logger = new Logger((new \danog\MadelineProto\Settings\Logger())->setType(Logger::CALLABLE_LOGGER)->setExtra(static function (): void {
            }));
            $tl = new TL($adapter);
            $tl->init(new TLSchema());
            $alternatives = EmojiFixtureTransport::alternatives();
            $buttons = [];
            foreach (array_values(Presentation::ICONS) as $index => $id) {
                $style = ['_' => 'keyboardButtonStyle','icon' => $id];
                if ($index % 4 < 3) {
                    $style[['bg_primary','bg_danger','bg_success'][$index % 4]] = true;
                }
                $buttons[] = [['text' => 'Fixture ' . $index,'callback_data' => 'fixture:' . $index,'style' => $style]];
            }
            $transport = new EmojiFixtureTransport();
            $gateway = new MadelineGateway($transport, true, $alternatives);
            $text = implode(' ', $alternatives);
            $gateway->answerInline('123', [['_' => 'inputBotInlineResult','id' => 'all-twelve','type' => 'article',
                'send_message' => ['_' => 'inputBotInlineMessageText','message' => '<b>' . $text . '</b>','parse_mode' => 'HTML',
                    'reply_markup' => ['inline_keyboard' => $buttons]]]]);
            $wire = $tl->serializeMethod('messages.setInlineBotResults', $transport->calls[0][1]);
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $wire);
            rewind($stream);
            $decoded = $tl->deserialize($stream, ['type' => '','connection' => null,'encrypted' => false]);
            self::assertSame(strlen($wire), ftell($stream));
            fclose($stream);
            $message = $decoded['results'][0]['send_message'];
            self::assertSame($text, $message['message']);
            $custom = array_values(array_filter($message['entities'], static fn(array $e): bool => $e['_'] === 'messageEntityCustomEmoji'));
            self::assertCount(12, $custom);
            self::assertSame(array_map('intval', array_values(Presentation::ICONS)), array_column($custom, 'document_id'));
            $offset = 0;
            foreach (array_values($alternatives) as $index => $alt) {
                $length = intdiv(strlen(mb_convert_encoding($alt, 'UTF-16LE', 'UTF-8')), 2);
                self::assertSame($offset, $custom[$index]['offset']);
                self::assertSame($length, $custom[$index]['length']);
                $offset += $length + 1;
                $style = $message['reply_markup']['rows'][$index]['buttons'][0]['style'];
                self::assertSame((int)array_values(Presentation::ICONS)[$index], $style['icon']);
                self::assertSame($index % 4 < 3, ($style['bg_primary'] ?? false) || ($style['bg_danger'] ?? false) || ($style['bg_success'] ?? false));
            }
        } finally {
            $tl = null;
            $adapter = null;
            Logger::$default = $previousLogger;
        }
    }
}
