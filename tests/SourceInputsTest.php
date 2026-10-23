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
        self::assertCount(105, $actual);
        self::assertArrayHasKey('additional-source/amphp-windows-process-wrapper/LICENSE', $manifest['preferred_source']);
        self::assertArrayHasKey('additional-source/madelineproto-build-inputs/LICENSE', $manifest['preferred_source']);
        self::assertSame('MIT', $manifest['additional_source']['license']);
        self::assertSame('f328e6b5682d7e885dcfab425839921312162008', $manifest['rtc_source_provenance']['reference']);
        $rtc = $manifest['rtc_source_provenance']['source_files'][0];
        $bytes = (string) file_get_contents($root . '/' . $manifest['preferred_source'][$rtc['path']]);
        self::assertSame('811f02bddfc3c0fb6954f0f418d58215e78219c5f479cac60fbe2fbc398e9ae4', hash('sha256', $bytes));
        self::assertSame($rtc['git_blob'], sha1('blob ' . strlen($bytes) . "\0" . $bytes));
    }

    public function testPreferredGeneratorInputsBindToTheLockedSdkAndOriginalGitBlobs(): void
    {
        $root = dirname(__DIR__);
        $manifest = $this->manifest();
        self::assertSame(83, $manifest['runtime_package_count']);
        self::assertSame(4095, array_sum(array_column($manifest['runtime_packages'], 'file_count')));
        $source = $manifest['madeline_generation_source'];
        self::assertSame('49d3d8460257c7bfb1a7e12708e1bfdd59e898c1', $source['reference']);
        self::assertSame('8.11.10', $source['upstream_provenance']['version']);
        self::assertSame(84, $source['file_count']);
        self::assertCount(84, $source['upstream_provenance']['source_files']);
        $paths = [];
        foreach ($source['upstream_provenance']['source_files'] as $file) {
            $archive = 'additional-source/madelineproto-build-inputs/' . $file['path'];
            self::assertArrayNotHasKey($file['path'], $paths);
            $paths[$file['path']] = true;
            $bytes = (string) file_get_contents($root . '/' . $manifest['preferred_source'][$archive]);
            self::assertSame($file['sha256'], hash('sha256', $bytes));
            self::assertSame($file['git_blob'], sha1('blob ' . strlen($bytes) . "\0" . $bytes));
            self::assertSame($file['bytes'], strlen($bytes));
            self::assertStringContainsString('/' . $file['reference'] . '/', $file['url']);
        }
        foreach (
            ['tools/build_tl.php', 'tools/TL/Builder.php', 'tools/TL/SecretBuilder.php',
            'tools/gen_filerefmap.php', 'tools/FileRefExtractor/FileRefGenerator.php',
            'tools/translator.php', 'langs/en.json', 'schemas/TL_telegram_v232.tl',
            'schemas/TL_mtproto_v1.tl', 'schemas/TL_secret.tl', 'docs/template/disallow.json', 'LICENSE'] as $required
        ) {
            self::assertArrayHasKey($required, $paths);
        }
    }

    public function testOnlyActivePatchOutputsArePinnedAndRetiredSourceIsAbsent(): void
    {
        $root = dirname(__DIR__);
        $manifest = $this->manifest();
        $actual = array_map('basename', glob($root . '/patches/*', GLOB_ONLYDIR));
        sort($actual);
        self::assertSame(['broadcastmanager', 'madelineproto-fresh-peer'], $actual);
        foreach ($actual as $name) {
            $patch = json_decode((string) file_get_contents($root . '/patches/' . $name . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $approved = $patch['approved'][0];
            $path = 'vendor/' . $patch['package'] . '/' . $patch['target'];
            self::assertSame($approved['patched_sha256'], $manifest['files_sha256'][$path]);
            self::assertSame($approved['original_sha256'], hash_file('sha256', $root . '/patches/' . $name . '/' . $approved['baseline']));
            self::assertSame($approved['patched_sha256'], hash_file('sha256', $root . '/patches/' . $name . '/' . $approved['reviewed_output']));
        }
        self::assertSame('57c8ff33c9c0cfc3ef00e650a1cc910d7ee479a8bc509f6c9209a7c2a11399d6', hash_file('sha256', $root . '/LICENSE'));
    }

    public function testDockerIgbinarySourceAndLicenseArePinnedInTheSourceOffer(): void
    {
        $root = dirname(__DIR__);
        $manifest = $this->manifest();
        $source = $manifest['igbinary_source'];
        self::assertSame('3.2.16', $source['version']);
        self::assertSame('BSD-3-Clause', $source['license']);
        self::assertSame('8bf25d465abc7973d9e2c9a3039a5f8eea635b23bc1477017ff3999ff95836da', $source['sha256']);
        self::assertSame($source['sha256'], $manifest['files_sha256'][$source['archive']]);
        self::assertArrayHasKey($source['license_file'], $manifest['preferred_source']);
        $docker = (string) file_get_contents($root . '/Dockerfile');
        self::assertStringContainsString($source['source_url'], $docker);
        self::assertStringContainsString($source['sha256'], $docker);
        self::assertStringContainsString('docker-php-ext-enable igbinary', $docker);
        self::assertStringContainsString('php bin/igbinary-check.php', $docker);
        self::assertStringContainsString('Redistribution and use', (string) file_get_contents($root . '/' . $manifest['preferred_source'][$source['license_file']]));
    }
}
