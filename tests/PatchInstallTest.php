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

require_once dirname(__DIR__) . '/scripts/apply-patches.php';

final class PatchInstallTest extends TestCase
{
    private string $fixture;
    private string $target;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->fixture = sys_get_temp_dir() . '/shabbat-patch-' . bin2hex(random_bytes(8));
        $this->target = $this->fixture . '/danog/madelineproto/src/Loop/Update/UpdateLoop.php';
        mkdir(dirname($this->target), 0700, true);
        mkdir($this->fixture . '/composer');
        copy($this->root . '/patches/madelineproto/baseline/UpdateLoop.php', $this->target);
        $this->installed('8.7.0');
    }

    private function installed(string $version): void
    {
        file_put_contents($this->fixture . '/composer/installed.json', json_encode(['packages' => [['name' => 'danog/madelineproto', 'version' => $version, 'source' => ['reference' => '7b99dd0f2d964a492c377912ffce9f5ce61aa9ed']]]], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->fixture);
    }

    public function testManifestTargetCannotEscapeVendor(): void
    {
        $manifest = $this->fixture . '/test-manifest.json';
        file_put_contents($manifest, json_encode(['package' => 'danog/madelineproto', 'target' => '../outside.php', 'approved' => []], JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contained relative paths');
        $this->manager($manifest);
    }

    public function testUnknownManifestModeIsRejected(): void
    {
        $data = json_decode((string) file_get_contents($this->root . '/patches/madelineproto/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $data['approved'][0]['mode'] = 'unreviewed-mode';
        $manifest = $this->fixture . '/test-manifest.json';
        file_put_contents($manifest, json_encode($data, JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown approved patch mode');
        $this->manager($manifest)->process(true);
    }

    public function testTamperedCorrespondingSourceBaselinePreventsVendorMutation(): void
    {
        $data = json_decode((string) file_get_contents($this->root . '/patches/madelineproto/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        mkdir($this->fixture . '/baseline');
        file_put_contents($this->fixture . '/baseline/UpdateLoop.php', (string) file_get_contents($this->target) . "\n");
        $manifest = $this->fixture . '/test-manifest.json';
        file_put_contents($manifest, json_encode($data, JSON_THROW_ON_ERROR));
        $before = file_get_contents($this->target);
        try {
            $this->manager($manifest)->process(true);
            self::fail('Tampered source baseline was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('baseline hash mismatch', $error->getMessage());
            self::assertSame($before, file_get_contents($this->target));
        }
    }

    public function testUnifiedDiffSupportsImplicitSingleLineCounts(): void
    {
        $diff = "--- a/example.php\n+++ b/example.php\n@@ -1 +1 @@\n-old\n+new\n";
        self::assertSame("new\n", \Shabbat\Runtime\PatchManager::applyDiff("old\n", $diff));
        $diff = str_replace('@@ -1 +1 @@', '@@ -1,1 +1 @@', $diff);
        self::assertSame("new\n", \Shabbat\Runtime\PatchManager::applyDiff("old\n", $diff));
    }

    public function testCleanInstallAndRepeatedApplyAreIdentical(): void
    {
        $manager = $this->manager();
        self::assertSame('patched', $manager->process(true));
        $hash = hash_file('sha256', $this->target);
        self::assertSame('already-patched', $manager->process(true));
        self::assertSame('already-patched', $manager->process(false));
        self::assertSame($hash, hash_file('sha256', $this->target));
    }

    public function testVerificationRefusesUnpatchedInstall(): void
    {
        $this->expectException(RuntimeException::class);
        ($this->manager())->process(false);
    }

    public function testUnknownVersionFailsWithoutWriting(): void
    {
        $this->installed('9.0.0');
        $before = file_get_contents($this->target);
        try {
            ($this->manager())->process(true);
            self::fail('Unknown version accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Unapproved', $error->getMessage());
            self::assertSame($before, file_get_contents($this->target));
        }
    }

    public function testPartialPatchIsRejected(): void
    {
        $manager = $this->manager();
        $manager->process(true);
        file_put_contents($this->target, str_replace('use Amp\\CancelledException;' . "\n", '', (string) file_get_contents($this->target)));
        $this->expectException(RuntimeException::class);
        $manager->process(true);
    }

    public function testModifiedKnownSourceIsRejected(): void
    {
        file_put_contents($this->target, "\n", FILE_APPEND);
        $this->expectException(RuntimeException::class);
        ($this->manager())->process(true);
    }

    private function manager(?string $manifest = null): \Shabbat\Runtime\PatchManager
    {
        return new \Shabbat\Runtime\PatchManager($this->root, $this->fixture, $manifest, $this->fixture . '/installer.writer.lock');
    }

    public function testActiveWriterPreventsPatchMutation(): void
    {
        $writer = \Shabbat\Runtime\SingleInstance::acquire($this->fixture . '/installer.writer.lock');
        $before = file_get_contents($this->target);
        try {
            $this->manager()->process(true);
            self::fail('A live writer must prevent patch application.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Another bot or maintenance', $error->getMessage());
            self::assertSame($before, file_get_contents($this->target));
        } finally {
            $writer->release();
        }
    }

    public function testReadOnlyVerificationCanRunWithActiveWriter(): void
    {
        $this->manager()->process(true);
        $writer = \Shabbat\Runtime\SingleInstance::acquire($this->fixture . '/installer.writer.lock');
        try {
            self::assertSame('already-patched', $this->manager()->process(false));
            self::assertSame('already-patched', $this->manager()->process(true));
        } finally {
            $writer->release();
        }
    }

    public function testReviewedOfficialModeIsNoOp(): void
    {
        $manifest = $this->fixture . '/test-manifest.json';
        file_put_contents($manifest, json_encode(['approved' => [['version' => '8.7.0', 'commit' => '7b99dd0f2d964a492c377912ffce9f5ce61aa9ed', 'mode' => 'official', 'sha256' => hash_file('sha256', $this->target)]]], JSON_THROW_ON_ERROR));
        $before = file_get_contents($this->target);
        self::assertSame('official-verified', ($this->manager($manifest))->process(true));
        self::assertSame($before, file_get_contents($this->target));
        // Fixture exercises the future manifest path. Current release is not official-fixed.
    }
}
