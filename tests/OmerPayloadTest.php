<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Content\OmerText;
use Shabbat\Runtime\Application;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\Lang;

require_once __DIR__ . '/Fixtures/CalendarFixtureClient.php';

final class OmerPayloadTest extends TestCase
{
    public function testActualFullMessageFitsAllDaysLocalesAndVariantsWithBoundedEmojiNames(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new DateTimeImmutable('2026-04-02T20:00:00+03:00'));
        $gateway = new RuntimeFakeGateway();
        $calendar = new CalendarService(new HebcalProvider(new CalendarFixtureClient()), $clock);
        $app = new Application($gateway, $clock, $db, $calendar);
        $settings = new SettingsRepository($db, $clock);
        $name = str_repeat('😀', 256);
        self::assertSame(256, mb_strlen($name, 'UTF-8'));
        foreach (Lang::CODES as $index => $language) {
            foreach (['ashkenaz' => '3017054', 'sephard' => '2929975'] as $nusach => $revision) {
                $group = $settings->ensureGroup('-100' . $index . ($nusach === 'ashkenaz' ? '1' : '2'), $name, [
                    'language' => $language, 'location_name' => $name, 'omer_nusach' => $nusach, 'omer_format' => 'full',
                ]);
                for ($day = 1; $day <= 49; $day++) {
                    $instant = (new DateTimeImmutable('2026-04-02T20:00:00+03:00'))->modify('+' . ($day - 1) . ' days');
                    $clock->set($instant);
                    $payload = $app->payload($group, 'omer', $instant);
                    self::assertLessThanOrEqual(4096, strlen(mb_convert_encoding($payload['text'], 'UTF-16LE', 'UTF-8')) / 2, $language . ':' . $nusach . ':' . $day);
                    self::assertStringContainsString(OmerText::count($day, $nusach, $language), html_entity_decode($payload['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    self::assertStringContainsString('Hebrew Wikisource contributors', $payload['text']);
                    self::assertStringContainsString('CC-BY-SA-4.0', $payload['text']);
                    self::assertStringContainsString('https://he.wikisource.org/w/index.php?oldid=' . $revision, $payload['text']);
                    self::assertStringContainsString('https://creativecommons.org/licenses/by-sa/4.0/', $payload['text']);
                    self::assertStringContainsString($name, $payload['text']);
                    self::assertStringContainsString('הרחמן', $payload['text']);
                    self::assertStringContainsString('אנא בכוח', $payload['text']);
                }
            }
        }
        self::assertSame([], $gateway->sent);
        self::assertSame(0, $gateway->mutations);
    }

    public function testShortAndDayCompletionDoNotAppendUnincludedLiturgyGrant(): void
    {
        foreach (Lang::CODES as $language) {
            self::assertStringNotContainsString('CC-BY-SA', OmerText::render(49, 'ashkenaz', false, false, $language));
            self::assertStringNotContainsString('CC-BY-SA', OmerText::render(49, 'sephard', true, true, $language));
            self::assertStringNotContainsString('ברוך אתה', OmerText::render(49, 'sephard', true, true, $language));
        }
    }
}
