<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Storage\Database;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\BotController;
use Shabbat\UI\Lang;
use Shabbat\UI\UiGateway;

final class UITest extends TestCase
{
    private SettingsRepository $settings;
    private FlowTokenRepository $flows;
    private FrozenClock $clock;
    private BotController $ui;
    private UiGateway $gateway;
    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-13T12:00:00Z'));
        $this->settings = new SettingsRepository($db, $this->clock);
        $this->flows = new FlowTokenRepository($db, $this->clock);
        $this->settings->ensureGroup('-1001', 'Group One');
        $this->settings->ensureGroup('-1002', 'Group Two');
        $this->gateway = new class implements UiGateway, \Shabbat\Telegram\MessageMaintenanceGateway {
            public array $messages = [];
            public array $sent = [];
            public array $edited = [];
            public array $inline = [];
            public array $answers = [];
            public bool $admin = true;
            public bool $capabilitiesUnavailable = false;
            public bool $revokeDuringCapabilities = false;
            public function botCapabilities(string $group): array
            {
                if ($this->revokeDuringCapabilities) {
                    $this->admin = false;
                }
                if ($this->capabilitiesUnavailable) {
                    throw new \RuntimeException('offline permission lookup failed');
                }
                return ['verified' => true,'group_id' => $group,'peer_type' => 'supergroup','creator' => false,'is_admin' => true,'can_delete_messages' => true,'rights' => array_replace(array_fill_keys(\Shabbat\Telegram\AdminRights::FLAGS, false), ['delete_messages' => true])];
            }
            public function botCanDeleteMessages(string $group): bool
            {
                return true;
            }
            public function deleteMessages(string $peer, array $messageIds): void
            {
                throw new \RuntimeException('No deletion permitted in UI fixtures');
            }
            public function senderIsBot(string $group, string $senderId): ?bool
            {
                return null;
            }
            public function selfUserId(): string
            {
                return '999';
            }
            public function extractSentMessageIds(array $response, ?string $expectedRandomId = null, ?string $expectedPeer = null): array
            {
                return [];
            }
            public function isGroupAdmin(string $userId, string $groupId): bool
            {
                return $this->admin && $userId === '1';
            }
            public function sendPrivate(string $userId, string $text, array $markup = []): void
            {
                $this->messages[] = compact('userId', 'text', 'markup');
                $this->sent[] = compact('userId', 'text', 'markup');
            }
            public function editPrivate(string $userId, int $messageId, string $text, array $markup = []): void
            {
                $this->messages[] = compact('userId', 'text', 'markup');
                $this->edited[] = compact('userId', 'messageId', 'text', 'markup');
            }
            public function answerCallback(string $queryId, string $text): void
            {
                $this->answers[] = compact('queryId', 'text');
            }
            public function answerInline(string $queryId, array $results, array $options = []): void
            {
                $this->inline[] = compact('queryId', 'results', 'options');
            }
            public function botUsername(): string
            {
                return 'ShabbatFixtureBot';
            }
        };
        $http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                $cancellation?->throwIfRequested();
                if (str_ends_with($url, 'searchJSON')) {
                    return ['geonames' => [$this->place(str_contains(strtolower($query['q']), 'london') ? 2643743 : 281184)],'totalResultsCount' => 1];
                }
                if (str_ends_with($url, 'getJSON')) {
                    return $this->place((int)$query['geonameId']);
                }
                if (str_ends_with($url, 'timezoneJSON')) {
                    return ['timezoneId' => $query['lat'] > 50 ? 'Europe/London' : 'Asia/Jerusalem','countryName' => $query['lat'] > 50 ? 'United Kingdom' : 'Israel'];
                }
                if (str_ends_with($url, '/hebcal')) {
                    return ['range' => ['start' => $query['start'],'end' => $query['end']],'items' => []];
                }
                $times = [];
                for ($day = new \DateTimeImmutable($query['start']); $day->format('Y-m-d') <= $query['end']; $day = $day->modify('+1 day')) {
                    $date = $day->format('Y-m-d');
                    foreach (['sunset' => '18:00','tzeit85deg' => '18:45','misheyakir' => '05:45','sunrise' => '06:30','alotHaShachar' => '05:00','minchaGedola' => '12:30'] as $name => $hour) {
                        $times[$name][$date] = (new \DateTimeImmutable($date . ' ' . $hour, new \DateTimeZone($query['tzid'])))->format('Y-m-d\TH:i:sP');
                    }
                }
                return ['date' => ['start' => $query['start'],'end' => $query['end']],'times' => $times];
            }
            private function place(int $id): array
            {
                return $id === 2643743 ? ['geonameId' => $id,'name' => 'London','countryName' => 'United Kingdom','adminName1' => 'England','lat' => '51.50853','lng' => '-0.12574','timezone' => ['timeZoneId' => 'Europe/London']] : ['geonameId' => $id,'name' => 'Jerusalem','countryName' => 'Israel','adminName1' => 'Jerusalem','lat' => '31.76904','lng' => '35.21633','timezone' => ['timeZoneId' => 'Asia/Jerusalem']];
            }
        };
        $this->ui = new BotController($this->settings, $this->flows, new CalendarService(new HebcalProvider($http), $this->clock), $this->gateway, new GeoNames($http, $this->clock, 'fixture'), $this->clock);
    }
    private function token(string $scope = 'group', ?string $group = '-1001', string $action = 'panel', array $payload = []): string
    {
        $settings = $group === null ? $this->settings->getUser('1') : $this->settings->getGroup($group);
        return $this->flows->create('1', $scope, $group, (int)$settings['revision'], $action, 600, $payload);
    }
    private function click(string $data, string $user = '1', bool $private = true, int $messageId = 101): void
    {
        $this->ui->handleCallback(['user_id' => $user,'data' => $data,'query_id' => 'fixture-query','message_id' => $messageId,'private' => $private]);
    }
    private function last(): array
    {
        return $this->gateway->messages[array_key_last($this->gateway->messages)];
    }
    private function confirm(): string
    {
        return $this->last()['markup']['rows'][0]['buttons'][0]['data'];
    }
    private function choice(): string
    {
        $last = $this->gateway->inline[array_key_last($this->gateway->inline)];
        return $last['results'][0]['send_message']['reply_markup']['rows'][0]['buttons'][0]['data'];
    }
    public function testMenusAreBilingualWithStableCallbacksAndNativeColors(): void
    {
        $this->ui->start('1');
        $he = $this->last();
        self::assertSame((new Lang())->text('he', 'welcome'), $he['text']);
        $this->settings->updateUser('1', 1, ['language' => 'en']);
        $this->ui->start('1');
        $en = $this->last();
        self::assertSame((new Lang())->text('en', 'welcome'), $en['text']);
        self::assertSame($he['markup'], $en['markup'] === [] ? [] : array_replace_recursive($en['markup'], ['rows' => array_map(function (array $row, int $index) use ($he) {
            foreach ($row['buttons'] as $i => $_) {
                $row['buttons'][$i]['text'] = $he['markup']['rows'][$index]['buttons'][$i]['text'];
            }return $row;
        }, $en['markup']['rows'], array_keys($en['markup']['rows']))]));
        self::assertSame(['_' => 'keyboardButtonStyle','bg_primary' => true,'icon' => '5944845194250690529'], $en['markup']['rows'][0]['buttons'][0]['style']);
        self::assertStringNotContainsString('JewishPulse', $en['text']);
    }
    public function testAllSevenCandleOptionsAreStoredExactly(): void
    {
        foreach ([10,15,18,20,22,30,40] as $minutes) {
            $token = $this->token();
            $this->click('sb:s:' . $token . ':c:' . $minutes);
            $this->click($this->confirm());
            self::assertSame($minutes, (int)$this->settings->getGroup('-1001')['candle_minutes']);
        }
        self::assertNull($this->settings->getUser('1')['candle_minutes']);
        $this->click('sb:v:' . $this->token() . ':candles');
        $values = [];
        foreach ($this->last()['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                $values[] = $button['text'];
            }
        }self::assertContains('40', $values);
    }
    public function testDegreesRemainFloatAndFiftyMinutesAreAvailable(): void
    {
        foreach (['8.5' => 'degrees','50' => 'minutes'] as $value => $method) {
            $this->click('sb:s:' . $this->token() . ':h:' . $value);
            $this->click($this->confirm());
            $s = $this->settings->getGroup('-1001');
            self::assertSame($method, $s['havdalah_method']);
            self::assertSame((float)$value, $s['havdalah_value']);
        }
    }
    public function testTwoGroupLocationFlowsSelectedInReverseOrder(): void
    {
        $one = $this->token('group', '-1001', 'location');
        $two = $this->token('group', '-1002', 'location');
        $this->ui->handleInline(['user_id' => '1','query_id' => 'q1','query' => 'loc ' . $one . ' Jerusalem']);
        $choiceOne = $this->choice();
        $this->clock->set(new \DateTimeImmutable('2026-10-13T12:00:02Z'));
        $this->ui->handleInline(['user_id' => '1','query_id' => 'q2','query' => 'loc ' . $two . ' London']);
        $choiceTwo = $this->choice();
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        $this->click($choiceTwo);
        self::assertStringContainsString('Group Two', $this->last()['text']);
        $this->click($this->confirm());
        $this->click($choiceOne);
        self::assertStringContainsString('Group One', $this->last()['text']);
        $this->click($this->confirm());
        self::assertSame('London', $this->settings->getGroup('-1002')['location_name']);
        self::assertSame('Jerusalem', $this->settings->getGroup('-1001')['location_name']);
        self::assertSame('Jerusalem', $this->settings->getUser('1')['location_name']);
    }
    public function testForgedLocationTextDoesNotSave(): void
    {
        self::assertFalse($this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '🌍 מיקום: 2643743 [London]']));
        self::assertSame(1, $this->settings->getUser('1')['revision']);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
    }
    public function testWrongUserReplayExpiryAndLossOfAdminCannotWrite(): void
    {
        $this->click('sb:s:' . $this->token() . ':c:40');
        $save = $this->confirm();
        $this->click($save, '2');
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        $this->gateway->admin = false;
        $this->click($save);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        $this->gateway->admin = true;
        $this->click($save);
        self::assertSame(2, $this->settings->getGroup('-1001')['revision']);
        $this->click($save);
        self::assertSame(2, $this->settings->getGroup('-1001')['revision']);
        $this->click('sb:s:' . $this->token() . ':c:18');
        $expired = $this->confirm();
        $this->clock->set(new \DateTimeImmutable('2026-10-13T12:11:00Z'));
        $this->click($expired);
        self::assertSame(40, (int)$this->settings->getGroup('-1001')['candle_minutes']);
    }
    public function testPersonalSaveAndPublicSharingNeverChangeGroup(): void
    {
        $token = $this->token('user', null);
        $this->click('sb:s:' . $token . ':h:72');
        $this->click($this->confirm());
        self::assertSame(72.0, $this->settings->getUser('1')['havdalah_value']);
        self::assertSame(8.5, $this->settings->getGroup('-1001')['havdalah_value']);
        $this->ui->handleInline(['user_id' => '1','query_id' => 'q','query' => 'loc London']);
        self::assertSame(2, $this->settings->getUser('1')['revision']);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
    }
    public function testManualTimezoneNeedsConfirmationAndPreservesCoordinates(): void
    {
        $before = $this->settings->getGroup('-1001');
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '/timezone ' . $this->token('group', '-1001', 'location') . ' Europe/London']);
        $choice = $this->last()['markup']['rows'][0]['buttons'][0]['data'];
        $this->click($choice);
        self::assertSame('Asia/Jerusalem', $this->settings->getGroup('-1001')['timezone_id']);
        $this->click($this->confirm());
        $after = $this->settings->getGroup('-1001');
        self::assertSame('Europe/London', $after['timezone_id']);
        self::assertSame($before['latitude'], $after['latitude']);
        self::assertSame($before['longitude'], $after['longitude']);
    }
    public function testLanguageCommandAndGroupLanguageAreIndependent(): void
    {
        self::assertTrue($this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '/lang']));
        self::assertStringContainsString('🇬🇧', $this->last()['markup']['rows'][0]['buttons'][1]['text']);
        $before = $this->settings->getGroup('-1001');
        $this->click('sb:s:' . $this->token() . ':l:en');
        $this->click($this->confirm());
        $after = $this->settings->getGroup('-1001');
        self::assertSame('en', $after['language']);
        self::assertSame('he', $this->settings->getUser('1')['language']);
        self::assertSame($before['latitude'], $after['latitude']);
        self::assertSame($before['havdalah_value'], $after['havdalah_value']);
        self::assertTrue($this->ui->handleMessage(['user_id' => '1','private' => false,'group_id' => '-1001','text' => '/language']));
    }
    public function testModuleFormsRejectInvalidFieldsTimesAndDuplicates(): void
    {
        self::assertSame(['tefillin_fixed_times' => ['09:00','15:30'],'tefillin_interval_minutes' => null,'tefillin_max_per_day' => 3], $this->ui->parseForm('tefillin', 'fixed_times=09:00,15:30;interval_minutes=off;max_per_day=3'));
        self::assertSame(['havdalah_method' => 'degrees','havdalah_value' => 8.5], $this->ui->parseForm('shabbat', 'havdalah_method=degrees;havdalah_value=8.5'));
        foreach (['time=25:00','time=08:00;time=09:00','language=en','days=9','format=unknown'] as $form) {
            try {
                $this->ui->parseForm('zmanim', $form);
                self::fail('invalid form accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testGroupShareUsesAuthorizedSnapshotInsteadOfPersonalLocation(): void
    {
        $group = $this->settings->getGroup('-1001');
        $this->settings->updateGroup('-1001', 1, ['location_name' => 'Group Location'], '1');
        $group = $this->settings->getGroup('-1001');
        $token = $this->flows->create('1', 'share', '-1001', $group['revision'], 'share_snapshot', 600, ['settings' => $group,'module' => 'shabbat','date' => null]);
        $this->ui->handleInline(['user_id' => '1','query_id' => 'share','query' => 'share ' . $token]);
        $result = $this->gateway->inline[0];
        self::assertStringContainsString('Group Location', $result['results'][0]['send_message']['message']);
        self::assertTrue($result['options']['private']);
        self::assertSame(0, $result['options']['cache_time']);
        self::assertSame(2, $this->settings->getGroup('-1001')['revision']);
        $this->gateway->admin = false;
        $this->ui->handleInline(['user_id' => '1','query_id' => 'share2','query' => 'share ' . $token]);
        self::assertStringNotContainsString('Group Location', $this->gateway->inline[1]['results'][0]['send_message']['message']);
    }
    public function testForwardedCallbackAndOldRevisionCannotWrite(): void
    {
        $token = $this->token();
        $this->click('sb:s:' . $token . ':c:40', '1', false);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        $this->settings->updateGroup('-1001', 1, ['candle_minutes' => 30], '1');
        $this->click('sb:s:' . $token . ':c:40');
        self::assertSame(30, (int)$this->settings->getGroup('-1001')['candle_minutes']);
    }
    public function testGroupRenderingUsesGroupLanguageWhilePrivatePanelUsesManagerLanguage(): void
    {
        $this->settings->updateUser('1', 1, ['language' => 'en']);
        $group = $this->settings->getGroup('-1001');
        $text = $this->ui->renderView('1', $group, 'shabbat');
        self::assertStringContainsString('הדלקת נרות', $text);
        self::assertStringNotContainsString('Candle lighting:', $text);
        $this->ui->panel('1', $group);
        self::assertStringContainsString('Mode:', $this->last()['text']);
        self::assertStringContainsString('Language', $this->last()['markup']['rows'][0]['buttons'][0]['text']);
    }
    public function testDailyFormatsAndMissingValuesAreClear(): void
    {
        $g = $this->settings->getGroup('-1001');
        $g['language'] = 'en';
        $g['zmanim_format'] = 'full';
        $full = $this->ui->renderView('1', $g, 'zmanim');
        self::assertStringContainsString('Dawn:', $full);
        self::assertStringContainsString('Unavailable for this location/date', $full);
        self::assertStringContainsString('72 fixed minutes', $full);
        $g['zmanim_format'] = 'short';
        $short = $this->ui->renderView('1', $g, 'zmanim');
        self::assertStringNotContainsString('Dawn:', $short);
        self::assertStringContainsString('Sunset:', $short);
    }
    public function testTefillinPreviewUsesCalculatedJobsWithoutEnablingOrSendingGroup(): void
    {
        $g = $this->settings->getGroup('-1001');
        $g['language'] = 'en';
        $text = $this->ui->renderView('1', $g, 'tefillin');
        self::assertStringContainsString('2026-10-13 09:00', $text);
        self::assertStringContainsString('Private preview only', $text);
        self::assertFalse($this->settings->getGroup('-1001')['tefillin_enabled']);
        self::assertSame([], $this->gateway->messages);
    }
    public function testGpsRepliesAreBoundToTheirExactFlowAndCanFinishInReverseOrder(): void
    {
        $one = $this->token('group', '-1001', 'location');
        $two = $this->token('group', '-1002', 'location');
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '/location ' . $one . ' Asia/Jerusalem']);
        $promptOne = $this->last()['text'];
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '/location ' . $two . ' Europe/London']);
        $promptTwo = $this->last()['text'];
        self::assertStringContainsString('Group One', $promptOne);
        self::assertStringContainsString('Group Two', $promptTwo);
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '','location' => ['latitude' => 51.5,'longitude' => -0.1],'reply_text' => $promptTwo,'replied_bot' => true]);
        self::assertStringContainsString('Group Two', $this->last()['text']);
        $this->click($this->confirm());
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '','location' => ['latitude' => 31.7,'longitude' => 35.2],'reply_text' => $promptOne,'replied_bot' => true]);
        self::assertStringContainsString('Group One', $this->last()['text']);
        $this->click($this->confirm());
        self::assertSame(51.5, $this->settings->getGroup('-1002')['latitude']);
        self::assertSame('Europe/London', $this->settings->getGroup('-1002')['timezone_id']);
        self::assertSame(31.7, $this->settings->getGroup('-1001')['latitude']);
        self::assertSame(31.76904, $this->settings->getUser('1')['latitude']);
    }
    public function testGpsWithoutBotReplyAndWrongUserCannotSelectAGroup(): void
    {
        $token = $this->token('group', '-1001', 'location');
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '/location ' . $token . ' Asia/Jerusalem']);
        $prompt = $this->last()['text'];
        foreach ([['user_id' => '1','reply_text' => '','replied_bot' => false],['user_id' => '1','reply_text' => $prompt,'replied_bot' => false],['user_id' => '2','reply_text' => $prompt,'replied_bot' => true]] as $context) {
            $this->ui->handleMessage($context + ['private' => true,'text' => '','location' => ['latitude' => 0.0,'longitude' => 0.0]]);
            self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        }
    }
    public function testCoordinatesAndGpsAutoTimezoneShowPreviewBeforeSaving(): void
    {
        $token = $this->token('group', '-1001', 'location');
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '/coordinates ' . $token . ' 51.5 -0.1 auto London Coordinates']);
        self::assertStringContainsString('Europe/London', $this->last()['text']);
        self::assertSame('Asia/Jerusalem', $this->settings->getGroup('-1001')['timezone_id']);
        $this->click($this->confirm());
        self::assertSame('Europe/London', $this->settings->getGroup('-1001')['timezone_id']);
        self::assertTrue($this->settings->getGroup('-1001')['israel']);
        $token = $this->token('group', '-1002', 'location');
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '/location ' . $token . ' auto']);
        $prompt = $this->last()['text'];
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '','location' => ['latitude' => 51.6,'longitude' => -0.2],'reply_text' => $prompt,'replied_bot' => true]);
        self::assertStringContainsString('Europe/London', $this->last()['text']);
        $this->click($this->confirm());
        self::assertSame('Europe/London', $this->settings->getGroup('-1002')['timezone_id']);
    }
    public function testTranslationKeysPlaceholdersEscapingAndFallback(): void
    {
        $lang = new Lang();
        $lang->validate();
        self::assertSame($lang->text('he', 'welcome'), $lang->text('unknown', 'welcome'));
        self::assertStringContainsString('&lt;group&gt;', $lang->text('en', 'saved', ['title' => '<group>']));
        $this->expectException(\InvalidArgumentException::class);
        $lang->text('he', 'saved');
    }

    public function testSixLocalesHaveRealMenusAndStableCallbacksInPrivateAndGroupSettings(): void
    {
        $lang = new Lang();
        $lang->validate();
        self::assertSame(['he','en','ru','fr','es','pt-br'], $lang->languages());
        $callbacks = null;
        foreach (Lang::CODES as $code) {
            $before = $this->settings->getUser('1');
            $this->settings->updateUser('1', $before['revision'], ['language' => $code]);
            $this->ui->start('1');
            $menu = $this->last();
            self::assertSame($lang->text($code, 'welcome'), $menu['text']);
            $actual = [];
            foreach ($menu['markup']['rows'] as $row) {
                foreach ($row['buttons'] as $button) {
                    $actual[] = $button['data'] ?? $button['url'];
                }
            }
            $callbacks ??= $actual;
            self::assertSame($callbacks, $actual);
            $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '/lang']);
            $buttons = [];
            foreach ($this->last()['markup']['rows'] as $row) {
                foreach ($row['buttons'] as $button) {
                    if (str_contains($button['data'], ':l:')) {
                            $buttons[] = substr($button['data'], strrpos($button['data'], ':') + 1);
                    }
                }
            }
            self::assertSame(Lang::CODES, $buttons);
            $groupBefore = $this->settings->getGroup('-1001');
            $this->click('sb:s:' . $this->token() . ':l:' . $code);
            $save = $this->confirm();
            $this->click($save);
            $group = $this->settings->getGroup('-1001');
            self::assertSame($code, $group['language']);
            self::assertSame($code, $this->settings->getUser('1')['language']);
            self::assertSame($groupBefore['latitude'], $group['latitude']);
            $savedRevision = $group['revision'];
            $this->click($save);
            self::assertSame($savedRevision, $this->settings->getGroup('-1001')['revision']);
            self::assertStringContainsString($lang->text($code, 'times_heading'), $this->ui->renderView('1', $group, 'shabbat'));
        }
        self::assertSame('pt', Lang::telegramLanguageCode('pt-br'));
        self::assertSame('pt-br', Lang::canonical('pt_BR'));
        self::assertSame('he', Lang::canonical('invalid'));
    }

    public function testModerationScreenHasSupportedGlobalFlagsAndRequiresOneUseConfirmation(): void
    {
        $this->click('sb:v:' . $this->token() . ':restrictions');
        $message = $this->last();
        $flagButtons = [];
        foreach ($message['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                if (str_starts_with($button['data'], 'sb:flag:')) {
                    $flagButtons[] = $button;
                    self::assertLessThanOrEqual(64, strlen($button['data']));
                    self::assertArrayHasKey('style', $button);
                }
            }
        }
        self::assertCount(22, $flagButtons);
        self::assertCount(23, \Shabbat\Scheduling\Permissions::FLAGS, 'All native fields remain available for exact snapshots.');
        self::assertSame(\Shabbat\Scheduling\Permissions::DEFAULT_BLOCK_FLAGS, $this->settings->getGroup('-1001')['lock_block_flags']);
        $reaction = null;
        foreach ($flagButtons as $button) {
            self::assertFalse(str_ends_with($button['data'], ':view_messages'));
            if (str_ends_with($button['data'], ':send_reactions')) {
                $reaction = $button['data'];
            }
        }
        self::assertNotNull($reaction);
        $this->click($reaction);
        self::assertContains('send_reactions', $this->settings->getGroup('-1001')['lock_block_flags']);
        self::assertStringContainsString('עריכת תארי חברים', $this->last()['text']);
        $confirm = $this->confirm();
        $this->click($confirm);
        self::assertNotContains('send_reactions', $this->settings->getGroup('-1001')['lock_block_flags']);
        self::assertContains('edit_rank', $this->settings->getGroup('-1001')['lock_block_flags']);
        $revision = $this->settings->getGroup('-1001')['revision'];
        $this->click($confirm);
        self::assertSame($revision, $this->settings->getGroup('-1001')['revision']);
        $this->click('sb:flag:' . $this->token() . ':view_messages');
        self::assertSame($revision, $this->settings->getGroup('-1001')['revision']);
        self::assertNotContains('view_messages', $this->settings->getGroup('-1001')['lock_block_flags']);
        self::assertContains('send_reactions', $this->settings->getGroup('-1002')['lock_block_flags']);
    }

    public function testGroupOnlyModerationRejectsPersonalForwardedAndRevokedSaves(): void
    {
        foreach (['flag:send_messages','mask:all','toggle:du','toggle:db','toggle:tp','at:1200'] as $action) {
            [$verb,$value] = explode(':', $action);
            $this->click('sb:' . $verb . ':' . $this->token('user', null) . ':' . $value);
        }
        self::assertArrayNotHasKey('delete_holy_user_messages', $this->settings->getUser('1'));
        self::assertFalse($this->settings->getGroup('-1001')['delete_holy_user_messages']);
        $this->click('sb:toggle:' . $this->token() . ':du', '2');
        $this->click('sb:toggle:' . $this->token() . ':du', '1', false);
        self::assertFalse($this->settings->getGroup('-1001')['delete_holy_user_messages']);
        $this->click('sb:toggle:' . $this->token() . ':du');
        $confirmation = $this->confirm();
        $this->gateway->admin = false;
        $this->click($confirmation);
        self::assertFalse($this->settings->getGroup('-1001')['delete_holy_user_messages']);
    }

    public function testIndependentCleanupTogglesAndTefillinDesignAccess(): void
    {
        foreach (['du' => 'delete_holy_user_messages','db' => 'delete_holy_bot_messages','tp' => 'tefillin_delete_previous'] as $short => $field) {
            self::assertFalse($this->settings->getGroup('-1001')[$field]);
            $this->click('sb:toggle:' . $this->token() . ':' . $short);
            self::assertFalse($this->settings->getGroup('-1001')[$field]);
            $this->click($this->confirm());
            self::assertTrue($this->settings->getGroup('-1001')[$field]);
            self::assertFalse($this->settings->getGroup('-1002')[$field]);
        }
        $this->click('sb:v:' . $this->token() . ':tefillin');
        self::assertStringContainsString('v3:templates:-1001:tefillin', json_encode($this->last(), JSON_THROW_ON_ERROR));
        $this->click('sb:r:' . $this->token() . ':tefillin');
        $this->click($this->confirm());
        self::assertFalse($this->settings->getGroup('-1001')['tefillin_delete_previous']);
        self::assertTrue($this->settings->getGroup('-1001')['delete_holy_user_messages']);
    }

    public function testAnnouncementPresetsAndCustomTimeAreLocalAndDoNotEnableSending(): void
    {
        $presets = ['10:00','11:00','12:00','12:30','13:00','13:30','14:00'];
        $this->click('sb:v:' . $this->token() . ':announcement');
        $message = $this->last();
        $actual = [];
        foreach ($message['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                if (str_starts_with($button['data'], 'sb:at:')) {
                    $actual[] = $button['text'];
                }
            }
        }
        self::assertSame($presets, $actual);
        self::assertStringContainsString('v3:templates:-1001:times_alert', json_encode($message, JSON_THROW_ON_ERROR));
        foreach ($presets as $time) {
            $this->click('sb:at:' . $this->token() . ':' . str_replace(':', '', $time));
            $this->click($this->confirm());
            self::assertSame($time, $this->settings->getGroup('-1001')['times_alert_time']);
            self::assertSame('local_time', $this->settings->getGroup('-1001')['times_alert_mode']);
            self::assertFalse($this->settings->getGroup('-1001')['times_alert_enabled']);
        }
        $token = $this->token('group', '-1001', 'edit', ['module' => 'shabbat']);
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '/configure ' . $token . ' times_alert_time=12:17']);
        $this->click($this->confirm());
        self::assertSame('12:17', $this->settings->getGroup('-1001')['times_alert_time']);
        self::assertFalse($this->settings->getGroup('-1001')['times_alert_enabled']);
    }

    public function testNewPanelsAreLocalizedInAllSixLanguagesAndPreviewIsPrivate(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            $user = $this->settings->getUser('1');
            $this->settings->updateUser('1', $user['revision'], ['language' => $language]);
            $this->click('sb:v:' . $this->token() . ':moderation');
            self::assertStringContainsString($lang->text($language, 'moderation_intro'), $this->last()['text']);
            self::assertStringContainsString('BotFather', $this->last()['text']);
            $this->click('sb:v:' . $this->token() . ':restrictions');
            self::assertStringContainsString($lang->text($language, 'permission_send_reactions'), json_encode($this->last(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->click('sb:ap:' . $this->token());
            self::assertSame('1', $this->last()['userId']);
        }
    }

    public function testNewFormsValidateMaskAndDeleteBooleans(): void
    {
        self::assertSame(['lock_block_flags' => ['send_messages','send_reactions'],'delete_holy_bot_messages' => true], $this->ui->parseForm('shabbat', 'lock_block_flags=send_messages,send_reactions;delete_holy_bot_messages=true'));
        self::assertSame(['tefillin_delete_previous' => false], $this->ui->parseForm('tefillin', 'delete_previous=false'));
        foreach (['lock_block_flags=send_messages,send_messages','lock_block_flags=administrator','delete_holy_bot_messages=yes'] as $form) {
            try {
                $this->ui->parseForm('shabbat', $form);
                self::fail('Invalid moderation form accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }


    private function lookup(string $kind, string $group = '-1001', int $messageId = 707): void
    {
        $this->click('sb:lookup:' . $this->token('group', $group) . ':' . $kind, messageId: $messageId);
        self::assertSame($messageId, $this->gateway->edited[array_key_last($this->gateway->edited)]['messageId']);
        self::assertDoesNotMatchRegularExpression('/\/(city|coordinates|location|timezone) [A-Za-z0-9_-]{24}/', $this->last()['text']);
    }

    public function testSimpleCoordinatesInputConfirmsExactGroupAndEditsOriginalPanel(): void
    {
        $this->lookup('coordinates');
        $sent = count($this->gateway->sent);
        self::assertFalse($this->ui->handleMessage(['user_id' => '2', 'private' => true, 'text' => '51.50853, -0.12574 Europe/London']));
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => '51.50853, -0.12574 Europe/London']));
        self::assertStringContainsString('Group One', $this->last()['text']);
        self::assertSame(707, $this->gateway->edited[array_key_last($this->gateway->edited)]['messageId']);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        $this->click($this->confirm(), messageId: 707);
        self::assertSame('Europe/London', $this->settings->getGroup('-1001')['timezone_id']);
        self::assertSame(51.50853, $this->settings->getGroup('-1001')['latitude']);
        self::assertSame(1, $this->settings->getGroup('-1002')['revision']);
        self::assertSame('Asia/Jerusalem', $this->settings->getUser('1')['timezone_id']);
        self::assertCount($sent, $this->gateway->sent);
    }

    public function testSimpleCityInputUsesButtonsThenSeparateSaveConfirmation(): void
    {
        $this->lookup('city');
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => 'London']));
        $select = $this->last()['markup']['rows'][0]['buttons'][0]['data'];
        self::assertStringStartsWith('sb:lp:', $select);
        $this->click($select, messageId: 707);
        self::assertStringContainsString('London', $this->last()['text']);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        $this->click($this->confirm(), messageId: 707);
        self::assertSame('London', $this->settings->getGroup('-1001')['location_name']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testSimpleGeoNamesIdentifierAndTimezonePreserveConfirmation(): void
    {
        $this->lookup('geoname');
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => '2643743']));
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        $this->click($this->confirm(), messageId: 707);
        self::assertSame(2643743, $this->settings->getGroup('-1001')['geoname_id']);
        $coordinates = array_intersect_key($this->settings->getGroup('-1001'), array_flip(['latitude', 'longitude']));
        $this->lookup('timezone');
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => 'Jerusalem']));
        $this->click($this->last()['markup']['rows'][0]['buttons'][0]['data'], messageId: 707);
        self::assertSame('Europe/London', $this->settings->getGroup('-1001')['timezone_id']);
        $this->click($this->confirm(), messageId: 707);
        self::assertSame('Asia/Jerusalem', $this->settings->getGroup('-1001')['timezone_id']);
        self::assertSame($coordinates, array_intersect_key($this->settings->getGroup('-1001'), array_flip(['latitude', 'longitude'])));
        self::assertSame([], $this->gateway->sent);
    }

    public function testSimpleGpsRequiresReplyToCurrentPromptAndExplicitConfirmation(): void
    {
        $this->lookup('gps');
        $gps = ['latitude' => 51.50853, 'longitude' => -0.12574];
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'location' => $gps, 'replied_bot' => true, 'reply_message_id' => 606]));
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        self::assertStringNotContainsString('sb:save:', json_encode($this->last(), JSON_THROW_ON_ERROR));
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'location' => $gps, 'replied_bot' => true, 'reply_message_id' => 707]));
        self::assertStringContainsString('sb:save:', json_encode($this->last(), JSON_THROW_ON_ERROR));
        $this->click($this->confirm(), messageId: 707);
        self::assertSame('Europe/London', $this->settings->getGroup('-1001')['timezone_id']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testSecondLocationPromptReplacesActiveGroupContextAndOldGpsCannotApply(): void
    {
        $this->lookup('gps', '-1001', 707);
        $this->lookup('coordinates', '-1002', 808);
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => '51.50853, -0.12574 Europe/London']));
        self::assertStringContainsString('Group Two', $this->last()['text']);
        self::assertSame(808, $this->gateway->edited[array_key_last($this->gateway->edited)]['messageId']);
        $confirm = $this->confirm();
        $this->click($confirm, messageId: 808);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        self::assertSame('Europe/London', $this->settings->getGroup('-1002')['timezone_id']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testReplacedLocationPromptRevokesOlderChoiceAndPendingConfirmation(): void
    {
        $this->lookup('city', '-1001', 707);
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => 'London']));
        $choice = $this->last()['markup']['rows'][0]['buttons'][0]['data'];
        $this->click($choice, messageId: 707);
        $save = $this->confirm();
        $this->lookup('coordinates', '-1002', 808);
        $edits = count($this->gateway->edited);
        $this->click($choice, messageId: 707);
        $this->click($save, messageId: 707);
        self::assertCount($edits, $this->gateway->edited);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        self::assertSame(1, $this->settings->getGroup('-1002')['revision']);
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => '51.50853, -0.12574 Europe/London']));
        self::assertStringContainsString('Group Two', $this->last()['text']);
        $this->click($this->confirm(), messageId: 808);
        self::assertSame('Europe/London', $this->settings->getGroup('-1002')['timezone_id']);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testCancelledLocationPromptCannotApplyItsPendingConfirmation(): void
    {
        $this->lookup('coordinates');
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => '51.50853, -0.12574 Europe/London']));
        $save = $this->confirm();
        $this->click('sb:start', messageId: 707);
        self::assertNull($this->flows->latest('1', 'location_input'));
        $edits = count($this->gateway->edited);
        $this->click($save, messageId: 707);
        self::assertCount($edits, $this->gateway->edited);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        self::assertSame('Asia/Jerusalem', $this->settings->getGroup('-1001')['timezone_id']);
        self::assertSame([], $this->gateway->sent);
    }
    public function testSimpleLocationStaleExpiredAndRevokedContextsCannotSave(): void
    {
        $this->lookup('coordinates');
        $this->settings->updateGroup('-1001', 1, ['candle_minutes' => 30], '1');
        self::assertFalse($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => '51.5, -0.1 Europe/London']));
        self::assertSame('Asia/Jerusalem', $this->settings->getGroup('-1001')['timezone_id']);
        self::assertStringNotContainsString('sb:save:', json_encode($this->last(), JSON_THROW_ON_ERROR));
        $this->lookup('coordinates');
        $this->gateway->admin = false;
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => '51.5, -0.1 Europe/London']));
        self::assertSame(2, $this->settings->getGroup('-1001')['revision']);
        $this->gateway->admin = true;
        $this->lookup('coordinates');
        $this->clock->set(new \DateTimeImmutable('2026-10-13T12:11:00Z'));
        self::assertFalse($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => '51.5, -0.1 Europe/London']));
        self::assertSame(2, $this->settings->getGroup('-1001')['revision']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testInlineTimezoneSearchIsPublicUntilBoundChoiceAndSaveConfirmation(): void
    {
        $this->lookup('timezone');
        $before = $this->settings->getGroup('-1001');
        foreach (['tz Jerusalem', 'Asia/Jerusalem'] as $query) {
            $this->ui->handleInline(['user_id' => '1', 'query_id' => 'timezone-public', 'query' => $query]);
            $public = $this->gateway->inline[array_key_last($this->gateway->inline)]['results'][0];
            self::assertStringContainsString('Asia/Jerusalem', $public['send_message']['message']);
            self::assertArrayNotHasKey('reply_markup', $public['send_message']);
            self::assertSame($before, $this->settings->getGroup('-1001'));
        }
        $this->ui->handleInline(['user_id' => '1', 'query_id' => 'timezone-bound', 'query' => 'timezone London']);
        $bound = $this->gateway->inline[array_key_last($this->gateway->inline)]['results'][0];
        $choice = $bound['send_message']['reply_markup']['rows'][0]['buttons'][0]['data'];
        self::assertStringStartsWith('sb:lp:', $choice);
        $this->click($choice, messageId: 707);
        self::assertSame($before, $this->settings->getGroup('-1001'));
        $this->click($this->confirm(), messageId: 707);
        $after = $this->settings->getGroup('-1001');
        self::assertSame('Europe/London', $after['timezone_id']);
        self::assertSame($before['latitude'], $after['latitude']);
        self::assertSame($before['longitude'], $after['longitude']);
        self::assertSame(1, $this->settings->getGroup('-1002')['revision']);
        self::assertSame([], $this->gateway->sent);
    }
    public function testBoundInlineCityAndTimezoneResultsKeepNonEmptyNativeMarkup(): void
    {
        foreach (['city' => 'location London', 'timezone' => 'timezone London'] as $kind => $query) {
            $this->lookup($kind);
            $this->ui->handleInline(['user_id' => '1', 'query_id' => 'markup-' . $kind, 'query' => $query]);
            $response = $this->gateway->inline[array_key_last($this->gateway->inline)];
            self::assertSame(0, $response['options']['cache_time']);
            self::assertTrue($response['options']['private']);
            $result = $response['results'][0];
            $markup = $result['send_message']['reply_markup'];
            self::assertSame('replyInlineMarkup', $markup['_']);
            self::assertNotEmpty($markup['rows']);
            foreach ($markup['rows'] as $row) {
                self::assertSame('keyboardButtonRow', $row['_']);
                self::assertNotEmpty($row['buttons']);
                foreach ($row['buttons'] as $button) {
                    self::assertContains($button['_'], ['keyboardButtonCallback', 'keyboardButtonUrl']);
                    if (isset($button['data'])) {
                        self::assertStringStartsWith('sb:lp:', $button['data']);
                        self::assertLessThanOrEqual(64, strlen($button['data']));
                    } else {
                        self::assertStringStartsWith('https://t.me/ShabbatFixtureBot?start=loc_', $button['url']);
                    }
                }
            }
            self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        }
    }
    public function testInlineArticlesUseInputConstructorsFromThePinnedTelegramSchema(): void
    {
        $schema = file_get_contents((new \danog\MadelineProto\Settings\TLSchema())->getAPISchema());
        self::assertIsString($schema);
        foreach (Lang::CODES as $language) {
            $user = $this->settings->getUser('1');
            $this->settings->updateUser('1', $user['revision'], ['language' => $language]);
            $this->clock->set($this->clock->now()->modify('+2 seconds'));
            $this->ui->handleInline(['user_id' => '1', 'query_id' => 'schema-' . $language, 'query' => 'London']);
            $result = $this->gateway->inline[array_key_last($this->gateway->inline)]['results'][0];
            self::assertSame('inputBotInlineResult', $result['_']);
            self::assertSame('inputBotInlineMessageText', $result['send_message']['_']);
            $objects = [$result, $result['send_message']];
            if (isset($result['send_message']['reply_markup'])) {
                $objects[] = $result['send_message']['reply_markup'];
            }
            foreach ($objects as $object) {
                self::assertSame(1, preg_match('/^' . preg_quote($object['_'], '/') . '#[^\r\n]+/m', $schema, $entry));
                foreach (array_keys($object) as $field) {
                    // The SDK consumes parse_mode before serializing this input object.
                    if (!in_array($field, ['_', 'parse_mode'], true)) {
                        self::assertMatchesRegularExpression('/\b' . preg_quote($field, '/') . ':/', $entry[0]);
                    }
                }
            }
            self::assertLessThanOrEqual(64, strlen($result['id']));
            self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        }
        self::assertSame([], $this->gateway->sent);
    }
    public function testPublicInlineLocationSharingDoesNotMutateActiveGroupFlow(): void
    {
        $this->lookup('city');
        $active = $this->flows->latest('1', 'location_input');
        $this->ui->handleInline(['user_id' => '1', 'query_id' => 'public', 'query' => 'London']);
        $public = $this->gateway->inline[array_key_last($this->gateway->inline)]['results'][0];
        self::assertStringContainsString('London', $public['send_message']['message']);
        self::assertArrayNotHasKey('reply_markup', $public['send_message']);
        self::assertSame($active, $this->flows->latest('1', 'location_input'));
        $this->ui->handleInline(['user_id' => '1', 'query_id' => 'bound', 'query' => 'location London']);
        $bound = $this->gateway->inline[array_key_last($this->gateway->inline)]['results'][0];
        self::assertStringContainsString('sb:lp:', json_encode($bound, JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        self::assertSame(1, $this->settings->getUser('1')['revision']);
    }
    public function testAllBotAdministratorRightsAreReadOnlyLocalizedAndFailClosed(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            $user = $this->settings->getUser('1');
            $this->settings->updateUser('1', $user['revision'], ['language' => $language]);
            $this->click('sb:v:' . $this->token() . ':botpermissions');
            $message = $this->last();
            foreach (\Shabbat\Telegram\AdminRights::FLAGS as $flag) {
                self::assertStringContainsString($lang->text($language, 'admin_right_' . $flag), $message['text']);
            }
            self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
            self::assertSame(1, count($message['markup']['rows']));
        }
        $this->gateway->capabilitiesUnavailable = true;
        $this->click('sb:v:' . $this->token() . ':botpermissions');
        self::assertStringContainsString($lang->text('pt-br', 'unavailable'), $this->last()['text']);
        self::assertStringNotContainsString('offline permission lookup failed', $this->last()['text']);
        $this->gateway->capabilitiesUnavailable = false;
        $this->gateway->revokeDuringCapabilities = true;
        $this->click('sb:v:' . $this->token() . ':botpermissions');
        self::assertSame($lang->text('pt-br', 'denied'), $this->gateway->answers[array_key_last($this->gateway->answers)]['text']);
    }
}
