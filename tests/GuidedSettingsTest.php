<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Location\GeoNames;
use Shabbat\Storage\Database;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Storage\StaleSettings;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\BotController;
use Shabbat\UI\Lang;

final class GuidedSettingsTest extends TestCase
{
    private BotController $ui;
    private SettingsRepository $settings;
    private FlowTokenRepository $flows;
    private UiNavigationFakeGateway $gateway;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00+03:00'));
        $this->settings = new SettingsRepository($db, $this->clock);
        $this->settings->ensureGroup('-1001', 'Guided group');
        $this->flows = new FlowTokenRepository($db, $this->clock);
        $this->gateway = new UiNavigationFakeGateway();
        $http = new FullHebcalFixtureClient();
        $this->ui = new BotController(
            $this->settings,
            $this->flows,
            new CalendarService(new HebcalProvider($http), $this->clock),
            $this->gateway,
            new GeoNames($http, $this->clock, ''),
            $this->clock
        );
    }

    private function click(string $data): void
    {
        self::assertTrue($this->ui->handleCallback(['user_id' => '1','chat_id' => '1','message_id' => 101,'private' => true,'query_id' => '123','data' => $data]));
    }

    private function last(): array
    {
        return $this->gateway->edited[array_key_last($this->gateway->edited)];
    }

    private function dataFor(string $prefix, ?string $label = null): string
    {
        foreach ($this->last()['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                if (str_starts_with($button['data'] ?? '', $prefix) && ($label === null || str_starts_with($button['text'], $label))) {
                    return $button['data'];
                }
            }
        }
        self::fail('Missing callback ' . $prefix . ' / ' . ($label ?? 'any'));
    }

    private function openTefillin(string $language = 'he'): void
    {
        $user = $this->settings->getUser('1');
        $this->settings->updateUser('1', $user['revision'], ['language' => $language]);
        $group = $this->settings->getGroup('-1001');
        $panel = $this->flows->create('1', 'group', '-1001', $group['revision'], 'panel');
        $this->click('sb:v:' . $panel . ':tefillin');
    }

    private function openField(string $field, string $language = 'he'): void
    {
        $lang = new Lang();
        if ($field === 'delete_previous') {
            $this->click($this->dataFor('sb:wp:'));
        }
        $this->click($this->dataFor('sb:wf:', $lang->text($language, 'field_' . $field)));
    }

    public function testEveryLanguageShowsHumanSettingsAndPresetSavesWithoutEnablingAlerts(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            $this->openTefillin($language);
            $text = $this->last()['text'];
            foreach (['days =','fixed_times','interval_minutes','quiet_start','true/false','/configure','city'] as $raw) {
                self::assertStringNotContainsString($raw, $text);
            }
            self::assertStringContainsString($lang->text($language, 'field_interval_minutes'), $text);
            $this->openField('interval_minutes', $language);
            $this->click($this->dataFor('sb:wo:', '60'));
            $this->click($this->dataFor('sb:save:'));
            self::assertSame(60, $this->settings->getGroup('-1001')['tefillin_interval_minutes']);
            self::assertFalse($this->settings->getGroup('-1001')['tefillin_enabled']);
        }
        self::assertSame([], $this->gateway->sent);
        foreach ($this->gateway->edited as $edit) {
            self::assertSame(101, $edit['messageId']);
        }
    }

    public function testCustomTimesWeekdaysAndLastPageToggleUseOnePanelAndExplicitConfirm(): void
    {
        $this->openTefillin();
        $this->openField('fixed_times');
        $this->click($this->dataFor('sb:wi:'));
        self::assertStringNotContainsString('/configure', $this->last()['text']);
        self::assertDoesNotMatchRegularExpression('/[A-Za-z0-9_-]{24}/', $this->last()['text']);
        self::assertTrue($this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '09:15,14:45']));
        self::assertSame(['09:00'], $this->settings->getGroup('-1001')['tefillin_fixed_times']);
        $this->click($this->dataFor('sb:save:'));
        self::assertSame(['09:15','14:45'], $this->settings->getGroup('-1001')['tefillin_fixed_times']);

        $this->openTefillin();
        $this->openField('days');
        $this->click($this->dataFor('sb:wd:', '✅ יום שישי'));
        $this->click($this->dataFor('sb:wc:'));
        self::assertStringContainsString('ראשון', $this->last()['text']);
        $this->click($this->dataFor('sb:save:'));
        self::assertSame([0,1,2,3,4], $this->settings->getGroup('-1001')['tefillin_days']);

        $this->openTefillin();
        $this->openField('delete_previous');
        $this->click($this->dataFor('sb:wo:', (new Lang())->text('he', 'on')));
        $this->click($this->dataFor('sb:save:'));
        self::assertTrue($this->settings->getGroup('-1001')['tefillin_delete_previous']);
        self::assertFalse($this->settings->getGroup('-1001')['tefillin_enabled']);
        self::assertSame([], $this->gateway->sent);
    }

    private function openSection(string $screen): void
    {
        $group = $this->settings->getGroup('-1001');
        $token = $this->flows->create('1', 'group', '-1001', $group['revision'], 'panel');
        $this->click('sb:v:' . $token . ':' . $screen);
    }

    public function testModeSelectorHasFourChoicesAndSaveReturnsToShabbatInEveryLanguage(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            $user = $this->settings->getUser('1');
            $this->settings->updateUser('1', $user['revision'], ['language' => $language]);
            $this->openSection('shabbat');
            self::assertStringContainsString('Asia/Jerusalem', $this->last()['text']);
            $buttons = array_merge(...array_column($this->last()['markup']['rows'], 'buttons'));
            self::assertCount(0, array_filter($buttons, static fn(array $button): bool => str_contains($button['data'] ?? '', ':m:')));
            $this->click($this->dataFor('sb:v:', $lang->text($language, 'settings_mode_button', ['mode' => $lang->text($language, 'mode_' . $this->settings->getGroup('-1001')['mode'])])));
            $buttons = array_merge(...array_column($this->last()['markup']['rows'], 'buttons'));
            $modes = array_values(array_filter($buttons, static fn(array $button): bool => str_contains($button['data'] ?? '', ':m:')));
            self::assertCount(4, $modes);
            $this->click($modes[1]['data']);
            $this->click($this->dataFor('sb:save:'));
            self::assertSame('messages', $this->settings->getGroup('-1001')['mode']);
            self::assertStringContainsString($lang->text($language, 'settings_mode_button', ['mode' => $lang->text($language, 'mode_messages')]), json_encode($this->last(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            self::assertStringContainsString(':shabbat_timing', json_encode($this->last(), JSON_THROW_ON_ERROR));
        }
        self::assertSame([], $this->gateway->sent);
    }

    public function testTimingAndAnnouncementSaveAndCancelStayInTheirCategory(): void
    {
        $this->openSection('shabbat_timing');
        $this->click($this->dataFor('sb:wf:', (new Lang())->text('he', 'field_candle_minutes')));
        $this->click($this->dataFor('sb:wo:', '40'));
        $this->click($this->dataFor('sb:save:'));
        self::assertSame(40, $this->settings->getGroup('-1001')['candle_minutes']);
        self::assertStringContainsString((new Lang())->text('he', 'settings_shabbat_timing_intro'), $this->last()['text']);
        $this->click($this->dataFor('sb:wf:', (new Lang())->text('he', 'field_candle_minutes')));
        $this->click($this->dataFor('sb:wo:', '60'));
        $this->click($this->dataFor('sb:cancel:'));
        self::assertSame(40, $this->settings->getGroup('-1001')['candle_minutes']);
        self::assertStringContainsString((new Lang())->text('he', 'settings_shabbat_timing_intro'), $this->last()['text']);

        $this->openSection('announcement');
        $this->click($this->dataFor('sb:at:', '12:30'));
        $this->click($this->dataFor('sb:save:'));
        self::assertSame('12:30', $this->settings->getGroup('-1001')['times_alert_time']);
        self::assertStringContainsString((new Lang())->text('he', 'announcement_schedule_intro', ['timezone' => 'Asia/Jerusalem']), $this->last()['text']);
        self::assertStringContainsString('sb:at:', json_encode($this->last(), JSON_THROW_ON_ERROR));
    }

    public function testAdvancedPageAndCustomQuestionReturnToTheSameFieldPage(): void
    {
        $this->openTefillin();
        $this->click($this->dataFor('sb:wp:'));
        $this->click($this->dataFor('sb:wf:', (new Lang())->text('he', 'field_sunset_safety_minutes')));
        $this->click($this->dataFor('sb:wi:'));
        self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => '45']));
        $this->click($this->dataFor('sb:save:'));
        self::assertSame(45, $this->settings->getGroup('-1001')['tefillin_sunset_safety_minutes']);
        self::assertStringContainsString((new Lang())->text('he', 'field_delete_previous'), json_encode($this->last(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString((new Lang())->text('he', 'field_fixed_times') . ':', json_encode($this->last()['markup'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->click($this->dataFor('sb:wf:', (new Lang())->text('he', 'field_sunset_safety_minutes')));
        $this->click($this->dataFor('sb:wi:'));
        $this->click($this->dataFor('sb:wp:'));
        self::assertNull($this->flows->latest('1', 'settings_wizard'));
        self::assertStringContainsString((new Lang())->text('he', 'field_delete_previous'), json_encode($this->last(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function testCancelReplacedPromptWrongUserAndLostRoleCannotSave(): void
    {
        $this->openTefillin();
        $this->openField('fixed_times');
        $this->click($this->dataFor('sb:wi:'));
        self::assertFalse($this->ui->handleMessage(['user_id' => '2','private' => true,'text' => '10:00']));
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '10:00']);
        $stale = $this->dataFor('sb:save:');
        $this->click('sb:start');
        $edits = count($this->gateway->edited);
        $this->click($stale);
        self::assertCount($edits, $this->gateway->edited);
        self::assertSame(['09:00'], $this->settings->getGroup('-1001')['tefillin_fixed_times']);

        $this->openTefillin();
        $this->openField('fixed_times');
        $this->click($this->dataFor('sb:wi:'));
        $this->gateway->admin = false;
        $this->ui->handleMessage(['user_id' => '1','private' => true,'text' => '11:00']);
        self::assertSame(['09:00'], $this->settings->getGroup('-1001')['tefillin_fixed_times']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testPromptRevokedDuringAsyncAuthorizationCannotConfirmInTransaction(): void
    {
        $context = $this->settings->getGroup('-1001');
        $parent = $this->flows->create('1', 'group', '-1001', $context['revision'], 'settings_wizard');
        $save = $this->flows->create('1', 'group', '-1001', $context['revision'], 'save', 600, ['source_wizard_token' => $parent]);
        try {
            $this->flows->confirm($save, '1', 'save', function (): bool {
                $this->flows->revokeAction('1', 'settings_wizard');
                return true;
            }, ['tefillin_fixed_times' => ['10:00']]);
            self::fail('A canceled parent prompt must not save after authorization.');
        } catch (StaleSettings) {
            self::assertSame(['09:00'], $this->settings->getGroup('-1001')['tefillin_fixed_times']);
            self::assertNotNull($this->flows->peek($save, '1', 'save'));
        }
    }
}
