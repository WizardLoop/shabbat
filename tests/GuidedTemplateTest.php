<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService, HebcalProvider};
use Shabbat\Content\TemplateEditor;
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\Application;
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\Lang;

final class GuidedTemplateTest extends TestCase
{
    private Application $app;
    private TemplateEditor $editor;
    private GuidedUiGateway $gateway;
    private FrozenClock $clock;
    private JsonClient $http;

    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z'));
        $this->gateway = new GuidedUiGateway();
        $this->http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('No calendar HTTP in guided template tests.');
            }
        };
        $this->app = $this->application($db);
        $this->app->settings->ensureGroup('-1001', 'Fixture group');
        $this->editor = new TemplateEditor($this->app);
    }

    private function application(Database $db): Application
    {
        return new Application($this->gateway, $this->clock, $db, new CalendarService(new HebcalProvider($this->http), $this->clock), new GeoNames($this->http, $this->clock, ''));
    }

    private function click(string $data, string $user = '1', int $panel = 200, bool $private = true): bool
    {
        return $this->editor->callback(['user_id' => $user,'private' => $private,'chat_id' => $user,'message_id' => $panel,'data' => $data,'query_id' => 'fixture-template']);
    }

    private function begin(string $kind = 'close', string $mode = 'edit', int $panel = 200): void
    {
        $this->click('v3:templates:-1001:' . $kind, panel:$panel);
        $menu = end($this->gateway->edited);
        foreach ($menu['markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                if (str_starts_with($button['data'] ?? '', 'tpl:' . $mode . ':')) {
                    $this->click($button['data'], panel: $panel);
                    return;
                }
            }
        }
        self::fail('Missing template action ' . $mode);
    }

    private function message(string $text, array $entities = [], array $extra = [], bool $confirm = true): bool
    {
        $handled = $this->editor->message(array_replace(['user_id' => '1','private' => true,'text' => $text,'entities' => $entities], $extra));
        if ($confirm && $handled && $this->app->flows->latest('1', 'template_review') !== null) {
            $review = end($this->gateway->payloads);
            if ($review !== false) {
                foreach ($review['payload']['reply_markup']['rows'] as $row) {
                    foreach ($row['buttons'] as $button) {
                        if (str_starts_with($button['data'] ?? '', 'tpl:save:')) {
                            $this->click($button['data'], panel:$review['messageId']);
                            break 2;
                        }
                    }
                }
            }
        }
        return $handled;
    }

    private function reviewAction(string $action): string
    {
        $review = end($this->gateway->payloads);
        foreach ($review['payload']['reply_markup']['rows'] as $row) {
            foreach ($row['buttons'] as $button) {
                if (str_starts_with($button['data'] ?? '', 'tpl:' . $action . ':')) {
                    return $button['data'];
                }
            }
        }
        self::fail('No pending review action ' . $action);
    }

    public function testNaturalTemplatePromptIsSimpleLocalizedAndEditsTheSamePanelInSixLanguages(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            $user = $this->app->settings->getUser('1');
            $this->app->settings->updateUser('1', $user['revision'], ['language' => $language]);
            $this->begin();
            $last = end($this->gateway->edited);
            self::assertSame($lang->text($language, 'template_edit_guided_prompt'), $last['text']);
            self::assertStringNotContainsString('/template', $last['text']);
            self::assertStringNotContainsString('TOKEN', $last['text']);
            self::assertSame(200, $last['id']);
            self::assertNotNull($this->app->flows->latest('1', 'template_input'));
            self::assertTrue($this->message('Simple message ' . $language));
            self::assertSame('Simple message ' . $language, $this->app->templates->get('-1001', 'close')['text']);
        }
        self::assertSame([], $this->gateway->sent);
    }

    public function testRichUtf16EntitiesAndMediaSurviveSaveRestartAndActualPayload(): void
    {
        $this->begin();
        $text = '🚀 bold SECRET';
        $entities = [
            ['_' => 'messageEntityCustomEmoji','offset' => 0,'length' => 2,'document_id' => 5944760643524500583],
            ['_' => 'messageEntityBold','offset' => 3,'length' => 4],
            ['_' => 'messageEntitySpoiler','offset' => 8,'length' => 6],
        ];
        $media = ['type' => 'photo','bot_api_file_id' => 'fixture-reusable-file'];
        self::assertTrue($this->message($text, $entities, ['media' => $media]));
        $saved = $this->app->templates->get('-1001', 'close');
        self::assertSame($text, $saved['text']);
        self::assertSame($entities, $saved['entities']);
        self::assertSame($media, $saved['media']);
        $this->app = $this->application($this->app->db);
        $this->editor = new TemplateEditor($this->app);
        $payload = $this->app->payload($this->app->settings->getGroup('-1001'), 'close', $this->clock->now());
        self::assertSame($text, $payload['text']);
        self::assertSame($entities, $payload['entities']);
        self::assertSame($media, $payload['media']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testPromptSurvivesRestartAndButtonsKeepNativeColorsIconsAndActions(): void
    {
        $this->begin('close', 'buttons');
        $this->app = $this->application($this->app->db);
        $this->editor = new TemplateEditor($this->app);
        $buttons = [[
            ['text' => 'Settings','url' => 'https://example.org','style' => ['_' => 'keyboardButtonStyle','bg_primary' => true,'icon' => 5944760643524500583]],
            ['text' => 'Copy','copy_text' => 'Sample','style' => ['_' => 'keyboardButtonStyle','bg_success' => true]],
        ]];
        self::assertTrue($this->message(json_encode($buttons, JSON_THROW_ON_ERROR)));
        $saved = $this->app->templates->get('-1001', 'close');
        self::assertSame($buttons, $saved['buttons']);
        self::assertFalse($this->message('Unprompted message'));
        self::assertSame(200, end($this->gateway->edited)['id']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testPairedMessageActionsAndSaveReturnToTheSameEditorWithFreshButtons(): void
    {
        $this->click('v3:templates:-1001:close');
        $menu = end($this->gateway->edited);
        self::assertCount(2, $menu['markup']['rows'][0]['buttons']);
        self::assertStringContainsString('tpl:edit:', $menu['markup']['rows'][0]['buttons'][0]['data']);
        self::assertStringContainsString('tpl:buttons:', $menu['markup']['rows'][0]['buttons'][1]['data']);
        $this->begin();
        self::assertTrue($this->message('Custom editor content'));
        $saved = end($this->gateway->edited);
        self::assertStringContainsString((new Lang())->text('he', 'template_text_custom'), $saved['text']);
        $actions = array_merge(...array_column($saved['markup']['rows'], 'buttons'));
        self::assertCount(1, array_filter($actions, static fn(array $button): bool => str_starts_with($button['data'] ?? '', 'tpl:edit:')));
        self::assertCount(1, array_filter($actions, static fn(array $button): bool => str_ends_with($button['data'] ?? '', ':shabbat_messages')));
        $edit = array_values(array_filter($actions, static fn(array $button): bool => str_starts_with($button['data'] ?? '', 'tpl:edit:')))[0];
        $this->click($edit['data']);
        self::assertNotNull($this->app->flows->latest('1', 'template_input'));
        self::assertSame(200, end($this->gateway->edited)['id']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testAdvancedButtonFormatsAreAnExplicitOptionalStep(): void
    {
        $this->begin('close', 'buttons');
        $prompt = end($this->gateway->edited);
        self::assertSame((new Lang())->text('he', 'template_buttons_simple_prompt'), $prompt['text']);
        self::assertStringNotContainsString('JSON', $prompt['text']);
        $this->click($prompt['markup']['rows'][1]['buttons'][0]['data']);
        self::assertSame((new Lang())->text('he', 'template_buttons_guided_prompt'), end($this->gateway->edited)['text']);
        self::assertSame('buttons', $this->app->flows->latest('1', 'template_input')['payload']['mode']);
    }

    public function testPendingReviewRequiresExplicitSaveAndRejectsReplacedOrCancelledCandidates(): void
    {
        $this->begin();
        self::assertTrue($this->message('First candidate', confirm:false));
        self::assertNull($this->app->templates->get('-1001', 'close'));
        self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
        $oldSave = $this->reviewAction('save');
        self::assertTrue($this->message('Second candidate', confirm:false));
        $newSave = $this->reviewAction('save');
        self::assertNotSame($oldSave, $newSave);
        $this->click($oldSave);
        self::assertNull($this->app->templates->get('-1001', 'close'));
        $this->click($newSave);
        self::assertSame('Second candidate', $this->app->templates->get('-1001', 'close')['text']);
        $revision = $this->app->templates->get('-1001', 'close')['revision'];
        $this->click($newSave);
        self::assertSame($revision, $this->app->templates->get('-1001', 'close')['revision']);
        $this->begin();
        $this->message('Discard me', confirm:false);
        $this->click($this->reviewAction('discard'));
        self::assertSame('Second candidate', $this->app->templates->get('-1001', 'close')['text']);
        self::assertNull($this->app->flows->latest('1', 'template_input'));
    }

    public function testNativeEmptyEntitiesRemainLiteralThroughPreviewConfirmRestartAndExpansion(): void
    {
        $this->http = new FullHebcalFixtureClient();
        $this->app = $this->application($this->app->db);
        $this->editor = new TemplateEditor($this->app);
        $this->begin();
        $text = '<literal> & {{location}}';
        $this->message($text, [], confirm:false);
        self::assertNull($this->app->templates->get('-1001', 'close'));
        $group = $this->app->settings->getGroup('-1001');
        $expected = '<literal> & ' . $group['location_name'];
        $preview = end($this->gateway->payloads)['payload'];
        self::assertSame($expected, $preview['text']);
        self::assertSame([], $preview['entities']);
        $this->click($this->reviewAction('save'));
        self::assertSame($text, $this->app->templates->get('-1001', 'close')['text']);
        self::assertSame([], $this->app->templates->get('-1001', 'close')['entities']);
        $this->app = $this->application($this->app->db);
        $payload = $this->app->payload($this->app->settings->getGroup('-1001'), 'close', $this->clock->now());
        self::assertSame($expected, $payload['text']);
        self::assertSame([], $payload['entities']);
    }

    public function testSimpleLinkWizardAsksLabelThenUrlAndReviewsBeforeSaving(): void
    {
        $this->begin('close', 'buttons');
        $prompt = end($this->gateway->edited);
        self::assertStringContainsString('https://', $prompt['text']);
        $this->click($prompt['markup']['rows'][0]['buttons'][0]['data']);
        self::assertTrue($this->message('More information', confirm:false));
        self::assertStringContainsString('https://', end($this->gateway->edited)['text']);
        self::assertNull($this->app->templates->get('-1001', 'close'));
        self::assertTrue($this->message('https://example.org/help', confirm:false));
        self::assertNull($this->app->templates->get('-1001', 'close'));
        $this->click($this->reviewAction('save'));
        $saved = $this->app->templates->get('-1001', 'close');
        self::assertSame('custom', $saved['buttons_mode']);
        self::assertSame('More information', $saved['buttons'][0][0]['text']);
        self::assertSame('https://example.org/help', $saved['buttons'][0][0]['url']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testPendingSaveRechecksParentAfterAsyncAuthorization(): void
    {
        $this->begin();
        $this->message('Pending content', confirm:false);
        $save = $this->reviewAction('save');
        $entered = new \Amp\DeferredFuture();
        $release = new \Amp\DeferredFuture();
        $this->gateway->onAdmin = static function () use ($entered, $release): void {
            $entered->complete();
            $release->getFuture()->await();
        };
        $pending = \Amp\async(fn(): bool=>$this->click($save));
        $entered->getFuture()->await();
        $this->app->flows->revokeAction('1', 'template_input');
        $release->complete();
        self::assertTrue($pending->await());
        self::assertNull($this->app->templates->get('-1001', 'close'));
        self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
    }
    public function testWrongUserLostAdminAndForeignCallbackNeverPersistTemplates(): void
    {
        $this->begin();
        self::assertFalse($this->message('Wrong user', extra:['user_id' => '2']));
        self::assertFalse($this->message('In group', extra:['private' => false]));
        $this->gateway->admin = false;
        self::assertTrue($this->message('Lost administrator permission'));
        self::assertNull($this->app->templates->get('-1001', 'close'));
        $this->click('v3:templates:-1001:close', private:false);
        self::assertNull($this->app->templates->get('-1001', 'close'));
        self::assertSame([], $this->gateway->sent);
    }

    public function testCommandsAndBackNavigationCancelTheHiddenInput(): void
    {
        $this->begin();
        self::assertFalse($this->message('/start'));
        self::assertNull($this->app->flows->latest('1', 'template_input'));
        self::assertFalse($this->message('Old prompt reply'));
        $this->begin();
        $last = end($this->gateway->edited);
        $this->click($last['markup']['rows'][0]['buttons'][0]['data']);
        self::assertNull($this->app->flows->latest('1', 'template_input'));
        self::assertFalse($this->message('Cancelled input'));
        self::assertNull($this->app->templates->get('-1001', 'close'));
    }

    public function testCancelledParentDuringAdministratorLookupCannotApplyChild(): void
    {
        $this->begin();
        $flow = $this->app->flows->latest('1', 'template_input');
        $child = $flow['payload']['template_token'];
        $this->gateway->onAdmin = function (): void {
            $this->app->flows->revokeAction('1', 'template_input');
        };
        self::assertTrue($this->message('Stale after authorization wait'));
        self::assertNull($this->app->templates->get('-1001', 'close'));
        self::assertNotNull($this->app->flows->peek($child, '1', 'template'));
        self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
    }

    public function testReplacedPromptDuringAdministratorLookupStaysActiveAndCannotBeOverwritten(): void
    {
        $this->begin();
        $newText = null;
        $this->gateway->onAdmin = function () use (&$newText): void {
            $this->begin('close', 'buttons', 201);
            $newText = end($this->gateway->edited)['text'];
        };
        self::assertTrue($this->message('Old content'));
        self::assertNull($this->app->templates->get('-1001', 'close'));
        $latest = $this->app->flows->latest('1', 'template_input');
        self::assertSame('buttons', $latest['payload']['mode']);
        self::assertSame(201, $latest['payload']['panel_id']);
        // A stale answer must not repaint a new prompt or send another message.
        self::assertSame($newText, end($this->gateway->edited)['text']);
        self::assertSame(201, end($this->gateway->edited)['id']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testMalformedEntityAndUnknownPlaceholderCannotPoisonFutureDelivery(): void
    {
        foreach (
            [
            ['text' => '🚀','entities' => [['_' => 'messageEntityBold','offset' => 1,'length' => 1]]],
            ['text' => 'Invalid {{unrecognized}}','entities' => []],
            ] as $input
        ) {
            $this->begin();
            self::assertTrue($this->message($input['text'], $input['entities']));
            self::assertNull($this->app->templates->get('-1001', 'close'));
            self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
        }
    }

    public function testFailureRollsBackChildClaimAndRetryKeepsNaturalInput(): void
    {
        $this->begin();
        $child = $this->app->flows->latest('1', 'template_input')['payload']['template_token'];
        $this->app->db->pdo()->exec("CREATE TRIGGER reject_guided_template BEFORE INSERT ON message_templates BEGIN SELECT RAISE(ABORT,'fixture write failure'); END");
        self::assertTrue($this->message('Retry me'));
        self::assertNull($this->app->templates->get('-1001', 'close'));
        self::assertNotNull($this->app->flows->peek($child, '1', 'template'));
        self::assertNotNull($this->app->flows->latest('1', 'template_input'));
        $this->app->db->pdo()->exec('DROP TRIGGER reject_guided_template');
        self::assertTrue($this->message('Retry me'));
        self::assertSame('Retry me', $this->app->templates->get('-1001', 'close')['text']);
        self::assertNull($this->app->flows->peek($child, '1', 'template'));
    }

    public function testActualAsyncAdministratorLookupCannotApplyAfterNavigationCancellation(): void
    {
        $this->begin();
        $entered = new \Amp\DeferredFuture();
        $release = new \Amp\DeferredFuture();
        $this->gateway->onAdmin = static function () use ($entered, $release): void {
            $entered->complete();
            $release->getFuture()->await();
        };
        $pending = \Amp\async(fn(): bool => $this->message('Content from the old screen'));
        $entered->getFuture()->await();
        $this->app->flows->revokeAction('1', 'template_input');
        $release->complete();
        self::assertTrue($pending->await());
        self::assertNull($this->app->templates->get('-1001', 'close'));
        self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testExpiredInputAndInvalidStyledButtonsDoNotPersist(): void
    {
        $this->begin('close', 'buttons');
        self::assertTrue($this->message(json_encode([[['text' => 'Bad','url' => 'https://example.org','style' => ['_' => 'keyboardButtonStyle','bg_primary' => true,'bg_danger' => true]]]], JSON_THROW_ON_ERROR)));
        self::assertNull($this->app->templates->get('-1001', 'close'));
        $this->clock->set($this->clock->now()->modify('+11 minutes'));
        self::assertFalse($this->message('Expired reply'));
        self::assertNull($this->app->templates->get('-1001', 'close'));
    }
}
