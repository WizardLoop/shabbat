<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Shabbat\Runtime\BuildMetadata;
use Shabbat\Runtime\ReleaseInputs;

final class BuildMetadataTest extends TestCase
{
    private string $fixture;
    private const COMMIT = '2976ab8339f489d933c7246e0cb27f3b0e4aa697';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/shabbat-metadata-' . bin2hex(random_bytes(8));
        foreach (ReleaseInputs::REQUIRED as $relative) {
            $path = $this->fixture . '/' . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, $relative === 'VERSION' ? "3.0.0-dev\n" : "fixture public source\n");
        }
    }

    protected function tearDown(): void
    {
        $resolved = realpath($this->fixture);
        if ($resolved === false || !str_starts_with($resolved, realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'shabbat-metadata-')) {
            throw new RuntimeException('Metadata test cleanup target escaped its fixture.');
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($resolved);
    }

    private function archiveMetadata(): array
    {
        $metadata = ['commit' => self::COMMIT, 'application_source_sha256' => ReleaseInputs::fingerprint(ReleaseInputs::application($this->fixture)), 'local_changes' => true, 'origin' => 'verified local archive'];
        file_put_contents($this->fixture . '/build-info.json', json_encode($metadata, JSON_THROW_ON_ERROR));
        return $metadata;
    }

    public function testArchiveFallbackPreservesSourceFingerprintAndLocalChangeEvidence(): void
    {
        $before = $this->archiveMetadata();
        $after = BuildMetadata::write($this->fixture);
        self::assertSame($before['commit'], $after['commit']);
        self::assertSame($before['application_source_sha256'], $after['application_source_sha256']);
        self::assertSame($before['local_changes'], $after['local_changes']);
        self::assertSame($before['origin'], $after['origin']);
        self::assertSame('3.0.0-dev', $after['version']);
    }

    public function testExplicitCheckoutCommitIsCanonicalAndSourceFingerprintIsActual(): void
    {
        $metadata = BuildMetadata::write($this->fixture, strtoupper(self::COMMIT));
        self::assertSame(self::COMMIT, $metadata['commit']);
        self::assertSame(ReleaseInputs::fingerprint(ReleaseInputs::application($this->fixture)), $metadata['application_source_sha256']);
        self::assertTrue($metadata['local_changes']);
    }

    public function testCheckoutWithoutExplicitCommitFailsBeforeWriting(): void
    {
        try {
            BuildMetadata::write($this->fixture);
            self::fail('Unknown checkout commit was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('explicit 40-hex', $error->getMessage());
            self::assertFileDoesNotExist($this->fixture . '/build-info.json');
        }
    }

    public function testInvalidCommitCannotOverwriteRetainedMetadata(): void
    {
        $this->archiveMetadata();
        $before = file_get_contents($this->fixture . '/build-info.json');
        try {
            BuildMetadata::write($this->fixture, self::COMMIT . "\n");
            self::fail('Invalid commit was accepted.');
        } catch (RuntimeException) {
            self::assertSame($before, file_get_contents($this->fixture . '/build-info.json'));
        }
    }

    public function testModifiedArchiveSourceCannotKeepAnOldFingerprint(): void
    {
        $this->archiveMetadata();
        $before = file_get_contents($this->fixture . '/build-info.json');
        file_put_contents($this->fixture . '/bot.php', "source changed\n", FILE_APPEND);
        try {
            BuildMetadata::write($this->fixture);
            self::fail('Changed source with stale fingerprint was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('differs from retained archive', $error->getMessage());
            self::assertSame($before, file_get_contents($this->fixture . '/build-info.json'));
        }
    }
}
