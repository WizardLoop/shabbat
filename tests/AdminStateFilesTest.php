<?php

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp;
use danog;
use DateTimeImmutable;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
// SPDX-License-Identifier: AGPL-3.0-only
use PHPUnit\Framework\TestCase;
use Shabbat\Storage\AdminStateFiles;
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;

final class AdminStateFilesTest extends TestCase
{
    private Database $database;
    private string $root;
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->database = Database::open(':memory:');
        $this->database->migrate();
        AdminStateFiles::configure($this->database, new FrozenClock(new DateTimeImmutable('2026-10-13T00:00:00Z')), $this->root);
    }
    public function testDraftReadWriteDeleteWithoutCreatingFiles(): void
    {
        $path = $this->root . '/data/7001/txt.txt';
        self::assertFalse(AdminStateFiles::exists($path));
        self::assertSame(5, AdminStateFiles::write($path, 'draft'));
        self::assertTrue(AdminStateFiles::exists($path));
        self::assertSame('draft', AdminStateFiles::read($path));
        self::assertFalse(file_exists($path));
        AdminStateFiles::delete($path);
        self::assertFalse(AdminStateFiles::exists($path));
        AdminStateFiles::delete($path);
    }
    public function testUserStatesRemainSeparate(): void
    {
        AdminStateFiles::write('data/7001/grs1.txt', 'broadcast1');
        AdminStateFiles::write('data/7002/grs1.txt', 'addBUTTONS');
        self::assertSame('broadcast1', AdminStateFiles::read('data/7001/grs1.txt'));
        self::assertSame('addBUTTONS', AdminStateFiles::read('data/7002/grs1.txt'));
    }
    public function testOperatorDraftOptionsAreIsolatedFromEachOtherAndLegacyGlobalRows(): void
    {
        foreach (['BUTTONS.txt', 'broadcastsend.txt', 'pinmessage.txt', 'page_var.txt'] as $key) {
            AdminStateFiles::write('data/' . $key, 'legacy-global');
            AdminStateFiles::write('data/7001/' . $key, 'operator-one');
            AdminStateFiles::write('data/7002/' . $key, 'operator-two');
            self::assertSame('legacy-global', AdminStateFiles::read('data/' . $key));
            self::assertSame('operator-one', AdminStateFiles::read('data/7001/' . $key));
            self::assertSame('operator-two', AdminStateFiles::read('data/7002/' . $key));
            AdminStateFiles::delete('data/7001/' . $key);
            self::assertFalse(AdminStateFiles::exists('data/7001/' . $key));
            self::assertSame('operator-two', AdminStateFiles::read('data/7002/' . $key));
        }
    }

    public function testMediaEntitiesAndButtonsPreserveExactBytes(): void
    {
        $values = ['data/7001/media.txt' => 'fixture-file-id', 'data/7001/ent.txt' => '[{"_":"messageEntityBold","offset":0,"length":7}]', 'data/BUTTONS.txt' => '[[{"text":"שלום","callback_data":"fixture","style":"primary"}]]'];
        foreach ($values as $path => $value) {
            AdminStateFiles::write($path, $value);
            self::assertSame($value, AdminStateFiles::read($path));
        }
        self::assertSame('json', $this->database->pdo()->query("SELECT value_kind FROM admin_state WHERE state_key='BUTTONS.txt'")->fetchColumn());
    }
    public function testVirtualDirectoriesAndLegacyLineReading(): void
    {
        self::assertTrue(AdminStateFiles::mkdir('data/7001'));
        self::assertTrue(AdminStateFiles::isDirectory('data/7001'));
        AdminStateFiles::write('ids.txt', "7\n8\n9");
        self::assertSame(["7\n","8\n","9"], AdminStateFiles::lines('ids.txt'));
    }
    public function testUnknownPathsFailClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdminStateFiles::write('data/7001/BOT_TOKEN.txt', 'fixture');
    }
    public function testTraversalFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdminStateFiles::read($this->root . '/data/7001/../../.env');
    }
    public function testSiblingRootPrefixCannotEscapeContainment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdminStateFiles::read($this->root . '-other/data/7001/txt.txt');
    }
    public function testTypedRegisteredGroupsViewIsReadonly(): void
    {
        $query = $this->database->pdo()->prepare('INSERT INTO groups VALUES(?,?,?,?,?)');
        $query->execute(['-100123','fixture',1,1,1]);
        $query->execute(['-100999','removed',0,1,1]);
        self::assertSame('-100123', AdminStateFiles::read('data/DBgroups.txt'));
        $this->expectException(InvalidArgumentException::class);
        AdminStateFiles::write('data/DBgroups.txt', '-100777');
    }
    public function testMissingDraftReadThrows(): void
    {
        $this->expectException(RuntimeException::class);
        AdminStateFiles::read('data/7001/media.txt');
    }
}
