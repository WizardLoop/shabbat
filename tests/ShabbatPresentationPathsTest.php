<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService, HebcalProvider};
use Shabbat\Content\ShabbatTimesFormatter;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\Application;
use Shabbat\Storage\{Database, FlowTokenRepository, SettingsRepository};
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{BotController, Lang, Presentation};

/** Offline captured provider data, real UI routes and the scheduled payload builder. */
final class ShabbatPresentationPathsTest extends TestCase
{
    public static function languagesAndExitPolicies(): iterable
    {
        foreach (Lang::CODES as $language) {
            yield $language . '-degrees' => [$language, 'degrees', 8.5, '18:41'];
            yield $language . '-fixed50' => [$language, 'minutes', 50.0, '18:55'];
        }
    }

    #[DataProvider('languagesAndExitPolicies')]
    public function testEveryDefaultPathUsesSameConciseLocalOverview(string $language, string $method, float $value, string $exit): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00+03:00'));
        $settings = new SettingsRepository($db, $clock);
        $user = $settings->getUser('1');
        $changes = ['language' => $language, 'havdalah_method' => $method, 'havdalah_value' => $value];
        $user = $settings->updateUser('1', $user['revision'], $changes);
        $group = $settings->ensureGroup('-1001', 'Calendar fixture', $changes + ['mode' => 'messages', 'times_alert_enabled' => true]);
        $http = new FullHebcalFixtureClient();
        $calendar = new CalendarService(new HebcalProvider($http), $clock);
        $flows = new FlowTokenRepository($db, $clock);
        $gateway = new UiNavigationFakeGateway();
        $geo = new GeoNames($http, $clock, '');
        $ui = new BotController($settings, $flows, $calendar, $gateway, $geo, $clock);
        $app = new Application(new GroupMessageButtonsGateway(), $clock, $db, $calendar, $geo);
        $snapshot = $calendar->snapshot($user);
        $expected = (new ShabbatTimesFormatter())->overview($user, $snapshot)['text'];
        self::assertSame($expected, $ui->renderView('1', $group, 'shabbat'), 'Group command view uses group settings.');
        self::assertSame($expected, $app->formatShabbat($group, $calendar->snapshot($group)));
        self::assertSame($expected, $app->payload($group, 'times_alert', $clock->now())['text']);

        self::assertTrue($ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => '/shabbat']));
        self::assertSame($expected, $gateway->sent[0]['text']);
        $query = ['user_id' => '1', 'chat_id' => '1', 'private' => true, 'message_id' => 101, 'query_id' => '123'];
        self::assertTrue($ui->handleCallback($query + ['data' => 'sb:show:shabbat']));
        self::assertSame($expected, $gateway->edited[0]['text']);
        $token = $flows->create('1', 'group', '-1001', $group['revision'], 'panel');
        self::assertTrue($ui->handleCallback($query + ['data' => 'sb:ap:' . $token]));
        self::assertSame($expected, $gateway->edited[1]['text'], 'Group announcement preview matches delivery.');

        self::assertTrue($ui->handleInline(['user_id' => '1', 'query_id' => '456', 'query' => 'shabat']));
        self::assertSame($expected, $gateway->inline[0]['results'][0]['send_message']['message']);
        $share = $flows->create('1', 'share', '-1001', $group['revision'], 'share_snapshot', payload: ['settings' => $group, 'module' => 'shabbat', 'date' => null]);
        self::assertTrue($ui->handleInline(['user_id' => '1', 'query_id' => '457', 'query' => 'share ' . $share]));
        self::assertSame("Calendar fixture\n" . $expected, $gateway->inline[1]['results'][0]['send_message']['message']);

        $buttons = [[['text' => 'Custom', 'url' => 'https://example.com/']]];
        $template = $app->templates->save('-1001', 'times_alert', ['text' => '', 'buttons' => $buttons, 'buttons_mode' => 'custom'], '1');
        $payload = $app->payload($group, 'times_alert', $clock->now());
        self::assertSame($expected, $payload['text'], 'Editing only buttons preserves the default calendar body.');
        self::assertSame($buttons, $payload['buttons']);
        self::assertSame($expected, $app->payload($group, 'times_alert', $clock->now(), templateOverride: $template)['text'], 'Template editor preview uses the same body.');
        $rich = ['text' => 'Personal message', 'entities' => [['_' => 'messageEntityBold', 'offset' => 0, 'length' => 8]], 'buttons_mode' => 'none'];
        $app->templates->save('-1001', 'times_alert', $rich, '1');
        self::assertSame($rich['text'], $app->payload($group, 'times_alert', $clock->now())['text'], 'Intentional custom bodies are not rewritten.');

        $lang = new Lang();
        self::assertStringContainsString('<b>' . $lang->text($language, 'times_shabbat_title') . '</b>', $expected);
        self::assertStringContainsString('Jerusalem / ירושלים', $expected);
        self::assertStringContainsString('Asia/Jerusalem', $expected);
        self::assertStringContainsString('2026-10-16 17:26', $expected);
        self::assertStringContainsString('2026-10-17 ' . $exit, $expected);
        self::assertStringContainsString($lang->text($language, 'method_' . $method) . ' ' . $value, $expected);
        self::assertStringContainsString($lang->text($language, 'times_day_name_5'), $expected);
        self::assertStringContainsString($lang->text($language, 'times_upcoming_calendar'), $expected);
        foreach (['2026-12-04','2026-12-05','2026-12-06','2026-12-07','2026-12-08'] as $date) {
            self::assertStringContainsString($date, $expected);
        }
        foreach (['2026-10-24', '2026-10-31', '2026-11-07', 'Genesis', 'Isaiah', 'Hebcal', '(+03:00)', '(+02:00)', $lang->text($language, 'times_upcoming_shabbat')] as $hidden) {
            self::assertStringNotContainsString($hidden, $expected);
        }
        self::assertSame($value, (float)$settings->getUser('1')['havdalah_value']);
        self::assertSame($method, $settings->getGroup('-1001')['havdalah_method']);
        self::assertArrayHasKey('fetched_at', $snapshot, 'Internal freshness metadata remains available.');

        $animated = Presentation::html($expected, true, [Presentation::ICONS['shabbat_holidays'] => '🕯']);
        $parsed = \danog\TelegramEntities\Entities::fromHtml($animated);
        self::assertNotEmpty(array_filter($parsed->entities, static fn(array $entity): bool => ($entity['type'] ?? '') === 'custom_emoji'));
        $plain = \danog\TelegramEntities\Entities::fromHtml(Presentation::html($animated, false));
        self::assertSame($parsed->message, $plain->message, 'Custom emoji fallback preserves visible text.');
        self::assertEmpty(array_filter($plain->entities, static fn(array $entity): bool => ($entity['type'] ?? '') === 'custom_emoji'));
        self::assertLessThanOrEqual(4000, strlen(mb_convert_encoding($plain->message, 'UTF-16LE', 'UTF-8')) / 2);
    }

    public function testLocationAliasesOnlyComeFromMatchingCuratedJerusalemAndNeverOverwriteCustomNames(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00+03:00'));
        $settings = (new SettingsRepository($db, $clock))->getUser('1');
        $formatter = new ShabbatTimesFormatter();
        self::assertStringContainsString('Jerusalem / ירושלים', $formatter->format($settings, [])['text']);
        foreach (
            [
            ['location_name' => 'My neighborhood'],
            ['location_name' => 'London', 'geoname_id' => 2643743, 'timezone_id' => 'Europe/London'],
            ['latitude' => 30.0],
            ['geoname_id' => 999],
            ] as $changed
        ) {
            $context = array_replace($settings, $changed);
            $text = $formatter->format($context, [])['text'];
            self::assertStringNotContainsString('Jerusalem / ירושלים', $text);
            self::assertStringContainsString($context['location_name'], $text);
        }
    }

    public function testDefaultGroupOffsetsStayQuietAndConfiguredOffsetsRemainVisible(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00+03:00'));
        $settings = (new SettingsRepository($db, $clock))->ensureGroup('-1001', 'Group', ['mode' => 'lock_messages']);
        $calendar = new CalendarService(new HebcalProvider(new FullHebcalFixtureClient()), $clock);
        $formatter = new ShabbatTimesFormatter();
        $plain = $formatter->overview($settings, $calendar->snapshot($settings))['text'];
        self::assertStringNotContainsString('סגירת הקבוצה:', $plain);
        self::assertStringNotContainsString('פתיחת הקבוצה:', $plain);
        $settings['close_before_candles_minutes'] = 10;
        $settings['open_after_havdalah_minutes'] = 15;
        $changed = $formatter->overview($settings, $calendar->snapshot($settings))['text'];
        self::assertStringContainsString('סגירת הקבוצה: <b>2026-10-16 17:16</b>', $changed);
        self::assertStringContainsString('פתיחת הקבוצה: <b>2026-10-17 18:56</b>', $changed);
        self::assertStringContainsString('2026-10-17 18:41', $changed);
    }
}
