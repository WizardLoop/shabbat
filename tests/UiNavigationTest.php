<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Shabbat\UI\CallbackUiGateway;
use Shabbat\UI\Lang;

final class UiNavigationTest extends TestCase
{
    private BotController $ui;
    private SettingsRepository $settings;
    private FlowTokenRepository $flows;
    private UiNavigationFakeGateway $gateway;
    private FrozenClock $clock;
    private bool $operator = false;

    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-13T12:00:00Z'));
        $this->settings = new SettingsRepository($db, $this->clock);
        $this->flows = new FlowTokenRepository($db, $this->clock);
        $this->settings->ensureGroup('-1001', 'First group');
        $this->settings->ensureGroup('-1002', 'Second group');
        $this->gateway = new UiNavigationFakeGateway();
        $http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('No remote requests in navigation fixtures.');
            }
        };
        $this->ui = new BotController(
            $this->settings,
            $this->flows,
            new CalendarService(new HebcalProvider($http), $this->clock),
            $this->gateway,
            new GeoNames($http, $this->clock, ''),
            $this->clock,
            authorizeOperator: fn(string $user): bool => $this->operator && $user === '1'
        );
    }

    private function click(string $data, int $messageId = 101, string $user = '1', array $extra = []): bool
    {
        return $this->ui->handleCallback(array_replace([
            'user_id' => $user,
            'chat_id' => $user,
            'message_id' => $messageId,
            'private' => true,
            'data' => $data,
            'query_id' => 'query-' . $messageId,
        ], $extra));
    }

    private function last(): array
    {
        return $this->gateway->edited[array_key_last($this->gateway->edited)];
    }

    private function buttons(array $panel): array
    {
        return array_merge(...array_map(static fn(array $row): array => $row['buttons'], $panel['markup']['rows']));
    }

    public function testInitialCommandsSendOnePanelEachAndButtonsEditIt(): void
    {
        foreach (['/start', '/preferences', '/help'] as $command) {
            self::assertTrue($this->ui->handleMessage(['user_id' => '1', 'private' => true, 'text' => $command]));
        }
        self::assertCount(3, $this->gateway->sent);
        self::assertSame([], $this->gateway->edited);
        foreach (['sb:start', 'sb:personal', 'sb:help'] as $callback) {
            self::assertTrue($this->click($callback));
            self::assertSame('1', $this->last()['userId']);
            self::assertSame(101, $this->last()['messageId']);
        }
        self::assertCount(3, $this->gateway->sent);
        self::assertCount(3, $this->gateway->edited);
    }

    public function testInformationCategoriesAndBackHomeEditOnePanelInEveryLanguage(): void
    {
        $lang = new Lang();
        $categories = ['personal', 'calendar', 'groups', 'permissions', 'notifications', 'messages', 'privacy'];
        foreach (Lang::CODES as $code) {
            $user = $this->settings->getUser('1');
            $this->settings->updateUser('1', $user['revision'], ['language' => $code]);
            $this->click('sb:help', 300);
            $index = $this->last();
            self::assertSame($lang->text($code, 'info_intro'), $index['text']);
            $callbacks = array_column($this->buttons($index), 'data');
            foreach ($categories as $category) {
                self::assertContains('sb:help:' . $category, $callbacks);
                $this->click('sb:help:' . $category, 300);
                $panel = $this->last();
                self::assertSame('<b>' . $lang->text($code, 'info_' . $category . '_title') . '</b>' . "\n\n" . $lang->text($code, 'info_' . $category . '_text'), $panel['text']);
                self::assertSame(300, $panel['messageId']);
                $navigation = array_column($this->buttons($panel), 'data');
                self::assertContains('sb:help', $navigation);
                self::assertContains('sb:start', $navigation);
            }
            self::assertNotContains('sb:help:operators', $callbacks);
            $this->click('sb:start', 300);
            self::assertSame($lang->text($code, 'welcome'), $this->last()['text']);
        }
        self::assertSame([], $this->gateway->sent);
    }

    public function testOperatorHelpIsHiddenAndRoleIsRecheckedOnEachClick(): void
    {
        $this->click('sb:help');
        self::assertNotContains('sb:help:operators', array_column($this->buttons($this->last()), 'data'));
        $edits = count($this->gateway->edited);
        $this->click('sb:help:operators');
        self::assertCount($edits, $this->gateway->edited);
        $this->operator = true;
        $this->click('sb:help');
        self::assertContains('sb:help:operators', array_column($this->buttons($this->last()), 'data'));
        $this->click('sb:help:operators');
        self::assertStringContainsString('/setcommands', $this->last()['text']);
        $this->operator = false;
        $edits = count($this->gateway->edited);
        $this->click('sb:help:operators');
        self::assertCount($edits, $this->gateway->edited);
        self::assertSame([], $this->gateway->sent);
    }

    public static function invalidPanels(): array
    {
        return [
            'missing message' => [['message_id' => null]],
            'string message' => [['message_id' => '101']],
            'zero message' => [['message_id' => 0]],
            'negative message' => [['message_id' => -1]],
            'oversized message' => [['message_id' => 2147483648]],
            'other private chat' => [['chat_id' => '2']],
            'group' => [['private' => false, 'chat_id' => '-1001']],
            'forum topic' => [['private' => false, 'chat_id' => '-1001', 'topic_id' => 42]],
        ];
    }

    #[DataProvider('invalidPanels')]
    public function testInvalidPanelTargetsNeverSendOrEdit(array $extra): void
    {
        self::assertTrue($this->click('sb:personal', extra: $extra));
        self::assertSame([], $this->gateway->sent);
        self::assertSame([], $this->gateway->edited);
        self::assertCount(1, $this->gateway->answers);
        self::assertNotSame('', $this->gateway->answers[0]['text']);
    }

    public function testSimultaneousCallbacksRetainTheirOwnMessageTargets(): void
    {
        $this->gateway->onEdit = static function (string $user, int $messageId): void {
            if ($messageId === 101) {
                \Fiber::suspend();
            }
        };
        $first = new \Fiber(fn(): bool => $this->click('sb:help:calendar', 101));
        $first->start();
        self::assertTrue($first->isSuspended());
        $this->click('sb:help:privacy', 202);
        $first->resume();
        self::assertTrue($first->isTerminated());
        self::assertSame([101, 202], array_column($this->gateway->edited, 'messageId'));
        self::assertStringContainsString('/shabbat', $this->gateway->edited[0]['text']);
        self::assertStringContainsString('/source', $this->gateway->edited[1]['text']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testFailedEditAnswersCallbackWithoutSendingReplacement(): void
    {
        $this->gateway->onEdit = static function (): void {
            throw new \RuntimeException('MESSAGE_ID_INVALID');
        };
        $this->click('sb:start');
        self::assertCount(1, $this->gateway->edited);
        self::assertSame([], $this->gateway->sent);
        self::assertCount(1, $this->gateway->answers);
        self::assertSame((new Lang())->text('he', 'callback_panel_unavailable'), $this->gateway->answers[0]['text']);
    }

    public function testSettingsPreviewConfirmationEditsPanelAndKeepsGroupBinding(): void
    {
        $group = $this->settings->getGroup('-1001');
        $token = $this->flows->create('1', 'group', '-1001', $group['revision'], 'panel', 600);
        $this->click('sb:s:' . $token . ':c:40', 404);
        $preview = $this->last();
        self::assertSame(404, $preview['messageId']);
        self::assertStringContainsString('First group', $preview['text']);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        $confirm = $preview['markup']['rows'][0]['buttons'][0]['data'];
        $this->click($confirm, 404, '2');
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
        $this->click($confirm, 404);
        self::assertSame(40, $this->settings->getGroup('-1001')['candle_minutes']);
        self::assertSame(404, $this->last()['messageId']);
        self::assertSame(1, $this->settings->getGroup('-1002')['revision']);
        self::assertNull($this->settings->getUser('1')['candle_minutes']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testGroupSettingsCommandStartsPrivatePanelWithoutEditingGroupMessage(): void
    {
        self::assertTrue($this->ui->handleMessage([
            'user_id' => '1', 'private' => false, 'group_id' => '-1001',
            'message_id' => 77, 'topic_id' => 42, 'text' => '/settings',
        ]));
        self::assertCount(1, $this->gateway->sent);
        self::assertSame('1', $this->gateway->sent[0]['userId']);
        self::assertStringContainsString('First group', $this->gateway->sent[0]['text']);
        self::assertSame([], $this->gateway->edited);
    }

    public function testScopedGatewayRejectsCrossUserOrCrossMessageEdits(): void
    {
        $scope = new CallbackUiGateway($this->gateway, '1', 101);
        foreach ([['2', 101], ['1', 202]] as [$user, $message]) {
            try {
                $scope->editPrivate($user, $message, 'Forged');
                self::fail('Cross-panel edit accepted.');
            } catch (\LogicException) {
                self::assertSame([], $this->gateway->edited);
            }
        }
        self::assertSame([], $this->gateway->sent);
    }

    private function nativeRpcError(string $rpc): \danog\MadelineProto\RPCErrorException
    {
        // Do not invoke the SDK error reporter, which can contact a remote service.
        $error = (new \ReflectionClass(\danog\MadelineProto\RPCErrorException::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\danog\MadelineProto\RPCErrorException::class, 'rpc'))->setValue($error, $rpc);
        return $error;
    }

    public function testNativeInlineOptionsAreHonoredWithoutOverridingAuthenticatedQueryOrResults(): void
    {
        $transport = new GatewayFixtureTransport();
        $gateway = new \Shabbat\Runtime\MadelineGateway($transport);
        $results = [[
            '_' => 'inputBotInlineResult', 'id' => 'fixture-result', 'type' => 'article',
            'title' => 'Fixture', 'send_message' => ['_' => 'inputBotInlineMessageText', 'message' => 'Fixture'],
        ]];
        $gateway->answerInline('123', $results, [
            'cache_time' => 45, 'private' => false, 'next_offset' => 'page-2',
            'query_id' => '999', 'results' => [],
        ]);
        [$method, $arguments] = $transport->calls[0];
        self::assertSame('messages.setInlineBotResults', $method);
        self::assertSame(123, $arguments['query_id']);
        self::assertSame($results, $arguments['results']);
        self::assertSame(45, $arguments['cache_time']);
        self::assertFalse($arguments['private']);
        self::assertSame('page-2', $arguments['next_offset']);
        $gateway->answerInline('124', []);
        self::assertSame(0, $transport->calls[1][1]['cache_time']);
        self::assertTrue($transport->calls[1][1]['private']);
    }
    public function testNativeUnchangedMessageIsSuccessfulWithoutReplacementSend(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->error = $this->nativeRpcError('MESSAGE_NOT_MODIFIED');
        $scope = new CallbackUiGateway(new \Shabbat\Runtime\MadelineGateway($transport), '1', 909);
        $scope->sendPrivate('1', 'Unchanged menu');
        self::assertCount(1, $transport->calls);
        self::assertSame('messages.editMessage', $transport->calls[0][0]);
        self::assertSame('1', $transport->calls[0][1]['peer']);
        self::assertSame(909, $transport->calls[0][1]['id']);
    }

    public function testNativeCancellationStopsPanelEditWithoutReplacementSend(): void
    {
        $transport = new GatewayFixtureTransport();
        $cancelled = new \Amp\CancelledException();
        $transport->error = $cancelled;
        $scope = new CallbackUiGateway(new \Shabbat\Runtime\MadelineGateway($transport), '1', 909);
        try {
            $scope->sendPrivate('1', 'Cancelled menu');
            self::fail('Intentional cancellation was swallowed.');
        } catch (\Amp\CancelledException $error) {
            self::assertSame($cancelled, $error);
            self::assertCount(1, $transport->calls);
            self::assertSame('messages.editMessage', $transport->calls[0][0]);
        }
    }
    public function testNativeDeletedPanelFailureDoesNotSendAnotherMessage(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->error = $this->nativeRpcError('MESSAGE_ID_INVALID');
        $scope = new CallbackUiGateway(new \Shabbat\Runtime\MadelineGateway($transport), '1', 909);
        try {
            $scope->sendPrivate('1', 'Deleted menu');
            self::fail('Deleted panel edit was reported as successful.');
        } catch (\Shabbat\UI\PanelEditException $error) {
            self::assertInstanceOf(\danog\MadelineProto\RPCErrorException::class, $error->getPrevious());
            self::assertCount(1, $transport->calls);
            self::assertSame('messages.editMessage', $transport->calls[0][0]);
        }
    }
    public function testAnnouncementPreviewAndDatePromptRetainBoundBackNavigation(): void
    {
        $group = $this->settings->getGroup('-1001');
        $token = $this->flows->create('1', 'group', '-1001', $group['revision'], 'panel', 600);
        $this->click('sb:ap:' . $token, 606);
        self::assertSame(606, $this->last()['messageId']);
        self::assertContains('sb:v:' . $token . ':announcement', array_column($this->buttons($this->last()), 'data'));
        $this->click('sb:date:' . $token . ':zmanim', 606);
        self::assertSame(606, $this->last()['messageId']);
        self::assertContains('sb:p:' . $token . ':zmanim', array_column($this->buttons($this->last()), 'data'));
        self::assertSame([], $this->gateway->sent);
        self::assertSame(1, $this->settings->getGroup('-1001')['revision']);
    }
    public function testRichPreviewEditsOriginalPanelWithoutPostingMediaAgain(): void
    {
        $scope = new CallbackUiGateway($this->gateway, '1', 505);
        $payload = [
            'text' => 'Preview',
            'entities' => [['_' => 'messageEntityBold', 'offset' => 0, 'length' => 7]],
            'media' => ['bot_api_file_id' => 'fixture-media'],
            'buttons' => [[['text' => 'Link', 'url' => 'https://example.org']]],
        ];
        $scope->preview($payload);
        self::assertSame([['userId' => '1', 'messageId' => 505, 'payload' => $payload]], $this->gateway->payloads);
        self::assertSame([], $this->gateway->sent);
    }
}
