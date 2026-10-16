<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
require dirname(__DIR__) . '/vendor/autoload.php';
use Shabbat\Storage\BackupService;
use Shabbat\Storage\Database;
$options = getopt('', ['db:','backup:','writers-stopped']);
try {
    if (!isset($options['backup'])) {
        throw new RuntimeException('Specify --backup=/path/to/verified.sqlite.');
    }
    $path = (string)($options['db'] ?? \Shabbat\Runtime\RuntimePaths::resolve(dirname(__DIR__))->databasePath);
    echo Database::encode(BackupService::restore((string)$options['backup'], $path, isset($options['writers-stopped']))) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
