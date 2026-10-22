<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use danog;
use DateTimeImmutable;
use ReflectionClass;
use RuntimeException;
use danog\MadelineProto\Logger;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\Settings\TLSchema;
use danog\MadelineProto\TL\TL;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\Runner;
use Shabbat\Storage\Database;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\BotController;

/** Actual locked serializer and decoder: no MTProto constructor, session, credentials or network. */
final class InlineSerializationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }

    public function testNativeInlineRpcWireDistinguishesMissingAndNonEmptyMarkup(): void
    {
        $previousLogger = Logger::$default;
        try {
            $adapter = (new ReflectionClass(MTProto::class))->newInstanceWithoutConstructor();
            $adapter->settings = new danog\MadelineProto\Settings();
            $adapter->logger = new Logger((new danog\MadelineProto\Settings\Logger())->setType(Logger::CALLABLE_LOGGER)->setExtra(static function (): void {
            }));
            $tl = new TL($adapter);
            $tl->init(new TLSchema());
            $clock = new FrozenClock(new DateTimeImmutable('2026-10-14T12:00:00Z'));
            $db = Database::open(':memory:');
            $db->migrate();
            $settings = new SettingsRepository($db, $clock);
            $flows = new FlowTokenRepository($db, $clock);
            $group = $settings->ensureGroup('-1001', 'Native serialization group');
            $http = new class implements JsonClient {
                public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
                {
                    $cancellation?->throwIfRequested();
                    if (!str_ends_with($url, '/searchJSON')) {
                        throw new RuntimeException('No other remote request is allowed by this offline fixture.');
                    }
                    return ['totalResultsCount' => 1, 'geonames' => [['geonameId' => 281184, 'name' => 'Jerusalem', 'countryName' => 'Israel', 'lat' => '31.76904', 'lng' => '35.21633', 'timezone' => ['timeZoneId' => 'Asia/Jerusalem']]]];
                }
            };
            $transport = new GatewayFixtureTransport();
            $transport->responses['channels.getParticipant'] = ['participant' => ['_' => 'channelParticipantAdmin']];
            $gateway = new MadelineGateway($transport);
            $ui = new BotController($settings, $flows, new CalendarService(new HebcalProvider($http), $clock), $gateway, new GeoNames($http, $clock, 'offline-fixture'), $clock);
            $ensure = static function (bool $condition, string $message): void {
                self::assertTrue($condition, $message);
            };
            $lastInline = static function () use ($transport, $ensure): array {
                $calls = array_values(array_filter($transport->calls, static fn (array $call): bool => $call[0] === 'messages.setInlineBotResults'));
                $ensure($calls !== [], 'No native inline RPC was emitted.');
                return $calls[array_key_last($calls)][1];
            };
            $roundTrip = static function (array $params) use ($tl, $ensure): array {
                $wire = $tl->serializeMethod('messages.setInlineBotResults', $params);
                $stream = fopen('php://memory', 'w+b');
                fwrite($stream, $wire);
                rewind($stream);
                $decoded = $tl->deserialize($stream, ['type' => '', 'connection' => null, 'encrypted' => false]);
                $ensure(ftell($stream) === strlen($wire), 'Native decoder did not consume the full RPC payload.');
                fclose($stream);
                $ensure($decoded['_'] === 'method_messages.setInlineBotResults', 'Native RPC identity mismatch.');
                $ensure($decoded['query_id'] === $params['query_id'], 'Native query identity changed.');
                return [$decoded, strlen($wire), hash('sha256', $wire)];
            };
            $cases = [];
            foreach (['public-city' => 'Jerusalem', 'public-timezone' => 'tz Jerusalem'] as $case => $query) {
                $ui->handleInline(['user_id' => '1', 'query_id' => '123', 'query' => $query]);
                $params = $lastInline();
                [$decoded, $bytes, $sha] = $roundTrip($params);
                $ensure($decoded['results'] !== [], 'Buttonless inline results unexpectedly empty.');
                foreach ($decoded['results'] as $result) {
                    $ensure($result['_'] === 'inputBotInlineResult', 'Wrong input result constructor.');
                    $ensure($result['send_message']['_'] === 'inputBotInlineMessageText', 'Wrong input message constructor.');
                    $ensure(!array_key_exists('reply_markup', $result['send_message']), 'Buttonless native message contains a reply markup flag.');
                }
                $cases[$case] = ['results' => count($decoded['results']), 'wire_bytes' => $bytes, 'wire_sha256' => $sha, 'reply_markup_present' => false];
            }
            $buttonless = $lastInline();
            $buttonless['results'][0]['send_message']['reply_markup'] = ['_' => 'replyInlineMarkup', 'rows' => []];
            [$oldDecoded, $oldBytes] = $roundTrip($buttonless);
            $ensure(array_key_exists('reply_markup', $oldDecoded['results'][0]['send_message']), 'Old empty markup did not set the native optional-field flag.');
            $ensure($oldDecoded['results'][0]['send_message']['reply_markup']['rows'] === [], 'Old empty keyboard was not preserved on wire.');
            $cases['dev1-empty-markup-reproduction'] = ['wire_bytes' => $oldBytes, 'reply_markup_present' => true, 'rows' => 0, 'server_rejection' => 'REPLY_MARKUP_INVALID corroborated by supplied real test-bot trace; no live RPC made here'];
            foreach (['bound-city' => ['city', 'location Jerusalem'], 'bound-timezone' => ['timezone', 'timezone Jerusalem']] as $case => [$kind, $query]) {
                $token = $flows->create('1', 'group', '-1001', $group['revision'], 'panel');
                $ui->handleCallback(['user_id' => '1', 'chat_id' => '1', 'private' => true, 'message_id' => 707, 'query_id' => '124', 'data' => 'sb:lookup:' . $token . ':' . $kind]);
                $ui->handleInline(['user_id' => '1', 'query_id' => '125', 'query' => $query]);
                [$decoded, $bytes, $sha] = $roundTrip($lastInline());
                $ensure($decoded['results'] !== [], 'Bound inline results unexpectedly empty.');
                foreach ($decoded['results'] as $result) {
                    $markup = $result['send_message']['reply_markup'] ?? [];
                    $ensure(($markup['_'] ?? '') === 'replyInlineMarkup' && ($markup['rows'] ?? []) !== [], 'Bound message has no nonempty inline markup.');
                    $buttons = array_merge(...array_column($markup['rows'], 'buttons'));
                    $ensure(count($buttons) === 2, 'Expected exactly a selection callback and private deep link.');
                    $ensure($buttons[0]['_'] === 'keyboardInlineButton' && $buttons[0]['type']['_'] === 'inlineButtonTypeCallback', 'Wrong native selection button.');
                    $ensure(str_starts_with((string)$buttons[0]['type']['data'], 'sb:lp:'), 'Selection flow identity changed.');
                    $ensure($buttons[1]['_'] === 'keyboardInlineButton' && $buttons[1]['type']['_'] === 'inlineButtonTypeUrl' && str_starts_with($buttons[1]['type']['url'], 'https://t.me/FixtureShabbatBot?start=loc_'), 'Wrong native confirmation link.');
                }
                $cases[$case] = ['results' => count($decoded['results']), 'wire_bytes' => $bytes, 'wire_sha256' => $sha, 'reply_markup_present' => true, 'buttons_per_result' => 2];
            }
            $unconfigured = new BotController($settings, $flows, new CalendarService(new HebcalProvider($http), $clock), $gateway, new GeoNames($http, $clock, ''), $clock);
            $unconfigured->handleInline(['user_id' => '1', 'query_id' => '126', 'query' => 'UnknownCity']);
            [$decoded, $bytes] = $roundTrip($lastInline());
            $ensure($decoded['results'][0]['id'] === 'location-unavailable', 'Provider error article missing.');
            $ensure(!array_key_exists('reply_markup', $decoded['results'][0]['send_message']), 'Provider error fallback contains invalid empty native markup.');
            $cases['provider-error'] = ['wire_bytes' => $bytes, 'reply_markup_present' => false];
            $ensure($settings->getGroup('-1001')['revision'] === $group['revision'] && $settings->getUser('1')['revision'] === 1, 'Inline search mutated settings.');
            self::assertSame(['public-city', 'public-timezone', 'dev1-empty-markup-reproduction', 'bound-city', 'bound-timezone', 'provider-error'], array_keys($cases));
        } finally {
            $tl = null;
            $adapter = null;
            Logger::$default = $previousLogger;
        }
    }
}
