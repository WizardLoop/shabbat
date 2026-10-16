<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\EventHandler\Media\GeoPoint;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Shabbat\Runtime\PatchManager;
use Shabbat\Runtime\SingleInstance;

final class GeoPointPatchTest extends TestCase
{
    private const COMMIT = '7b99dd0f2d964a492c377912ffce9f5ce61aa9ed';
    private string $root;
    private string $vendor;
    private string $target;
    private string $manifest;
    private string $writer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shabbat-geopoint-patch-' . bin2hex(random_bytes(8));
        $this->vendor = $this->root . '/vendor';
        $this->target = $this->vendor . '/danog/madelineproto/src/EventHandler/Media/GeoPoint.php';
        $this->manifest = $this->root . '/patches/madelineproto-geopoint/manifest.json';
        $this->writer = $this->root . '/application.writer.lock';
        mkdir(dirname($this->target), 0700, true);
        mkdir($this->vendor . '/composer', 0700, true);
        mkdir(dirname($this->manifest) . '/baseline', 0700, true);
        $source = dirname(__DIR__) . '/patches/madelineproto-geopoint/';
        foreach (['manifest.json', 'geopoint-double-coordinates.patch', 'baseline/GeoPoint.php'] as $file) {
            copy($source . $file, dirname($this->manifest) . '/' . $file);
        }
        copy($source . 'baseline/GeoPoint.php', $this->target);
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
        self::assertSame(file_get_contents(dirname(__DIR__) . '/patches/madelineproto-geopoint/reviewed/GeoPoint.php'), file_get_contents($this->target));
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
        $partial = str_replace('public readonly int $long;', 'public readonly float $long;', $baseline);
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
        foreach (['baseline/GeoPoint.php', 'geopoint-double-coordinates.patch'] as $artifact) {
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

    public function testPristineConstructorReproducesFractionalCoordinateTypeError(): void
    {
        $code = <<<'PHP'
require $argv[1];
try {
    new \danog\MadelineProto\EventHandler\Media\GeoPoint(['long' => 35.235, 'lat' => 31.778, 'access_hash' => 0]);
} catch (\TypeError $error) {
    echo 'confirmed-type-error';
    exit(0);
}
exit(1);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, dirname($this->manifest) . '/baseline/GeoPoint.php'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame('confirmed-type-error', $output);
    }

    public function testActualPatchedConstructorPreservesFiniteCoordinatesAndJsonRoundtrip(): void
    {
        self::assertSame('float', (string)(new ReflectionProperty(GeoPoint::class, 'long'))->getType());
        self::assertSame('float', (string)(new ReflectionProperty(GeoPoint::class, 'lat'))->getType());
        foreach ([[31.778, 35.235], [-33.8688, -151.2093], [0.0, 0.0], [90.0, -180.0], [0, 0]] as [$latitude, $longitude]) {
            $geo = new GeoPoint(['long' => $longitude, 'lat' => $latitude, 'access_hash' => 123, 'accuracy_radius' => 7]);
            self::assertSame((float)$latitude, $geo->lat);
            self::assertSame((float)$longitude, $geo->long);
            self::assertTrue(is_finite($geo->lat) && is_finite($geo->long));
            self::assertSame(123, $geo->accessHash);
            self::assertSame(7, $geo->accuracyRadius);
            $json = json_decode(json_encode($geo, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame((float)$latitude, $json['lat']);
            self::assertSame((float)$longitude, $json['long']);
            $roundtrip = new GeoPoint(['long' => $json['long'], 'lat' => $json['lat'], 'access_hash' => $json['accessHash'], 'accuracy_radius' => $json['accuracyRadius']]);
            self::assertSame($geo->jsonSerialize(), $roundtrip->jsonSerialize());
        }
        self::assertNull((new GeoPoint(['long' => 0, 'lat' => 0, 'access_hash' => 0]))->accuracyRadius);
    }
}
