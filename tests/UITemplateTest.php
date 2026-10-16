<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\CalendarService;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Content\TemplateEditor;
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\Application;
use Shabbat\Storage\Database;
use Shabbat\Telegram\TelegramGateway;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\UiGateway;

final class UITemplateTest extends TestCase
{
    private Application $app;
    private TemplateEditor $editor;
    private UiGateway&TelegramGateway $gateway;
    private FrozenClock $clock;
    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-13T12:00:00Z'));
        $this->gateway = new class implements UiGateway, TelegramGateway, \Shabbat\UI\PanelPayloadGateway
        {
            public array $messages = [];
            public array $edited = [];
            public array $payloads = [];
            public array $sent = [];
            public bool $admin = true;
            public function isGroupAdmin(string $user, string $group): bool
            {
                return $this->admin && $user === '1' && $group === '-1001';
            }
            public function sendPrivate(string $user, string $text, array $markup = []): void
            {
                $this->messages[] = compact('user', 'text', 'markup');
            }
            public function editPrivate(string $user, int $id, string $text, array $markup = []): void
            {
                $this->messages[] = compact('user', 'text', 'markup');
                $this->edited[] = compact('user', 'id', 'text', 'markup');
            }
            public function editPrivatePayload(string $userId, int $messageId, array $payload): void
            {
                $this->payloads[] = compact('userId', 'messageId', 'payload');
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
                return ['_' => 'chatBannedRights', 'until_date' => 0, 'send_messages' => false];
            }
            public function setPermissions(string $group, array $permissions): void
            {
                throw new \RuntimeException('permission calls forbidden in editor tests');
            }
            public function send(string $peer, array $payload, string $randomId): array
            {
                $this->sent[] = compact('peer', 'payload', 'randomId');
                return ['id' => count($this->sent)];
            }
        };
        $http = new class implements JsonClient
        {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('HTTP forbidden in close-template fixture');
            }
        };
        $this->app = new Application($this->gateway, $this->clock, $db, new CalendarService(new HebcalProvider($http), $this->clock), new GeoNames($http, $this->clock, ''));
        $this->app->settings->ensureGroup('-1001', 'Fixture Group');
        $this->editor = new TemplateEditor($this->app);
    }
    private function click(string $data, string $user = '1', bool $private = true): void
    {
        $this->editor->callback(['user_id' => $user, 'data' => $data, 'private' => $private, 'message_id' => 101, 'query_id' => 'fixture']);
    }
    private function token(): string
    {
        $this->click('v3:templates:-1001');
        $last = $this->gateway->messages[array_key_last($this->gateway->messages)];
        $data = $last['markup']['rows'][0]['buttons'][0]['data'];
        return substr($data, 9);
    }
    private function text(string $token, string $body, string $user = '1', array $extra = []): void
    {
        $this->editor->message(['user_id' => $user, 'private' => true, 'text' => '/template ' . $token . "\n" . $body] + $extra);
    }
    private function last(): array
    {
        return $this->gateway->messages[array_key_last($this->gateway->messages)];
    }
    public function testPrivatePreviewSendsOnlyToPrivatePeer(): void
    {
        $token = $this->token();
        $this->click('tpl:preview:' . $token);
        self::assertCount(1, $this->gateway->payloads);
        self::assertSame('1', $this->gateway->payloads[0]['userId']);
        self::assertSame(101, $this->gateway->payloads[0]['messageId']);
        self::assertSame([], $this->gateway->sent);
        self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
    }
    public function testTestSendRequiresNamedGroupConfirmationAndIsRateLimited(): void
    {
        $token = $this->token();
        $this->click('tpl:test:' . $token);
        self::assertSame([], $this->gateway->sent);
        self::assertStringContainsString('Fixture Group', $this->last()['text']);
        $confirm = $this->last()['markup']['rows'][0]['buttons'][0]['data'];
        self::assertSame(['_' => 'keyboardButtonStyle', 'bg_primary' => true], $this->last()['markup']['rows'][0]['buttons'][0]['style']);
        $this->click($confirm);
        self::assertCount(1, $this->gateway->sent);
        self::assertSame('-1001', $this->gateway->sent[0]['peer']);
        self::assertStringStartsWith('[TEST / בדיקה]', $this->gateway->sent[0]['payload']['text']);
        $token = $this->token();
        $this->click('tpl:test:' . $token);
        $this->click($this->last()['markup']['rows'][0]['buttons'][0]['data']);
        self::assertCount(1, $this->gateway->sent);
    }
    public function testPlainNativePlaceholderExpansionEnforcesUtf16Limit(): void
    {
        $source = ['text' => str_repeat('a', 3900) . '{{location}}', 'entities' => []];
        $location = str_repeat('🚀', 100);
        self::assertSame(4000, mb_strlen(str_repeat('a', 3900) . $location));
        self::assertSame(4100, intdiv(strlen(mb_convert_encoding(str_repeat('a', 3900) . $location, 'UTF-16LE', 'UTF-8')), 2));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rendered template exceeds Telegram limits.');
        (new \Shabbat\Content\TemplateRenderer())->renderPayload($source, ['location' => $location]);
    }
    public function testExplicitTestRetainsPlainLiteralTextWithEmptyNativeEntities(): void
    {
        $this->app->templates->save('-1001', 'close', ['text' => '<literal> & text', 'entities' => []], '1', 0);
        $token = $this->token();
        $this->click('tpl:test:' . $token);
        $this->click($this->last()['markup']['rows'][0]['buttons'][0]['data']);
        $payload = $this->gateway->sent[0]['payload'];
        self::assertSame('[TEST / בדיקה] <literal> & text', $payload['text']);
        self::assertArrayHasKey('entities', $payload);
        self::assertSame([], $payload['entities']);
        self::assertSame('<literal> & text', $this->app->templates->get('-1001', 'close')['text']);
    }

    public function testRemoveTextExplainsDefaultRestorationOrCaptionRemoval(): void
    {
        $lang = new \Shabbat\UI\Lang();
        foreach (\Shabbat\UI\Lang::CODES as $language) {
            $user = $this->app->settings->getUser('1');
            $this->app->settings->updateUser('1', $user['revision'], ['language' => $language]);
            foreach ([null, ['fixture' => true]] as $media) {
                $previous = $this->app->templates->get('-1001', 'close');
                $this->app->templates->save('-1001', 'close', ['text' => 'custom', 'media' => $media], '1', $previous['revision'] ?? 0);
                $token = $this->token();
                $this->click('tpl:menu:' . $token);
                $buttons = array_merge(...array_column($this->last()['markup']['rows'], 'buttons'));
                $remove = array_values(array_filter($buttons, static fn(array $b): bool => $b['data'] === 'tpl:remove_text:' . $token))[0];
                self::assertSame($lang->text($language, $media === null ? 'template_restore_text' : 'template_remove_caption'), $remove['text']);
                $this->click($remove['data']);
                self::assertStringStartsWith($lang->text($language, $media === null ? 'template_text_restored' : 'template_caption_removed'), $this->last()['text']);
                self::assertSame($media, $this->app->templates->get('-1001', 'close')['media']);
                self::assertSame('', $this->app->templates->get('-1001', 'close')['text']);
            }
        }
    }
    public function testExplicitTestPrefixPreservesNativeEntitiesAndLiteralPlainText(): void
    {
        $native = ['text' => '🚀 <literal> & text','entities' => [
            ['_' => 'messageEntityCustomEmoji','offset' => 0,'length' => 2,'document_id' => 5944760643524500583],
            ['_' => 'messageEntityBold','offset' => 3,'length' => 9]]];
        $this->app->templates->save('-1001', 'close', $native, '1', 0);
        $token = $this->token();
        $this->click('tpl:test:' . $token);
        $this->click($this->last()['markup']['rows'][0]['buttons'][0]['data']);
        $sent = $this->gateway->sent[0]['payload'];
        $prefix = '[TEST / בדיקה] ';
        $offset = intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2);
        self::assertSame($prefix . $native['text'], $sent['text']);
        foreach ($native['entities'] as $index => $entity) {
            $entity['offset'] += $offset;
            self::assertSame($entity, $sent['entities'][$index]);
        }
        self::assertSame($native['text'], $this->app->templates->get('-1001', 'close')['text']);
        self::assertSame($native['entities'], $this->app->templates->get('-1001', 'close')['entities']);
    }

    public function testDefaultTefillinPreviewAndTestShowAnInertCounterWithoutVotes(): void
    {
        $http = new FullHebcalFixtureClient();
        $this->app = new Application($this->gateway, $this->clock, $this->app->db, new CalendarService(new HebcalProvider($http), $this->clock), new GeoNames($http, $this->clock, ''));
        $this->editor = new TemplateEditor($this->app);
        $this->click('v3:templates:-1001:tefillin');
        $token = substr($this->last()['markup']['rows'][0]['buttons'][0]['data'], 9);
        $this->click('tpl:preview:' . $token);
        $payload = end($this->gateway->payloads)['payload'];
        $buttons = array_merge(...array_column($payload['reply_markup']['rows'], 'buttons'));
        $counter = array_values(array_filter($buttons, static fn(array $b): bool=>str_starts_with($b['data'] ?? '', 'tpl:ack_preview:')));
        self::assertCount(1, $counter);
        self::assertSame('הנחתי (0)', $counter[0]['text']);
        self::assertTrue($counter[0]['style']['bg_primary']);
        $this->click($counter[0]['data'], '2', false);
        self::assertSame(0, (int)$this->app->db->pdo()->query('SELECT COUNT(*) FROM tefillin_ack_votes')->fetchColumn());
        $this->click('tpl:test:' . $token);
        $this->click($this->last()['markup']['rows'][0]['buttons'][0]['data']);
        $sent = $this->gateway->sent[0]['payload'];
        self::assertStringStartsWith('tpl:ack_preview:', $sent['buttons'][0][0]['callback_data']);
        self::assertSame('הנחתי (0)', $sent['buttons'][0][0]['text']);
        self::assertSame(0, (int)$this->app->db->pdo()->query('SELECT COUNT(*) FROM tefillin_ack_rounds')->fetchColumn());
        self::assertSame(0, (int)$this->app->db->pdo()->query('SELECT COUNT(*) FROM tefillin_ack_votes')->fetchColumn());
    }
    public function testRichTextEntitiesMediaAndButtonsArePreserved(): void
    {
        $token = $this->token();
        $prefix = '/template ' . $token . "\n";
        $offset = intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2);
        $this->text($token, 'שלום 🚀', '1', ['entities' => [['_' => 'messageEntityBold', 'offset' => $offset, 'length' => 7], ['_' => 'messageEntityItalic', 'offset' => $offset - 2, 'length' => 5]], 'media' => ['_' => 'inputMediaPhoto', 'id' => ['_' => 'inputPhoto', 'id' => 123, 'access_hash' => 456, 'file_reference' => 'fixture']]]);
        $template = $this->app->templates->get('-1001', 'close');
        self::assertSame('שלום 🚀', $template['text']);
        self::assertSame(0, $template['entities'][0]['offset']);
        self::assertSame(7, $template['entities'][0]['length']);
        self::assertSame(0, $template['entities'][1]['offset']);
        self::assertSame(3, $template['entities'][1]['length']);
        self::assertSame('inputMediaPhoto', $template['media']['_']);
        $token = $this->token();
        $rows = [[['text' => 'Visit', 'url' => 'https://example.org', 'style' => 'success']]];
        $this->editor->message(['user_id' => '1', 'private' => true, 'text' => '/buttons ' . $token . "\n" . json_encode($rows, JSON_THROW_ON_ERROR)]);
        $template = $this->app->templates->get('-1001', 'close');
        self::assertSame($rows, $template['buttons']);
        self::assertNotNull($template['media']);
        self::assertSame('שלום 🚀', $template['text']);
        $token = $this->token();
        $this->text($token, 'new text');
        $template = $this->app->templates->get('-1001', 'close');
        self::assertSame('new text', $template['text']);
        self::assertNotNull($template['media']);
        self::assertSame($rows, $template['buttons']);
    }
    public function testPermissionRecheckWrongUserReplayAndForwardedCallback(): void
    {
        $token = $this->token();
        $this->text($token, 'wrong user', '2');
        self::assertNull($this->app->templates->get('-1001', 'close'));
        $this->gateway->admin = false;
        $this->text($token, 'lost admin');
        self::assertNull($this->app->templates->get('-1001', 'close'));
        $this->gateway->admin = true;
        $this->click('tpl:reset:' . $token, '1', false);
        self::assertNull($this->app->templates->get('-1001', 'close'));
        $this->text($token, 'saved');
        $this->text($token, 'replayed');
        self::assertSame('saved', $this->app->templates->get('-1001', 'close')['text']);
    }
    public function testTemplateCasFailureRollsBackTokenAndSettings(): void
    {
        $token = $this->token();
        $this->app->templates->save('-1001', 'close', ['text' => 'newer'], '1', 0);
        $this->text($token, 'stale');
        self::assertSame('newer', $this->app->templates->get('-1001', 'close')['text']);
        self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
        self::assertNotNull($this->app->flows->peek($token, '1', 'template'));
    }
    public function testDatabaseFailureCannotProduceSavedSuccessOrConsumeFlow(): void
    {
        $token = $this->token();
        $this->app->db->pdo()->exec("CREATE TRIGGER reject_template BEFORE INSERT ON message_templates BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
        $this->text($token, 'blocked');
        self::assertNull($this->app->templates->get('-1001', 'close'));
        self::assertSame(1, $this->app->settings->getGroup('-1001')['revision']);
        self::assertNotNull($this->app->flows->peek($token, '1', 'template'));
        self::assertStringNotContainsString('נשמרה', $this->last()['text']);
    }
    public function testRemovingOnePartKeepsOtherParts(): void
    {
        $this->app->templates->save('-1001', 'close', ['text' => 'keep', 'media' => ['fixture' => true], 'buttons' => [[['text' => 'Link', 'url' => 'https://example.org']]]], '1', 0);
        $token = $this->token();
        $this->click('tpl:remove_media:' . $token);
        $template = $this->app->templates->get('-1001', 'close');
        self::assertNull($template['media']);
        self::assertSame('keep', $template['text']);
        self::assertCount(1, $template['buttons']);
        $token = $this->token();
        $this->click('tpl:remove_text:' . $token);
        $template = $this->app->templates->get('-1001', 'close');
        self::assertSame('', $template['text']);
        self::assertCount(1, $template['buttons']);
    }
    public function testPlaintextButtonFallbackUsesOriginalParserAndPreservesNativeStyle(): void
    {
        $native = ['_' => 'replyInlineMarkup', 'rows' => [['_' => 'keyboardButtonRow', 'buttons' => [['_' => 'keyboardButtonUrl', 'text' => 'Site', 'url' => 'https://example.org', 'style' => ['_' => 'keyboardButtonStyle', 'bg_success' => true, 'icon' => 123]], ['_' => 'keyboardButtonCopy', 'text' => 'Copy', 'copy_text' => 'Sample']]]]];
        $called = null;
        $parser = static function (string $input) use ($native, &$called): array {
            $called = $input;
            return $native;
        };
        $this->app = new Application($this->gateway, $this->clock, $this->app->db, $this->app->calendar, null, $parser);
        $this->editor = new TemplateEditor($this->app);
        $token = $this->token();
        $body = 'Site - https://example.org - style:bg_success - icon:123 && Copy - copy:Sample';
        $this->editor->message(['user_id' => '1', 'private' => true, 'text' => '/buttons ' . $token . "\n" . $body]);
        self::assertSame($body, $called);
        $buttons = $this->app->templates->get('-1001', 'close')['buttons'];
        self::assertSame(['_' => 'keyboardButtonStyle', 'bg_success' => true, 'icon' => 123], $buttons[0][0]['style']);
        self::assertSame('Sample', $buttons[0][1]['copy_text']);
        self::assertSame('https://example.org', $buttons[0][0]['url']);
    }
    public function testEnglishTemplateMenusAndCancelAreImplemented(): void
    {
        $this->app->settings->updateUser('1', 1, ['language' => 'en']);
        $token = $this->token();
        self::assertStringContainsString('Messages and design', $this->last()['text']);
        $this->click('tpl:test:' . $token);
        $cancel = $this->last()['markup']['rows'][1]['buttons'][0]['data'];
        $this->click($cancel);
        self::assertStringContainsString('cancelled', $this->last()['text']);
        self::assertSame([], $this->gateway->sent);
    }
    public function testAllSixTemplateScreensAreLocalizedAndPrivatePreviewDropsGroupTopic(): void
    {
        $lang = new \Shabbat\UI\Lang();
        $group = $this->app->settings->getGroup('-1001');
        $this->app->settings->updateGroup('-1001', $group['revision'], ['target_topic_id' => 123], '1');
        foreach (\Shabbat\UI\Lang::CODES as $code) {
            $user = $this->app->settings->getUser('1');
            $this->app->settings->updateUser('1', $user['revision'], ['language' => $code]);
            $token = $this->token();
            self::assertStringStartsWith($lang->text($code, 'template_panel', ['title' => 'Fixture Group']), $this->last()['text']);
            $this->click('tpl:menu:' . $token);
            $actions = array_merge(...array_column($this->last()['markup']['rows'], 'buttons'));
            self::assertContains('tpl:preview:' . $token, array_column($actions, 'data'));
            $this->click('tpl:preview:' . $token);
            $sent = $this->gateway->payloads[array_key_last($this->gateway->payloads)];
            self::assertSame('1', $sent['userId']);
            self::assertSame(101, $sent['messageId']);
            self::assertArrayNotHasKey('topic_id', $sent['payload']);
        }
    }

    public function testDirectTefillinAndAnnouncementDesignsRemainIndependentAndSecured(): void
    {
        foreach (['tefillin','times_alert'] as $kind) {
            $this->click('v3:templates:-1001:' . $kind);
            self::assertStringContainsString('tpl:edit:', json_encode($this->last(), JSON_THROW_ON_ERROR));
            $token = substr($this->last()['markup']['rows'][0]['buttons'][0]['data'], 9);
            $this->text($token, $kind . ' custom 😀', '1', ['media' => ['type' => 'photo','bot_api_file_id' => 'fixture-file-id']]);
            $saved = $this->app->templates->get('-1001', $kind);
            self::assertSame($kind . ' custom 😀', $saved['text']);
            self::assertSame('fixture-file-id', $saved['media']['bot_api_file_id']);
            $this->click('v3:templates:-1001:' . $kind);
            $token = substr($this->last()['markup']['rows'][0]['buttons'][0]['data'], 9);
            $this->editor->message(['user_id' => '1','private' => true,'text' => '/buttons ' . $token . "\n" . json_encode([[['text' => 'Design','url' => 'https://example.org','style' => ['_' => 'keyboardButtonStyle','bg_success' => true]]]], JSON_THROW_ON_ERROR)]);
            self::assertSame(['_' => 'keyboardButtonStyle','bg_success' => true], $this->app->templates->get('-1001', $kind)['buttons'][0][0]['style']);
        }
        self::assertSame('tefillin custom 😀', $this->app->templates->get('-1001', 'tefillin')['text']);
        self::assertSame('times_alert custom 😀', $this->app->templates->get('-1001', 'times_alert')['text']);
        self::assertNull($this->app->templates->get('-1001', 'zmanim'));
        self::assertSame([], $this->gateway->sent);
        $revision = $this->app->templates->get('-1001', 'tefillin')['revision'];
        $this->click('v3:templates:-1001:tefillin', '2');
        $this->click('v3:templates:-1001:tefillin', '1', false);
        $this->gateway->admin = false;
        $this->click('v3:templates:-1001:tefillin');
        self::assertSame($revision, $this->app->templates->get('-1001', 'tefillin')['revision']);
    }
}
