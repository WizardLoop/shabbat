<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use DateTimeImmutable;
use PDOException;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\HebcalProvider;
use Shabbat\Content\OmerText;
use Shabbat\Runtime\Donations;
use Shabbat\Storage\Database;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\Lang;

final class LanguageMigrationTest extends TestCase
{
    public function testMigrationPreservesAllSettingsPaymentBindingsAndUnchangedOperationalTables(): void
    {
        $dir = sys_get_temp_dir() . '/shabbat-language-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        foreach (['001_initial.sql','002_admin_state.sql','003_manual_payments.sql'] as $file) {
            copy(dirname(__DIR__) . '/migrations/' . $file, $dir . '/' . $file);
        }
        try {
            $db = Database::open(':memory:');
            $db->migrate($dir);
            $clock = new FrozenClock(new DateTimeImmutable('2026-10-13T09:00:00Z'));
            $settings = new SettingsRepository($db, $clock);
            $settings->ensureGroup('-1', 'Preserved');
            $settings->getUser('17');
            $settings->updateGroup('-1', 1, ['language' => 'en','location_name' => 'Edited city','latitude' => 42.1,'longitude' => -71.2,'timezone_id' => 'America/New_York','israel' => false,'omer_enabled' => true,'omer_nusach' => 'sephard','tefillin_fixed_times' => ['09:01','12:07'],'manual_override_mode' => 'lock','manual_override_until' => 2000000000], '17');
            $settings->updateUser('17', 1, ['language' => 'en','location_name' => 'Personal city']);
            $db->pdo()->exec("INSERT INTO group_lock_state(group_id,event_key,status,snapshot_json,snapshot_sha256,prepared_at) VALUES('-1','festival','recovery_required','{\"send_polls\":true}','original-signature',1791882000)");
            // Historical 001-003 fixture: do not call today's repository, which requires later schema fields.
            $db->pdo()->prepare("INSERT INTO scheduled_jobs(job_id,group_id,module,event_key,run_at,expires_at,settings_revision,payload_json,random_id,status,attempts,created_at,completed_at) VALUES(91,'-1','omer','omer-2026',1791882000,1791885600,2,?,'9191','sent',1,1791882000,1791882001)")
                ->execute([Database::encode(['message' => 'Stored text'])]);
            $db->pdo()->prepare("INSERT INTO delivery_log(job_id,attempt,random_id,started_at,finished_at,outcome,response_json) VALUES(91,1,'9191',1791882000,1791882001,'sent',?)")
                ->execute([Database::encode(['message_id' => 51])]);
            $db->pdo()->exec("INSERT INTO admin_state(user_id,state_key,value_kind,value_text,updated_at) VALUES('17','txt.txt','text','Edited draft',1791882000)");
            $donations = new Donations($db, $clock, static fn(string $method, array $args): mixed=>$method === 'payments.exportInvoice' ? ['url' => 'https://t.me/invoice-fixture'] : true);
            $offer = $donations->createOffers('17', 'en')['offers'][0];
            $donations->answerPrecheckout(['query_id' => '91','user_id' => 17,'currency' => 'XTR','total_amount' => 5,'payload' => $offer['payload']]);
            $donations->recordPayment(['user_id' => 17,'currency' => 'XTR','total_amount' => 5,'payload' => $offer['payload'],'charge' => ['id' => 'fixture-preserved-charge']]);
            $tables = ['group_settings','user_preferences','payment_intents','donations','groups','users','group_lock_state','scheduled_jobs','delivery_log','audit_log','admin_state'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = $db->pdo()->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll();
            }
            copy(dirname(__DIR__) . '/migrations/004_expand_languages.sql', $dir . '/004_expand_languages.sql');
            self::assertSame(['004_expand_languages.sql'], $db->migrate($dir));
            foreach ($tables as $table) {
                self::assertSame($before[$table], $db->pdo()->query('SELECT * FROM ' . $table . ' ORDER BY rowid')->fetchAll(), $table . ' changed during language-only migration');
            }
            self::assertSame([], $db->integrity()['foreign_key_check']);
            self::assertSame(['ok'], $db->integrity()['integrity_check']);
            self::assertSame(['donations' => 1,'stars' => 5], $donations->stats());
            self::assertSame('payment_intents', $db->pdo()->query('PRAGMA foreign_key_list(donations)')->fetchAll()[1]['table'] ?? $db->pdo()->query('PRAGMA foreign_key_list(donations)')->fetchAll()[0]['table']);
            self::assertSame([], $db->migrate($dir));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            } rmdir($dir);
        }
    }
    public function testAllSixGroupUserAndInvoiceLanguagesRoundTripWithoutCrossScopeChanges(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-13T09:00:00Z'));
        $settings = new SettingsRepository($db, $clock);
        $lang = new Lang();
        $group = $settings->ensureGroup('-1');
        $user = $settings->getUser('17');
        foreach (Lang::CODES as $code) {
            $group = $settings->updateGroup('-1', $group['revision'], ['language' => $code], '17');
            self::assertSame($user['language'], $settings->getUser('17')['language']);
            $user = $settings->updateUser('17', $user['revision'], ['language' => $code]);
            self::assertSame($code, $settings->getGroup('-1')['language']);
            self::assertSame($code, $settings->getUser('17')['language']);
            $calls = [];
            $service = new Donations($db, $clock, static function (string $method, array $args) use (&$calls): array {
                $calls[] = $args;
                return ['url' => 'https://t.me/invoice-fixture'];
            });
            $offers = $service->createOffers('17', $code);
            self::assertSame($lang->text($code, 'donate_prompt'), $offers['text']);
            self::assertCount(6, $offers['offers']);
            self::assertSame($lang->text($code, 'donate_title'), $calls[0]['invoice_media']['title']);
            self::assertSame($code, $db->pdo()->query('SELECT language FROM payment_intents ORDER BY intent_id DESC LIMIT 1')->fetchColumn());
        }
        try {
            $db->pdo()->exec("UPDATE group_settings SET language='unsupported'");
            self::fail('SQL constraint accepted an unknown language.');
        } catch (PDOException) {
        }
    }
    public function testSixProviderMappingsAndBundledOmerCountsAreCompleteAndDeterministic(): void
    {
        self::assertSame(['he','s','ru','fr','es','pt'], array_map(HebcalProvider::providerLanguage(...), Lang::CODES));
        $localized = json_decode(file_get_contents(dirname(__DIR__) . '/resources/templates/omer-counts-localized.json'), true, 64, JSON_THROW_ON_ERROR)['counts'];
        foreach (Lang::CODES as $code) {
            for ($day = 1; $day <= 49; $day++) {
                $text = OmerText::count($day, 'ashkenaz', $code);
                self::assertSame($text, OmerText::count($day, 'ashkenaz', $code));
                self::assertStringContainsString('בָּעֽוֹמֶר', $text);
                self::assertNotSame('', $text);
                if (!in_array($code, ['he','en'], true)) {
                    self::assertStringContainsString($localized[(string)$day][$code], $text);
                }
            }
            self::assertStringNotContainsString('ברוך אתה', OmerText::render(7, 'sephard', true, true, $code));
            self::assertStringContainsString('ברוך אתה', OmerText::render(49, 'sephard', true, false, $code));
        }
    }
}
