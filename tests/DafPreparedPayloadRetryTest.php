<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use danog\MadelineProto\TextEntities;
use DateTimeImmutable;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shabbat\Content\LegacyDafPayload;
use Shabbat\Scheduling\JobRepository;
use Shabbat\Scheduling\OutboxWorker;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class DafPreparedPayloadRetryTest extends TestCase
{
    private const LINK = 'https://www.sefaria.org/Bekhorot.35a?lang=bi&utm_source=hebcal.com&utm_medium=api';
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private JobRepository $jobs;
    private SchedulerFakeGateway $gateway;
    private int $sequence = 0;
    private int $factoryCalls = 0;

    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-23T08:12:00+03:00'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->settings->ensureGroup('-1001', 'Daf retry', ['daf_enabled' => true, 'zmanim_enabled' => true]);
        $this->jobs = new JobRepository($this->db, $this->clock);
        $this->gateway = new SchedulerFakeGateway();
        $this->gateway->onSend = static function (string $peer, array $payload): array {
            TextEntities::fromHtml($payload['text']);
            return ['_' => 'updates', 'message_id' => 35];
        };
    }

    private function oldPayload(): array
    {
        return [
            'text' => "Bekhorot 35 - 2026-10-23\n" . self::LINK,
            'topic_id' => 42,
            'media' => ['bot_api_file_id' => 'preserved-media'],
            'buttons_mode' => 'custom',
            'buttons' => [[['text' => 'Study', 'url' => self::LINK, 'style' => 'primary']]],
        ];
    }

    private function prepare(?array $payload = null, string $module = 'daf', int $leaseSeconds = 120): int
    {
        $now = $this->clock->now()->getTimestamp();
        $group = $this->settings->getGroup('-1001');
        $id = $this->jobs->enqueue('-1001', $module, 'fixture-' . ++$this->sequence, $now, $now + 3600, $group['revision'], []);
        self::assertSame($id, $this->jobs->lease('legacy-worker', $leaseSeconds)['job_id']);
        $this->jobs->preparePayload($id, 'legacy-worker', $payload ?? $this->oldPayload());
        return $id;
    }

    private function worker(): OutboxWorker
    {
        return new OutboxWorker(
            $this->jobs,
            $this->settings,
            $this->gateway,
            $this->clock,
            static fn(): bool => true,
            function (): array {
                $this->factoryCalls++;
                throw new RuntimeException('A frozen delivery must not reload its calendar provider.');
            }
        );
    }

    private function actualParseError(string $html): string
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            TextEntities::fromHtml($html);
        } catch (ErrorException $error) {
            self::assertStringContainsString('DOMDocument::loadXML()', $error->getMessage());
            self::assertStringContainsString('EntityRef: expecting', $error->getMessage());
            return $error->getMessage();
        } finally {
            restore_error_handler();
        }
        self::fail('The real pre-3.1.1 Sefaria query must reproduce the parser failure.');
    }

    public function testKnownParseFailureRetriesWithoutRecreatingPayloadOrIdentity(): void
    {
        $id = $this->prepare();
        $this->jobs->markDispatch($id, 'legacy-worker');
        $error = $this->actualParseError($this->oldPayload()['text']);
        $this->jobs->retry($id, 'legacy-worker', $error, 30);
        $before = $this->jobs->get($id);
        $firstHistory = $this->db->pdo()->query('SELECT * FROM delivery_log ORDER BY delivery_id')->fetch();
        self::assertNotNull($before['dispatch_started_at']);
        $this->clock->set($this->clock->now()->modify('+31 seconds'));

        self::assertSame('sent', $this->worker()->runOnce('new-worker')['status']);
        $after = $this->jobs->get($id);
        self::assertSame(0, $this->factoryCalls);
        foreach (['random_id', 'payload_prepared_at', 'run_at', 'expires_at', 'settings_revision', 'deferrals', 'dispatch_started_at'] as $field) {
            self::assertSame($before[$field], $after[$field], $field);
        }
        self::assertSame(2, $after['attempts']);
        self::assertSame($firstHistory, $this->db->pdo()->query('SELECT * FROM delivery_log ORDER BY delivery_id LIMIT 1')->fetch());
        self::assertSame($before['random_id'], $this->gateway->sends[0][2]);
        $expected = $this->oldPayload();
        $expected['text'] = "Bekhorot 35 - 2026-10-23\n" . htmlspecialchars(self::LINK, ENT_QUOTES, 'UTF-8');
        self::assertSame($expected, $after['payload']);
        self::assertSame($expected, $this->gateway->sends[0][1]);
        self::assertSame($this->oldPayload()['text'], TextEntities::fromHtml($after['payload']['text'])->message);
        self::assertSame(['retry', 'sent'], $this->db->pdo()->query('SELECT outcome FROM delivery_log ORDER BY delivery_id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testNeverDispatchedPreparedPayloadIsRepairedAfterLeaseRecovery(): void
    {
        $id = $this->prepare(leaseSeconds: 1);
        $before = $this->jobs->get($id);
        self::assertNull($before['dispatch_started_at']);
        self::assertNull($before['last_error']);
        $this->clock->set($this->clock->now()->modify('+2 seconds'));
        self::assertSame('sent', $this->worker()->runOnce('new-worker')['status']);
        $after = $this->jobs->get($id);
        self::assertSame($before['random_id'], $after['random_id']);
        self::assertSame($before['payload_prepared_at'], $after['payload_prepared_at']);
        self::assertSame(0, $this->factoryCalls);
        self::assertSame($this->oldPayload()['text'], TextEntities::fromHtml($after['payload']['text'])->message);
    }

    public function testRepairOnlyChangesPayloadJsonAndIsIdempotent(): void
    {
        $payload = $this->oldPayload();
        $payload['text'] = "Bekhorot &amp; learning 35 - 2026-10-23\n" . self::LINK;
        $id = $this->prepare($payload);
        $before = $this->db->pdo()->query('SELECT * FROM scheduled_jobs')->fetch();
        $history = $this->db->pdo()->query('SELECT * FROM delivery_log')->fetchAll();
        self::assertTrue($this->jobs->repairPreparedDafPayload($id, 'legacy-worker'));
        $after = $this->db->pdo()->query('SELECT * FROM scheduled_jobs')->fetch();
        unset($before['payload_json'], $after['payload_json']);
        self::assertSame($before, $after);
        self::assertSame($history, $this->db->pdo()->query('SELECT * FROM delivery_log')->fetchAll());
        self::assertFalse($this->jobs->repairPreparedDafPayload($id, 'legacy-worker'));
        $repaired = $this->jobs->get($id)['payload'];
        self::assertStringNotContainsString('&amp;amp;', $repaired['text']);
        self::assertSame("Bekhorot & learning 35 - 2026-10-23\n" . self::LINK, TextEntities::fromHtml($repaired['text'])->message);
    }

    public function testGenericUncertainDeliveryIsNeverRewritten(): void
    {
        $id = $this->prepare();
        $this->jobs->markDispatch($id, 'legacy-worker');
        $this->jobs->retry($id, 'legacy-worker', 'Connection lost after possible acceptance', 30);
        $this->clock->set($this->clock->now()->modify('+31 seconds'));
        $this->jobs->lease('new-worker');
        $before = $this->db->pdo()->query('SELECT * FROM scheduled_jobs')->fetch();
        self::assertFalse($this->jobs->repairPreparedDafPayload($id, 'new-worker'));
        self::assertSame($before, $this->db->pdo()->query('SELECT * FROM scheduled_jobs')->fetch());
    }

    public static function unaffectedPayloads(): iterable
    {
        $text = "Bekhorot 35 - 2026-10-23\n" . self::LINK;
        yield 'native entities' => [['text' => $text, 'entities' => []]];
        yield 'custom HTML' => [['text' => '<b>Personal</b> ' . $text]];
        yield 'custom prefix' => [['text' => "Personal\n" . $text]];
        yield 'raw custom heading' => [['text' => "Learning & sharing - 2026-10-23\n" . self::LINK]];
        yield 'already escaped' => [['text' => htmlspecialchars($text, ENT_QUOTES, 'UTF-8')]];
        yield 'different host' => [['text' => str_replace('www.sefaria.org', 'example.com', $text)]];
        yield 'host suffix' => [['text' => str_replace('www.sefaria.org/', 'www.sefaria.org.example.com/', $text)]];
        yield 'not a generated dated heading' => [['text' => "Custom Daf\n" . self::LINK]];
        yield 'new HTML card' => [['text' => '<b>Daf Yomi</b><a href="' . htmlspecialchars(self::LINK, ENT_QUOTES, 'UTF-8') . '">Open</a>']];
    }

    #[DataProvider('unaffectedPayloads')]
    public function testOtherPayloadsAreNotRepaired(array $payload): void
    {
        self::assertNull(LegacyDafPayload::repair($payload));
        $id = $this->prepare($payload);
        $before = $this->jobs->get($id);
        self::assertFalse($this->jobs->repairPreparedDafPayload($id, 'legacy-worker'));
        self::assertSame($before, $this->jobs->get($id));
    }

    public function testOtherModulesAreNotRepaired(): void
    {
        $id = $this->prepare(module: 'zmanim');
        $before = $this->jobs->get($id);
        self::assertFalse($this->jobs->repairPreparedDafPayload($id, 'legacy-worker'));
        self::assertSame($before, $this->jobs->get($id));
    }

    public function testTerminalJobsAreNeverRevivedOrModified(): void
    {
        foreach (['failed', 'expired', 'cancelled', 'sent'] as $status) {
            $id = $this->prepare();
            $query = $this->db->pdo()->prepare('UPDATE scheduled_jobs SET status=?,lease_owner=NULL,lease_until=NULL WHERE job_id=?');
            $query->execute([$status, $id]);
        }
        $before = $this->db->pdo()->query('SELECT * FROM scheduled_jobs ORDER BY job_id')->fetchAll();
        $history = $this->db->pdo()->query('SELECT * FROM delivery_log ORDER BY delivery_id')->fetchAll();
        self::assertNull($this->worker()->runOnce('new-worker'));
        self::assertSame($before, $this->db->pdo()->query('SELECT * FROM scheduled_jobs ORDER BY job_id')->fetchAll());
        self::assertSame($history, $this->db->pdo()->query('SELECT * FROM delivery_log ORDER BY delivery_id')->fetchAll());
        self::assertSame([], $this->gateway->sends);
    }

    public function testRetryTimeAndExpirationStillApply(): void
    {
        $id = $this->prepare();
        $this->jobs->retry($id, 'legacy-worker', $this->actualParseError($this->oldPayload()['text']), 30);
        $before = $this->jobs->get($id);
        self::assertNull($this->worker()->runOnce('new-worker'));
        self::assertSame($before, $this->jobs->get($id));
        $this->clock->set(new DateTimeImmutable('@' . $before['expires_at']));
        self::assertNull($this->worker()->runOnce('new-worker'));
        $after = $this->jobs->get($id);
        self::assertSame('expired', $after['status']);
        self::assertSame($before['payload'], $after['payload']);
        self::assertSame($before['random_id'], $after['random_id']);
        self::assertSame($before['attempts'], $after['attempts']);
        self::assertSame([], $this->gateway->sends);
    }

    public function testLostLeaseCannotModifyThePayload(): void
    {
        $id = $this->prepare();
        $before = $this->jobs->get($id);
        try {
            $this->jobs->repairPreparedDafPayload($id, 'other-worker');
            self::fail('A different lease owner must not repair this payload.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('ownership', $error->getMessage());
        }
        self::assertSame($before, $this->jobs->get($id));
    }

    public function testFailedRepairPersistenceCannotSend(): void
    {
        $id = $this->prepare(leaseSeconds: 1);
        $this->db->pdo()->exec("CREATE TRIGGER reject_daf_repair BEFORE UPDATE OF payload_json ON scheduled_jobs BEGIN SELECT RAISE(FAIL,'simulated disk failure'); END");
        $this->clock->set($this->clock->now()->modify('+2 seconds'));
        self::assertSame('retry', $this->worker()->runOnce('new-worker')['status']);
        self::assertSame($this->oldPayload(), $this->jobs->get($id)['payload']);
        self::assertSame([], $this->gateway->sends);
        self::assertSame(0, $this->factoryCalls);
    }
}
