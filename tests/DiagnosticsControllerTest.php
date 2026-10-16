<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\{Cancellation, CancelledException};
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\EventHandler\Query\ChatButtonQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService, HebcalProvider};
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\{Application, DiagnosticReport};
use Shabbat\Storage\{Database, DiagnosticsRepository};
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{DiagnosticsController, Lang};

final class DiagnosticsControllerTest extends TestCase
{
    private Application $app;
    private FrozenClock $clock;
    private DiagnosticsController $ui;
    private DiagnosticsRepository $repo;
    private array $sent = [];
    private array $edits = [];
    private array $answers = [];
    private array $documents = [];
    private int $queryId = 100;
    private int $captures = 0;
    private int $permissionReads = 0;
    private bool $owner = true;
    private ?\Throwable $editError = null;
    private ?\Throwable $permissionError = null;
    private ?\Throwable $documentError = null;

    protected function setUp(): void
    {
        $gateway = $this->createMock(DiagnosticsGatewayFixture::class);
        $gateway->method('botUsername')->willReturn('FixtureBot');
        $gateway->method('isGroupAdmin')->willReturn(true);
        $gateway->expects(self::never())->method('setPermissions');
        $gateway->expects(self::never())->method('send');
        $gateway->method('getPermissions')->willReturnCallback(function (): array {
            $this->permissionReads++;
            if ($this->permissionError !== null) {
                throw $this->permissionError;
            }
            return ['_' => 'chatBannedRights', 'until_date' => 0, 'send_media' => true];
        });
        $gateway->method('sendPrivate')->willReturnCallback(function (string $user, string $text): void {
            $this->sent[] = compact('user', 'text');
        });
        $gateway->method('sendDiagnosticPanel')->willReturnCallback(function (string $user, string $text, array $markup): int {
            $this->sent[] = compact('user', 'text', 'markup');
            return 800;
        });
        $gateway->method('editPrivate')->willReturnCallback(function (string $user, int $id, string $text, array $markup): void {
            if ($this->editError !== null) {
                throw $this->editError;
            }
            $this->edits[] = compact('user', 'id', 'text', 'markup');
        });
        $gateway->method('sendDiagnosticDocument')->willReturnCallback(function (string $user, string $json, string $caption, string $randomId): void {
            $this->documents[] = compact('user', 'json', 'caption', 'randomId');
            if ($this->documentError !== null) {
                throw $this->documentError;
            }
        });
        $gateway->method('answerCallback')->willReturnCallback(function (string $query, string $text): void {
            $this->answers[] = compact('query', 'text');
        });
        $http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('private-secret-exception-message');
            }
        };
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z'));
        $db = Database::open(':memory:');
        $db->migrate();
        $this->app = new Application($gateway, $this->clock, $db, new CalendarService(new HebcalProvider($http), $this->clock), new GeoNames($http, $this->clock, ''), configuredOwners: fn(): array => $this->owner ? [2] : []);
        $this->app->settings->getUser('2');
        $this->app->settings->ensureGroup('-1001', 'Example group');
        $this->repo = new DiagnosticsRepository($db, $this->clock);
        $this->ui = new DiagnosticsController($this->app, function (string $user, ?string $group): array {
            $this->captures++;
            $records = [];
            for ($n = 0; $n < 30; $n++) {
                $records[] = ['category' => 'calendar', 'label' => 'period', 'value' => 'period-' . $n, 'status' => $n === 10 ? 'warning' : 'ok', 'data' => []];
            }
            $records[] = ['category' => 'groups', 'label' => 'group', 'value' => 'Example -1001', 'status' => 'ok', 'data' => ['group_id' => '-1001']];
            return ['schema' => 'shabbat-diagnostics-1', 'read_only' => true, 'created_at' => $this->clock->now()->getTimestamp(), 'context' => $group ?? 'personal', 'timezone' => 'Asia/Jerusalem', 'records' => $records];
        });
    }

    public static function languages(): array
    {
        return array_map(static fn(string $language): array => [$language], Lang::CODES);
    }

    #[DataProvider('languages')]
    public function testNavigationSearchFiltersAndJumpEditOnlyOnePanel(string $language): void
    {
        $settings = $this->app->settings->getUser('2');
        $this->app->settings->updateUser('2', $settings['revision'], ['language' => $language]);
        $this->ui->open('2');
        self::assertCount(1, $this->sent);
        self::assertLessThan(2000, mb_strlen($this->sent[0]['text']));
        $this->action('next');
        self::assertStringContainsString('period-4', $this->last()['text']);
        $this->action('page');
        self::assertTrue($this->ui->input(['user_id' => '2', 'private' => true, 'text' => '4']));
        self::assertStringContainsString('period-12', $this->last()['text']);
        $this->action('page');
        $this->ui->input(['user_id' => '2', 'private' => true, 'text' => '999']);
        self::assertStringContainsString((new Lang())->text($language, 'diagnostics_invalid_page'), $this->last()['text']);
        $this->action('list');
        $this->action('search');
        $this->ui->input(['user_id' => '2', 'private' => true, 'text' => 'period-10']);
        self::assertStringContainsString('period-10', $this->last()['text']);
        self::assertStringNotContainsString('period-11', $this->last()['text']);
        $this->action('clear');
        $this->action('filters');
        $this->action('filter:problems');
        self::assertStringContainsString('period-10', $this->last()['text']);
        self::assertCount(1, $this->sent);
        self::assertSame(1, $this->captures);
        self::assertSame(0, $this->permissionReads);
        foreach ($this->edits as $edit) {
            self::assertSame(800, $edit['id']);
            self::assertLessThan(3000, mb_strlen($edit['text']));
            foreach ($edit['markup']['rows'] as $row) {
                foreach ($row['buttons'] as $button) {
                    self::assertLessThanOrEqual(64, strlen($button['data']));
                }
            }
        }
    }

    public function testExportIsExplicitOneFileAndDebouncedAcrossRefresh(): void
    {
        $this->ui->open('2');
        self::assertSame([], $this->documents);
        $this->action('export');
        $this->action('export');
        self::assertCount(1, $this->documents);
        self::assertSame('shabbat-diagnostics-1', json_decode($this->documents[0]['json'], true)['schema']);
        $this->advance(31);
        $this->action('refresh');
        $this->action('export');
        self::assertCount(1, $this->documents);
        self::assertCount(1, $this->sent);
        self::assertSame(2, $this->captures);
        $this->advance(30);
        $this->action('export');
        self::assertCount(2, $this->documents);
        self::assertNotSame($this->documents[0]['randomId'], $this->documents[1]['randomId']);
    }

    public function testUncertainExportIsNotRepeatedByDuplicateClick(): void
    {
        $this->ui->open('2');
        $this->documentError = new \RuntimeException('uncertain transport');
        $this->action('export');
        $this->action('export');
        self::assertCount(1, $this->documents);
        self::assertCount(1, $this->sent);
    }

    public function testForgeryStaleTokenWrongPanelAndRevokedRoleCannotReadOrExport(): void
    {
        $this->ui->open('2');
        $event = $this->event('export');
        foreach (
            [
            ['private' => false, 'chat_id' => '-1001'],
            ['inline_selection' => true],
            ['message_id' => 801],
            ['user_id' => '3', 'chat_id' => '3'],
            ['data' => 'dz:' . str_repeat('a', 24) . ':export'],
            ] as $change
        ) {
            self::assertTrue($this->ui->callback(array_replace($event, $change)));
        }
        self::assertSame([], $this->documents);
        self::assertSame([], $this->edits);
        $this->action('page');
        $edits = count($this->edits);
        $this->owner = false;
        $this->ui->input(['user_id' => '2', 'private' => true, 'text' => '2']);
        $this->action('export');
        self::assertCount($edits, $this->edits);
        self::assertSame([], $this->documents);
        $this->owner = true;
        $this->advance(901);
        $this->ui->callback($event);
        self::assertSame([], $this->documents);
    }

    public function testCancelledAndFailedEditNeverSendReplacementMessages(): void
    {
        $this->ui->open('2');
        $this->editError = new \RuntimeException('message missing');
        $this->action('next');
        self::assertCount(1, $this->sent);
        $this->editError = new CancelledException();
        $this->expectException(CancelledException::class);
        $this->action('next');
    }

    public function testCurrentRightsOnlyReadForSelectedGroupAndErrorsRemainPerSection(): void
    {
        $report = new DiagnosticReport($this->app);
        $personal = $report->capture('2', null);
        self::assertSame(0, $this->permissionReads);
        self::assertStringNotContainsString('private-secret', json_encode($personal));
        $this->permissionError = new \RuntimeException('secret-token-must-not-leak');
        $group = $report->capture('2', '-1001');
        self::assertSame(1, $this->permissionReads);
        self::assertStringNotContainsString('secret-token', json_encode($group));
        $errors = array_values(array_filter($group['records'], static fn(array $r): bool => $r['status'] === 'error'));
        self::assertSame(['provider_error', 'current'], array_column($errors, 'label'));
        self::assertTrue($group['read_only']);
    }

    public function testActualNativeCallbackAndPrivateInputBridgeReachPanel(): void
    {
        $this->ui->open('2');
        require_once dirname(__DIR__) . '/bot.php';
        $bridge = new CallbackRoutingFixture($this->app);
        $event = $this->event('next');
        $reflection = new \ReflectionClass(ChatButtonQuery::class);
        $query = $reflection->newInstanceWithoutConstructor();
        foreach (['data' => $event['data'], 'userId' => 2, 'chatId' => 2, 'messageId' => 800, 'queryId' => 99, 'chatInstance' => 1] as $field => $value) {
            $reflection->getProperty($field)->setValue($query, $value);
        }
        $bridge->upgradedCallbacks($query);
        self::assertStringContainsString('period-4', $this->last()['text']);
        $this->action('search');
        $reflection = new \ReflectionClass(PrivateMessage::class);
        $message = $reflection->newInstanceWithoutConstructor();
        foreach (['senderId' => 2, 'chatId' => 2, 'id' => 901, 'message' => 'period-10'] as $field => $value) {
            $reflection->getProperty($field)->setValue($message, $value);
        }
        $method = new \ReflectionMethod($bridge, 'v4HandleMessage');
        self::assertTrue($method->invoke($bridge, $message, ['user_id' => '2', 'private' => true, 'text' => 'period-10']));
        self::assertStringContainsString('period-10', $this->last()['text']);
        self::assertCount(1, $this->sent);
    }

    public function testDuplicateExportQueryNeverReplaysAfterCooldownOrRefresh(): void
    {
        $this->ui->open('2');
        $event = $this->event('export');
        $this->ui->callback($event);
        $this->advance(61);
        $this->ui->callback($event);
        self::assertCount(1, $this->documents);
        $this->action('export');
        self::assertCount(2, $this->documents);
    }

    public function testExpiredClaimCannotOverwriteReleaseOrExportForNewOwner(): void
    {
        $this->ui->open('2');
        $row = $this->repo->get('2');
        $old = $this->repo->claim('2', $row['token']);
        $this->advance(121);
        $new = $this->repo->claim('2', $row['token']);
        self::assertIsString($old);
        self::assertIsString($new);
        self::assertNotSame($old, $new);
        $this->repo->release('2', $row['token'], $old);
        self::assertSame($new, $this->repo->get('2')['busy_owner']);
        foreach (
            [
            fn() => $this->repo->update('2', $row['token'], ['page' => 9], $old),
            fn() => $this->repo->saveReport('2', $row['token'], [], $old),
            fn() => $this->repo->claimExport('2', $row['token'], $old, '22'),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('An expired owner must not mutate diagnostic state.');
            } catch (\RuntimeException) {
                self::assertSame($new, $this->repo->get('2')['busy_owner']);
            }
        }
        $this->repo->update('2', $row['token'], ['page' => 2], $new);
        self::assertSame(2, $this->repo->get('2')['page']);
        $this->repo->release('2', $row['token'], $new);
    }

    public function testGroupSelectionWorksWithoutWaitingForRefreshCooldownAndRotatesToken(): void
    {
        $this->ui->open('2');
        $old = $this->event('next');
        $this->advance(2);
        $this->action('group:30');
        self::assertSame('-1001', $this->repo->get('2')['group_id']);
        self::assertCount(1, $this->sent);
        self::assertSame(2, $this->captures);
        $edits = count($this->edits);
        $this->ui->callback($old);
        self::assertCount($edits, $this->edits);
        $this->action('refresh');
        self::assertSame(2, $this->captures);
    }

    public function testPermissionSnapshotsIncludeVerifiedBaselineIntermediateAndJournal(): void
    {
        $original = Database::encode(['_' => 'chatBannedRights', 'until_date' => 0]);
        $applied = Database::encode(['_' => 'chatBannedRights', 'until_date' => 0, 'send_plain' => true]);
        $baseline = Database::encode(['_' => 'chatBannedRights', 'until_date' => 0, 'send_media' => true]);
        $this->app->db->pdo()->prepare("INSERT INTO group_lock_state(group_id,event_key,status,snapshot_json,snapshot_sha256,applied_json,previous_applied_json,recovery_baseline_json,recovery_baseline_sha256,prepared_at) VALUES('-1001','test','conflict',?,?,?,?,?,?,1)")
            ->execute([$original, hash('sha256', $original), $applied, $applied, $baseline, hash('sha256', $baseline)]);
        $journal = new \Shabbat\Scheduling\PermissionRecoveryJournal($this->app->db, $this->clock);
        $id = $journal->begin('-1001', 'lock', json_decode($original, true), json_decode($applied, true), ['snapshot_json' => $original, 'snapshot_sha256' => hash('sha256', $original)]);
        $journal->update($id, 'verified', 'conflict', json_decode($baseline, true));
        $report = (new DiagnosticReport($this->app))->capture('2', '-1001');
        $rows = array_column($report['records'], null, 'label');
        self::assertArrayHasKey('previous_rights', $rows);
        self::assertArrayHasKey('baseline', $rows);
        self::assertSame([], $rows['compare_baseline']['data']['different_fields']);
        self::assertSame('verified', $rows['operation']['data']['stage']);
        self::assertSame('conflict', $rows['operation']['data']['outcome']);
        self::assertTrue($rows['operation']['data']['observed']['send_media']);
        $this->app->db->pdo()->exec("UPDATE group_lock_state SET snapshot_sha256='corrupted'");
        $report = (new DiagnosticReport($this->app))->capture('2', '-1001');
        $rows = array_column($report['records'], null, 'label');
        self::assertSame('error', $rows['original']['status']);
        self::assertSame(['integrity' => 'checksum_mismatch'], $rows['original']['data']);
        self::assertArrayNotHasKey('compare_original', $rows);
    }
    public function testNativePrivateCommandCannotExecuteFromGroupAndRepeatDoesNotFlood(): void
    {
        require_once dirname(__DIR__) . '/bot.php';
        $method = new \ReflectionMethod(\Shabbat::class, 'testzmanim');
        self::assertCount(1, $method->getAttributes(\danog\MadelineProto\EventHandler\Filter\FilterCommandCaseInsensitive::class));
        $filter = \danog\MadelineProto\EventHandler\Filter\Filter::fromReflectionType($method->getParameters()[0]->getType());
        $bridge = new CallbackRoutingFixture($this->app);
        $handler = new \ReflectionMethod($bridge, 'v3TestZmanim');
        foreach ([\danog\MadelineProto\EventHandler\Message\GroupMessage::class, PrivateMessage::class] as $className) {
            $reflection = new \ReflectionClass($className);
            $message = $reflection->newInstanceWithoutConstructor();
            foreach (['senderId' => 2, 'chatId' => $className === PrivateMessage::class ? 2 : -1001, 'id' => 910, 'message' => '/testzmanim', 'out' => false, 'editDate' => null] as $field => $value) {
                $reflection->getProperty($field)->setValue($message, $value);
            }
            self::assertSame($className === PrivateMessage::class, $filter->apply($message));
            $handler->invoke($bridge, $message);
            if ($className !== PrivateMessage::class) {
                self::assertSame([], $this->sent);
            }
        }
        self::assertCount(1, $this->sent);
        $handler->invoke($bridge, $message);
        self::assertCount(1, $this->sent);
        self::assertStringNotContainsString('private-secret', $this->sent[0]['text']);
        self::assertLessThan(3000, mb_strlen($this->sent[0]['text']));
    }

    public function testNativeCallbackForgeriesCannotExportAndMissingPanelDoesNotSend(): void
    {
        $this->ui->open('2');
        $bridge = new CallbackRoutingFixture($this->app);
        foreach (
            [
            [ChatButtonQuery::class, 2, -1001, 800],
            [ChatButtonQuery::class, 3, 3, 800],
            [ChatButtonQuery::class, 2, 2, 801],
            [\danog\MadelineProto\EventHandler\Query\InlineButtonQuery::class, 2, null, null],
            ] as [$className, $actor, $chat, $panel]
        ) {
            $reflection = new \ReflectionClass($className);
            $query = $reflection->newInstanceWithoutConstructor();
            $fields = ['data' => $this->event('export')['data'], 'userId' => $actor, 'queryId' => 99, 'chatInstance' => 1];
            if ($chat !== null) {
                $fields += ['chatId' => $chat, 'messageId' => $panel];
            }
            foreach ($fields as $field => $value) {
                $reflection->getProperty($field)->setValue($query, $value);
            }
            $bridge->upgradedCallbacks($query);
        }
        self::assertSame([], $this->documents);
        self::assertSame([], $this->edits);
        self::assertCount(1, $this->sent);
        $reflection = new \ReflectionClass(ChatButtonQuery::class);
        $query = $reflection->newInstanceWithoutConstructor();
        foreach (['data' => $this->event('next')['data'], 'userId' => 2, 'chatId' => 2, 'messageId' => 800, 'queryId' => 100, 'chatInstance' => 1] as $field => $value) {
            $reflection->getProperty($field)->setValue($query, $value);
        }
        $this->editError = new \RuntimeException('MESSAGE_ID_INVALID');
        $bridge->upgradedCallbacks($query);
        self::assertCount(1, $this->sent);
        self::assertSame([], $this->edits);
        self::assertSame((new Lang())->text('he', 'diagnostics_unavailable'), $this->answers[array_key_last($this->answers)]['text']);
    }
    public function testLongSearchRetainsPromptAndPageJumpShowsRange(): void
    {
        $this->ui->open('2');
        $this->action('page');
        self::assertStringContainsString('8', $this->last()['text']);
        $this->action('search');
        $this->ui->input(['user_id' => '2', 'private' => true, 'text' => str_repeat('x', 81)]);
        self::assertSame('search', $this->repo->get('2')['prompt']);
        self::assertStringContainsString((new Lang())->text('he', 'diagnostics_invalid_search'), $this->last()['text']);
        $this->ui->input(['user_id' => '2', 'private' => true, 'text' => 'period-2']);
        self::assertNull($this->repo->get('2')['prompt']);
        self::assertCount(1, $this->sent);
    }
    public function testSnapshotVerificationRequiresOriginalBytesAndEvidenceNotOnlyLabel(): void
    {
        $raw = '{"_":"chatBannedRights","until_date":0,"invite_users":true}';
        $now = $this->clock->now()->getTimestamp();
        $evidence = \Shabbat\Scheduling\PermissionRecoveryProvenance::capture('-1001', $raw, $now);
        $this->app->db->pdo()->prepare('INSERT INTO group_lock_state(group_id,event_key,status,snapshot_json,snapshot_sha256,prepared_at,snapshot_provenance,snapshot_evidence_json) VALUES(?,?,?,?,?,?,?,?)')
            ->execute(['-1001', 'diagnostic-fixture', 'conflict', $raw, hash('sha256', $raw), $now, 'verified_native_v1', $evidence]);
        $report = new DiagnosticReport($this->app);
        $verification = static fn(array $data): array => array_values(array_filter($data['records'], static fn(array $record): bool => $record['label'] === 'snapshot_verification'))[0];
        self::assertSame('ok', $verification($report->capture('2', '-1001'))['status']);
        $this->app->db->pdo()->exec("UPDATE group_lock_state SET snapshot_json='{}'");
        self::assertSame('warning', $verification($report->capture('2', '-1001'))['status']);
        $this->app->db->pdo()->prepare("UPDATE group_lock_state SET snapshot_json=?,snapshot_evidence_json=NULL,snapshot_provenance='legacy_unverified'")->execute([$raw]);
        $legacy = $verification($report->capture('2', '-1001'));
        self::assertSame('warning', $legacy['status']);
        self::assertSame('legacy_unverified', $legacy['data']['snapshot_provenance']);
    }
    private function action(string $action): void
    {
        self::assertTrue($this->ui->callback($this->event($action)));
    }

    private function event(string $action): array
    {
        $row = $this->repo->get('2');
        return ['user_id' => '2', 'chat_id' => '2', 'query_id' => (string)++$this->queryId, 'message_id' => 800, 'private' => true, 'inline_selection' => false, 'data' => 'dz:' . $row['token'] . ':' . $action];
    }

    private function last(): array
    {
        return $this->edits[array_key_last($this->edits)];
    }

    private function advance(int $seconds): void
    {
        $this->clock->set($this->clock->now()->modify('+' . $seconds . ' seconds'));
    }
}
