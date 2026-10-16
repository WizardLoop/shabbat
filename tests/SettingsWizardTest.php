<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Storage\{Database, SettingsRepository};
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{BotController, Lang, SettingsWizard};

final class SettingsWizardTest extends TestCase
{
    private SettingsRepository $settings;
    private BotController $parser;
    private array $context;

    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $this->settings = new SettingsRepository($db, new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z')));
        $this->context = $this->settings->ensureGroup('-1', 'Fixture group');
        $this->parser = (new \ReflectionClass(BotController::class))->newInstanceWithoutConstructor();
    }

    public function testDefinitionsCoverEveryExistingFormFieldAndPrivateScopeIsLimited(): void
    {
        $reference = (new \ReflectionClass(BotController::class))->getConstant('FORM_FIELDS');
        foreach ($reference as $module => $fields) {
            self::assertSame($fields, SettingsWizard::fields($module));
            foreach ($fields as $field) {
                $definition = SettingsWizard::definition($module, $field);
                self::assertContains($definition['kind'], ['bool','enum','time','times','days','integer','float','text','minutes_list','flags']);
                self::assertIsArray($definition['options']);
                self::assertIsBool($definition['nullable']);
            }
        }
        self::assertSame(['candle_minutes','havdalah_method','havdalah_value'], SettingsWizard::fields('shabbat', false));
        self::assertSame([], SettingsWizard::fields('tefillin', false));
        self::assertCount(17, SettingsWizard::fields('tefillin'));
    }

    public function testEveryPresetPassesExistingCanonicalParserAndRepositoryValidation(): void
    {
        foreach (['shabbat','zmanim','daf','tefillin','omer'] as $module) {
            foreach (SettingsWizard::fields($module) as $field) {
                $definition = SettingsWizard::definition($module, $field);
                if (in_array($definition['kind'], ['days','flags'], true)) {
                    $inputs = [$definition['options'],[]];
                } elseif ($definition['kind'] === 'text') {
                    $inputs = $field === 'ack_label' ? ['Custom {{location}}', null] : ['Custom {{location}}'];
                } else {
                    $inputs = $definition['options'];
                }
                foreach ($inputs as $input) {
                    $changes = SettingsWizard::changes($module, $field, $input, $this->context, $this->parser->parseForm(...));
                    self::assertArrayHasKey(SettingsWizard::key($module, $field), $changes);
                    $this->context = $this->settings->updateGroup('-1', $this->context['revision'], $changes, '1');
                    self::assertSame($changes[SettingsWizard::key($module, $field)], $this->context[SettingsWizard::key($module, $field)]);
                }
            }
        }
    }

    public function testNightfallMethodAndValueStayCompatibleForBothModules(): void
    {
        foreach (['shabbat' => ['havdalah_method','havdalah_value'], 'omer' => ['nightfall_method','nightfall_value']] as $module => [$method,$value]) {
            $changes = SettingsWizard::changes($module, $value, 72, $this->context, $this->parser->parseForm(...));
            self::assertSame('minutes', $changes[SettingsWizard::key($module, $method)]);
            self::assertSame(72.0, $changes[SettingsWizard::key($module, $value)]);
            $this->context = $this->settings->updateGroup('-1', $this->context['revision'], $changes, '1');
            $changes = SettingsWizard::changes($module, $method, 'degrees', $this->context, $this->parser->parseForm(...));
            self::assertSame(8.5, $changes[SettingsWizard::key($module, $value)]);
            $this->context = $this->settings->updateGroup('-1', $this->context['revision'], $changes, '1');
            $changes = SettingsWizard::changes($module, $method, 'minutes', $this->context, $this->parser->parseForm(...));
            self::assertSame(42.0, $changes[SettingsWizard::key($module, $value)]);
        }
    }

    public function testHumanValuesShowCityOnlyForCandlesAndOffForNullableReminders(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            self::assertSame($lang->text($language, 'city_custom'), SettingsWizard::current('shabbat', 'candle_minutes', $this->context, $language));
            self::assertSame($lang->text($language, 'off'), SettingsWizard::current('tefillin', 'interval_minutes', $this->context, $language));
            self::assertSame($lang->text($language, 'off'), SettingsWizard::current('tefillin', 'quiet_start', $this->context, $language));
            self::assertSame($lang->text($language, 'off'), SettingsWizard::current('tefillin', 'quiet_end', $this->context, $language));
            self::assertSame($lang->text($language, 'tefillin_ack'), SettingsWizard::current('tefillin', 'ack_label', $this->context, $language));
            $days = SettingsWizard::current('tefillin', 'days', $this->context, $language);
            self::assertStringContainsString(SettingsWizard::dayLabel(0, $language), $days);
            self::assertStringNotContainsString('0,1,2', $days);
            foreach (['havdalah_method','times_alert_mode'] as $field) {
                self::assertStringNotContainsString((string)$this->context[$field], SettingsWizard::current('shabbat', $field, $this->context, $language));
            }
        }
        self::assertSame(['10:00','11:00','12:00','12:30','13:00','13:30','14:00'], SettingsWizard::definition('shabbat', 'times_alert_time')['options']);
        self::assertContains(40, SettingsWizard::definition('shabbat', 'candle_minutes')['options']);
    }

    public function testInvalidTypedInputUsesCanonicalParserAndNeverWrites(): void
    {
        $revision = $this->context['revision'];
        foreach (
            [
            ['tefillin','interval_minutes',4],['tefillin','days',[0,7]],['tefillin','fixed_times',['25:00']],
            ['tefillin','sunset_offsets',[0]],['tefillin','max_per_day',25],['shabbat','havdalah_value',20.5],
            ['shabbat','times_alert_time','25:00'],['omer','format','invented'],['tefillin','chol_hamoed','true'],
            ['shabbat','havdalah_method',null],
            ] as [$module,$field,$input]
        ) {
            try {
                SettingsWizard::changes($module, $field, $input, $this->context, $this->parser->parseForm(...));
                self::fail('Invalid wizard input accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame($revision, $this->settings->getGroup('-1')['revision']);
            }
        }
    }
}
