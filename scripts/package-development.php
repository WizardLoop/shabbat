<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once __DIR__ . '/apply-patches.php';
\Shabbat\Runtime\PatchSet::process($root, false);
if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('PHP zip extension is required for archive generation.');
}
$version = Shabbat\Runtime\Version::current($root);
if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9.-]+)?$/i', $version)) {
    throw new RuntimeException('VERSION must be a safe SemVer string.');
}
$files = \Shabbat\Runtime\ReleaseInputs::application($root);
$approvedExampleKeys = [
    'vendor/amphp/http-server/tools/tls/localhost.pem' => '7a2aa6d828415a02c060a2e9377e5402fa6039f4e3392d0c1a308221962b357d',
    'vendor/amphp/http-server/tools/tls/localhost.key.pem' => '1e11dc00c61adae8180d95a8e1774d2910cab3459c4744b2f6567f061e094eb0',
];
$omittedExampleKeys = array_keys($approvedExampleKeys);
$exampleSource = [
    'package' => 'amphp/http-server',
    'version' => 'v3.4.7',
    'commit' => 'aa96ca38c5f822a827236543d5417d0c2a782ef0',
    'permalink' => 'https://github.com/amphp/http-server/tree/aa96ca38c5f822a827236543d5417d0c2a782ef0',
];
if (Composer\InstalledVersions::getReference($exampleSource['package']) !== $exampleSource['commit']) {
    throw new RuntimeException('Review the immutable source of development TLS example-key omissions for this dependency version.');
}
$omissionEvidence = [];
foreach ($approvedExampleKeys as $path => $sha256) {
    if (is_file($root . '/' . $path) && hash_file('sha256', $root . '/' . $path) !== $sha256) {
        throw new RuntimeException('Unknown content at a reviewed development example-key path: ' . $path);
    }
    $omissionEvidence[] = ['file' => $path, 'sha256' => $sha256, 'source' => $exampleSource, 'reason' => 'Public upstream development TLS example key; not required to build or run the bot.'];
}
$forbidden = static function (string $path) use ($omittedExampleKeys): bool {
    if (in_array($path, $omittedExampleKeys, true)) {
        return true;
    }
    foreach (explode('/', $path) as $segment) {
        if (
            $segment === '.git' || $segment === '.env' || ($segment === 'data' && !str_starts_with($path, 'tests/Fixtures/legacy/data/')) || $segment === 'runtime'
            || $segment === 'logs' || $segment === 'node_modules' || str_ends_with($segment, '.madeline')
            || preg_match('/\.(?:sqlite|db)(?:-wal|-shm)?$/i', $segment)
        ) {
            return true;
        }
    }
    return str_contains($path, 'bot.shabbat') || (str_contains($path, '.env.') && !str_ends_with($path, '.env.example'));
};
$scan = static function (string $contents, string $path): void {
    if (
        preg_match('/^-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----$/m', $contents)
        || preg_match('/(?:BOT_TOKEN|API_HASH)\s*=\s*["\x27]?[0-9a-f]{32,}/i', $contents)
        || preg_match('/\b[0-9]{6,12}:[A-Za-z0-9_-]{30,}\b/', $contents)
    ) {
        throw new RuntimeException('Potential private credential in archive input: ' . $path);
    }
};
foreach ($files as $path => $absolute) {
    if ($forbidden($path)) {
        throw new RuntimeException('Forbidden runtime file in archive inputs: ' . $path);
    }
    $scan((string) file_get_contents($absolute), $path);
}
ksort($files);
$sourceFiles = $files;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/vendor', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || $file->isLink()) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (!$forbidden($relative) && !str_contains($relative, '/composer/tmp-') && !str_ends_with($relative, '/write-check.txt')) {
        $sourceFiles[$relative] = $file->getPathname();
    }
}
ksort($sourceFiles);
$hashes = [];
foreach ($sourceFiles as $path => $absolute) {
    $hashes[$path] = hash_file('sha256', $absolute);
}
$manifest = ['version' => $version, 'commit' => Shabbat\Runtime\Version::commit($root), 'application_source_sha256' => hash('sha256', json_encode(array_intersect_key($hashes, $files), JSON_THROW_ON_ERROR)), 'review_status' => 'local development bundle, not the scoped corresponding-source release offer', 'source_files' => $hashes, 'omitted_public_example_keys' => $omissionEvidence, 'excludes' => ['production data', 'secrets', 'sessions', '.git'], 'patches' => ['madelineproto' => json_decode((string) file_get_contents($root . '/patches/madelineproto/manifest.json'), true, flags: JSON_THROW_ON_ERROR), 'madelineproto-geopoint' => json_decode((string) file_get_contents($root . '/patches/madelineproto-geopoint/manifest.json'), true, flags: JSON_THROW_ON_ERROR), 'madelineproto-formatted-date' => json_decode((string) file_get_contents($root . '/patches/madelineproto-formatted-date/manifest.json'), true, flags: JSON_THROW_ON_ERROR), 'madelineproto-fresh-peer' => json_decode((string) file_get_contents($root . '/patches/madelineproto-fresh-peer/manifest.json'), true, flags: JSON_THROW_ON_ERROR), 'broadcastmanager' => json_decode((string) file_get_contents($root . '/patches/broadcastmanager/manifest.json'), true, flags: JSON_THROW_ON_ERROR)]];
if (!is_dir($root . '/dist/development')) {
    mkdir($root . '/dist/development', 0700, true);
}
$metadata = json_encode(['commit' => $manifest['commit'], 'application_source_sha256' => $manifest['application_source_sha256'], 'local_changes' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
$archive = static function (string $name, array $entries, string $kind) use ($root, $manifest, $metadata, $forbidden, $scan): string {
    $hasDevelopmentTools = isset($entries['vendor/phpunit/phpunit/phpunit'], $entries['vendor/phpstan/phpstan/phpstan'], $entries['vendor/squizlabs/php_codesniffer/bin/phpcs']);
    if ($kind === 'server-test' && !$hasDevelopmentTools) {
        throw new RuntimeException('The server-test archive requires the locked installed offline verification tools.');
    }
    $finalPath = $root . '/dist/development/' . $name;
    $path = $finalPath . '.tmp-' . bin2hex(random_bytes(6));
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to open release archive.');
    }
    foreach ($entries as $relative => $absolute) {
        if (!$zip->addFile($absolute, $relative)) {
            throw new RuntimeException('Unable to add archive input: ' . $relative);
        }
    }
    $archiveManifest = $manifest;
    $archiveManifest['artifact_kind'] = $kind;
    $archiveManifest['includes_installed_dependencies'] = $kind !== 'application';
    $archiveManifest['includes_development_tools'] = $hasDevelopmentTools;
    $archiveManifest['setup_guide'] = 'docs/SERVER_TEST_DEPLOYMENT.md';
    $zip->addFromString('release-manifest.json', json_encode($archiveManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $zip->addFromString('build-info.json', $metadata);
    $zip->close();
    $check = new ZipArchive();
    if ($check->open($path) !== true) {
        throw new RuntimeException('Generated archive could not be reopened.');
    }
    for ($index = 0; $index < $check->numFiles; $index++) {
        $entry = $check->getNameIndex($index);
        if (!is_string($entry) || $forbidden($entry)) {
            throw new RuntimeException('Forbidden entry in final archive.');
        }
        $contents = $check->getFromIndex($index);
        if (!is_string($contents)) {
            throw new RuntimeException('Unreadable archive entry.');
        }
        $scan($contents, $entry);
        if (isset($entries[$entry]) && (hash('sha256', $contents) !== $manifest['source_files'][$entry] || hash_file('sha256', $entries[$entry]) !== $manifest['source_files'][$entry])) {
            throw new RuntimeException('Archive entry checksum mismatch: ' . $entry);
        }
    }
    $check->close();
    if (!rename($path, $finalPath)) {
        throw new RuntimeException('Unable to publish verified local archive atomically.');
    }
    return $finalPath;
};
$application = $archive('shabbat-' . $version . '.zip', $files, 'application');
$source = $archive('shabbat-' . $version . '-corresponding-source.zip', $sourceFiles, 'corresponding-source');
$server = $archive('shabbat-' . $version . '-server-test.zip', $sourceFiles, 'server-test');
$artifacts = [$application, $source, $server];
$checksums = '';
foreach ($artifacts as $artifact) {
    $checksums .= hash_file('sha256', $artifact) . '  ' . basename($artifact) . "\n";
}
file_put_contents($root . '/dist/development/SHA256SUMS', $checksums);
$manifest['artifacts'] = array_map(static fn (string $artifact): array => ['file' => basename($artifact), 'sha256' => hash_file('sha256', $artifact)], $artifacts);
file_put_contents($root . '/dist/development/release-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'Created and scanned ' . implode(', ', array_map('basename', $artifacts)) . ".\n";
