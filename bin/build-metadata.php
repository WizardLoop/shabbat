<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

$root = dirname(__DIR__);
foreach (['Version', 'ReleaseInputs', 'BuildMetadata'] as $class) {
    require_once $root . '/src/Runtime/' . $class . '.php';
}
$options = getopt('', ['commit:']);
try {
    $metadata = \Shabbat\Runtime\BuildMetadata::write($root, (string) ($options['commit'] ?? ''));
    echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
