<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use danog\TelegramEntities\Entities;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\Runner;
use Shabbat\UI\Lang;
use Shabbat\UI\Presentation;

final class PresentationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }

    public function testHebrewWelcomeIsExactlyRequestedAndAnimatedTextIsIdentical(): void
    {
        $expected = "✡️ שומר שבת\nזמני שבת וחג, זמני היום, דף יומי וספירת העומר, לפי המיקום שלך.\n\nבחרו מה להציג, או פתחו את ההעדפות שלכם לניהול השפה והמיקום.\n\nמנהלי קבוצות יכולים לנהל כל קבוצה דרך ״הקבוצות שלי״.";
        $html = (new Lang())->text('he', 'welcome');
        $parsed = Entities::fromHtml(Presentation::html($html, true, EmojiFixtureTransport::alternatives()));
        self::assertSame($expected, $parsed->message);
        self::assertSame(
            ['type' => 'custom_emoji', 'custom_emoji_id' => 5947339492212875204, 'offset' => 0, 'length' => 2],
            array_values(array_filter($parsed->entities, static fn(array $e): bool => $e['type'] === 'custom_emoji'))[0]
        );
        self::assertContains('bold', array_column($parsed->entities, 'type'));
        self::assertSame($expected, Entities::fromHtml(Presentation::html($html, false))->message);
    }

    public function testEverySuppliedAssetHasAUniqueSemanticMappingAndNativeInt64(): void
    {
        self::assertCount(12, Presentation::ICONS);
        self::assertCount(12, array_unique(Presentation::ICONS));
        foreach (['settings' => 'preferences','support' => 'donate','info' => 'help','source_code' => 'source','groups' => 'groups','add_to_group' => 'add','shabbat_holidays' => 'shabbat','tefillin' => 'tefillin','daily_zmanim' => 'zmanim','daf_yomi' => 'daf','omer' => 'omer','brand_coin' => 'welcome'] as $semantic => $key) {
            self::assertSame(Presentation::ICONS[$semantic], Presentation::iconForKey($key));
            $native = MadelineGateway::normalizeMarkup(['inline_keyboard' => [[Presentation::button(['text' => 'Label', 'callback_data' => 'x', 'style' => 'primary'], true, $key)]]]);
            self::assertSame((int)Presentation::ICONS[$semantic], $native['rows'][0]['buttons'][0]['style']['icon']);
            self::assertTrue($native['rows'][0]['buttons'][0]['style']['bg_primary']);
        }
    }

    public function testHtmlPreservesLinksCodeExistingEmojiAndIsIdempotent(): void
    {
        $html = '<b>⚙️ Settings</b> — <a href="https://example.org/📖">📖 Study</a> <code>⚙️ /start</code> <pre>⭐</pre> <tg-emoji emoji-id="42">🌾</tg-emoji>';
        $once = Presentation::html($html, true, EmojiFixtureTransport::alternatives());
        self::assertSame($once, Presentation::html($once, true, EmojiFixtureTransport::alternatives()));
        self::assertSame(3, substr_count($once, '<tg-emoji'));
        self::assertStringContainsString('href="https://example.org/📖"', $once);
        self::assertStringContainsString('<code>⚙️ /start</code>', $once);
        self::assertStringContainsString('<pre>⭐</pre>', $once);
        self::assertStringContainsString('<tg-emoji emoji-id="42">🌾</tg-emoji>', $once);
        self::assertStringNotContainsString('—', $once);
        self::assertStringNotContainsString('<tg-emoji', Presentation::html($once, false));
    }

    public function testExplicitEntitiesRetainUtf16OffsetsAndExistingCustomAssets(): void
    {
        $text = '😀 ⚙️ - 🌾 <tag>';
        $entities = [
            ['_' => 'messageEntityBold', 'offset' => 3, 'length' => 2],
            ['_' => 'messageEntityCustomEmoji', 'offset' => 8, 'length' => 2, 'document_id' => 42],
        ];
        $payload = Presentation::payload(['text' => $text, 'entities' => $entities], true, EmojiFixtureTransport::alternatives());
        self::assertSame($text, $payload['text']);
        self::assertSame($entities, array_slice($payload['entities'], 0, 2));
        self::assertCount(3, $payload['entities']);
        self::assertSame(['_' => 'messageEntityCustomEmoji', 'offset' => 3, 'length' => 2, 'document_id' => 5944760643524500583], $payload['entities'][2]);
        self::assertSame($payload, Presentation::payload($payload, true, EmojiFixtureTransport::alternatives()));
        $plain = Presentation::payload(['text' => '<b>⭐</b>', 'entities' => [['_' => 'messageEntityCustomEmoji', 'offset' => 3, 'length' => 1, 'document_id' => 42]]], false);
        self::assertSame([], $plain['entities']);
        self::assertSame('<b>⭐</b>', $plain['text'], 'Native empty entities keep literal HTML-looking text.');
    }

    public function testBotApiIconNormalizesWithoutLosingColorsAndGlobalOptOutKeepsActions(): void
    {
        $markup = ['inline_keyboard' => [[['text' => 'Other', 'url' => 'https://example.org', 'icon_custom_emoji_id' => Presentation::ICONS['support'], 'style' => 'danger']]]];
        $native = MadelineGateway::normalizeMarkup($markup);
        $button = $native['rows'][0]['buttons'][0];
        self::assertSame('keyboardInlineButton', $button['_']);
        self::assertSame('inlineButtonTypeUrl', $button['type']['_']);
        self::assertSame('https://example.org', $button['type']['url']);
        self::assertTrue($button['style']['bg_danger']);
        self::assertSame(5945186253308699040, $button['style']['icon']);
        self::assertArrayNotHasKey('icon_custom_emoji_id', $button);
        $plain = MadelineGateway::normalizeMarkup($native, false)['rows'][0]['buttons'][0];
        self::assertArrayNotHasKey('icon', $plain['style']);
        self::assertSame('Other', $plain['text']);
        self::assertSame($button['type']['url'], $plain['type']['url']);
        self::assertTrue($plain['style']['bg_danger']);
    }

    public function testNativeGatewayDecoratesInitialEditsScheduledAndInlineWithoutResends(): void
    {
        $transport = new GatewayFixtureTransport();
        $gateway = new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives());
        $markup = ['inline_keyboard' => [[['text' => '⚙️ Settings', 'callback_data' => 'settings', 'style' => 'primary']]]];
        $gateway->sendPrivate('1', '<b>✡️ Welcome</b>', $markup);
        $gateway->editPrivate('1', 42, '<b>📖 Study</b>', $markup);
        $gateway->send('-1001', ['text' => '🔔 Tefillin', 'buttons' => $markup['inline_keyboard']], '123');
        $gateway->answerInline('456', [['_' => 'inputBotInlineResult', 'id' => 'i', 'type' => 'article', 'send_message' => ['_' => 'inputBotInlineMessageText', 'message' => '🌾 Omer', 'parse_mode' => 'HTML']]]);
        self::assertSame(['messages.sendMessage','messages.editMessage','messages.sendMessage','messages.setInlineBotResults'], array_column($transport->calls, 0));
        foreach (array_slice($transport->calls, 0, 3) as [, $parameters]) {
            self::assertStringContainsString('<tg-emoji', $parameters['message']);
            self::assertSame(5944760643524500583, $parameters['reply_markup']['rows'][0]['buttons'][0]['style']['icon']);
        }
        $inline = $transport->calls[3][1]['results'][0]['send_message'];
        self::assertStringContainsString('5945033816329428634', $inline['message']);
        self::assertArrayNotHasKey('reply_markup', $inline);
        $plainTransport = new GatewayFixtureTransport();
        (new MadelineGateway($plainTransport, false))->sendPrivate('1', '⚙️ Settings', $markup);
        self::assertSame('⚙️ Settings', $plainTransport->calls[0][1]['message']);
        self::assertArrayNotHasKey('icon', $plainTransport->calls[0][1]['reply_markup']['rows'][0]['buttons'][0]['style']);
    }

    public function testOwnedButtonsUseOneIconRestoreBasicPrefixAndSendNoInternalTlFields(): void
    {
        $error = (new \ReflectionClass(\danog\MadelineProto\RPCErrorException::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\danog\MadelineProto\RPCErrorException::class, 'rpc'))->setValue($error, 'PREMIUM_ACCOUNT_REQUIRED');
        $transport = new EmojiFixtureTransport();
        $transport->failures['messages.sendMessage'] = [$error];
        $gateway = new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives());
        $button = Presentation::button(['text' => '🌐 Language','callback_data' => 'language','style' => 'primary'], true, 'language');
        $gateway->sendPrivate('1', 'Plain text', ['inline_keyboard' => [[$button]]]);
        self::assertSame('Language', $transport->calls[0][1]['reply_markup']['rows'][0]['buttons'][0]['text']);
        self::assertSame('🌐 Language', $transport->calls[1][1]['reply_markup']['rows'][0]['buttons'][0]['text']);
        foreach ($transport->calls as $call) {
            self::assertStringNotContainsString('_presentation_', json_encode($call, JSON_THROW_ON_ERROR));
        }
        $transport = new EmojiFixtureTransport();
        (new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives()))->sendPrivate(
            '1',
            'Plain text',
            ['inline_keyboard' => [[['text' => '⚙️ User-chosen label','callback_data' => 'custom','style' => ['icon' => 5944760643524500583]]]]]
        );
        self::assertSame('⚙️ User-chosen label', $transport->calls[0][1]['reply_markup']['rows'][0]['buttons'][0]['text']);
    }

    public function testActualStartMenuHasOneIconPerOwnedButtonBeforeNativeRpc(): void
    {
        $db = \Shabbat\Storage\Database::open(':memory:');
        $db->migrate();
        $clock = new \Shabbat\Time\FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00+03:00'));
        $http = new FullHebcalFixtureClient();
        $transport = new EmojiFixtureTransport();
        $gateway = new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives());
        $ui = new \Shabbat\UI\BotController(
            new \Shabbat\Storage\SettingsRepository($db, $clock),
            new \Shabbat\Storage\FlowTokenRepository($db, $clock),
            new \Shabbat\Calendar\CalendarService(new \Shabbat\Calendar\HebcalProvider($http), $clock),
            $gateway,
            new \Shabbat\Location\GeoNames($http, $clock, ''),
            $clock
        );
        $ui->start('1');
        self::assertCount(1, $transport->calls);
        foreach ($transport->calls[0][1]['reply_markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                if (isset($button['style']['icon'])) {
                    self::assertDoesNotMatchRegularExpression('/^[\p{Extended_Pictographic}\p{Regional_Indicator}]/u', $button['text']);
                }
            }
        }
        self::assertStringNotContainsString('_presentation_', json_encode($transport->calls, JSON_THROW_ON_ERROR));
    }
    public function testGroupCounterEditsOnlyMarkupAndRetainsIdentityDuringBasicFallback(): void
    {
        $error = (new \ReflectionClass(\danog\MadelineProto\RPCErrorException::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\danog\MadelineProto\RPCErrorException::class, 'rpc'))->setValue($error, 'PREMIUM_ACCOUNT_REQUIRED');
        $transport = new EmojiFixtureTransport();
        $transport->failures['messages.editMessage'] = [$error];
        (new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives()))->editGroupMarkup(
            '-1001',
            42,
            ['inline_keyboard' => [[['text' => 'הנחתי (1)','callback_data' => 'tf:fixture','style' => ['bg_primary' => true,'icon' => 5944970388252402183]]]]]
        );
        self::assertCount(2, $transport->calls);
        foreach ($transport->calls as [$method,$parameters]) {
            self::assertSame('messages.editMessage', $method);
            self::assertSame('-1001', $parameters['peer']);
            self::assertSame(42, $parameters['id']);
            self::assertArrayNotHasKey('message', $parameters);
            self::assertArrayNotHasKey('entities', $parameters);
            self::assertArrayNotHasKey('media', $parameters);
            self::assertSame('הנחתי (1)', $parameters['reply_markup']['rows'][0]['buttons'][0]['text']);
        }
        self::assertArrayNotHasKey('icon', $transport->calls[1][1]['reply_markup']['rows'][0]['buttons'][0]['style']);
    }
    public function testPlainHyphensPreserveUtf16EntitiesInMediaCaptionsAndButtonLabels(): void
    {
        $payload = ['text' => '😀 — ⚙️ text','entities' => [
            ['_' => 'messageEntityBold','offset' => 0,'length' => 12],
            ['_' => 'messageEntityCustomEmoji','offset' => 5,'length' => 2,'document_id' => 42]],'media' => 'fixture-file-id',
            'buttons' => [[['text' => 'Label — value','callback_data' => 'x','style' => 'primary']]]];
        $original = $payload;
        $transport = new EmojiFixtureTransport();
        (new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives()))->send('-1001', $payload, '123');
        self::assertSame($original, $payload, 'Rendering must not mutate the saved custom payload.');
        self::assertSame('messages.sendMedia', $transport->calls[0][0]);
        self::assertSame('😀 - ⚙️ text', $transport->calls[0][1]['message']);
        self::assertSame($original['entities'], $transport->calls[0][1]['entities']);
        self::assertSame('Label - value', $transport->calls[0][1]['reply_markup']['rows'][0]['buttons'][0]['text']);
        self::assertSame(12, intdiv(strlen(mb_convert_encoding($transport->calls[0][1]['message'], 'UTF-16LE', 'UTF-8')), 2));
    }
    public function testNativeEmptyEntitiesPreserveLiteralHtmlInSendsEditsMediaAndInline(): void
    {
        $text = '<literal> & {{location}}';
        $transport = new EmojiFixtureTransport();
        $gateway = new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives());
        $payload = ['text' => $text,'entities' => []];
        $gateway->send('-1001', $payload, '123');
        $gateway->editPrivatePayload('1', 42, $payload);
        $gateway->send('-1001', $payload + ['media' => 'fixture-file-id'], '456');
        $gateway->answerInline('789', [['_' => 'inputBotInlineResult','id' => 'native','type' => 'article',
            'send_message' => ['_' => 'inputBotInlineMessageText','message' => $text,'entities' => []]]]);
        foreach (array_slice($transport->calls, 0, 3) as [$method,$parameters]) {
            self::assertSame($text, $parameters['message']);
            self::assertSame([], $parameters['entities']);
            self::assertArrayNotHasKey('parse_mode', $parameters);
        }
        $message = $transport->calls[3][1]['results'][0]['send_message'];
        self::assertSame($text, $message['message']);
        self::assertSame([], $message['entities']);
        self::assertArrayNotHasKey('parse_mode', $message);
        self::assertSame(['text' => $text,'entities' => []], $payload);
    }
    public function testSixLanguageHelpUsesFormattingAndContainsNoEmDash(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            self::assertStringStartsWith('<b>', $lang->text($language, 'info_intro'));
            foreach (['personal','calendar','groups','permissions','notifications','messages','privacy','operators'] as $category) {
                $text = $lang->text($language, 'info_' . $category . '_text');
                self::assertStringNotContainsString('—', $text);
                self::assertSame(
                    html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    Entities::fromHtml(Presentation::html($text))->message
                );
            }
            self::assertStringContainsString('10000', str_replace([',',' ','.','־'], '', $lang->text($language, 'donate_custom_invalid')));
        }
    }
}
