<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService, HebcalProvider};
use Shabbat\Location\GeoNames;
use Shabbat\Scheduling\{LockCoordinator, PermissionRecovery, Permissions};
use Shabbat\Storage\{Database, FlowTokenRepository, SettingsRepository};
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{BotController, Lang};

final class PermissionRecoveryUiTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private FlowTokenRepository $flows;
    private UiNavigationFakeGateway $gateway;
    private PermissionRetryFakeGateway $telegram;
    private LockCoordinator $locks;
    private BotController $ui;
    private bool $safe = true;
    private array $original = ['_' => 'chatBannedRights', 'until_date' => 0, 'send_polls' => true];

    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T09:00:00+03:00'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->flows = new FlowTokenRepository($this->db, $this->clock);
        $this->settings->ensureGroup('-1001', 'Recovery <named> group');
        $this->gateway = new UiNavigationFakeGateway();
        $this->telegram = new PermissionRetryFakeGateway();
        $this->telegram->permissions['-1001'] = $this->original;
        $this->locks = new LockCoordinator($this->db, $this->clock, $this->telegram);
        self::assertSame('locked', $this->locks->reconcile('-1001', 'old-event', true)['status']);
        $this->db->pdo()->exec("UPDATE group_lock_state SET status='conflict',revision=revision+1 WHERE group_id='-1001'");
        $this->telegram->permissions['-1001'] = $this->original + ['send_photos' => true];
        $this->telegram->resetActivity();
        $recovery = new PermissionRecovery(
            $this->db,
            $this->clock,
            $this->telegram,
            $this->locks,
            fn(string $actor, string $group): bool => $this->gateway->isGroupAdmin($actor, $group),
            fn(string $group): bool => $this->safe
        );
        $http = new FullHebcalFixtureClient();
        $this->ui = new BotController(
            $this->settings,
            $this->flows,
            new CalendarService(new HebcalProvider($http), $this->clock),
            $this->gateway,
            new GeoNames($http, $this->clock, ''),
            $this->clock,
            permissionRecovery: $recovery
        );
    }

    public static function languageActions(): array
    {
        $cases = [];
        foreach (Lang::CODES as $language) {
            foreach (['adopt', 'restore'] as $action) {
                $cases[$language . '-' . $action] = [$language, $action];
            }
        }
        return $cases;
    }

    #[DataProvider('languageActions')]
    public function testReviewedActionsUseGeneratedButtonsAndEditTheSamePrivatePanel(string $language, string $action): void
    {
        $user = $this->settings->getUser('1');
        $this->settings->updateUser('1', $user['revision'], ['language' => $language]);
        $this->open('shabbat');
        self::assertStringContainsString('Recovery &lt;named&gt; group', $this->last()['text']);
        self::assertStringContainsString('-1001', $this->last()['text']);
        $lang = new Lang();
        self::assertStringContainsString($lang->text($language, 'permission_send_photos'), $this->last()['text']);
        self::assertStringContainsString($lang->text($language, 'recovery_blocked'), $this->last()['text']);
        $this->click($this->button('sb:pa:', ':' . $action));
        self::assertStringContainsString($lang->text($language, 'recovery_preview_' . $action), $this->last()['text']);
        self::assertSame(0, $this->telegram->writes);
        $confirm = $this->button('sb:pc:');
        $this->click($confirm);
        self::assertSame($action === 'restore' ? 1 : 0, $this->telegram->writes);
        self::assertSame('restored', $this->locks->state('-1001')['status']);
        self::assertStringContainsString($lang->text($language, 'recovery_result_' . ($action === 'adopt' ? 'adopted' : 'restored')), $this->last()['text']);
        self::assertTrue(Permissions::equal($action === 'restore' ? $this->original : $this->original + ['send_photos' => true], $this->telegram->permissions['-1001']));
        $this->click($this->button('sb:v:', ':shabbat'));
        self::assertStringContainsString($lang->text($language, 'settings_shabbat_timing'), json_encode($this->last()['markup'], JSON_UNESCAPED_UNICODE));
        $edits = count($this->gateway->edited);
        $this->click($confirm);
        self::assertCount($edits, $this->gateway->edited);
        self::assertSame($lang->text($language, 'denied'), $this->lastAnswer());
        self::assertSame([], $this->gateway->sent);
        foreach ($this->gateway->edited as $edit) {
            self::assertSame('1', $edit['userId']);
            self::assertSame(101, $edit['messageId']);
            self::assertLessThan(4096, mb_strlen(strip_tags($edit['text']), 'UTF-8'));
            foreach ($edit['markup']['rows'] as $row) {
                foreach ($row['buttons'] as $button) {
                    self::assertLessThanOrEqual(64, strlen($button['data']));
                }
            }
        }
    }

    public static function screens(): array
    {
        return array_map(static fn(string $screen): array => [$screen], ['group', 'shabbat', 'moderation', 'restrictions', 'botpermissions', 'status']);
    }

    #[DataProvider('screens')]
    public function testRecoveryIsDiscoverableAndReturnsToItsCategory(string $screen): void
    {
        $this->open($screen);
        $back = $this->button($screen === 'group' ? 'sb:group:' : 'sb:v:', $screen === 'group' ? null : ':' . $screen);
        $this->click($back);
        self::assertNotSame('', $this->button('sb:pr:'));
        self::assertSame(0, $this->telegram->writes);
        self::assertSame([], $this->gateway->sent);
    }

    public function testRevokedAuthorityAndForeignUserNeverExecuteConfirmation(): void
    {
        $this->open();
        $this->click($this->button('sb:pa:', ':restore'));
        $confirm = $this->button('sb:pc:');
        $this->click($confirm, ['user_id' => '2', 'chat_id' => '2']);
        self::assertSame(0, $this->telegram->writes);
        $this->gateway->admin = false;
        $this->click($confirm);
        self::assertSame(0, $this->telegram->writes);
        self::assertSame('conflict', $this->locks->state('-1001')['status']);
    }

    public function testRevocationDuringFreshReadDisclosesNoRecoveryData(): void
    {
        $this->telegram->onGet = function (): void {
            $this->gateway->admin = false;
        };
        $panel = $this->panelToken();
        $this->click('sb:pr:' . $panel);
        self::assertSame([], $this->gateway->edited);
        self::assertSame((new Lang())->text('he', 'denied'), $this->lastAnswer());
    }

    public static function invalidQueries(): array
    {
        return [
            'group' => [['private' => false]], 'foreign-chat' => [['chat_id' => '2']],
            'inline' => [['inline_selection' => true, 'private' => false]],
            'no-message' => [['message_id' => null]], 'bad-message' => [['message_id' => 0]],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function testForgedContextsNeverReadOrMutateRights(array $overrides): void
    {
        $this->click('sb:pr:' . $this->panelToken(), $overrides);
        self::assertSame(0, $this->telegram->reads);
        self::assertSame(0, $this->telegram->writes);
        self::assertSame([], $this->gateway->sent);
    }

    public static function staleCases(): array
    {
        return [['expiry'], ['settings'], ['remote'], ['protected']];
    }

    #[DataProvider('staleCases')]
    public function testChangesAfterReviewRequireNewConfirmation(string $case): void
    {
        $this->open();
        $this->click($this->button('sb:pa:', ':restore'));
        $confirm = $this->button('sb:pc:');
        if ($case === 'expiry') {
            $this->clock->set($this->clock->now()->modify('+301 seconds'));
        } elseif ($case === 'settings') {
            $group = $this->settings->getGroup('-1001');
            $this->settings->updateGroup('-1001', $group['revision'], ['candle_minutes' => 40], '1');
        } elseif ($case === 'remote') {
            $this->telegram->permissions['-1001']['send_videos'] = true;
        } else {
            $this->safe = false;
        }
        $this->click($confirm);
        self::assertSame(0, $this->telegram->writes);
        self::assertSame('conflict', $this->locks->state('-1001')['status']);
        if ($case === 'protected') {
            self::assertStringContainsString((new Lang())->text('he', 'recovery_block_active_lock'), $this->last()['text']);
            self::assertFalse($this->hasButton('sb:pa:'));
        }
    }

    public function testCancelAndNavigatingAwayInvalidateReviewedAction(): void
    {
        $this->open();
        $this->click($this->button('sb:pa:', ':restore'));
        $first = $this->button('sb:pc:');
        $this->click($this->button('sb:px:'));
        $this->click($first);
        self::assertSame(0, $this->telegram->writes);
        $this->click($this->button('sb:pa:', ':restore'));
        $second = $this->button('sb:pc:');
        $this->click('sb:start');
        $this->click($second);
        self::assertSame(0, $this->telegram->writes);
    }

    public function testLockedStateCannotBeAdoptedAndFailedEditsNeverSendReplacement(): void
    {
        $this->telegram->permissions['-1001'] = Permissions::locked($this->original);
        $this->open();
        self::assertStringContainsString((new Lang())->text('he', 'recovery_block_locked_current'), $this->last()['text']);
        self::assertFalse($this->hasButton('sb:pa:', ':adopt'));
        self::assertTrue($this->hasButton('sb:pa:', ':restore'));
        $refresh = $this->button('sb:pr:');
        $this->gateway->onEdit = static function (): void {
            throw new \RuntimeException('Message no longer exists.');
        };
        $this->click($refresh);
        self::assertSame((new Lang())->text('he', 'callback_panel_unavailable'), $this->lastAnswer());
        self::assertSame([], $this->gateway->sent);
        self::assertSame(0, $this->telegram->writes);
    }

    public function testComparisonIsBoundedAndUnknownPermissionNamesAreNeverDisclosed(): void
    {
        $this->telegram->permissions['-1001'] = array_replace($this->original, array_fill_keys([
            'send_photos', 'send_videos', 'send_roundvideos', 'send_audios', 'send_voices', 'send_docs',
            'send_stickers', 'send_gifs', 'send_games', 'send_inline', 'embed_links', 'send_reactions',
            'unsafe<custom>permission',
        ], true));
        $this->open();
        $text = $this->last()['text'];
        self::assertSame(8, substr_count($text, "\n• "));
        self::assertStringContainsString((new Lang())->text('he', 'recovery_more', ['count' => 5]), $text);
        self::assertStringNotContainsString('unsafe', $text);
        self::assertStringNotContainsString('send_photos', $text);
        self::assertLessThan(2500, mb_strlen($text, 'UTF-8'));
    }

    public function testMissingOriginalHasClearWarningAndOffersNoRestore(): void
    {
        $this->db->pdo()->exec("UPDATE group_lock_state SET snapshot_sha256='invalid' WHERE group_id='-1001'");
        $this->open();
        self::assertStringContainsString((new Lang())->text('he', 'recovery_block_missing_snapshot'), $this->last()['text']);
        self::assertFalse($this->hasButton('sb:pa:', ':restore'));
        self::assertTrue($this->hasButton('sb:pa:', ':adopt'));
        self::assertStringNotContainsString((new Lang())->text('he', 'recovery_compare'), $this->last()['text']);
    }

    public function testActivePeriodOffersOnlyReadOnlyRefreshAndBack(): void
    {
        $this->safe = false;
        $this->open('moderation');
        self::assertFalse($this->hasButton('sb:pa:'));
        self::assertStringContainsString((new Lang())->text('he', 'recovery_block_active_lock'), $this->last()['text']);
        $this->click($this->button('sb:pr:'));
        self::assertSame(0, $this->telegram->writes);
        self::assertSame([], $this->gateway->sent);
    }

    public static function languages(): array
    {
        return array_map(static fn(string $language): array => [$language], Lang::CODES);
    }

    #[DataProvider('languages')]
    public function testLegacySnapshotRequiresFreshExplicitAdoptionInEveryLanguage(string $language): void
    {
        $user = $this->settings->getUser('1');
        $this->settings->updateUser('1', $user['revision'], ['language' => $language]);
        $this->db->pdo()->exec("UPDATE group_lock_state SET snapshot_provenance='legacy_unverified',snapshot_evidence_json=NULL,revision=revision+1 WHERE group_id='-1001'");
        $before = $this->locks->state('-1001')['snapshot_json'];
        $this->open('shabbat');
        $lang = new Lang();
        self::assertStringContainsString($lang->text($language, 'recovery_block_unverified_snapshot'), $this->last()['text']);
        self::assertFalse($this->hasButton('sb:pa:', ':restore'));
        self::assertTrue($this->hasButton('sb:pa:', ':adopt'));
        $this->click($this->button('sb:pa:', ':adopt'));
        self::assertStringContainsString($lang->text($language, 'recovery_preview_adopt'), $this->last()['text']);
        self::assertStringContainsString($lang->text($language, 'recovery_block_unverified_snapshot'), $this->last()['text']);
        $this->click($this->button('sb:pc:'));
        self::assertSame(0, $this->telegram->writes);
        self::assertSame('restored', $this->locks->state('-1001')['status']);
        self::assertSame($before, $this->locks->state('-1001')['snapshot_json']);
        self::assertStringContainsString($lang->text($language, 'recovery_result_adopted'), $this->last()['text']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testLegacyWarningRemainsVisibleWhileRecoveryIsBlocked(): void
    {
        $this->db->pdo()->exec("UPDATE group_lock_state SET snapshot_provenance='legacy_unverified',snapshot_evidence_json=NULL WHERE group_id='-1001'");
        $this->safe = false;
        $this->open();
        self::assertStringContainsString((new Lang())->text('he', 'recovery_block_active_lock'), $this->last()['text']);
        self::assertStringContainsString((new Lang())->text('he', 'recovery_block_unverified_snapshot'), $this->last()['text']);
        self::assertFalse($this->hasButton('sb:pa:'));
        self::assertSame(0, $this->telegram->writes);
    }
    private function panelToken(): string
    {
        return $this->flows->create('1', 'group', '-1001', $this->settings->getGroup('-1001')['revision'], 'panel');
    }

    private function open(string $screen = 'group'): void
    {
        $token = $this->panelToken();
        $this->click($screen === 'group' ? 'sb:group:' . $token : 'sb:v:' . $token . ':' . $screen);
        $this->click($this->button('sb:pr:'));
    }

    private function click(string $data, array $overrides = []): void
    {
        self::assertTrue($this->ui->handleCallback(array_replace(['user_id' => '1', 'chat_id' => '1', 'private' => true, 'message_id' => 101, 'query_id' => 'query', 'data' => $data], $overrides)));
    }

    private function last(): array
    {
        return $this->gateway->edited[array_key_last($this->gateway->edited)];
    }

    private function lastAnswer(): string
    {
        return $this->gateway->answers[array_key_last($this->gateway->answers)]['text'];
    }

    private function hasButton(string $prefix, ?string $suffix = null): bool
    {
        foreach ($this->last()['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                if (str_starts_with($button['data'] ?? '', $prefix) && ($suffix === null || str_ends_with($button['data'], $suffix))) {
                    return true;
                }
            }
        }
        return false;
    }

    private function button(string $prefix, ?string $suffix = null): string
    {
        foreach ($this->last()['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                if (str_starts_with($button['data'] ?? '', $prefix) && ($suffix === null || str_ends_with($button['data'], $suffix))) {
                    return $button['data'];
                }
            }
        }
        self::fail('Missing generated recovery button ' . $prefix . ($suffix ?? ''));
    }
}
