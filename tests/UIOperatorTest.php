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
use Shabbat\Runtime\Application;
use Shabbat\Storage\AdminStateFiles;
use Shabbat\Storage\Database;
use Shabbat\Telegram\TelegramGateway;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{Lang, OperatorController, OperatorDraft, UiGateway};

final class UIOperatorTest extends TestCase
{
    private Application $app;
    private OperatorController $ui;
    private OperatorDraft $draft;
    private FrozenClock $clock;
    private bool $allowed = true;
    private UiGateway&TelegramGateway $gateway;
    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-13T12:00:00Z'));
        $this->gateway = new class implements UiGateway, TelegramGateway
        {
            public array $sent = [];
            public function isGroupAdmin(string $user, string $group): bool
            {
                return true;
            }
            public function sendPrivate(string $user, string $text, array $markup = []): void
            {
            }
            public function editPrivate(string $user, int $id, string $text, array $markup = []): void
            {
            }
            public function answerCallback(string $id, string $text): void
            {
            }
            public function answerInline(string $id, array $results, array $options = []): void
            {
            }
            public function botUsername(): string
            {
                return 'FixtureBot';
            }
            public function getPermissions(string $group): array
            {
                return [];
            }
            public function setPermissions(string $group, array $permissions): void
            {
                throw new \RuntimeException('No Telegram permission calls in operator tests.');
            }
            public function send(string $peer, array $payload, string $randomId): array
            {
                $this->sent[] = compact('peer', 'payload', 'randomId');
                return [];
            }
        };
        $http = new class implements JsonClient
        {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('No remote providers in operator tests.');
            }
        };
        $parser = static fn(string $text): array => ['_' => 'replyInlineMarkup', 'rows' => [['_' => 'keyboardButtonRow', 'buttons' => [['_' => 'keyboardButtonCopy', 'text' => $text, 'copy_text' => 'sample', 'style' => ['_' => 'keyboardButtonStyle', 'bg_success' => true, 'icon' => 123]]]]]];
        $this->app = new Application($this->gateway, $this->clock, $db, new CalendarService(new HebcalProvider($http), $this->clock), new GeoNames($http, $this->clock, ''), $parser);
        $authorize = fn(string $user): bool => $this->allowed && in_array($user, ['1', '2'], true);
        $this->ui = new OperatorController($this->app->settings, new Lang(), $authorize);
        $this->draft = new OperatorDraft($this->app, $authorize);
    }
    public function testAllSixOperatorMenusHaveTranslatedFramesWithStableRealCallbacks(): void
    {
        $lang = new Lang();
        $ids = null;
        foreach (Lang::CODES as $code) {
            $user = $this->app->settings->getUser('1');
            $this->app->settings->updateUser('1', $user['revision'], ['language' => $code]);
            $main = $this->ui->main('1');
            self::assertSame($lang->text($code, 'operator_panel'), $main['text']);
            $callbacks = [];
            foreach ($main['markup']['rows'] as $row) {
                foreach ($row['buttons'] as $button) {
                    $callbacks[] = $button['data'];
                    if ($button['data'] === 'Broadcast') {
                        self::assertSame(['_' => 'keyboardButtonStyle', 'bg_primary' => true], $button['style']);
                    } else {
                        self::assertArrayNotHasKey('style', $button);
                    }
                }
            }
            $ids ??= $callbacks;
            self::assertSame($ids, $callbacks);
            $draft = $this->ui->draft('1', ['exists' => true, 'pin' => true, 'audience' => 'channels']);
            self::assertStringContainsString($lang->text($code, 'operator_recipients_channels'), $draft['text']);
            self::assertSame('bg_primary', array_key_last($draft['markup']['rows'][3]['buttons'][0]['style']));
            $audience = $this->ui->audience('1');
            self::assertSame('מצבתפוצה2', $audience['markup']['rows'][1]['buttons'][0]['data']);
            $stats = $this->ui->statistics('1', ['known' => 3, 'registered' => 2, 'locking' => 1, 'locked' => 0, 'recovery' => 1], ['users' => 1, 'groups' => 2, 'channels' => 3, 'bots' => 4, 'total' => 10]);
            self::assertStringContainsString($lang->text($code, 'operator_statistics'), $stats['text']);
            self::assertStringContainsString('10', $stats['text']);
            $page = $this->ui->subscribers('1', [['id' => '-1001', 'name' => 'Group']], 2, 3);
            self::assertSame('openprofile_-1001', $page['markup']['rows'][0]['buttons'][0]['data']);
            self::assertSame($lang->text($code, 'operator_page', ['page' => 2, 'total' => 3]), $page['markup']['rows'][2]['buttons'][0]['text']);
            self::assertStringContainsString($lang->text($code, 'operator_edit_text'), $this->ui->prompt('1', 'text')['text']);
        }
        self::assertSame(['סטטיסטיקות', 'רשימתמשתמשים', 'Broadcast', 'sb:start'], $ids);
    }
    public function testOperatorPaletteDistinguishesActionsNavigationAndDeletion(): void
    {
        $broadcast = $this->ui->broadcast('1');
        $byData = [];
        foreach ($broadcast['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                $byData[$button['data']] = $button;
            }
        }
        self::assertArrayNotHasKey('style', $byData['LastBrodDATA']);
        self::assertArrayNotHasKey('style', $byData['חזרהמנהל']);
        self::assertSame(['_' => 'keyboardButtonStyle', 'bg_primary' => true], $byData['setBroadcast']['style']);
        foreach (['deleteLastBroadcast', 'deleteAllBroadcast'] as $data) {
            self::assertSame(['_' => 'keyboardButtonStyle', 'bg_danger' => true], $byData[$data]['style']);
        }
        $enabled = $this->ui->draft('1', ['exists' => true, 'pin' => true]);
        $disabled = $this->ui->draft('1', ['exists' => true, 'pin' => false]);
        self::assertSame(['_' => 'keyboardButtonStyle', 'bg_primary' => true], $enabled['markup']['rows'][0]['buttons'][0]['style']);
        self::assertSame(['_' => 'keyboardButtonStyle', 'bg_danger' => true], $disabled['markup']['rows'][0]['buttons'][0]['style']);
        self::assertSame(['_' => 'keyboardButtonStyle', 'bg_danger' => true], $enabled['markup']['rows'][4]['buttons'][0]['style']);
    }

    public function testDetailedStatisticsKeepOriginalChatCategoriesAndReadableTotalsInSixLanguages(): void
    {
        $lang = new Lang();
        $counts = ['known' => 1200, 'registered' => 1199, 'locking' => 1100, 'locked' => 1000, 'recovery' => 2];
        $subscribers = ['users' => 1000, 'chats' => 200, 'supergroups' => 1000, 'channels' => 50, 'bots' => 4, 'total' => 2254];
        foreach (Lang::CODES as $code) {
            $user = $this->app->settings->getUser('1');
            $this->app->settings->updateUser('1', $user['revision'], ['language' => $code]);
            $panel = $this->ui->statistics('1', $counts, $subscribers);
            $formatted = array_map(static fn(int $value): string => number_format($value), $subscribers);
            self::assertStringContainsString($lang->text($code, 'operator_subscriber_stats_full', $formatted), $panel['text']);
            self::assertStringContainsString($lang->text($code, 'operator_group_stats', array_map(static fn(int $value): string => number_format($value), $counts)), $panel['text']);
            self::assertStringContainsString('2,254', $panel['text']);
            self::assertArrayNotHasKey('style', $panel['markup']['rows'][0]['buttons'][0]);
        }
    }
    public function testGroupAdminPrivilegeDoesNotGrantOperatorAccess(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->ui->main('99');
    }
    public function testOperatorOptionsAndPagesAreIndependentAndPreserveRichDraft(): void
    {
        $this->draft->begin('1', 'broadcast1');
        self::assertTrue($this->draft->capture('1', 'Hello 🚀', [['_' => 'messageEntityBold', 'offset' => 0, 'length' => 8]], 'fixtureMedia'));
        $this->draft->setAudience('1', 'channels');
        $this->draft->setPin('1', true);
        $this->draft->begin('1', 'addBUTTONS');
        self::assertTrue($this->draft->buttons('1', 'Copy'));
        $one = $this->draft->state('1');
        self::assertSame('channels', $one['audience']);
        self::assertTrue($one['pin']);
        self::assertSame('fixtureMedia', $one['payload']['media']['bot_api_file_id']);
        self::assertSame(8, $one['payload']['entities'][0]['length']);
        self::assertSame(['_' => 'keyboardButtonStyle', 'bg_success' => true, 'icon' => 123], $one['payload']['buttons'][0][0]['style']);
        $this->draft->begin('2', 'broadcast1');
        $this->draft->capture('2', 'Second operator');
        $this->draft->setAudience('2', 'groups');
        $this->draft->setPin('2', false);
        $two = $this->draft->state('2');
        self::assertSame('groups', $two['audience']);
        self::assertFalse($two['pin']);
        self::assertSame([], $two['payload']['buttons']);
        self::assertSame($one, $this->draft->state('1'));
        self::assertSame([], $this->gateway->sent);
    }
    public function testCommandsCannotBeCapturedAndMalformedEntityCannotOverwriteDraft(): void
    {
        $this->draft->begin('1', 'broadcast1');
        self::assertFalse($this->draft->capture('1', '/lang'));
        self::assertSame('broadcast1', $this->draft->mode('1'));
        try {
            $this->draft->capture('1', '🚀', [['_' => 'messageEntityBold', 'offset' => 1, 'length' => 1]]);
            self::fail('Mid-surrogate entity accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame('', $this->draft->payload('1')['text']);
        }
        $this->draft->capture('1', 'Original');
        $this->draft->begin('1', 'broadcast1');
        try {
            $this->draft->capture('1', str_repeat('x', 1025), [], 'fixtureMedia');
            self::fail('Too-long media caption accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame('Original', $this->draft->payload('1')['text']);
        }
    }
    public function testBroadcastConfirmationRechecksRoleDigestUserAndOneUse(): void
    {
        $this->draft->begin('1', 'broadcast1');
        $this->draft->capture('1', 'Draft');
        $token = $this->draft->confirmation('1', ['1', '-1001']);
        try {
            $this->draft->claim('2', $token);
            self::fail('Wrong user accepted');
        } catch (\RuntimeException) {
            self::assertNotNull($this->app->flows->peek($token, '1', 'operator_broadcast'));
        }
        $this->allowed = false;
        try {
            $this->draft->claim('1', $token);
            self::fail('Lost operator role accepted');
        } catch (\RuntimeException) {
            self::assertNotNull($this->app->flows->peek($token, '1', 'operator_broadcast'));
        }
        $this->allowed = true;
        $this->draft->setPin('1', true);
        try {
            $this->draft->claim('1', $token);
            self::fail('Changed draft accepted');
        } catch (\RuntimeException) {
            self::assertNotNull($this->app->flows->peek($token, '1', 'operator_broadcast'));
        }
        $token = $this->draft->confirmation('1', ['1', '-1001']);
        $snapshot = $this->draft->claim('1', $token);
        self::assertSame(['1', '-1001'], $snapshot['targets']);
        self::assertTrue($snapshot['state']['pin']);
        try {
            $this->draft->claim('1', $token);
            self::fail('Replay accepted');
        } catch (\RuntimeException) {
            self::assertNull($this->app->flows->peek($token, '1', 'operator_broadcast'));
        }
        self::assertSame([], $this->gateway->sent);
    }
    public function testExpiredAndRevisionStaleBroadcastConfirmationsCannotSend(): void
    {
        $this->draft->begin('1', 'broadcast1');
        $this->draft->capture('1', 'Draft');
        $token = $this->draft->confirmation('1', ['1']);
        $this->clock->set(new \DateTimeImmutable('2026-10-13T12:11:00Z'));
        try {
            $this->draft->claim('1', $token);
            self::fail('Expired confirmation accepted');
        } catch (\RuntimeException) {
            self::assertSame([], $this->gateway->sent);
        }
        $token = $this->draft->confirmation('1', ['1']);
        $user = $this->app->settings->getUser('1');
        $this->app->settings->updateUser('1', $user['revision'], ['language' => 'en']);
        try {
            $this->draft->claim('1', $token);
            self::fail('Revision-stale confirmation accepted');
        } catch (\RuntimeException) {
            self::assertSame([], $this->gateway->sent);
        }
    }
    public function testDraftSaveRollsBackAllFieldsOnDatabaseFailure(): void
    {
        $this->draft->begin('1', 'broadcast1');
        $this->draft->capture('1', 'Original');
        $this->draft->begin('1', 'broadcast1');
        $this->app->db->pdo()->exec("CREATE TRIGGER reject_operator_entities BEFORE UPDATE ON admin_state WHEN NEW.state_key='ent.txt' BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
        try {
            $this->draft->capture('1', 'Rejected');
            self::fail('Failed SQL save reported success');
        } catch (\PDOException) {
            self::assertSame('Original', $this->draft->payload('1')['text']);
            self::assertSame('broadcast1', $this->draft->mode('1'));
        }
    }
    public function testLegacyProfileFilterPrecedesGuardAndCaptureRoutesPreserveCommands(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/bot.php');
        self::assertIsString($source);
        $profile = substr($source, strpos($source, 'public function adnimhandle('), 600);
        self::assertLessThan(strpos($profile, 'legacySystemQueryAllowed'), strpos($profile, "str_starts_with(\$query->data,'openprofile_')"));
        self::assertStringContainsString("v3OperatorCapture(\$message,'broadcast1')", $source);
        self::assertStringContainsString("v3OperatorCapture(\$message,'addBUTTONS')", $source);
        $runtime = file_get_contents(dirname(__DIR__) . '/src/Runtime/BotIntegration.php');
        self::assertStringContainsString('getFileInfo($payload[', $runtime);
        self::assertStringContainsString('operatorBroadcastCallbacks(ChatButtonQuery', $runtime);
    }
    public function testEmptyDraftAudienceAndOversizeButtonsAreRejectedWithoutSideEffects(): void
    {
        try {
            $this->draft->confirmation('1', ['1']);
            self::fail('Empty draft accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->gateway->sent);
        }
        $this->draft->begin('1', 'broadcast1');
        $this->draft->capture('1', 'Draft');
        try {
            $this->draft->confirmation('1', []);
            self::fail('Empty audience accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->gateway->sent);
        }
        $this->draft->begin('1', 'addBUTTONS');
        try {
            $this->draft->buttons('1', json_encode([[['text' => 'Too large', 'callback_data' => str_repeat('x', 65)]]], JSON_THROW_ON_ERROR));
            self::fail('Oversize callback accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->draft->payload('1')['buttons']);
            self::assertSame('addBUTTONS', $this->draft->mode('1'));
        }
    }
}
