<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use danog\MadelineProto\TextEntities;
use danog\MadelineProto\EventHandler\Message\Entities\{Bold, CustomEmoji, MessageEntity, TextUrl};
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService, HebcalProvider};
use Shabbat\Content\{DafFormatter, GroupMessageButtons};
use Shabbat\Http\{JsonClient, ProviderException};
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\{Application, MadelineGateway, Runner};
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{Lang, Presentation};

final class DafFormatterTest extends TestCase
{
    private const URL = 'https://www.sefaria.org/Bekhorot.35a?lang=bi&utm_source=hebcal.com&utm_medium=api';
    private const DATE = '2026-10-23';
    private Application $app;
    private FrozenClock $clock;
    private JsonClient $http;
    private array $group;
    private array $data;

    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }

    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-23T08:00:00+03:00'));
        $this->data = [
            'title' => 'Bekhorot 35', 'hebrew' => 'בכורות ל״ה', 'date' => self::DATE,
            'category' => 'dafyomi', 'hdate' => '12 Cheshvan 5787', 'link' => self::URL,
        ];
        $this->http = new class ($this->data) implements JsonClient
        {
            public bool $fail = false;
            public int $dailyCalls = 0;
            public function __construct(public array $daf)
            {
            }
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                $cancellation?->throwIfRequested();
                if ($this->fail) {
                    throw new ProviderException('Daf provider temporarily unavailable.');
                }
                if (str_ends_with($url, '/hebcal') && $query['start'] === $query['end']) {
                    $this->dailyCalls++;
                    return ['range' => ['start' => $query['start'], 'end' => $query['end']], 'items' => [$this->daf]];
                }
                return (new FullHebcalFixtureClient())->get($url, array_replace($query, ['lg' => 'he']), $cancellation);
            }
        };
        $gateway = new MadelineGateway(new GatewayFixtureTransport(), false);
        $this->app = new Application(
            $gateway,
            $this->clock,
            $db,
            new CalendarService(new HebcalProvider($this->http), $this->clock),
            new GeoNames($this->http, $this->clock, '')
        );
        $this->app->settings->ensureGroup('-1001', 'Daf Group');
        $this->group = array_replace($this->app->settings->getGroup('-1001'), [
            'location_name' => 'Jerusalem & <Old City>', 'target_topic_id' => 77,
        ]);
    }

    public function testPrivateAndScheduledCardsMatchInAllSixLanguages(): void
    {
        foreach (Lang::CODES as $language) {
            $group = array_replace($this->group, ['language' => $language]);
            $private = $this->app->ui->renderView('1', $group, 'daf', self::DATE);
            $scheduled = $this->app->payload($group, 'daf', $this->clock->now());
            self::assertSame($private, $scheduled['text'], $language);
            self::assertSame(77, $scheduled['topic_id']);
            self::assertSame(GroupMessageButtons::defaults('daf', $language), $scheduled['buttons']);
            $parsed = TextEntities::fromHtml($scheduled['text']);
            self::assertStringContainsString('Jerusalem & <Old City>', $parsed->message);
            self::assertStringContainsString('Asia/Jerusalem', $parsed->message);
            self::assertStringContainsString(self::DATE . ' · ' . $this->data['hdate'], $parsed->message);
            self::assertStringContainsString($language === 'he' ? $this->data['hebrew'] : $this->data['title'], $parsed->message);
            self::assertSame(self::URL, $this->url($parsed));
            self::assertCount(2, array_filter($parsed->entities, static fn(MessageEntity $e): bool => $e instanceof Bold));
            self::assertSame(1, substr_count($parsed->message, '📖'));
            self::assertStringNotContainsString('&amp;amp;', $scheduled['text']);
        }
        self::assertSame(12, $this->http->dailyCalls, 'Each consumer loads the Daf once; the formatter performs no provider requests.');
    }

    public function testBareDefaultAndKeyboardOnlyEditsKeepTheRichCard(): void
    {
        $default = $this->app->payload($this->group, 'daf', $this->clock->now());
        $withoutInline = $this->app->payload(array_replace($this->group, ['daf_template' => '']), 'daf', $this->clock->now());
        self::assertSame($default, $withoutInline);
        $buttons = [[['text' => 'Study', 'url' => self::URL, 'style' => 'primary']]];
        $this->app->templates->save('-1001', 'daf', ['text' => '', 'buttons_mode' => 'custom', 'buttons' => $buttons], '1');
        $edited = $this->app->payload($this->group, 'daf', $this->clock->now());
        self::assertSame($default['text'], $edited['text']);
        self::assertSame($buttons, $edited['buttons']);
        self::assertArrayNotHasKey('entities', $edited);
        self::assertSame(self::URL, $this->url(TextEntities::fromHtml($edited['text'])));
        self::assertSame(77, $edited['topic_id']);
    }

    public function testHtmlTemplateEscapesTheRawVariableOnlyOnce(): void
    {
        $this->http->daf['title'] = 'Bekhorot & <study> 35';
        $this->http->daf['hebrew'] = 'בכורות & <לימוד> ל״ה';
        $group = array_replace($this->group, ['daf_template' => '<b>Study</b> {{daf}}']);
        $payload = $this->app->payload($group, 'daf', $this->clock->now());
        $parsed = TextEntities::fromHtml($payload['text']);
        self::assertSame('Study ' . (new DafFormatter())->plain($group, $this->http->daf, self::DATE), $parsed->message);
        self::assertStringContainsString('&amp;utm_source=', $payload['text']);
        self::assertStringNotContainsString('&amp;amp;', $payload['text']);
        self::assertStringContainsString('בכורות & <לימוד>', $parsed->message);
    }

    public function testNativeTemplateRetainsRawTextEntitiesMediaButtonsAndTopic(): void
    {
        $source = '😀 {{daf}}';
        $template = [
            'text' => $source,
            'entities' => [['_' => 'messageEntityBold', 'offset' => 3, 'length' => 7]],
            'media' => ['file_id' => 'fixture-media'],
            'buttons_mode' => 'custom',
            'buttons' => [[['text' => 'Read', 'url' => self::URL]]],
        ];
        $original = $template;
        $payload = $this->app->payload($this->group, 'daf', $this->clock->now(), $template);
        $raw = (new DafFormatter())->plain($this->group, $this->data, self::DATE);
        self::assertSame('😀 ' . $raw, $payload['text']);
        self::assertSame([['_' => 'messageEntityBold', 'offset' => 3, 'length' => $this->utf16($raw)]], $payload['entities']);
        self::assertSame($template['media'], $payload['media']);
        self::assertSame($template['buttons'], $payload['buttons']);
        self::assertSame(77, $payload['topic_id']);
        self::assertSame($original, $template);
        self::assertStringNotContainsString('&amp;', $payload['text']);
        // HTML rendering of the same variable gives exactly the same visible raw text.
        $html = $this->app->payload(array_replace($this->group, ['daf_template' => '😀 {{daf}}']), 'daf', $this->clock->now());
        self::assertSame(TextEntities::fromHtml($html['text'])->message, $payload['text']);
    }

    public function testExplicitNativeEmptyEntitiesAreNotReplacedByTheDefaultCard(): void
    {
        $template = ['text' => '{{daf}}', 'entities' => [], 'media' => null, 'buttons_mode' => 'none', 'buttons' => []];
        $payload = $this->app->payload($this->group, 'daf', $this->clock->now(), $template);
        self::assertSame((new DafFormatter())->plain($this->group, $this->data, self::DATE), $payload['text']);
        self::assertSame([], $payload['entities']);
        self::assertSame('none', $payload['buttons_mode']);
        self::assertArrayNotHasKey('buttons', $payload);
    }

    public function testAnimatedDafIconAndFallbackRetainTheSameCardAndUrl(): void
    {
        $payload = $this->app->payload($this->group, 'daf', $this->clock->now());
        $animated = Presentation::payload($payload, true, EmojiFixtureTransport::alternatives());
        $basic = Presentation::payload($animated, false);
        $parsed = TextEntities::fromHtml($animated['text']);
        $fallback = TextEntities::fromHtml($basic['text']);
        self::assertSame($parsed->message, $fallback->message);
        self::assertSame(self::URL, $this->url($parsed));
        self::assertSame(self::URL, $this->url($fallback));
        $icons = array_values(array_filter($parsed->entities, static fn(MessageEntity $e): bool => $e instanceof CustomEmoji));
        self::assertCount(1, $icons);
        self::assertSame(5947481135939328427, $icons[0]->documentId);
        self::assertSame([], array_values(array_filter($fallback->entities, static fn(MessageEntity $e): bool => $e instanceof CustomEmoji)));
        self::assertSame($animated, Presentation::payload($animated, true, EmojiFixtureTransport::alternatives()));
    }

    public function testPrivateLanguageOverrideUsesTheSharedFormatter(): void
    {
        $private = $this->app->ui->renderView('1', $this->group, 'daf', self::DATE, 'en');
        self::assertSame((new DafFormatter())->html(array_replace($this->group, ['language' => 'en']), $this->data, self::DATE), $private);
    }

    public function testScheduledProviderFailurePropagatesAndCanRecover(): void
    {
        $this->http->fail = true;
        try {
            $this->app->payload($this->group, 'daf', $this->clock->now());
            self::fail('A failed provider must not become a deliverable error-text message.');
        } catch (ProviderException $error) {
            self::assertSame('Daf provider temporarily unavailable.', $error->getMessage());
        }
        self::assertSame((new Lang())->text('he', 'provider_unavailable'), $this->app->ui->renderView('1', $this->group, 'daf', self::DATE));
        $this->http->fail = false;
        $retried = $this->app->payload($this->group, 'daf', $this->clock->now());
        self::assertSame(self::URL, $this->url(TextEntities::fromHtml($retried['text'])));
    }

    private function url(TextEntities $message): string
    {
        foreach ($message->entities as $entity) {
            if ($entity instanceof TextUrl) {
                return $entity->url;
            }
        }
        self::fail('The rich Daf card has no page link.');
    }

    private function utf16(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
