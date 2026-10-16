<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Shabbat\Calendar\{CalendarService, HebcalProvider};
use Shabbat\Content\GroupMessageButtons;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\{Application, Runner};
use Shabbat\Scheduling\{JobRepository, OutboxWorker};
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\Lang;

final class GroupMessageButtonsIntegrationTest extends TestCase
{
    private Database $db;
    private Application $app;
    private JobRepository $jobs;
    private OutboxWorker $worker;
    private FrozenClock $clock;
    private GroupMessageButtonsGateway $gateway;
    private int $sequence = 0;
    protected function setUp(): void
    {
        (new ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T09:00:00+03:00'));
        $this->gateway = new GroupMessageButtonsGateway();
        $http = new GroupMessageFixtureClient();
        $calendar = new CalendarService(new HebcalProvider($http), $this->clock);
        $this->app = new Application($this->gateway, $this->clock, $this->db, $calendar, new GeoNames($http, $this->clock, ''));
        $this->app->settings->ensureGroup('-1001', 'Worker integration', ['tefillin_enabled' => true, 'zmanim_enabled' => true, 'daf_enabled' => true, 'times_alert_enabled' => true]);
        $this->jobs = new JobRepository($this->db, $this->clock);
        $this->worker = (new ReflectionProperty(Application::class, 'worker'))->getValue($this->app);
    }
    protected function tearDown(): void
    {
        (new ReflectionProperty(Runner::class, 'stopping'))->setValue(null, false);
    }
    private function deliver(string $kind): array
    {
        $g = $this->app->settings->getGroup('-1001');
        $now = $this->clock->now()->getTimestamp();
        $id = $this->jobs->enqueue('-1001', $kind, 'button-integration-' . ++$this->sequence, $now, $now + 300, $g['revision'], []);
        $result = $this->worker->runOnce('fixture-worker');
        self::assertSame('sent', $result['status'], Database::encode($result));
        self::assertSame($id, $result['job_id']);
        return ['job' => $this->jobs->get($id), 'result' => $result, 'sent' => $this->gateway->sent[array_key_last($this->gateway->sent)]];
    }
    private function payload(string $kind): array
    {
        return (new ReflectionMethod(Application::class, 'payload'))->invoke($this->app, $this->app->settings->getGroup('-1001'), $kind, $this->clock->now());
    }
    private function advanceTefillinSpacing(): void
    {
        // Separate content fixtures must still obey the actual-delivery spacing gate.
        $minutes = $this->app->settings->getGroup('-1001')['tefillin_min_spacing_minutes'];
        $this->clock->set($this->clock->now()->modify('+' . $minutes . ' minutes'));
    }
    public function testActualScheduledShabbatDeliveryKeepsConciseOverviewAndDefaultShare(): void
    {
        $delivery = $this->deliver('times_alert');
        $payload = $delivery['sent']['payload'];
        $group = $this->app->settings->getGroup('-1001');
        self::assertSame($this->app->ui->renderView('0', $group, 'shabbat'), $payload['text']);
        self::assertSame($delivery['job']['payload']['text'], $payload['text']);
        self::assertSame('shabat', $payload['buttons'][0][0]['switch_inline_query']);
        foreach (['2026-10-16 17:26', '2026-10-17 18:41', 'חנוכה', '2026-12-04'] as $visible) {
            self::assertStringContainsString($visible, $payload['text']);
        }
        foreach (['2026-10-24', '2026-10-31', '2026-11-07', 'Hebcal', 'עודכן', '(+03:00)', 'Genesis', 'Isaiah'] as $hidden) {
            self::assertStringNotContainsString($hidden, $payload['text']);
        }
    }

    public function testActualWorkerPreparesPersistsSendsAndRegistersDefaultTefillinRound(): void
    {
        $delivery = $this->deliver('tefillin');
        $job = $delivery['job'];
        $message = $delivery['sent']['id'];
        self::assertNotNull($job['payload_prepared_at']);
        self::assertNotNull($job['module_delivery_processed_at']);
        $token = $job['payload']['_tefillin_ack_token'];
        self::assertSame($token, $delivery['sent']['payload']['_tefillin_ack_token']);
        self::assertSame('הנחתי (0)', $job['payload']['reply_markup']['inline_keyboard'][0][0]['text']);
        self::assertSame(0, $this->app->tefillinAcknowledgements->snapshot('-1001', $message)['count']);
        self::assertSame([$message], $this->app->deletions->getLastModuleMessages('-1001', 'tefillin'));
        self::assertSame(1, $this->app->tefillinAcknowledgements->toggle('42', '-1001', $message, $token, 'native-query-1')['count']);
    }
    public function testConfirmedSendBookkeepingFailureRecoversThroughActualApplicationReplayWithoutResend(): void
    {
        $this->db->pdo()->exec("CREATE TRIGGER ack_registration_failure BEFORE INSERT ON tefillin_ack_messages BEGIN SELECT RAISE(ABORT,'fixture'); END");
        $delivery = $this->deliver('tefillin');
        self::assertTrue($delivery['result']['cleanup_pending']);
        self::assertNull($delivery['job']['module_delivery_processed_at']);
        self::assertSame('sent', $delivery['job']['status']);
        self::assertNull($this->app->tefillinAcknowledgements->snapshot('-1001', $delivery['sent']['id']));
        $this->db->pdo()->exec('DROP TRIGGER ack_registration_failure');
        (new ReflectionMethod(Application::class, 'replayDeliveryBookkeeping'))->invoke($this->app);
        $job = $this->jobs->get($delivery['job']['job_id']);
        self::assertNotNull($job['module_delivery_processed_at']);
        self::assertCount(1, $this->gateway->sent);
        $message = $delivery['sent']['id'];
        $token = $job['payload']['_tefillin_ack_token'];
        $this->app->tefillinAcknowledgements->toggle('42', '-1001', $message, $token, 'query-a');
        (new ReflectionMethod(Application::class, 'replayDeliveryBookkeeping'))->invoke($this->app);
        self::assertSame(1, $this->app->tefillinAcknowledgements->snapshot('-1001', $message)['count']);
        self::assertCount(1, $this->gateway->sent);
    }
    public function testUnverifiedResponsePreservesPreviousPointerAndDoesNotBindUnknownMessage(): void
    {
        $first = $this->deliver('tefillin');
        $this->advanceTefillinSpacing();
        $this->gateway->verified = false;
        $second = $this->deliver('tefillin');
        self::assertSame([$first['sent']['id']], $this->app->deletions->getLastModuleMessages('-1001', 'tefillin'));
        self::assertNull($this->app->tefillinAcknowledgements->snapshot('-1001', $second['sent']['id']));
        self::assertNotNull($second['job']['module_delivery_processed_at']);
    }
    public function testGeneratedZmanimDafAndTimesAlertsUseOriginalInlineShareActionInAllSixLanguages(): void
    {
        foreach (Lang::CODES as $language) {
            $g = $this->app->settings->getGroup('-1001');
            $this->app->settings->updateGroup('-1001', $g['revision'], ['language' => $language], '42');
            foreach (['zmanim' => 'zmanim', 'daf' => 'daf', 'times_alert' => 'shabat'] as $kind => $query) {
                $p = $this->payload($kind);
                self::assertNotSame('', trim($p['text']));
                self::assertSame('inherit', $p['buttons_mode']);
                self::assertSame($query, $p['buttons'][0][0]['switch_inline_query']);
                self::assertSame(GroupMessageButtons::defaults($kind, $language), $this->app->groupMessageButtons($this->app->settings->getGroup('-1001'), $kind));
            }
        }
    }
    public function testKeyboardOnlyTemplatesPreserveCurrentGeneratedCalendarBodyAndCustomButtonStyles(): void
    {
        $expected = $this->payload('zmanim')['text'];
        $buttons = [[['text' => 'Personal', 'url' => 'https://example.com/', 'style' => ['_' => 'keyboardButtonStyle', 'bg_success' => true, 'icon' => 123]]]];
        $template = $this->app->templates->save('-1001', 'zmanim', ['text' => '', 'buttons' => $buttons, 'buttons_mode' => 'custom'], '42');
        $p = $this->payload('zmanim');
        self::assertSame($expected, $p['text']);
        self::assertSame($buttons, $p['buttons']);
        self::assertSame($buttons, $this->app->groupMessageButtons($this->app->settings->getGroup('-1001'), 'zmanim'));
        $template['buttons'] = [];
        $template['buttons_mode'] = 'none';
        $template = $this->app->templates->save('-1001', 'zmanim', $template, '42', $template['revision']);
        $p = $this->payload('zmanim');
        self::assertSame($expected, $p['text']);
        self::assertArrayNotHasKey('buttons', $p);
        self::assertSame([], $this->app->groupMessageButtons($this->app->settings->getGroup('-1001'), 'zmanim'));
        $template['buttons_mode'] = 'inherit';
        $this->app->templates->save('-1001', 'zmanim', $template, '42', $template['revision']);
        self::assertSame($expected, $this->payload('zmanim')['text']);
        self::assertSame('zmanim', $this->payload('zmanim')['buttons'][0][0]['switch_inline_query']);
    }
    public function testCustomRichTefillinBodyInheritsDefaultAckAndExplicitCustomOrNoneOverrideIt(): void
    {
        foreach (['inherit', 'custom', 'none'] as $mode) {
            $content = ['text' => 'My reminder 🚀', 'entities' => [['_' => 'messageEntityBold', 'offset' => 0, 'length' => 11]], 'media' => ['bot_api_file_id' => 'offline-file'], 'buttons_mode' => $mode,
                'buttons' => $mode === 'custom' ? [[['text' => 'Custom', 'url' => 'https://example.com/']]] : []];
            $this->app->templates->save('-1001', 'tefillin', $content, '42');
            $delivery = $this->deliver('tefillin');
            $p = $delivery['sent']['payload'];
            self::assertSame($content['text'], $p['text']);
            self::assertSame($content['entities'], $p['entities']);
            self::assertSame($content['media'], $p['media']);
            if ($mode === 'inherit') {
                self::assertArrayHasKey('_tefillin_ack_token', $p);
                self::assertNotNull($this->app->tefillinAcknowledgements->snapshot('-1001', $delivery['sent']['id']));
            } else {
                self::assertArrayNotHasKey('_tefillin_ack_token', $p);
                self::assertNull($this->app->tefillinAcknowledgements->snapshot('-1001', $delivery['sent']['id']));
                if ($mode === 'custom') {
                    self::assertSame($content['buttons'], $p['buttons']);
                }
            }
            $this->advanceTefillinSpacing();
        }
    }
    public function testAcknowledgementSettingOffStillDeliversTheReminderWithoutCounter(): void
    {
        $g = $this->app->settings->getGroup('-1001');
        $this->app->settings->updateGroup('-1001', $g['revision'], ['tefillin_ack_enabled' => false], '42');
        $delivery = $this->deliver('tefillin');
        self::assertArrayNotHasKey('_tefillin_ack_token', $delivery['sent']['payload']);
        self::assertNull($this->app->tefillinAcknowledgements->snapshot('-1001', $delivery['sent']['id']));
        self::assertNotSame('', $delivery['sent']['payload']['text']);
    }
}
