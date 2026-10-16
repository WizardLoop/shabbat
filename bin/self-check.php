<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/scripts/apply-patches.php';
$failures = [];
$warnings = [];
foreach (['pdo_sqlite', 'mbstring', 'xml', 'dom', 'fileinfo', 'zlib'] as $extension) {
    if (!extension_loaded($extension)) {
        $failures[] = 'Missing PHP extension ' . $extension;
    }
}
if (PHP_INT_SIZE !== 8 || PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80600) {
    $failures[] = 'Supported platform is 64-bit PHP 8.3, 8.4 or 8.5.';
}
try {
    $pdo = new PDO('sqlite::memory:');
    $sqlite = $pdo->query('SELECT sqlite_version()')->fetchColumn();
    if (version_compare((string) $sqlite, '3.35.0', '<')) {
        $failures[] = 'SQLite >=3.35 is required for supported database operations.';
    }
    $walFixed = version_compare((string) $sqlite, '3.51.3', '>=') || ((string) $sqlite === '3.44.6') || ((string) $sqlite === '3.50.7');
    if (!$walFixed) {
        $message = 'Loaded SQLite predates the verified WAL-reset fix. Use >=3.51.3 or a reviewed backport for production.';
        if (in_array('--release', $argv, true)) {
            $failures[] = $message;
        } else {
            $warnings[] = $message;
        }
    }
} catch (Throwable $error) {
    $sqlite = 'unavailable';
    $failures[] = 'SQLite connection failed.';
}
try {
    $patches = \Shabbat\Runtime\PatchSet::process($root, false);
    $patch = $patches['madelineproto'];
} catch (Throwable $error) {
    $patch = 'FAILED';
    $failures[] = $error->getMessage();
}
$output = ['version' => Shabbat\Runtime\Version::current($root), 'commit' => Shabbat\Runtime\Version::commit($root), 'php' => PHP_VERSION, 'sqlite' => $sqlite, 'timezone' => (new DateTimeZone('Asia/Jerusalem'))->getName(), 'patch' => $patch, 'library_patches' => $patches ?? [], 'secrets_required' => false, 'warnings' => $warnings, 'failures' => $failures];
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit($failures === [] ? 0 : 1);
