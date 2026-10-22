<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use danog\MadelineProto\Logger;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\Settings\TLSchema;
use danog\MadelineProto\TL\TL;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Content\TemplateRenderer;
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\Runner;
use Shabbat\Storage\Database;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Storage\TemplateRepository;
use Shabbat\Telegram\KeyboardMarkup;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\BotController;

final class KeyboardCompatibilityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }

    public static function inlineFormats(): iterable
    {
        $legacy = [
            'keyboardButtonUrl' => ['url' => 'https://example.org/a'],
            'keyboardButtonCallback' => ['data' => "binding:\0\xFF", 'requires_password' => true],
            'keyboardButtonSwitchInline' => ['query' => '', 'same_peer' => true, 'peer_types' => [['_' => 'inlineQueryPeerTypePM']]],
            'keyboardButtonCopy' => ['copy_text' => 'Copy me'],
            'keyboardButtonWebView' => ['url' => 'https://example.org/app'],
            'keyboardButtonBuy' => [],
            'keyboardButtonGame' => [],
            'keyboardButtonUrlAuth' => ['url' => 'https://example.org/login', 'button_id' => 7, 'fwd_text' => 'Forward'],
            'inputKeyboardButtonUrlAuth' => ['url' => 'https://example.org/login', 'bot' => ['_' => 'inputUserSelf'], 'request_write_access' => true],
            'keyboardButtonUserProfile' => ['user_id' => 12345678],
            'inputKeyboardButtonUserProfile' => ['user_id' => ['_' => 'inputUserSelf']],
        ];
        $types = ['Url', 'Callback', 'SwitchInline', 'Copy', 'WebView', 'Buy', 'Game', 'UrlAuth', 'UrlAuth', 'UserProfile', 'UserProfile'];
        foreach ($legacy as $constructor => $fields) {
            $type = (str_starts_with($constructor, 'input') ? 'inputInlineButtonType' : 'inlineButtonType') . array_shift($types);
            yield $constructor => [['_' => $constructor, 'text' => 'Fixture'] + $fields, $type];
        }
        foreach (
            [
            'url' => ['url' => 'https://example.org/a'],
            'callback' => ['callback_data' => 'sb:help:calendar'],
            'copy' => ['copy_text' => ['text' => 'Copy me']],
            'copy-legacy-string' => ['copy_text' => 'Copy me'],
            'switch-empty' => ['switch_inline_query' => ''],
            'switch-current' => ['switch_inline_query_current_chat' => 'loc '],
            'chosen-chat' => ['switch_inline_query_chosen_chat' => ['query' => 'Search','allow_user_chats' => true,'allow_group_chats' => true]],
            'web-app' => ['web_app' => ['url' => 'https://example.org/app']],
            'login-url' => ['login_url' => ['url' => 'https://example.org/login','bot_username' => ['_' => 'inputUserSelf'],'forward_text' => 'Forward','request_write_access' => true]],
            'payment' => ['pay' => true],
            'game' => ['callback_game' => []],
            ] as $name => $fields
        ) {
            $expected = match ($name) {
                'url'=>'Url', 'callback'=>'Callback', 'copy','copy-legacy-string'=>'Copy',
                'switch-empty','switch-current','chosen-chat'=>'SwitchInline',
                'web-app'=>'WebView', 'login-url'=>'UrlAuth', 'payment'=>'Buy', default=>'Game',
            };
            yield $name => [['text' => 'Fixture'] + $fields, ($name === 'login-url' ? 'inputInlineButtonType' : 'inlineButtonType') . $expected];
        }
        yield 'legacy-native-http' => [['_' => 'keyboardButtonUrl', 'text' => 'Legacy HTTP', 'url' => 'http://example.org/retained'], 'inlineButtonTypeUrl'];
        yield 'current-native-http' => [['_' => 'keyboardInlineButton', 'text' => 'Current HTTP', 'type' => ['_' => 'inlineButtonTypeUrl', 'url' => 'http://example.org/retained']], 'inlineButtonTypeUrl'];
        yield 'login-without-bot' => [['text' => 'Current bot login', 'login_url' => ['url' => 'https://example.org/login']], 'inputInlineButtonTypeUrlAuth'];
        yield 'current-disabled' => [['_' => 'keyboardInlineButton','text' => 'Disabled','type' => ['_' => 'inlineButtonTypeDisabled']], 'inlineButtonTypeDisabled'];
    }

    #[DataProvider('inlineFormats')]
    public function testLegacyAndBotApiActionsRoundTripThroughOfficialLockedLayer(array $button, string $expected): void
    {
        $button['style'] = ['_' => 'keyboardButtonStyle','bg_success' => true,'icon' => '5944970388252402183'];
        $source = ['_' => 'replyInlineMarkup','rows' => [['_' => 'keyboardButtonRow','buttons' => [$button]]]];
        $before = $source;
        $native = KeyboardMarkup::normalize($source);
        self::assertSame($before, $source, 'Conversion must not mutate retained templates.');
        self::assertSame('keyboardInlineButtonRow', $native['rows'][0]['_']);
        self::assertSame('keyboardInlineButton', $native['rows'][0]['buttons'][0]['_']);
        self::assertSame($expected, $native['rows'][0]['buttons'][0]['type']['_']);
        self::assertSame($native, KeyboardMarkup::normalize($native));
        $decoded = $this->wire($native);
        $actual = $decoded['rows'][0]['buttons'][0];
        self::assertSame($expected, $actual['type']['_']);
        self::assertTrue($actual['style']['bg_success']);
        self::assertSame(5944970388252402183, $actual['style']['icon']);
        if (isset($button['login_url']) && !array_key_exists('bot_username', $button['login_url'])) {
            self::assertArrayNotHasKey('bot', $native['rows'][0]['buttons'][0]['type']);
            self::assertArrayNotHasKey('bot', $actual['type'], 'The optional bot field must follow the official current-bot default.');
        }
        if (isset($button['url']) && str_starts_with($button['url'], 'http://')) {
            self::assertSame($button['url'], $actual['type']['url']);
        }
        if ($expected === 'inlineButtonTypeCallback') {
            self::assertSame($button['data'] ?? $button['callback_data'], (string)$actual['type']['data']);
        }
    }

    public static function replyFormats(): iterable
    {
        yield 'text' => [['text' => 'Text'], 'buttonTypeDefault'];
        yield 'contact' => [['text' => 'Contact','request_contact' => true], 'buttonTypeRequestPhone'];
        yield 'gps' => [['text' => 'GPS','request_location' => true], 'buttonTypeRequestGeoLocation'];
        yield 'poll' => [['text' => 'Poll','request_poll' => ['type' => 'quiz']], 'buttonTypeRequestPoll'];
        yield 'webapp' => [['text' => 'App','web_app' => ['url' => 'https://example.org/app']], 'buttonTypeSimpleWebView'];
        yield 'legacy-gps' => [['_' => 'keyboardButtonRequestGeoLocation','text' => 'GPS'], 'buttonTypeRequestGeoLocation'];
        yield 'legacy-peer' => [['_' => 'inputKeyboardButtonRequestPeer','text' => 'Choose','button_id' => 17,'max_quantity' => 3,'peer_type' => ['_' => 'requestPeerTypeUser','bot' => false],'name_requested' => true], 'inputButtonTypeRequestPeer'];
        yield 'current' => [['_' => 'keyboardButton','text' => 'GPS','type' => ['_' => 'buttonTypeRequestGeoLocation']], 'buttonTypeRequestGeoLocation'];
    }

    #[DataProvider('replyFormats')]
    public function testReplyKeyboardsStayReplyKeyboards(array $button, string $type): void
    {
        $native = KeyboardMarkup::normalize(['keyboard' => [[$button]],'resize_keyboard' => true,'one_time_keyboard' => true,'is_persistent' => false,'input_field_placeholder' => 'Search']);
        self::assertSame('replyKeyboardMarkup', $native['_']);
        self::assertSame('keyboardButtonRow', $native['rows'][0]['_']);
        self::assertTrue($native['resize']);
        self::assertTrue($native['single_use']);
        $decoded = $this->wire($native);
        self::assertSame('keyboardButton', $decoded['rows'][0]['buttons'][0]['_']);
        self::assertSame($type, $decoded['rows'][0]['buttons'][0]['type']['_']);
        self::assertSame('Search', $decoded['placeholder']);
    }

    public function testHideForceReplyAndEmptyInlineRemainDistinct(): void
    {
        self::assertSame('replyKeyboardHide', $this->wire(KeyboardMarkup::normalize(['remove_keyboard' => true,'selective' => true]))['_']);
        self::assertSame('replyKeyboardForceReply', $this->wire(KeyboardMarkup::normalize(['force_reply' => true,'input_field_placeholder' => 'Reply']))['_']);
        self::assertSame(['_' => 'replyInlineMarkup','rows' => []], KeyboardMarkup::normalize(['inline_keyboard' => []]));
        self::assertSame([], KeyboardMarkup::normalize([]));
    }

    public static function invalid(): iterable
    {
        yield 'unknown-markup' => [['_' => 'inventedMarkup','force_reply' => true]];
        yield 'mixed-typed-action' => [['inline_keyboard' => [[['_' => 'keyboardInlineButton','text' => 'Bad','type' => ['_' => 'inlineButtonTypeBuy'],'url' => 'https://example.org']]]]];
        yield 'mixed-keyboards' => [['inline_keyboard' => [],'keyboard' => []]];
        yield 'reply-in-inline' => [['inline_keyboard' => [[['text' => 'GPS','request_location' => true]]]]];
        yield 'inline-in-reply' => [['keyboard' => [[['text' => 'Link','url' => 'https://example.org']]]]];
        yield 'reply-type-in-inline' => [['_' => 'replyInlineMarkup','rows' => [['_' => 'keyboardInlineButtonRow','buttons' => [['_' => 'keyboardInlineButton','text' => 'Bad','type' => ['_' => 'buttonTypeRequestPhone']]]]]]];
        yield 'inline-row-in-reply' => [['_' => 'replyKeyboardMarkup','rows' => [['_' => 'keyboardInlineButtonRow','buttons' => [['text' => 'Bad']]]]]];
        yield 'ambiguous' => [['inline_keyboard' => [[['text' => 'Bad','url' => 'https://example.org','callback_data' => 'x']]]]];
        yield 'overlong-callback' => [['inline_keyboard' => [[['text' => 'Bad','callback_data' => str_repeat('x', 65)]]]]];
        yield 'empty-callback' => [['inline_keyboard' => [[['text' => 'Bad','callback_data' => '']]]]];
        yield 'unknown-constructor' => [['inline_keyboard' => [[['_' => 'keyboardButtonInvented','text' => 'Bad']]]]];
        yield 'flat-http-policy' => [['inline_keyboard' => [[['text' => 'New flat HTTP', 'url' => 'http://example.org/']]]]];
        yield 'missing-url-authority-prefix' => [['inline_keyboard' => [[['text' => 'Malformed URL', 'url' => 'https:example.org']]]]];
        yield 'unsafe-url' => [['inline_keyboard' => [[['text' => 'Bad','url' => 'javascript:alert(1)']]]]];
        yield 'conflicting-colors' => [['inline_keyboard' => [[['text' => 'Bad','callback_data' => 'x','style' => ['bg_primary' => true,'bg_danger' => true]]]]]];
        yield 'unknown-action' => [['inline_keyboard' => [[['_' => 'keyboardInlineButton','text' => 'Bad','type' => ['_' => 'inlineButtonTypeInvented']]]]]];
    }

    #[DataProvider('invalid')]
    public function testMalformedAndCrossContextMarkupFailsClosed(array $markup): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KeyboardMarkup::normalize($markup);
    }

    public function testStoredOldAndCurrentTemplateJsonIsReadWithoutRewriting(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-21T12:00:00Z'));
        $settings = new SettingsRepository($db, $clock);
        $settings->ensureGroup('-1001', 'Group');
        $templates = new TemplateRepository($db, $clock);
        $old = ['_' => 'replyInlineMarkup','rows' => [['_' => 'keyboardButtonRow','buttons' => [['_' => 'keyboardButtonCopy','text' => 'Copy','copy_text' => 'Legacy','style' => ['bg_primary' => true]]]]]];
        foreach ([$old,KeyboardMarkup::normalize($old)] as $index => $buttons) {
            $kind = $index === 0 ? 'close' : 'open';
            $templates->save('-1001', $kind, ['text' => 'Text','buttons' => $buttons], '1');
            $before = $db->pdo()->query("SELECT buttons_json FROM message_templates WHERE kind='" . $kind . "'")->fetchColumn();
            $loaded = $templates->get('-1001', $kind);
            TemplateRenderer::validateButtons($loaded['buttons']);
            self::assertSame('inlineButtonTypeCopy', KeyboardMarkup::normalize(['inline_keyboard' => $loaded['buttons']])['rows'][0]['buttons'][0]['type']['_']);
            self::assertSame($before, $db->pdo()->query("SELECT buttons_json FROM message_templates WHERE kind='" . $kind . "'")->fetchColumn());
        }
    }

    public function testStartAndNavigationEmitValidTypedKeyboardsAndEditTheSamePanel(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-21T12:00:00Z'));
        $settings = new SettingsRepository($db, $clock);
        $flows = new FlowTokenRepository($db, $clock);
        $http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('Network forbidden in keyboard test.');
            }
        };
        $transport = new GatewayFixtureTransport();
        $gateway = new MadelineGateway($transport, false);
        $ui = new BotController($settings, $flows, new CalendarService(new HebcalProvider($http), $clock), $gateway, new GeoNames($http, $clock, ''), $clock);
        $ui->handleMessage(['user_id' => '1','private' => true,'text' => '/start']);
        $sent = array_values(array_filter($transport->calls, static fn(array $call): bool=>$call[0] === 'messages.sendMessage'));
        self::assertCount(1, $sent);
        self::assertSame('1', $sent[0][1]['peer']);
        $markup = $this->wire($sent[0][1]['reply_markup']);
        $actions = array_merge(...array_column($markup['rows'], 'buttons'));
        self::assertContains('sb:help', array_map(static fn(array $b): string=>(string)($b['type']['data'] ?? ''), $actions));
        self::assertContains('inlineButtonTypeUrl', array_column(array_column($actions, 'type'), '_'));
        foreach (['sb:help','sb:help:calendar','sb:start','sb:personal'] as $index => $data) {
            self::assertTrue($ui->handleCallback(['user_id' => '1','chat_id' => '1','private' => true,'message_id' => 707,'query_id' => (string)(900 + $index),'data' => $data]));
        }
        $edits = array_values(array_filter($transport->calls, static fn(array $call): bool=>$call[0] === 'messages.editMessage'));
        self::assertCount(4, $edits);
        foreach ($edits as $edit) {
            self::assertSame(707, $edit[1]['id']);
            self::assertSame('1', $edit[1]['peer']);
            self::assertNotEmpty($this->wire($edit[1]['reply_markup'])['rows']);
        }
        self::assertCount(1, array_filter($transport->calls, static fn(array $call): bool=>$call[0] === 'messages.sendMessage'));
    }

    public function testNestedInlineResultsAndBroadcastMediaUseTheSameBoundary(): void
    {
        $markup = ['inline_keyboard' => [[['text' => 'Copy','copy_text' => ['text' => 'Snapshot'],'style' => 'success']]]];
        $parameters = ['message' => 'Body','random_id' => 123,'entities' => [['_' => 'messageEntityBold','offset' => 0,'length' => 4]],'reply_markup' => $markup,
            'multi_media' => [['message' => 'Caption','random_id' => 456,'reply_markup' => $markup]],
            'results' => [['id' => 'identity','send_message' => ['message' => 'Inline','reply_markup' => $markup]]]];
        $normalized = KeyboardMarkup::parameters($parameters);
        foreach ([$normalized,$normalized['multi_media'][0],$normalized['results'][0]['send_message']] as $body) {
            self::assertSame('inlineButtonTypeCopy', $this->wire($body['reply_markup'])['rows'][0]['buttons'][0]['type']['_']);
        }
        self::assertSame(123, $normalized['random_id']);
        self::assertSame(456, $normalized['multi_media'][0]['random_id']);
        self::assertSame($parameters['entities'], $normalized['entities']);
        self::assertSame('identity', $normalized['results'][0]['id']);
    }
    private function wire(array $markup): array
    {
        $previous = Logger::$default;
        try {
            $api = (new \ReflectionClass(MTProto::class))->newInstanceWithoutConstructor();
            $api->settings = new \danog\MadelineProto\Settings();
            $api->logger = new Logger((new \danog\MadelineProto\Settings\Logger())->setType(Logger::CALLABLE_LOGGER)->setExtra(static function (): void {
            }));
            $tl = new TL($api);
            $tl->init(new TLSchema());
            $wire = $tl->serializeObject(['type' => 'ReplyMarkup'], $markup, 'reply_markup');
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $wire);
            rewind($stream);
            $decoded = $tl->deserialize($stream, ['type' => 'ReplyMarkup','connection' => null,'encrypted' => false]);
            self::assertSame(strlen($wire), ftell($stream));
            fclose($stream);
            return $decoded;
        } finally {
            $tl = null;
            $api = null;
            Logger::$default = $previous;
        }
    }
}
