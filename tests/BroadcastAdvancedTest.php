<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService, HebcalProvider};
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\Application;
use Shabbat\Storage\{AdminStateFiles, Database, SubscriberDirectory};
use Shabbat\Telegram\TelegramGateway;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{AdminDirectoryText, BroadcastController, Lang, OperatorDraft, UiGateway};

final class BroadcastAdvancedTest extends TestCase
{
    private Application $app;
    private OperatorDraft $draft;
    private BroadcastController $ui;
    private FrozenClock $clock;
    private bool $view = true;
    private bool $mutate = true;

    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z'));
        $gateway = new class implements UiGateway, TelegramGateway {
            public function isGroupAdmin(string $user, string $group): bool
            {
                return false;
            }
            public function sendPrivate(string $user, string $text, array $markup = []): void
            {
                throw new \RuntimeException('No live messages.');
            }
            public function editPrivate(string $user, int $id, string $text, array $markup = []): void
            {
                throw new \RuntimeException('No live messages.');
            }
            public function answerCallback(string $id, string $text): void
            {
                throw new \RuntimeException('No live callbacks.');
            }
            public function answerInline(string $id, array $results, array $options = []): void
            {
                throw new \RuntimeException('No live inline.');
            }
            public function botUsername(): string
            {
                return 'FixtureBot';
            }
            public function getPermissions(string $group): array
            {
                throw new \RuntimeException('No live permissions.');
            }
            public function setPermissions(string $group, array $permissions): void
            {
                throw new \RuntimeException('No live permissions.');
            }
            public function send(string $peer, array $payload, string $randomId): array
            {
                throw new \RuntimeException('No live messages.');
            }
        };
        $http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('No network.');
            }
        };
        $this->app = new Application($gateway, $this->clock, $db, new CalendarService(new HebcalProvider($http), $this->clock), new GeoNames($http, $this->clock, ''));
        $this->draft = new OperatorDraft($this->app, fn(string $user, bool $mutate = false): bool => $this->view && (!$mutate || $this->mutate));
        $this->ui = new BroadcastController($this->app->settings, $this->draft, $this->clock, fn(string $user, string $cap, bool $mutate = false): bool => $this->view && (!$mutate || $this->mutate));
    }

    public function testGuidedLinkButtonsKeepRichContentAreScopedAndConfirmationBecomesStale(): void
    {
        $this->draft->begin('1', 'broadcast1');
        $this->draft->capture('1', 'Rich text', [['_' => 'messageEntityBold', 'offset' => 0, 'length' => 4]]);
        $this->ui->callback('1', 'bc:buttons');
        $this->ui->callback('1', 'bc:button:add');
        self::assertNotNull($this->ui->input('1', 'Open website'));
        self::assertSame('broadcast_button_url', $this->draft->mode('1'));
        try {
            $this->ui->input('1', 'javascript:alert(1)');
            self::fail('Unsafe URL accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame('broadcast_button_url', $this->draft->mode('1'));
        }
        $this->ui->input('1', 'https://example.com');
        $payload = $this->draft->payload('1');
        self::assertSame('Rich text', $payload['text']);
        self::assertSame('messageEntityBold', $payload['entities'][0]['_']);
        self::assertSame('https://example.com', $payload['buttons'][0][0]['url']);
        self::assertSame([], $this->draft->payload('2')['buttons']);
        $token = $this->draft->confirmation('1', ['2']);
        $frame = $this->ui->confirmation('1', 1, 'op:send:' . $token);
        self::assertStringContainsString('Rich text', $frame['text']);
        self::assertContains('bc:draft', $this->callbacks($frame));
        $this->ui->callback('1', 'bc:button:add');
        $this->ui->input('1', 'Second');
        $this->ui->input('1', 'tg://resolve?domain=example');
        self::assertCount(2, $this->draft->payload('1')['buttons'][0]);
        $this->ui->callback('1', 'bc:content');
        $this->draft->capture('1', 'Updated rich text', $payload['entities']);
        self::assertCount(2, $this->draft->payload('1')['buttons'][0], 'Editing content preserves the designed keyboard.');
        try {
            $this->draft->claim('1', $token);
            self::fail('Changed keyboard accepted stale confirmation.');
        } catch (\RuntimeException) {
            self::assertSame('', $this->draft->mode('1'));
        }
        $this->ui->callback('1', 'bc:button:clear');
        self::assertSame([], $this->draft->payload('1')['buttons']);
        self::assertSame($payload['entities'], $this->draft->payload('1')['entities']);
    }

    public function testLinkWizardExpiresAndNavigationCancelsInputWithoutChangingDraft(): void
    {
        $this->draft->begin('1', 'broadcast1');
        $this->draft->capture('1', 'Keep me');
        $this->ui->callback('1', 'bc:button:add');
        $this->ui->input('1', 'Expired');
        $this->clock->set($this->clock->now()->modify('+16 minutes'));
        try {
            $this->ui->input('1', 'https://example.com');
            self::fail('Expired button label accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->draft->payload('1')['buttons']);
        }
        $this->ui->callback('1', 'bc:draft');
        self::assertNull($this->ui->input('1', 'https://example.com'));
        self::assertSame('Keep me', $this->draft->payload('1')['text']);
    }

    public function testReferenceOptionsAreAvailableInAllSixLanguagesWithBoundedRealCallbacks(): void
    {
        $this->draft->begin('1', 'broadcast1');
        $this->draft->capture('1', 'Hello');
        foreach (Lang::CODES as $language) {
            $user = $this->app->settings->getUser('1');
            $this->app->settings->updateUser('1', $user['revision'], ['language' => $language]);
            $main = $this->ui->main('1');
            self::assertStringContainsString(AdminDirectoryText::text($language, 'broadcast'), $main['text']);
            foreach (['bc:new','bc:edit','bc:jobs:0','deleteLastBroadcast','deleteAllBroadcast','cancelPinned','LastBrodDATA'] as $callback) {
                self::assertContains($callback, $this->callbacks($main));
            }
            $draft = $this->ui->draft('1');
            foreach (['bc:content','bc:buttons','bc:pin:on','bc:audience','bc:sd','bc:schedule','bc:preview','bc:confirm'] as $callback) {
                self::assertContains($callback, $this->callbacks($draft));
            }
            $audience = $this->ui->callback('1', 'bc:audience');
            foreach (SubscriberDirectory::AUDIENCES as $name) {
                self::assertContains('bc:audience:' . $name, $this->callbacks($audience));
            }
            foreach ([$main,$draft,$audience,$this->ui->callback('1', 'bc:sd'),$this->ui->callback('1', 'bc:schedule')] as $frame) {
                self::assertStringNotContainsString('—', $frame['text']);
                foreach ($this->callbacks($frame) as $callback) {
                    self::assertLessThanOrEqual(64, strlen($callback));
                }
            }
        }
    }

    public function testSelfDestructPresetsCustomOffAndEditModeAreStrictAndOperatorScoped(): void
    {
        $this->ui->callback('1', 'bc:sd:48');
        self::assertSame(48, $this->draft->state('1')['self_destruct_hours']);
        self::assertNull($this->draft->state('2')['self_destruct_hours']);
        $this->ui->callback('1', 'bc:sd:custom');
        self::assertNull($this->ui->input('1', '/start'));
        self::assertNotNull($this->ui->input('1', '17'));
        self::assertSame(17, $this->draft->state('1')['self_destruct_hours']);
        $this->ui->callback('1', 'bc:sd:off');
        self::assertNull($this->draft->state('1')['self_destruct_hours']);
        foreach ([0,49,-1] as $hours) {
            try {
                $this->draft->setSelfDestructHours('1', $hours);
                self::fail('Invalid self-destruct accepted.');
            } catch (\InvalidArgumentException) {
                self::assertNull($this->draft->state('1')['self_destruct_hours']);
            }
        }
        $this->draft->setSelfDestructHours('1', 6);
        $this->draft->setScheduledAt('1', $this->clock->now()->getTimestamp() + 600);
        $this->ui->callback('1', 'bc:edit');
        self::assertSame('edit', $this->draft->state('1')['operation']);
        self::assertNull($this->draft->state('1')['self_destruct_hours']);
        self::assertNull($this->draft->state('1')['scheduled_at']);
        self::assertNotContains('bc:sd', $this->callbacks($this->ui->draft('1')));
        $this->expectException(\InvalidArgumentException::class);
        $this->draft->setSelfDestructHours('1', 1);
    }

    public function testCustomInputValidationRetainsPromptUntilValidAndCancelStopsCapture(): void
    {
        $this->ui->callback('1', 'bc:sd:custom');
        try {
            $this->ui->input('1', '1000');
            self::fail('Invalid custom hours accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame('broadcast_sd_custom', $this->draft->mode('1'));
        }
        $this->ui->callback('1', 'bc:draft');
        self::assertSame('', $this->draft->mode('1'));
        self::assertNull($this->ui->input('1', '12'));
        $this->ui->callback('1', 'bc:schedule:custom');
        self::assertNotNull($this->ui->input('1', 'off'));
        self::assertNull($this->draft->state('1')['scheduled_at']);
    }

    public function testSchedulingUsesTheUserTimeZoneAndStrictDatesInsteadOfFreeFormParsing(): void
    {
        $expected = new \DateTimeImmutable('2026-10-16 13:45', new \DateTimeZone('Asia/Jerusalem'));
        foreach (['2026-10-16 13:45','16/10/2026 13:45','16.10.2026 13:45'] as $input) {
            self::assertSame($expected->getTimestamp(), $this->draft->parseSchedule('1', $input));
        }
        self::assertSame($this->clock->now()->getTimestamp() + 1800, $this->draft->parseSchedule('1', '30'));
        self::assertNull($this->draft->parseSchedule('1', 'off'));
        foreach (['0','-1','tomorrow','2026-02-30 13:00','2026-10-14 13:00','2028-10-16 13:45','2026-10-25 01:30','2027-03-26 02:30'] as $input) {
            try {
                $this->draft->parseSchedule('1', $input);
                self::fail('Invalid or ambiguous time accepted: ' . $input);
            } catch (\InvalidArgumentException) {
                self::assertNull($this->draft->state('1')['scheduled_at']);
            }
        }
        $user = $this->app->settings->getUser('1');
        $this->app->settings->updateUser('1', $user['revision'], ['timezone_id' => 'America/New_York']);
        self::assertSame((new \DateTimeImmutable('2026-10-16 13:45', new \DateTimeZone('America/New_York')))->getTimestamp(), $this->draft->parseSchedule('1', '2026-10-16 13:45'));
    }

    public function testScheduledPresetsSnapshotAndConfirmationCoverEveryDeliverySetting(): void
    {
        $this->draft->begin('1', 'broadcast1');
        $this->draft->capture('1', 'Original 🚀', [['_' => 'messageEntityBold','offset' => 0,'length' => 8]], 'fileMedia');
        $this->ui->callback('1', 'bc:audience:authorized_groups_channels');
        $this->ui->callback('1', 'bc:pin:on');
        $this->ui->callback('1', 'bc:sd:12');
        $this->ui->callback('1', 'bc:schedule:30');
        $state = $this->draft->state('1');
        self::assertSame('authorized_groups_channels', $state['audience']);
        self::assertSame(12, $state['self_destruct_hours']);
        self::assertTrue($state['pin']);
        self::assertSame($this->clock->now()->getTimestamp() + 1800, $state['scheduled_at']);
        $token = $this->draft->confirmation('1', ['1','-1001']);
        $this->ui->callback('1', 'bc:sd:24');
        try {
            $this->draft->claim('1', $token);
            self::fail('Changed delivery settings accepted.');
        } catch (\RuntimeException) {
            self::assertNotNull($this->app->flows->peek($token, '1', 'operator_broadcast'));
        }
        $token = $this->draft->confirmation('1', ['1','-1001']);
        $snapshot = $this->draft->claim('1', $token);
        self::assertSame(24, $snapshot['state']['self_destruct_hours']);
        self::assertSame('fileMedia', $snapshot['state']['payload']['media']['bot_api_file_id']);
        self::assertSame('Original 🚀', $snapshot['state']['payload']['text']);
        self::assertNull($this->app->flows->peek($token, '1', 'operator_broadcast'));
    }

    public function testExpiredScheduleCannotSilentlyTurnIntoImmediateSend(): void
    {
        $this->draft->begin('1', 'broadcast1');
        $this->draft->capture('1', 'Hello');
        $this->draft->setScheduledAt('1', $this->clock->now()->getTimestamp() + 60);
        $this->clock->set($this->clock->now()->modify('+2 minutes'));
        $this->expectException(\InvalidArgumentException::class);
        $this->draft->confirmation('1', ['1']);
    }

    public function testReadOnlyRoleCanViewJobsAndDraftButCannotMutateOrReturnSendActions(): void
    {
        $this->mutate = false;
        $main = $this->ui->main('1');
        self::assertNotContains('bc:new', $this->callbacks($main));
        self::assertContains('bc:jobs:0', $this->callbacks($main));
        self::assertSame(['action' => 'jobs','page' => 0], $this->ui->callback('1', 'bc:jobs:0'));
        self::assertNotContains('bc:confirm', $this->callbacks($this->ui->draft('1')));
        foreach (['bc:new','bc:edit','bc:pin:on','bc:sd:1','bc:schedule:30','bc:confirm','bc:preview','bc:canceljob:test'] as $callback) {
            try {
                $this->ui->callback('1', $callback);
                self::fail('View-only action accepted: ' . $callback);
            } catch (\RuntimeException) {
                self::assertFalse($this->draft->state('1')['pin']);
            }
        }
        $this->view = false;
        $this->expectException(\RuntimeException::class);
        $this->ui->callback('1', 'bc:jobs:0');
    }

    public function testJobsArePagedAndCancelActionsNeverExecuteRemoteOperations(): void
    {
        $jobs = [];
        foreach (range(1, 12) as $id) {
            $jobs[] = ['id' => 'job_' . $id,'kind' => $id % 2 === 0 ? 'self_destruct' : 'scheduled','at' => $this->clock->now()->getTimestamp() + $id * 3600,'targets' => 50];
        }
        $first = $this->ui->jobs('1', $jobs);
        self::assertContains('bc:jobs:1', $this->callbacks($first));
        self::assertContains('bc:canceljob:job_1', $this->callbacks($first));
        self::assertContains('bc:canceldelete:job_2', $this->callbacks($first));
        self::assertSame(['action' => 'cancel_job','id' => 'job_1'], $this->ui->callback('1', 'bc:canceljob:job_1'));
        self::assertSame(['action' => 'cancel_self_destruct','id' => 'job_2'], $this->ui->callback('1', 'bc:canceldelete:job_2'));
        self::assertSame(['action' => 'confirm'], $this->ui->callback('1', 'bc:confirm'));
        self::assertSame(['action' => 'preview'], $this->ui->callback('1', 'bc:preview'));
        $this->mutate = false;
        self::assertNotContains('bc:canceljob:job_1', $this->callbacks($this->ui->jobs('1', $jobs)));
    }

    public function testMalformedPersistedDeliverySettingsFailClosedAndClearRemovesAllOptions(): void
    {
        $this->app->settings->getUser('1');
        $this->app->db->pdo()->exec("INSERT INTO operator_broadcast_options VALUES('1','selfdestructhours.txt','999',0)");
        try {
            $this->draft->state('1');
            self::fail('Invalid stored settings accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame('999', $this->app->db->pdo()->query("SELECT value_text FROM operator_broadcast_options WHERE user_id='1'")->fetchColumn());
        }
        $this->draft->cancel('1', true);
        self::assertNull($this->draft->state('1')['self_destruct_hours']);
        self::assertNull($this->draft->state('1')['scheduled_at']);
        self::assertSame('send', $this->draft->state('1')['operation']);
    }

    public function testScheduleExpiringDuringConfirmationCannotBeClaimed(): void
    {
        $this->draft->begin('1', 'broadcast1');
        $this->draft->capture('1', 'Hello');
        $this->draft->setScheduledAt('1', $this->clock->now()->getTimestamp() + 60);
        $token = $this->draft->confirmation('1', ['1']);
        $this->clock->set($this->clock->now()->modify('+2 minutes'));
        try {
            $this->draft->claim('1', $token);
            self::fail('Expired schedule claim accepted.');
        } catch (\RuntimeException) {
            self::assertNotNull($this->app->flows->peek($token, '1', 'operator_broadcast'));
        }
    }

    public function testLegacyFullKeyboardWrappersAreReadWithoutRewritingTheirStoredBytes(): void
    {
        $native = ['_' => 'replyInlineMarkup','rows' => [['_' => 'keyboardButtonRow','buttons' => [
            ['_' => 'keyboardButtonUrl','text' => 'Native','url' => 'https://example.org','style' => ['_' => 'keyboardButtonStyle','bg_primary' => true,'icon' => 5944760643524500583]],
        ]]]];
        $botApi = ['inline_keyboard' => [[
            ['text' => 'Bot API','url' => 'https://example.org','style' => 'success'],
        ]]];
        $this->draft->begin('1', 'broadcast1');
        $this->draft->capture('1', 'Preserved legacy draft');
        foreach ([$native,$botApi] as $wrapper) {
            $raw = json_encode($wrapper, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            AdminStateFiles::write('data/1/BUTTONS.txt', $raw);
            $state = $this->draft->state('1');
            $expected = \Shabbat\Storage\TemplateRepository::buttonRows($wrapper);
            self::assertSame($expected, $state['payload']['buttons']);
            self::assertSame($raw, AdminStateFiles::read('data/1/BUTTONS.txt'));
            $this->draft->begin('1', 'addBUTTONS');
            self::assertTrue($this->draft->buttons('1', $raw));
            self::assertSame($expected, $this->draft->state('1')['payload']['buttons']);
        }
    }

    private function callbacks(array $frame): array
    {
        $result = [];
        foreach ($frame['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                $result[] = $button['data'];
            }
        }
        return $result;
    }
}
