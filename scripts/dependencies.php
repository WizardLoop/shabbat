<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
$root = dirname(__DIR__);
$output = $root . '/docs/dependencies.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
        if ($output === '') {
            throw new InvalidArgumentException('Dependency report output must be a file path.');
        }
    } elseif ($argument !== '--check') {
        throw new InvalidArgumentException('Unknown dependency inventory argument.');
    }
}
$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$report = ['schema_version' => 1, 'lock_sha256' => hash_file('sha256', $root . '/composer.lock'), 'packages' => [], 'services_and_assets' => [
    ['name' => 'Hebcal', 'kind' => 'data service', 'terms' => 'https://www.hebcal.com/home/195/jewish-calendar-rest-api', 'status' => 'Review separate data terms and attribution in THIRD_PARTY_NOTICES.md'],
    ['name' => 'GeoNames', 'kind' => 'data service', 'terms' => 'https://www.geonames.org/about.html', 'status' => 'Account and quotas required; data attribution independent of application AGPL'],
]];
foreach (['omer-counts.json' => 'CC-BY-4.0', 'omer-counts-localized.json' => 'CC-BY-4.0', 'omer-liturgy.json' => 'CC-BY-SA-4.0'] as $filename => $license) {
    $relative = 'resources/templates/' . $filename;
    $absolute = $root . '/' . $relative;
    if (!is_file($absolute)) {
        throw new RuntimeException('Missing required bundled data resource: ' . $relative);
    }
    $metadata = json_decode((string) file_get_contents($absolute), true, flags: JSON_THROW_ON_ERROR);
    if (($metadata['license'] ?? $license) !== $license) {
        throw new RuntimeException('Bundled resource license differs from its reviewed grant: ' . $relative);
    }
    $licenseFile = 'LICENSES/' . $license . '.txt';
    $licenseHash = match ($license) {
        'CC-BY-4.0' => '9ba9550ad48438d0836ddab3da480b3b69ffa0aac7b7878b5a0039e7ab429411',
        'CC-BY-SA-4.0' => '28a9529c7d0bb4dc51f4bf5c116a3d16ef247a052f7591466768ddf563fd1cf5',
    };
    if (!is_file($root . '/' . $licenseFile) || !hash_equals($licenseHash, (string) hash_file('sha256', $root . '/' . $licenseFile))) {
        throw new RuntimeException('Bundled data license text is missing or differs from its reviewed publisher text.');
    }
    $report['services_and_assets'][] = [
        'name' => $filename,
        'kind' => 'bundled data resource',
        'file' => $relative,
        'sha256' => hash_file('sha256', $absolute),
        'spdx' => $license,
        'license_files' => [['file' => $licenseFile, 'sha256' => $licenseHash]],
        'attribution' => $metadata['attribution'] ?? '',
        'source' => $metadata['source'] ?? $metadata['sources'] ?? [],
        'retrieved_at' => $metadata['retrieved_at'] ?? null,
        'notes' => $metadata['notes'] ?? $metadata['note'] ?? '',
        'status' => 'Bundled metadata recorded; preserve data license and attribution separately from the application AGPL.',
    ];
}
$report['local_patches'] = [];
foreach (['madelineproto-fresh-peer', 'broadcastmanager'] as $library) {
    $relative = 'patches/' . $library . '/manifest.json';
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Missing required local patch manifest: ' . $relative);
    }
    $manifest = json_decode((string) file_get_contents($root . '/' . $relative), true, flags: JSON_THROW_ON_ERROR);
    $report['local_patches'][] = [
        'manifest' => $relative,
        'sha256' => hash_file('sha256', $root . '/' . $relative),
        'package' => $manifest['package'] ?? 'danog/madelineproto',
        'target' => $manifest['target'] ?? 'src/Loop/Update/UpdateLoop.php',
        'approved' => $manifest['approved'],
        'notes' => 'Locally modified library source and its immutable baseline/diff ship in the corresponding-source archive; preserve upstream notices.',
    ];
}
$sqlite = (string) (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
$report['external_runtime'] = [['name' => 'SQLite', 'php_loaded_version' => $sqlite, 'license' => 'public-domain', 'terms' => 'https://sqlite.org/copyright.html', 'wal_fix_verified' => version_compare($sqlite, '3.51.3', '>=') || in_array($sqlite, ['3.44.6', '3.50.7'], true), 'docker_source_version' => '3.51.3', 'docker_source_url' => 'https://www.sqlite.org/2026/sqlite-autoconf-3510300.tar.gz', 'docker_source_sha256' => '81f5be397049b0cae1b167f2225af7646fc0f82e4a9b3c48c9ea3a533e21d77a', 'docker_build_status' => 'NOT VERIFIED locally: Docker unavailable on test host']];
foreach (['packages' => 'runtime', 'packages-dev' => 'dev'] as $section => $scope) {
    foreach ($lock[$section] ?? [] as $package) {
        $name = $package['name'];
        $directory = $root . '/vendor/' . $name;
        $licenses = [];
        if (is_dir($directory)) {
            foreach (new DirectoryIterator($directory) as $file) {
                if ($file->isFile() && preg_match('/^(?:licen[cs]e|copying|notice)(?:[._-].*)?$/i', $file->getFilename())) {
                    $licenses[] = ['file' => 'vendor/' . $name . '/' . $file->getFilename(), 'sha256' => hash_file('sha256', $file->getPathname())];
                }
            }
        }
        $metadata = $package['license'] ?? [];
        $findings = [];
        $text = '';
        foreach ($licenses as $license) {
            $text .= file_get_contents($root . '/' . $license['file']);
        }
        $verified = false;
        foreach ($metadata as $spdx) {
            $verified = $verified || match ($spdx) {
                'MIT' => str_contains($text, 'Permission is hereby granted'),
                'BSD-3-Clause', 'BSD-2-Clause' => str_contains($text, 'Redistribution and use'),
                'Apache-2.0' => str_contains($text, 'Apache License'),
                'AGPL-3.0-only', 'AGPL-3.0-or-later' => str_contains($text, 'GNU AFFERO GENERAL PUBLIC LICENSE') || str_contains($text, 'GNU Affero General Public License'),
                'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0-only' => str_contains($text, 'GNU LESSER GENERAL PUBLIC LICENSE'),
                'ISC' => str_contains($text, 'Permission to use, copy, modify'),
                'Unlicense' => str_contains($text, 'public domain'),
                default => false,
            };
        }
        if (!$verified) {
            $findings[] = 'NOT VERIFIED: declared SPDX not confirmed against a distributed root license text; release review required.';
        }
        if ($name === 'danog/php-rtc-av') {
            $findings[] = 'Metadata declares MIT, but distributed LICENSE and PHP source headers state BSD-3-Clause. Preserve both upstream texts; the MIT declaration is not confirmed by the distributed license.';
        }
        if ($name === 'danog/madelineproto') {
            $findings[] = 'Metadata says AGPL-3.0-only; source headers grant version 3 or later. Preserve upstream grant and report mismatch; application license remains AGPL-3.0-only.';
        }
        $source = $package['source'] ?? $package['dist'] ?? [];
        $report['packages'][] = [
            'name' => $name, 'version' => $package['version'], 'direct' => isset($composer['require'][$name]) || isset($composer['require-dev'][$name]),
            'scope' => $scope, 'spdx' => $metadata, 'source_url' => $source['url'] ?? '', 'source_commit' => $source['reference'] ?? '',
            'source_permalink' => isset($source['url'], $source['reference']) ? preg_replace('/\.git$/', '', $source['url']) . '/tree/' . $source['reference'] : '',
            'license_files' => $licenses, 'attribution' => 'Preserve distributed license and copyright notices; additional source obligations depend on the declared license.',
            'status' => $verified ? 'LICENSE_TEXT_PRESENT' : 'NOT VERIFIED', 'findings' => $findings,
        ];
    }
}
if (!is_dir(dirname($output))) {
    mkdir(dirname($output), 0755, true);
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
if (in_array('--check', $argv, true)) {
    $saved = $output;
    if (!is_file($saved) || file_get_contents($saved) !== $json) {
        fwrite(STDERR, "Dependency report differs; regenerate with composer dependencies.\n");
        exit(1);
    }
} else {
    if (file_put_contents($output, $json) !== strlen($json)) {
        throw new RuntimeException('Unable to write dependency inventory.');
    }
}
echo 'Dependency inventory: ' . count($report['packages']) . " locked packages. License text checks are evidence, not a legal certification.\n";
