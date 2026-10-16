<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Content\ShabbatTimesFormatter;
use Shabbat\UI\Lang;

final class ShabbatTimesFormatterTest extends TestCase
{
    private function settings(string $language = 'en'): array
    {
        return ['language' => $language,'location_name' => 'Jerusalem','timezone_id' => 'Asia/Jerusalem','geoname_id' => 281184,'israel' => false,'candle_minutes' => 40,'havdalah_method' => 'minutes','havdalah_value' => 72,'mode' => 'lock_messages'];
    }

    private function timestamp(string $date): int
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('Asia/Jerusalem')))->getTimestamp();
    }

    private function event(): array
    {
        return ['event_key' => '2026-05-22+2026-05-23','days' => ['2026-05-22','2026-05-23'],'candles_at' => $this->timestamp('2026-05-21 17:20'),'havdalah_at' => $this->timestamp('2026-05-23 19:12'),'close_at' => $this->timestamp('2026-05-21 17:10'),'open_at' => $this->timestamp('2026-05-23 19:27'),'intermediate' => [
            ['event_key' => '2026-05-23','days' => ['2026-05-23'],'candles_at' => $this->timestamp('2026-05-22 17:20'),'havdalah_at' => $this->timestamp('2026-05-22 19:12')],
        ]];
    }

    private function snapshot(): array
    {
        return ['next_event' => $this->event(),'fetched_at' => $this->timestamp('2026-05-20 10:00'),'calendar_items' => [
            ['date' => '2026-05-22','category' => 'holiday','title' => 'Shavuot I','hebrew' => 'שבועות א׳','hdate' => '6 Sivan 5786','memo' => 'Giving of the Torah'],
            ['date' => '2026-05-23','category' => 'holiday','title' => 'Shavuot II','hebrew' => 'שבועות ב׳','hdate' => '7 Sivan 5786'],
            ['date' => '2026-05-23','category' => 'parashat','title' => 'Bamidbar','hebrew' => 'במדבר','leyning' => ['torah' => 'Numbers 1:1–4:20']],
            ['date' => '2026-05-23','category' => 'mevarchim','title' => 'Mevarchim Chodesh Sivan','hebrew' => 'מברכים חודש סיוון'],
            ['date' => '2026-05-30','category' => 'holiday','title' => 'Unrelated event'],
        ]];
    }

    public function testSequenceUsesEveryCandleTimeAndFinalHavdalahOnly(): void
    {
        $payload = (new ShabbatTimesFormatter())->format($this->settings(), $this->snapshot());
        $text = html_entity_decode(strip_tags($payload['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach (['Jerusalem','Asia/Jerusalem','2026-05-21 17:20','2026-05-22 17:20','2026-05-23 19:12','2026-05-23 19:27','2026-05-21 17:10','6 Sivan 5786','7 Sivan 5786','Bamidbar','Mevarchim Chodesh Sivan','40 minutes','72'] as $value) {
            self::assertStringContainsString($value, $text);
        }
        self::assertStringNotContainsString('2026-05-22 19:12', $text);
        self::assertStringNotContainsString('Unrelated event', $text);
        foreach (['Hebcal', 'Giving of the Torah', 'Numbers 1:1–4:20', '2026-05-20 10:00'] as $hidden) {
            self::assertStringNotContainsString($hidden, $text);
        }
        self::assertStringNotContainsString('Candle lighting for 2026-05-22+2026-05-23', $text);
        self::assertArrayNotHasKey('entities', $payload, 'Built-in HTML must remain distinct from native empty-entity text.');
        self::assertSame([], $payload['buttons']);
        self::assertNull($payload['media']);
    }

    public function testExplicitScheduledEventOverridesCurrentEvent(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['current_event'] = ['event_key' => '2026-05-01','days' => ['2026-05-01'],'candles_at' => $this->timestamp('2026-04-30 17:00')];
        $text = (new ShabbatTimesFormatter())->format($this->settings(), $snapshot, $this->event())['text'];
        self::assertStringContainsString('2026-05-23 19:12', $text);
        self::assertStringNotContainsString('2026-04-30', $text);
    }

    public function testAllSixGroupLanguagesAndSafeProviderMetadata(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            $snapshot = $this->snapshot();
            $snapshot['calendar_items'][0]['title'] = '<script>holiday & unsafe</script>';
            $snapshot['calendar_items'][0]['hebrew'] = '<script>חג</script>';
            $payload = (new ShabbatTimesFormatter($lang))->format($this->settings($language), $snapshot);
            self::assertStringContainsString($lang->text($language, 'times_heading'), $payload['text']);
            self::assertStringContainsString($lang->text($language, 'times_sequence'), $payload['text']);
            self::assertStringNotContainsString('<script>', $payload['text']);
            self::assertStringContainsString('&lt;script&gt;', $payload['text']);
        }
    }

    public function testDstUsesCorrectLocalTimeWithoutNumericOffsetAndMessagesOnlyDoesNotPromiseLocking(): void
    {
        $settings = $this->settings();
        $settings['timezone_id'] = 'Europe/London';
        $settings['mode'] = 'messages';
        $event = ['event_key' => '2026-10-24','days' => ['2026-10-24'],'candles_at' => (new \DateTimeImmutable('2026-10-23T16:00:00Z'))->getTimestamp(),'havdalah_at' => (new \DateTimeImmutable('2026-10-25T18:00:00Z'))->getTimestamp(),'close_at' => 1,'open_at' => 2];
        $text = (new ShabbatTimesFormatter())->format($settings, ['next_event' => $event])['text'];
        self::assertStringContainsString('2026-10-23 17:00', $text);
        self::assertStringContainsString('2026-10-25 18:00', $text);
        self::assertStringNotContainsString('Group closes:', $text);
        self::assertStringNotContainsString('Group opens:', $text);
    }

    public function testUnavailableDataIsExplicitAndOutputIsBoundedByUtf16Units(): void
    {
        $formatter = new ShabbatTimesFormatter();
        $missing = $formatter->format($this->settings(), ['error' => 'private network error']);
        self::assertStringContainsString((new Lang())->text('en', 'times_data_missing'), $missing['text']);
        self::assertStringNotContainsString('private network error', $missing['text']);
        $snapshot = $this->snapshot();
        $snapshot['next_event']['candles_at'] = null;
        $snapshot['calendar_items'][0]['title'] = str_repeat('🔔<&>', 1500);
        $payload = $formatter->format($this->settings(), $snapshot);
        $plain = html_entity_decode(strip_tags($payload['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertLessThanOrEqual(4000, strlen(mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8')) / 2);
        self::assertStringNotContainsString('<script>', $payload['text']);
    }

    public function testStaleWarningIsExplicitOnlyForStaleVerifiedData(): void
    {
        $lang = new Lang();
        $formatter = new ShabbatTimesFormatter($lang);
        foreach (Lang::CODES as $language) {
            $snapshot = $this->snapshot();
            $fresh = $formatter->format($this->settings($language), $snapshot)['text'];
            self::assertStringNotContainsString($lang->text($language, 'times_stale'), $fresh);
            $snapshot['stale'] = true;
            $cached = $formatter->format($this->settings($language), $snapshot)['text'];
            self::assertStringContainsString($lang->text($language, 'times_stale'), $cached);
            self::assertStringContainsString('2026-05-23 19:12', $cached);
        }
    }

    public function testWeekdaysAndConciseScheduleArePreservedInEveryLanguage(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            $text = (new ShabbatTimesFormatter($lang))->format($this->settings($language), $this->snapshot())['text'];
            self::assertStringContainsString('<b>' . $lang->text($language, 'times_section_schedule') . '</b>', $text);
            self::assertStringNotContainsString('<b>' . $lang->text($language, 'times_section_calendar') . '</b>', $text);
            self::assertDoesNotMatchRegularExpression('/\([+-]\d{2}:\d{2}\)/', $text);
            self::assertStringContainsString($lang->text($language, 'times_day_name_5') . ' · 2026-05-22', strip_tags($text));
            self::assertStringContainsString($lang->text($language, 'times_day_name_4') . ' · 2026-05-21', strip_tags($text));
            self::assertStringContainsString('2026-05-21 17:20', $text);
            self::assertStringContainsString('2026-05-23 19:12', $text);
            self::assertLessThan(strpos($text, $lang->text($language, 'times_section_schedule')), strpos($text, '2026-05-22 / 2026-05-23'));
        }
    }
    public function testOfficialProviderRequestsParashaAndMevarchimMetadata(): void
    {
        $http = new class implements \Shabbat\Http\JsonClient
        {
            public array $query = [];
            public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array
            {
                $this->query = $query;
                return ['range' => ['start' => $query['start'],'end' => $query['end']],'items' => []];
            }
        };
        (new \Shabbat\Calendar\HebcalProvider($http))->calendar('2026-05-01', '2026-05-30', false, 'fr');
        self::assertSame('on', $http->query['s']);
        self::assertSame('on', $http->query['mvch']);
        self::assertSame('fr', $http->query['lg']);
        self::assertSame('off', $http->query['i']);
        self::assertArrayNotHasKey('mv', $http->query);
    }
}
