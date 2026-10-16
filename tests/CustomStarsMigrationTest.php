<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\Donations;
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;

final class CustomStarsMigrationTest extends TestCase
{
    private Database $db;
    private string $directory;
    private array $files = [];
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/shabbat-star-migration-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700)) {
            throw new \RuntimeException('Cannot create fixture migration directory.');
        }
        foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $file) {
            if (strcmp(basename($file), '011_custom_star_amounts.sql') < 0) {
                $target = $this->directory . '/' . basename($file);
                if (!copy($file, $target)) {
                    throw new \RuntimeException('Cannot copy fixture migration.');
                }
                $this->files[] = $target;
            }
        }
        $this->db = Database::open(':memory:');
        $this->db->migrate($this->directory);
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z'));
        $now = $this->clock->now()->getTimestamp();
        $this->db->pdo()->prepare('INSERT INTO users(user_id,created_at,updated_at) VALUES(?,?,?)')->execute(['17',$now - 3600,$now]);
        foreach (['paid','precheckout_approved','exported','created','expired','failed'] as $index => $status) {
            $id = 7 + $index;
            $this->db->pdo()->prepare('INSERT INTO payment_intents(intent_id,user_id,payload,nonce,currency,amount,language,status,created_at,expires_at,invoice_url,precheckout_query_id,approved_at,paid_at,last_error) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id,'17','legacy-payload-' . $id,'legacy-nonce-' . $id,'XTR',$index === 0 ? 5 : 25,['ru','fr','pt-br','he','en','es'][$index],$status,$now - 100,$now + 3600,'https://t.me/invoice-fixture-' . $id,$index < 2 ? (string)(1000 + $id) : null,$index < 2 ? $now - 80 : null,$index === 0 ? $now - 60 : null,$status === 'failed' ? 'legacy failure' : null]);
        }
        $this->db->pdo()->prepare('INSERT INTO donations(donation_id,intent_id,user_id,currency,amount,charge_uid,received_at,service_message_id) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([23,7,'17','XTR',5,'legacy-charge',$now - 60,777]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (dirname($file) !== $this->directory) {
                throw new \RuntimeException('Unexpected fixture cleanup path.');
            }
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function addMigration(string $suffix = ''): void
    {
        $path = $this->directory . '/011_custom_star_amounts.sql';
        $this->files[] = $path;
        $source = (string)file_get_contents(dirname(__DIR__) . '/migrations/011_custom_star_amounts.sql');
        if (file_put_contents($path, $source . $suffix) === false) {
            throw new \RuntimeException('Cannot write fixture migration.');
        }
    }

    public function testUpgradePreservesEveryExistingRecordAndAllBindingsWithForeignKeysEnabled(): void
    {
        $intents = $this->db->pdo()->query('SELECT * FROM payment_intents ORDER BY intent_id')->fetchAll();
        $donations = $this->db->pdo()->query('SELECT * FROM donations ORDER BY donation_id')->fetchAll();
        $this->addMigration();
        self::assertSame(['011_custom_star_amounts.sql'], $this->db->migrate($this->directory));
        self::assertSame($intents, $this->db->pdo()->query('SELECT * FROM payment_intents ORDER BY intent_id')->fetchAll());
        self::assertSame($donations, $this->db->pdo()->query('SELECT * FROM donations ORDER BY donation_id')->fetchAll());
        self::assertSame(1, (int)$this->db->pdo()->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame(['ok'], $this->db->integrity()['integrity_check']);
        self::assertSame([], $this->db->integrity()['foreign_key_check']);
        self::assertNotFalse($this->db->pdo()->query("SELECT name FROM sqlite_master WHERE type='index' AND name='payment_intent_expiry'")->fetchColumn());
        $foreignKeys = $this->db->pdo()->query('PRAGMA foreign_key_list(donations)')->fetchAll();
        $intentKey = array_values(array_filter($foreignKeys, static fn(array $key): bool => $key['from'] === 'intent_id'));
        self::assertSame('payment_intents', $intentKey[0]['table']);
        self::assertSame([], $this->db->migrate($this->directory));
    }

    public function testPendingApprovalStillWorksAndNewCustomPaymentsContinuePrimaryKeys(): void
    {
        $this->addMigration();
        $this->db->migrate($this->directory);
        $service = new Donations($this->db, $this->clock, static fn(string $method): mixed => $method === 'payments.exportInvoice' ? ['url' => 'https://t.me/invoice-fixture-custom'] : true);
        // Reserved IDs are arbitrary signed-decimal Telegram query identifiers; preserve legacy binding byte for byte.
        $old = $this->db->pdo()->query('SELECT precheckout_query_id FROM payment_intents WHERE intent_id=8')->fetchColumn();
        self::assertSame('1008', $old);
        self::assertSame('precheckout_approved', $this->db->pdo()->query('SELECT status FROM payment_intents WHERE intent_id=8')->fetchColumn());
        self::assertTrue($service->validatePrecheckout(['query_id' => '1008','user_id' => '17','currency' => 'XTR','total_amount' => 25,'payload' => 'legacy-payload-8'])['ok']);
        $one = $service->createCustomOffer('17', 1, 'ru');
        $max = $service->createCustomOffer('17', 10000, 'pt-br');
        self::assertSame(13, $one['intent_id']);
        self::assertSame(14, $max['intent_id']);
        $query = ['query_id' => '1234','user_id' => '17','currency' => 'XTR','total_amount' => 1,'payload' => $one['payload']];
        self::assertTrue($service->answerPrecheckout($query)['ok']);
        $payment = ['user_id' => '17','payload' => $one['payload'],'currency' => 'XTR','total_amount' => 1,'charge_uid' => 'new-custom-charge'];
        self::assertSame('recorded', $service->recordPayment($payment)['status']);
        self::assertSame(24, (int)$this->db->pdo()->query("SELECT donation_id FROM donations WHERE charge_uid='new-custom-charge'")->fetchColumn());
        self::assertSame(['donations' => 2,'stars' => 6], $service->stats());
        self::assertSame([], $this->db->integrity()['foreign_key_check']);
    }

    public function testDatabaseRetainsIntegerBoundsUniqueBindingsAndForeignKeys(): void
    {
        $this->addMigration();
        $this->db->migrate($this->directory);
        foreach ([0,10001,1.5] as $amount) {
            try {
                $this->db->pdo()->prepare("INSERT INTO payment_intents(user_id,payload,nonce,currency,amount,language,status,created_at,expires_at) VALUES('17',?,?,'XTR',?,'en','created',1,2)")
                    ->execute(['invalid-' . (string)$amount,'invalid-nonce-' . (string)$amount,$amount]);
                self::fail('Out-of-bound or non-integer database amount accepted.');
            } catch (\PDOException) {
                self::assertSame(6, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM payment_intents')->fetchColumn());
            }
        }
        foreach (
            [
            "INSERT INTO payment_intents(user_id,payload,nonce,currency,amount,language,created_at,expires_at) VALUES('17','legacy-payload-7','unique','XTR',1,'en',1,2)",
            "INSERT INTO payment_intents(user_id,payload,nonce,currency,amount,language,created_at,expires_at) VALUES('17','unique','legacy-nonce-7','XTR',1,'en',1,2)",
            "INSERT INTO donations(intent_id,user_id,currency,amount,charge_uid,received_at) VALUES(999,'17','XTR',1,'new',1)",
            "INSERT INTO donations(intent_id,user_id,currency,amount,charge_uid,received_at) VALUES(8,'17','XTR',1,'legacy-charge',1)",
            "INSERT INTO donations(intent_id,user_id,currency,amount,charge_uid,received_at) VALUES(7,'17','XTR',1,'new',1)",
            ] as $sql
        ) {
            try {
                $this->db->pdo()->exec($sql);
                self::fail('Payment uniqueness or FK guard lost.');
            } catch (\PDOException) {
                self::assertSame(1, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM donations')->fetchColumn());
            }
        }
    }

    public function testFailedMigrationRollsBackCopiesDropsRenamesDataAndHistory(): void
    {
        $before = $this->db->pdo()->query('SELECT * FROM payment_intents ORDER BY intent_id')->fetchAll();
        $history = $this->db->pdo()->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll();
        $this->addMigration("\nSELECT deliberately_missing_migration_function();\n");
        try {
            $this->db->migrate($this->directory);
            self::fail('Broken migration reported success.');
        } catch (\PDOException) {
            self::assertSame($before, $this->db->pdo()->query('SELECT * FROM payment_intents ORDER BY intent_id')->fetchAll());
            self::assertSame($history, $this->db->pdo()->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll());
            self::assertSame(1, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM donations')->fetchColumn());
            self::assertSame(0, (int)$this->db->pdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE '%_v11'")->fetchColumn());
            self::assertSame(1, (int)$this->db->pdo()->query('PRAGMA foreign_keys')->fetchColumn());
            self::assertSame([], $this->db->integrity()['foreign_key_check']);
        }
    }
}
