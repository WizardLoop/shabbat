<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shabbat\Storage\AdminStateFiles;
use Shabbat\Storage\Database;
use Shabbat\Storage\LegacyImporter;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\FrozenClock;

final class LegacyAdminImportTest extends TestCase
{
    private string $root;
    private Database $db;
    private FrozenClock $clock;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shabbat-legacy-admin-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/data/17', 0700, true);
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-13T09:00:00Z'));
        file_put_contents($this->root . '/data/DBgroups.txt', "-1001\n");
        file_put_contents($this->root . '/ids.txt', "17\n18\n17\n-1001\n-1009876\n0\n9223372036854775808\nbad\n");
        file_put_contents($this->root . '/idsnew.txt', "18\n-1001\n");
        file_put_contents($this->root . '/data/17/grs1.txt', "chatpost\r\n");
        file_put_contents($this->root . '/data/17/messagetodelete.txt', '913');
        file_put_contents($this->root . '/data/17/txt.txt', "שבת שלום 😀\r\nLiteral text");
        file_put_contents($this->root . '/data/17/ent.txt', '[{"_":"messageEntityBold","offset":0,"length":4}]');
        file_put_contents($this->root . '/data/17/media.txt', 'preserved-bot-file-id');
        file_put_contents($this->root . '/data/BUTTONS.txt', '[[{"text":"Help","url":"https://example.test","style":{"_":"keyboardButtonStyle","bg_primary":true}}]]');
        file_put_contents($this->root . '/data/17/BUTTONS.txt', '[[{"text":"Private","url":"https://private.example.test","style":{"_":"keyboardButtonStyle","bg_success":true}}]]');
        file_put_contents($this->root . '/data/17/pinmessage.txt', '0');
        file_put_contents($this->root . '/data/17/broadcastsend.txt', '2');
        file_put_contents($this->root . '/data/17/page_var.txt', '4');
        file_put_contents($this->root . '/data/pinmessage.txt', '1');
        file_put_contents($this->root . '/data/broadcastsend.txt', '3');
        file_put_contents($this->root . '/api.txt', 'never-import-this-secret');
        AdminStateFiles::configure($this->db, $this->clock, $this->root);
    }
    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }
    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            } $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        } rmdir($path);
    }
    public function testDryRunReportsKnownUsersAndWhitelistedDraftsWithoutWrites(): void
    {
        $result = (new LegacyImporter($this->db, $this->clock))->run($this->root);
        self::assertSame(2, $result['counts']['users']);
        self::assertSame(14, $result['counts']['admin_state']);
        foreach (['users','groups','admin_state','message_templates','legacy_imports','known_broadcast_peers'] as $table) {
            self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
        self::assertStringContainsString('never-import', $this->root !== '' ? file_get_contents($this->root . '/api.txt') : '');
    }
    public function testCommittedImportPreservesExactDraftBytesAndAddsUsersWithoutAuthority(): void
    {
        $importer = new LegacyImporter($this->db, $this->clock);
        $result = $importer->run($this->root, false);
        self::assertSame(['17','18'], $this->db->pdo()->query('SELECT user_id FROM users ORDER BY user_id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(4, $result['counts']['known_peers']);
        self::assertSame(['-1001','-1009876','17','18'], $this->db->pdo()->query('SELECT peer_id FROM known_broadcast_peers ORDER BY peer_id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(['-1001'], $this->db->pdo()->query('SELECT group_id FROM groups ORDER BY group_id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame('unknown', $this->db->pdo()->query("SELECT peer_type FROM known_broadcast_peers WHERE peer_id='-1009876'")->fetchColumn());
        foreach (['grs1.txt','messagetodelete.txt','txt.txt','ent.txt','media.txt','BUTTONS.txt','pinmessage.txt','broadcastsend.txt','page_var.txt'] as $key) {
            self::assertSame(file_get_contents($this->root . '/data/17/' . $key), AdminStateFiles::read('data/17/' . $key));
        }
        foreach (['BUTTONS.txt','pinmessage.txt','broadcastsend.txt'] as $key) {
            self::assertSame(file_get_contents($this->root . '/data/' . $key), AdminStateFiles::read('data/' . $key));
        }
        self::assertSame(file_get_contents($this->root . '/ids.txt'), AdminStateFiles::read('ids.txt'));
        self::assertSame(0, (int)$this->db->pdo()->query("SELECT COUNT(*) FROM legacy_imports WHERE source_path LIKE '%api.txt'")->fetchColumn());
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM conversation_states')->fetchColumn());
        self::assertTrue(AdminStateFiles::isDirectory('data/17'));
        $draft = json_decode($this->db->pdo()->query("SELECT buttons_json FROM message_templates WHERE user_id='17' AND kind='broadcast'")->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('custom', $this->db->pdo()->query("SELECT buttons_mode FROM message_templates WHERE user_id='17' AND kind='broadcast'")->fetchColumn());
        self::assertSame('Private', $draft[0][0]['text']);
        self::assertTrue($draft[0][0]['style']['bg_success']);
        self::assertSame('0', AdminStateFiles::read('data/17/pinmessage.txt'));
        self::assertSame('1', AdminStateFiles::read('data/pinmessage.txt'));
        $settings = new SettingsRepository($this->db, $this->clock);
        $settings->updateUser('17', 1, ['language' => 'fr','location_name' => 'Edited location']);
        AdminStateFiles::write('data/17/txt.txt', 'Edited live draft');
        AdminStateFiles::write('ids.txt', "42\n");
        $this->db->pdo()->exec("UPDATE known_broadcast_peers SET peer_type='channel',title='Reviewed channel' WHERE peer_id='-1009876'");
        $importer->run($this->root, false);
        self::assertSame('channel', $this->db->pdo()->query("SELECT peer_type FROM known_broadcast_peers WHERE peer_id='-1009876'")->fetchColumn());
        self::assertSame('Reviewed channel', $this->db->pdo()->query("SELECT title FROM known_broadcast_peers WHERE peer_id='-1009876'")->fetchColumn());
        self::assertSame('Edited live draft', AdminStateFiles::read('data/17/txt.txt'));
        self::assertSame("42\n", AdminStateFiles::read('ids.txt'));
        self::assertSame('fr', $settings->getUser('17')['language']);
        self::assertSame('Edited location', $settings->getUser('17')['location_name']);
        self::assertSame(1, (int)$this->db->pdo()->query("SELECT COUNT(*) FROM message_templates WHERE user_id='17' AND kind='broadcast'")->fetchColumn());
        self::assertSame(14, $result['counts']['admin_state']);
    }
    public function testAutomaticGroupBridgeDoesNotImportPrivateUsersOrDrafts(): void
    {
        (new LegacyImporter($this->db, $this->clock))->importGroupListAutomatically($this->root);
        self::assertSame(1, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM groups')->fetchColumn());
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM admin_state')->fetchColumn());
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM known_broadcast_peers')->fetchColumn());
    }

    public function testNativeGroupAndBotApiPrivateWrappersPreserveButtonsStylesAndCustomMode(): void
    {
        $nativeButton = ['_' => 'keyboardButtonCallback', 'text' => 'Existing callback', 'data' => 'legacy:action', 'style' => ['_' => 'keyboardButtonStyle', 'bg_primary' => true, 'icon' => 5944760643524500583]];
        $privateButton = ['text' => 'Existing link', 'url' => 'https://example.com/', 'style' => ['_' => 'keyboardButtonStyle', 'bg_success' => true, 'icon' => 5945186253308699040]];
        $native = Database::encode(['_' => 'replyInlineMarkup', 'rows' => [['_' => 'keyboardButtonRow', 'buttons' => [$nativeButton]]]]);
        $botApi = Database::encode(['inline_keyboard' => [[$privateButton]]]);
        mkdir($this->root . '/data/-1001', 0700);
        file_put_contents($this->root . '/data/-1001/MsgCloserButtons.txt', $native);
        file_put_contents($this->root . '/data/17/BUTTONS.txt', $botApi);
        (new LegacyImporter($this->db, $this->clock))->run($this->root, false);
        $templates = new \Shabbat\Storage\TemplateRepository($this->db, $this->clock);
        $group = $templates->get('-1001', 'close');
        self::assertSame('custom', $group['buttons_mode']);
        self::assertSame([[$nativeButton]], $group['buttons']);
        $private = $this->db->pdo()->query("SELECT buttons_mode,buttons_json FROM message_templates WHERE user_id='17' AND kind='broadcast'")->fetch();
        self::assertSame('custom', $private['buttons_mode']);
        self::assertSame([[$privateButton]], json_decode($private['buttons_json'], true, 64, JSON_THROW_ON_ERROR));
        self::assertSame($native, file_get_contents($this->root . '/data/-1001/MsgCloserButtons.txt'));
        self::assertSame($botApi, file_get_contents($this->root . '/data/17/BUTTONS.txt'));
    }
}
