<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\CancelledException;
use Amp\DeferredFuture;
use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\{JobRepository, TefillinAcknowledgements, TefillinAckText};
use Shabbat\Storage\{Database, SettingsRepository, TemplateRepository};
use Shabbat\Telegram\RetryableTelegramException;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\Lang;

use function Amp\async;

final class TefillinAcknowledgementsTest extends TestCase
{
    private Database $db;
    private SettingsRepository $settings;
    private JobRepository $jobs;
    private TefillinAcknowledgements $ack;
    private FrozenClock $clock;
    private int $sequence = 0;
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T09:00:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->settings->ensureGroup('-1001', 'Fixture group', ['tefillin_enabled' => true]);
        $this->settings->ensureGroup('-1002', 'Other group', ['tefillin_enabled' => true]);
        $this->jobs = new JobRepository($this->db, $this->clock);
        $this->ack = new TefillinAcknowledgements($this->db, $this->settings, $this->clock, '999');
    }
    private function prepare(array $payload = [], string $group = '-1001'): array
    {
        $g = $this->settings->getGroup($group);
        $now = $this->clock->now()->getTimestamp();
        $id = $this->jobs->enqueue($group, 'tefillin', 'ack-test-' . ++$this->sequence, $now, $now + 300, $g['revision'], []);
        $job = $this->jobs->lease('fixture');
        self::assertSame($id, $job['job_id']);
        $payload += ['text' => '🚀 Reminder', 'entities' => [['_' => 'messageEntityBold', 'offset' => 3, 'length' => 8]], 'buttons_mode' => 'inherit'];
        $prepared = $this->ack->preparePayload($job, $g, $this->clock->now(), $payload);
        $this->jobs->preparePayload($id, 'fixture', $prepared);
        return ['id' => $id, 'job' => $job, 'payload' => $prepared];
    }
    private function deliver(array $prepared, array $ids = [41]): string
    {
        $updates = [];
        foreach ($ids as $id) {
            $updates[] = ['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => $id, 'out' => true, 'peer_id' => (int)$prepared['job']['group_id']]];
        }
        $this->jobs->sent($prepared['id'], 'fixture', ['_' => 'updates', 'updates' => $updates]);
        $this->ack->registerDelivery($prepared['id'], $ids);
        return $prepared['payload']['_tefillin_ack_token'] ?? '';
    }
    private function text(array $markup): string
    {
        if (isset($markup['inline_keyboard'])) {
            return $markup['inline_keyboard'][array_key_last($markup['inline_keyboard'])][0]['text'];
        }
        return $markup['rows'][array_key_last($markup['rows'])]['buttons'][0]['text'];
    }
    public function testDefaultBlueButtonAndPreparedPayloadAreStableAndPreserveRichContent(): void
    {
        $p = $this->prepare();
        self::assertSame('🚀 Reminder', $p['payload']['text']);
        self::assertSame([['_' => 'messageEntityBold', 'offset' => 3, 'length' => 8]], $p['payload']['entities']);
        $button = $p['payload']['reply_markup']['inline_keyboard'][0][0];
        self::assertSame('הנחתי (0)', $button['text']);
        self::assertSame(['_' => 'keyboardButtonStyle', 'bg_primary' => true, 'icon' => 5944970388252402183], $button['style']);
        self::assertSame('keyboardButtonCallback', $button['_']);
        self::assertSame(27, strlen($button['data']));
        $again = $this->ack->preparePayload($p['job'], $this->settings->getGroup('-1001'), $this->clock->now(), $p['payload']);
        self::assertSame($p['payload'], $again);
        self::assertNull($this->ack->snapshot('-1001', 41));
    }
    public function testOnlyPersistedSuccessfulSendAndVerifiedNativeMessageIdsCanBind(): void
    {
        $p = $this->prepare();
        try {
            $this->ack->registerDelivery($p['id'], [41]);
            self::fail('Unsent round registered');
        } catch (\RuntimeException) {
            self::assertNull($this->ack->snapshot('-1001', 41));
        }
        $this->jobs->sent($p['id'], 'fixture', ['_' => 'updateShortSentMessage', 'id' => 41]);
        try {
            $this->ack->registerDelivery($p['id'], [42]);
            self::fail('Unknown message registered');
        } catch (\RuntimeException) {
            self::assertNull($this->ack->snapshot('-1001', 42));
        }
        $this->ack->registerDelivery($p['id'], [41]);
        self::assertSame(0, $this->ack->snapshot('-1001', 41)['count']);
    }
    public function testWrongPeerAndIncomingMessagesCannotRegister(): void
    {
        $p = $this->prepare();
        $this->jobs->sent($p['id'], 'fixture', ['_' => 'updates', 'updates' => [
            ['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 41, 'out' => true, 'peer_id' => -1002]],
            ['_' => 'updateNewMessage', 'message' => ['_' => 'message', 'id' => 42, 'out' => false, 'peer_id' => -1001]],
        ]]);
        $this->expectException(\RuntimeException::class);
        $this->ack->registerDelivery($p['id'], [41, 42]);
    }
    public function testToggleIsPerMemberAndDuplicateDeliveryAndCallbackDoNotResetOrToggleTwice(): void
    {
        $p = $this->prepare();
        $token = $this->deliver($p);
        $first = $this->ack->toggle('1', '-1001', 41, $token, 'callback-1');
        self::assertSame(1, $first['count']);
        self::assertTrue($first['selected']);
        $duplicate = $this->ack->toggle('1', '-1001', 41, $token, 'callback-1');
        self::assertTrue($duplicate['duplicate']);
        self::assertSame(1, $duplicate['count']);
        $this->ack->registerDelivery($p['id'], [41]);
        self::assertSame(1, $this->ack->snapshot('-1001', 41)['count']);
        self::assertSame(2, $this->ack->toggle('2', '-1001', 41, $token, 'callback-2')['count']);
        $off = $this->ack->toggle('1', '-1001', 41, $token, 'callback-3');
        self::assertFalse($off['selected']);
        self::assertSame(1, $off['count']);
        self::assertSame(3, $off['revision']);
    }
    public function testSeveralDeliveredMessagesAndSeparateRoundsHaveIndependentCounts(): void
    {
        $p = $this->prepare();
        $token = $this->deliver($p, [41, 42]);
        $this->ack->toggle('1', '-1001', 41, $token, 'a');
        self::assertSame(0, $this->ack->snapshot('-1001', 42)['count']);
        $this->ack->toggle('1', '-1001', 42, $token, 'b');
        self::assertSame(1, $this->ack->snapshot('-1001', 42)['count']);
        $p2 = $this->prepare();
        $token2 = $this->deliver($p2, [43]);
        self::assertNotSame($token, $token2);
        self::assertSame(0, $this->ack->snapshot('-1001', 43)['count']);
    }
    public function testCrossGroupMessageTokenAndCallbackReuseAreRejectedWithoutMutation(): void
    {
        $token = $this->deliver($this->prepare());
        $this->ack->toggle('1', '-1001', 41, $token, 'a');
        foreach ([['1', '-1002', 41, $token, 'b'], ['1', '-1001', 42, $token, 'b'], ['1', '-1001', 41, str_repeat('a', 24), 'b'], ['2', '-1001', 41, $token, 'a']] as $args) {
            try {
                $this->ack->toggle(...$args);
                self::fail('Context replay accepted');
            } catch (\RuntimeException) {
                self::assertSame(1, $this->ack->snapshot('-1001', 41)['count']);
            }
        }
    }
    public function testSelfBotAnonymousInlineAndUnidentifiedActorsAreRejected(): void
    {
        $token = $this->deliver($this->prepare());
        foreach ([['999', false, false, false], ['0', false, false, false], ['-1001', false, false, false], ['1', true, false, false], ['1', false, true, false], ['1', false, false, true]] as [$user, $bot, $anonymous, $inline]) {
            try {
                $this->ack->toggle($user, '-1001', 41, $token, 'a', $bot, $anonymous, $inline);
                self::fail('Unidentified or unsupported actor accepted');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $this->ack->snapshot('-1001', 41)['count']);
            }
        }
    }
    public function testTurnedOffAndRemovedGroupsCannotReceiveAcknowledgements(): void
    {
        $token = $this->deliver($this->prepare());
        $g = $this->settings->getGroup('-1001');
        $this->settings->updateGroup('-1001', $g['revision'], ['tefillin_ack_enabled' => false], '1');
        $this->expectException(\RuntimeException::class);
        $this->ack->toggle('1', '-1001', 41, $token, 'a');
    }
    public function testVoteCallbackAndRevisionRollBackTogetherOnSqlFailure(): void
    {
        $token = $this->deliver($this->prepare());
        $this->db->pdo()->exec("CREATE TRIGGER ack_failure BEFORE UPDATE ON tefillin_ack_messages BEGIN SELECT RAISE(ABORT,'fixture'); END");
        try {
            $this->ack->toggle('1', '-1001', 41, $token, 'a');
            self::fail('Failed vote committed');
        } catch (\PDOException) {
            self::assertSame(0, $this->ack->snapshot('-1001', 41)['count']);
            self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM tefillin_ack_callbacks')->fetchColumn());
        }
    }
    public function testAsyncEditsCoalesceAndOldCountCannotOverwriteNewerCountAcrossInstances(): void
    {
        $custom = ['_' => 'keyboardButtonUrl', 'text' => 'Other', 'url' => 'https://example.com/', 'style' => ['_' => 'keyboardButtonStyle', 'bg_success' => true, 'icon' => 123]];
        $p = $this->prepare(['buttons_mode' => 'inherit', 'reply_markup' => ['_' => 'replyInlineMarkup', 'rows' => [['_' => 'keyboardButtonRow', 'buttons' => [$custom]]]]]);
        $token = $this->deliver($p);
        $other = new TefillinAcknowledgements($this->db, $this->settings, $this->clock);
        $this->ack->toggle('1', '-1001', 41, $token, 'a');
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $calls = [];
        $edit = function (string $group, int $message, array $markup) use ($entered, $release, &$calls, $custom): void {
            self::assertSame('-1001', $group);
            self::assertSame(41, $message);
            self::assertSame($custom, $markup['rows'][0]['buttons'][0]);
            $calls[] = $this->text($markup);
            if (count($calls) === 1) {
                $entered->complete();
                $release->getFuture()->await();
            }
        };
        $work = async(fn(): array => $this->ack->refresh('-1001', 41, $edit));
        $entered->getFuture()->await();
        $other->toggle('2', '-1001', 41, $token, 'b');
        self::assertSame('coalesced', $other->refresh('-1001', 41, $edit)['status']);
        $release->complete();
        self::assertSame('current', $work->await()['status']);
        self::assertSame(['הנחתי (1)', 'הנחתי (2)'], $calls);
        self::assertSame(2, $other->snapshot('-1001', 41)['rendered_revision']);
    }
    public function testFloodWaitPersistsAndAdditionalVotesCannotBypassBackoff(): void
    {
        $token = $this->deliver($this->prepare());
        $this->ack->toggle('1', '-1001', 41, $token, 'a');
        $calls = 0;
        $edit = static function () use (&$calls): void {
            $calls++;
            throw new RetryableTelegramException('fixture', 120);
        };
        self::assertSame('retry', $this->ack->refresh('-1001', 41, $edit)['status']);
        $this->ack->toggle('2', '-1001', 41, $token, 'b');
        self::assertSame('deferred', $this->ack->refresh('-1001', 41, $edit)['status']);
        self::assertSame([], $this->ack->flushPending($edit));
        self::assertSame(1, $calls);
        $this->clock->set($this->clock->now()->modify('+121 seconds'));
        $seen = [];
        $this->ack->flushPending(function (string $group, int $message, array $markup) use (&$seen): void {
            $seen[] = $this->text($markup);
        });
        self::assertSame(['הנחתי (2)'], $seen);
    }
    public function testCancellationPropagatesAndRestartCanRecoverPendingMarkup(): void
    {
        $token = $this->deliver($this->prepare());
        $this->ack->toggle('1', '-1001', 41, $token, 'a');
        $error = new CancelledException(new \RuntimeException('fixture'));
        try {
            $this->ack->refresh('-1001', 41, static function () use ($error): never {
                throw $error;
            });
            self::fail('Intentional cancellation swallowed');
        } catch (CancelledException $caught) {
            self::assertSame($error, $caught);
        }
        $restart = new TefillinAcknowledgements($this->db, $this->settings, $this->clock);
        $seen = [];
        $restart->flushPending(function (string $group, int $message, array $markup) use (&$seen): void {
            $seen[] = $this->text($markup);
        });
        self::assertSame(['הנחתי (1)'], $seen);
        self::assertSame(1, $restart->snapshot('-1001', 41, '1')['count']);
    }
    public function testCustomAndRemovedButtonsOverrideAcknowledgementDefault(): void
    {
        foreach (['custom', 'none'] as $mode) {
            $p = $this->prepare(['buttons_mode' => $mode, 'buttons' => $mode === 'custom' ? [[['text' => 'Custom', 'url' => 'https://example.com']]] : []]);
            self::assertArrayNotHasKey('_tefillin_ack_token', $p['payload']);
            $this->deliver($p, [40 + $this->sequence]);
            self::assertNull($this->ack->snapshot('-1001', 40 + $this->sequence));
        }
    }
    public function testLocalizedDefaultAndCustomLabelAreStoredPerRoundInAllSixLanguages(): void
    {
        foreach (Lang::CODES as $language) {
            $g = $this->settings->getGroup('-1001');
            $this->settings->updateGroup('-1001', $g['revision'], ['language' => $language, 'tefillin_ack_label' => null], '1');
            $p = $this->prepare();
            self::assertSame(TefillinAckText::label($language) . ' (0)', $this->text($p['payload']['reply_markup']));
            $this->deliver($p, [100 + $this->sequence]);
        }
        $g = $this->settings->getGroup('-1001');
        $this->settings->updateGroup('-1001', $g['revision'], ['tefillin_ack_label' => '✅ My reminder'], '1');
        $p = $this->prepare();
        $token = $this->deliver($p, [201]);
        self::assertSame('✅ My reminder (1)', $this->text($this->ack->toggle('1', '-1001', 201, $token, 'a')['markup']));
        $g = $this->settings->getGroup('-1001');
        $this->settings->updateGroup('-1001', $g['revision'], ['tefillin_ack_label' => null], '1');
        self::assertSame('✅ My reminder (1)', $this->text($this->ack->snapshot('-1001', 201)['markup']));
    }
    public function testAcknowledgementSettingsRejectMultilineOversizedAndNonBooleanValues(): void
    {
        foreach ([['tefillin_ack_label' => "bad\nlabel"], ['tefillin_ack_label' => ''], ['tefillin_ack_label' => str_repeat('🚀', 25)], ['tefillin_ack_enabled' => 'yes']] as $changes) {
            $g = $this->settings->getGroup('-1001');
            try {
                $this->settings->updateGroup('-1001', $g['revision'], $changes, '1');
                self::fail('Invalid acknowledgement setting accepted');
            } catch (\InvalidArgumentException) {
                self::assertNull($this->settings->getGroup('-1001')['tefillin_ack_label']);
            }
        }
    }
    public function testTemplateButtonModesPreserveBodyEntitiesAndAllowExplicitDefaultRestoration(): void
    {
        $templates = new TemplateRepository($this->db, $this->clock);
        $t = $templates->save('-1001', 'tefillin', ['text' => 'Custom', 'entities' => [['_' => 'messageEntityBold', 'offset' => 0, 'length' => 6]]], '1');
        self::assertSame('inherit', $t['buttons_mode']);
        $t = $templates->save('-1001', 'tefillin', $t + ['buttons_mode' => 'inherit'], '1', $t['revision']);
        self::assertSame('inherit', $t['buttons_mode']);
        $t['buttons'] = [[['text' => 'Custom', 'url' => 'https://example.com']]];
        $t['buttons_mode'] = 'custom';
        $t = $templates->save('-1001', 'tefillin', $t, '1', $t['revision']);
        self::assertSame('custom', $t['buttons_mode']);
        $t['buttons'] = [];
        $t['buttons_mode'] = 'none';
        $t = $templates->save('-1001', 'tefillin', $t, '1', $t['revision']);
        self::assertSame('none', $t['buttons_mode']);
        $t['buttons_mode'] = 'inherit';
        $t = $templates->save('-1001', 'tefillin', $t, '1', $t['revision']);
        self::assertSame('inherit', $t['buttons_mode']);
        self::assertSame('Custom', $t['text']);
        self::assertSame([['_' => 'messageEntityBold', 'offset' => 0, 'length' => 6]], $t['entities']);
    }
    public function testTemplateContradictoryButtonModeCannotSilentlyDiscardCustomButtons(): void
    {
        $templates = new TemplateRepository($this->db, $this->clock);
        $this->expectException(\InvalidArgumentException::class);
        $templates->save('-1001', 'tefillin', ['buttons_mode' => 'inherit', 'buttons' => [[['text' => 'Custom', 'url' => 'https://example.com']]]], '1');
    }

    public function testPreviousTemplateRowsMigrateEmptyRowsToInheritedAndAllNonemptyKeyboardsToCustom(): void
    {
        $old = Database::open(':memory:');
        $old->pdo()->exec(file_get_contents(dirname(__DIR__) . '/migrations/001_initial.sql'));
        $old->pdo()->exec("INSERT INTO groups(group_id,created_at,updated_at) VALUES('-1',0,0)");
        foreach (['tefillin' => [], 'daf' => [[['text' => 'Custom', 'url' => 'https://example.com']]], 'zmanim' => ['_' => 'replyInlineMarkup', 'rows' => [['_' => 'keyboardButtonRow', 'buttons' => [['_' => 'keyboardButtonCopy', 'text' => 'Copy', 'copy_text' => 'text']]]]]] as $kind => $buttons) {
            $old->pdo()->prepare('INSERT INTO message_templates(group_id,kind,buttons_json,updated_at) VALUES(?,?,?,0)')->execute(['-1', $kind, Database::encode($buttons)]);
        }
        $old->pdo()->exec(file_get_contents(dirname(__DIR__) . '/migrations/012_tefillin_acknowledgements.sql'));
        $modes = $old->pdo()->query('SELECT kind,buttons_mode FROM message_templates')->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame('inherit', $modes['tefillin']);
        self::assertSame('custom', $modes['daf']);
        self::assertSame('custom', $modes['zmanim']);
        self::assertSame([], $old->integrity()['foreign_key_check']);
    }

    public function testReplannedUnsentJobGetsNewRoundSettingsAndCannotReuseOldToken(): void
    {
        $p = $this->prepare();
        $oldToken = $p['payload']['_tefillin_ack_token'];
        $g = $this->settings->getGroup('-1001');
        $g = $this->settings->updateGroup('-1001', $g['revision'], ['tefillin_ack_label' => 'Updated label'], '1');
        $now = $this->clock->now()->getTimestamp();
        $id = $this->jobs->enqueue('-1001', 'tefillin', 'ack-test-1', $now, $now + 300, $g['revision'], []);
        self::assertSame($p['id'], $id);
        $job = $this->jobs->lease('fixture');
        $payload = $this->ack->preparePayload($job, $g, $this->clock->now(), ['text' => 'Updated', 'buttons_mode' => 'inherit']);
        self::assertNotSame($oldToken, $payload['_tefillin_ack_token']);
        self::assertSame('Updated label (0)', $this->text($payload['reply_markup']));
        self::assertSame(1, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM tefillin_ack_rounds')->fetchColumn());
    }

    public function testFailedMarkupEditsHaveBoundedAutomaticRetriesAndKeepVotes(): void
    {
        $token = $this->deliver($this->prepare());
        $this->ack->toggle('1', '-1001', 41, $token, 'a');
        $calls = 0;
        $error = static function () use (&$calls): never {
            $calls++;
            throw new \RuntimeException('No raw diagnostic should persist');
        };
        for ($i = 0; $i < 5; $i++) {
            $this->ack->refresh('-1001', 41, $error);
            $this->clock->set($this->clock->now()->modify('+1000 seconds'));
        }
        self::assertSame('deferred', $this->ack->refresh('-1001', 41, $error)['status']);
        self::assertSame([], $this->ack->flushPending($error));
        self::assertSame(5, $calls);
        self::assertSame(1, $this->ack->snapshot('-1001', 41)['count']);
        self::assertSame('RuntimeException', $this->db->pdo()->query('SELECT last_error_code FROM tefillin_ack_messages')->fetchColumn());
        $this->ack->toggle('2', '-1001', 41, $token, 'b');
        $this->ack->flushPending(static function (): void {
        });
        self::assertSame(2, $this->ack->snapshot('-1001', 41)['rendered_revision']);
    }

    public function testFileBackedRestartAndSeparateConnectionsPreserveUniqueMemberToggles(): void
    {
        $token = $this->deliver($this->prepare());
        $this->ack->toggle('1', '-1001', 41, $token, 'a');
        $path = sys_get_temp_dir() . '/shabbat-ack-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->db->pdo()->exec('VACUUM INTO ' . $this->db->pdo()->quote($path));
        try {
            $firstDb = Database::open($path);
            $secondDb = Database::open($path);
            $first = new TefillinAcknowledgements($firstDb, new SettingsRepository($firstDb, $this->clock), $this->clock);
            $second = new TefillinAcknowledgements($secondDb, new SettingsRepository($secondDb, $this->clock), $this->clock);
            self::assertSame(1, $first->snapshot('-1001', 41, '1')['count']);
            self::assertTrue($second->snapshot('-1001', 41, '1')['selected']);
            $first->toggle('2', '-1001', 41, $token, 'b');
            $second->toggle('2', '-1001', 41, $token, 'c');
            self::assertSame(1, $first->snapshot('-1001', 41)['count']);
            $second->toggle('3', '-1001', 41, $token, 'd');
            self::assertSame(2, $first->snapshot('-1001', 41)['count']);
            $second->flushPending(static function (): void {
            });
            self::assertSame(4, $first->snapshot('-1001', 41)['rendered_revision']);
        } finally {
            unset($first, $second, $firstDb, $secondDb);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function testAlreadyStoredNativeKeyboardWrapperNormalizesWithoutChangingStoredBytesOrStyles(): void
    {
        $button = ['_' => 'keyboardButtonCallback', 'text' => 'Custom', 'data' => 'existing:action', 'style' => ['_' => 'keyboardButtonStyle', 'bg_success' => true, 'icon' => 5944760643524500583]];
        $markup = ['_' => 'replyInlineMarkup', 'rows' => [['_' => 'keyboardButtonRow', 'buttons' => [$button]]]];
        $templates = new TemplateRepository($this->db, $this->clock);
        $saved = $templates->save('-1001', 'tefillin', ['text' => 'Existing', 'buttons' => $markup, 'buttons_mode' => 'custom'], '1');
        self::assertSame([[$button]], $saved['buttons']);
        self::assertSame('custom', $saved['buttons_mode']);
        self::assertSame(Database::encode($markup), $this->db->pdo()->query("SELECT buttons_json FROM message_templates WHERE kind='tefillin'")->fetchColumn());
        self::assertSame([[$button]], $templates->get('-1001', 'tefillin')['buttons']);
        self::assertSame([[$button]], TemplateRepository::buttonRows(['inline_keyboard' => [[$button]]]));
    }
}
