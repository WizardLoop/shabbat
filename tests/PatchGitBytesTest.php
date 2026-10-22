<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class PatchGitBytesTest extends TestCase
{
    private string $fixture;
    private string $git;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/shabbat-git-bytes-' . bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700);
        $this->git = PHP_OS_FAMILY === 'Windows' && is_file('C:/Program Files/Git/cmd/git.exe') ? 'C:/Program Files/Git/cmd/git.exe' : 'git';
        $this->command(['init', '--bare', $this->fixture . '/git']);
    }

    protected function tearDown(): void
    {
        $resolved = realpath($this->fixture);
        if ($resolved === false || !str_starts_with($resolved, realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'shabbat-git-bytes-')) {
            throw new RuntimeException('Git-byte fixture cleanup escaped its isolated directory.');
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($resolved);
    }

    private function command(array $arguments, string $input = ''): string
    {
        $process = proc_open([$this->git, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch Git byte verification.');
        }
        $offset = 0;
        while ($offset < strlen($input)) {
            $written = fwrite($pipes[0], substr($input, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to stream binary patch source to Git.');
            }
            $offset += $written;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $errors);
        return trim((string) $output);
    }

    public function testAutocrlfCannotAlterReviewedPatchOrPublisherLicenseBytes(): void
    {
        $root = dirname(__DIR__);
        $files = ['LICENSES/CC-BY-4.0.txt', 'LICENSES/CC-BY-SA-4.0.txt'];
        foreach (['madelineproto-fresh-peer', 'broadcastmanager'] as $library) {
            $manifest = json_decode((string) file_get_contents($root . '/patches/' . $library . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $entry = $manifest['approved'][0];
            foreach (['baseline' => 'original_sha256', 'patch' => 'patch_sha256'] as $kind => $hashField) {
                $relative = 'patches/' . $library . '/' . $entry[$kind];
                self::assertSame($entry[$hashField], hash_file('sha256', $root . '/' . $relative));
                $files[] = $relative;
            }
        }
        $broadcast = json_decode((string) file_get_contents($root . '/patches/broadcastmanager/manifest.json'), true, flags: JSON_THROW_ON_ERROR)['approved'][0];
        $broadcastSources = [[$broadcast['reviewed_output'], $broadcast['patched_sha256']]];
        foreach ($broadcast['intermediates'] ?? [] as $intermediate) {
            $broadcastSources[] = [$intermediate['source'], $intermediate['sha256']];
            $broadcastSources[] = [$intermediate['patch'], $intermediate['patch_sha256']];
        }
        foreach ($broadcastSources as [$file, $sha256]) {
            $relative = 'patches/broadcastmanager/' . $file;
            self::assertSame($sha256, hash_file('sha256', $root . '/' . $relative));
            $files[] = $relative;
        }
        foreach ($files as $relative) {
            $bytes = (string) file_get_contents($root . '/' . $relative);
            $expected = hash('sha1', 'blob ' . strlen($bytes) . "\0" . $bytes);
            $filtered = $this->command([
                '--git-dir=' . $this->fixture . '/git', '--work-tree=' . $root,
                '-c', 'core.bare=false', '-c', 'core.autocrlf=true',
                'hash-object', '--path=' . $relative, '--stdin',
            ], $bytes);
            self::assertSame($expected, $filtered, 'Git normalization changed reviewed bytes: ' . $relative);
        }
        self::assertFileDoesNotExist($this->fixture . '/git/index');
        self::assertSame([], glob($this->fixture . '/git/objects/[0-9a-f][0-9a-f]'));
    }

    public function testAutocrlfCannotAlterMigrationOrBundledResourceBytes(): void
    {
        $root = dirname(__DIR__);
        $files = [
            'resources/templates/omer-counts.json',
            'resources/templates/omer-counts-localized.json',
            'resources/templates/omer-liturgy.json',
            'tests/Fixtures/CalendarFixtureClient.php',
            'tests/Fixtures/hebcal-full-runtime-provenance.json',
            'tests/Fixtures/legacy/data/-100101/chatb1.json',
        ];
        foreach (glob($root . '/migrations/*.sql') ?: [] as $migration) {
            $files[] = 'migrations/' . basename($migration);
        }
        self::assertGreaterThan(6, count($files));
        foreach ($files as $relative) {
            $bytes = (string) file_get_contents($root . '/' . $relative);
            $expected = hash('sha1', 'blob ' . strlen($bytes) . "\0" . $bytes);
            foreach (['true', 'false'] as $autocrlf) {
                $filtered = $this->command([
                    '--git-dir=' . $this->fixture . '/git', '--work-tree=' . $root,
                    '-c', 'core.bare=false', '-c', 'core.autocrlf=' . $autocrlf,
                    'hash-object', '--path=' . $relative, '--stdin',
                ], $bytes);
                self::assertSame($expected, $filtered, 'Git normalization changed immutable bytes: ' . $relative);
            }
        }
    }
}
