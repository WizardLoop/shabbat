<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shabbat\Runtime\PatchManager;
use Shabbat\Runtime\SingleInstance;

final class FreshPeerPatchTest extends TestCase
{
    private const COMMIT = '7b99dd0f2d964a492c377912ffce9f5ce61aa9ed';
    private string $root;
    private string $vendor;
    private string $target;
    private string $manifest;
    private string $writer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shabbat-fresh-peer-patch-' . bin2hex(random_bytes(8));
        $this->vendor = $this->root . '/vendor';
        $this->target = $this->vendor . '/danog/madelineproto/src/MTProtoTools/PeerHandler.php';
        $this->manifest = $this->root . '/patches/madelineproto-fresh-peer/manifest.json';
        $this->writer = $this->root . '/application.writer.lock';
        mkdir(dirname($this->target), 0700, true);
        mkdir($this->vendor . '/composer', 0700, true);
        mkdir(dirname($this->manifest) . '/baseline', 0700, true);
        $source = dirname(__DIR__) . '/patches/madelineproto-fresh-peer/';
        foreach (['manifest.json', 'fresh-full-peer-response.patch', 'baseline/PeerHandler.php'] as $file) {
            copy($source . $file, dirname($this->manifest) . '/' . $file);
        }
        copy($source . 'baseline/PeerHandler.php', $this->target);
        $this->installed('8.7.0', self::COMMIT);
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
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }

    private function installed(string $version, string $commit): void
    {
        file_put_contents($this->vendor . '/composer/installed.json', json_encode(['packages' => [
            ['name' => 'danog/madelineproto', 'version' => $version, 'source' => ['reference' => $commit]],
        ]], JSON_THROW_ON_ERROR));
    }

    private function installer(): PatchManager
    {
        return new PatchManager($this->root, $this->vendor, $this->manifest, $this->writer);
    }

    private function rejected(callable $operation): void
    {
        $error = null;
        try {
            $operation();
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(RuntimeException::class, $error);
    }

    public function testCleanRepeatedAndVerifyOnlyInstallationMatchReviewedSource(): void
    {
        $this->rejected(fn (): string => $this->installer()->process(false));
        self::assertSame('patched', $this->installer()->process(true));
        $approved = json_decode(file_get_contents($this->manifest), true, 64, JSON_THROW_ON_ERROR)['approved'][0];
        self::assertSame($approved['patched_sha256'], hash_file('sha256', $this->target));
        self::assertSame(file_get_contents(dirname(__DIR__) . '/patches/madelineproto-fresh-peer/reviewed/PeerHandler.php'), file_get_contents($this->target));
        $before = file_get_contents($this->target);
        self::assertSame('already-patched', $this->installer()->process(true));
        self::assertSame('already-patched', $this->installer()->process(false));
        self::assertSame($before, file_get_contents($this->target));
    }

    public function testUnapprovedVersionOrCommitDoNotMutateSource(): void
    {
        $before = file_get_contents($this->target);
        foreach ([['8.6.5', self::COMMIT], ['8.7.0', str_repeat('0', 40)]] as [$version, $commit]) {
            $this->installed($version, $commit);
            $this->rejected(fn (): string => $this->installer()->process(true));
            self::assertSame($before, file_get_contents($this->target));
        }
    }

    public function testUnknownAndPartiallyCorrectedSourceFailClosed(): void
    {
        $baseline = file_get_contents($this->target);
        $partial = str_replace('public function getFullInfo(mixed $id): array', 'public function getFullInfo(mixed $id): array /* partial */', $baseline);
        foreach ([$baseline . "\n// unreviewed bytes\n", $partial] as $changed) {
            file_put_contents($this->target, $changed);
            $this->rejected(fn (): string => $this->installer()->process(true));
            $this->rejected(fn (): string => $this->installer()->process(false));
            self::assertSame($changed, file_get_contents($this->target));
        }
    }

    public function testBaselineAndDiffHashesAreRequiredEvenForAlreadyPatchedTarget(): void
    {
        self::assertSame('patched', $this->installer()->process(true));
        $before = file_get_contents($this->target);
        foreach (['baseline/PeerHandler.php', 'fresh-full-peer-response.patch'] as $artifact) {
            $path = dirname($this->manifest) . '/' . $artifact;
            $original = file_get_contents($path);
            file_put_contents($path, $original . "\n");
            $this->rejected(fn (): string => $this->installer()->process(false));
            $this->rejected(fn (): string => $this->installer()->process(true));
            self::assertSame($before, file_get_contents($this->target));
            file_put_contents($path, $original);
        }
    }

    public function testActiveWriterPreventsSourceMutation(): void
    {
        $before = file_get_contents($this->target);
        $writer = SingleInstance::acquire($this->writer);
        try {
            $this->rejected(fn (): string => $this->installer()->process(true));
            self::assertSame($before, file_get_contents($this->target));
        } finally {
            $writer->release();
        }
        self::assertSame('patched', $this->installer()->process(true));
    }
}
