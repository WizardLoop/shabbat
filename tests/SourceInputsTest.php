<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SourceInputsTest extends TestCase
{
    private function manifest(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/resources/release/source-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testRuntimeSourceInventoryMatchesTheLockAndHasNoUnownedFiles(): void
    {
        $root = dirname(__DIR__);
        $manifest = $this->manifest();
        self::assertSame(hash_file('sha256', $root . '/composer.lock'), $manifest['composer_lock_sha256']);
        $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $expected = [];
        foreach ($lock['packages'] as $package) {
            $expected[$package['name']] = [$package['version'], $package['source']['reference'] ?? $package['dist']['reference'], $package['license'] ?? []];
        }
        $actual = [];
        $counts = [];
        foreach ($manifest['runtime_packages'] as $package) {
            self::assertArrayNotHasKey($package['name'], $actual);
            $actual[$package['name']] = [$package['version'], $package['reference'], $package['license']];
            $counts[$package['name']] = 0;
        }
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
        self::assertCount($manifest['runtime_package_count'], $actual);
        self::assertCount($manifest['development_packages_omitted'], $lock['packages-dev']);
        foreach ($manifest['files_sha256'] as $path => $hash) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $hash);
            self::assertStringNotContainsString('..', $path);
            if (isset($manifest['preferred_source'][$path])) {
                self::assertStringStartsWith('additional-source/', $path);
                continue;
            }
            self::assertMatchesRegularExpression('~^vendor/[^/]+/[^/]+/.+~D', $path);
            $parts = explode('/', $path);
            $package = $parts[1] . '/' . $parts[2];
            self::assertArrayHasKey($package, $counts);
            $counts[$package]++;
        }
        foreach ($manifest['runtime_packages'] as $package) {
            self::assertSame($package['file_count'], $counts[$package['name']]);
        }
        foreach ($manifest['omitted_public_example_keys'] as $omitted) {
            self::assertArrayNotHasKey($omitted['file'], $manifest['files_sha256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $omitted['sha256']);
        }
    }

    public function testApprovedSourceSymlinksRetainExactUpstreamTargetsWithinLockedPackages(): void
    {
        $manifest = $this->manifest();
        $approved = [
            'vendor/danog/async-orm/docs/index.md',
            'vendor/danog/telegram-entities/docs/index.md',
        ];
        self::assertSame($approved, array_keys($manifest['source_symlinks']));
        foreach ($manifest['source_symlinks'] as $path => $link) {
            self::assertSame(['target', 'mode', 'sha256'], array_keys($link));
            self::assertSame('../README.md', $link['target']);
            self::assertSame('120777', $link['mode']);
            self::assertSame('dbcc210d7f4962499db6d4cfa18658e26d05ee700c962a811cac911f095e22fd', $link['sha256']);
            self::assertSame(hash('sha256', $link['target']), $link['sha256']);
            self::assertSame($manifest['files_sha256'][$path], $link['sha256']);
            self::assertArrayNotHasKey($path, $manifest['preferred_source']);
            self::assertStringNotContainsString('\\', $link['target']);
            self::assertStringNotContainsString(':', $link['target']);
            self::assertStringNotContainsString("\0", $link['target']);
            self::assertFalse(str_starts_with($link['target'], '/'));
            $parts = explode('/', $path);
            $packageRoot = implode('/', array_slice($parts, 0, 3));
            $relative = explode('/', dirname(implode('/', array_slice($parts, 3))));
            foreach (explode('/', $link['target']) as $component) {
                if ($component === '..') {
                    self::assertNotEmpty($relative, 'A source symlink must not escape its locked package.');
                    array_pop($relative);
                } elseif ($component !== '.' && $component !== '') {
                    $relative[] = $component;
                }
            }
            $target = $packageRoot . '/' . implode('/', $relative);
            self::assertArrayHasKey($target, $manifest['files_sha256']);
            self::assertArrayNotHasKey($target, $manifest['source_symlinks'], 'Approved source links resolve directly to regular files.');
        }
    }

    public function testPreferredSourceFilesAndOriginalLicensesMatchApprovedBytes(): void
    {
        $root = dirname(__DIR__);
        $manifest = $this->manifest();
        $expected = [];
        foreach ($manifest['preferred_source'] as $archive => $repository) {
            self::assertStringStartsWith('resources/release/preferred-source/', $repository);
            self::assertNotSame('.gitattributes', basename($repository), 'Upstream attributes must not override immutable checkout bytes.');
            self::assertArrayHasKey($archive, $manifest['files_sha256']);
            self::assertFileExists($root . '/' . $repository);
            self::assertSame($manifest['files_sha256'][$archive], hash_file('sha256', $root . '/' . $repository), $archive);
            $expected[] = $repository;
        }
        $actual = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/resources/release/preferred-source', FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertFalse($file->isLink());
            if ($file->isFile()) {
                $actual[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertCount(92, $actual);
        self::assertArrayHasKey('additional-source/amphp-windows-process-wrapper/LICENSE', $manifest['preferred_source']);
        self::assertArrayHasKey('additional-source/madelineproto-build-inputs/LICENSE', $manifest['preferred_source']);
        self::assertSame('MIT', $manifest['additional_source']['license']);
    }

    public function testMaintainerAttestationAppliesToTheUnmodifiedHistoricalHelperOnly(): void
    {
        $root = dirname(__DIR__);
        $manifest = json_decode((string) file_get_contents($root . '/patches/madelineproto/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $reference = $manifest['original_reference'];
        $attestation = $reference['maintainer_attestation'];
        self::assertSame('2026-10-16', $attestation['date']);
        self::assertSame('AGPL-3.0-only', $attestation['license']);
        self::assertSame('patches/madelineproto/original-reference.php', $attestation['scope']);
        self::assertSame('1ec2691498e878d28ca8e9cde94b00c803d9ccba789096ffc8471156dfb429e9', $reference['sha256']);
        self::assertSame($reference['sha256'], $attestation['sha256']);
        self::assertSame($attestation['sha256'], hash_file('sha256', $root . '/' . $attestation['scope']));
        self::assertStringContainsString('authored', $attestation['authorship']);
        self::assertStringContainsString('authorizes distribution', $attestation['distribution_authorization']);
        self::assertStringContainsString('not an independent', $attestation['limits']);
        self::assertSame('57c8ff33c9c0cfc3ef00e650a1cc910d7ee479a8bc509f6c9209a7c2a11399d6', hash_file('sha256', $root . '/LICENSE'));
        self::assertSame(hash_file('sha256', $root . '/LICENSE'), hash_file('sha256', $root . '/patches/madelineproto-formatted-date/upstream-LICENSE'));
    }
}
