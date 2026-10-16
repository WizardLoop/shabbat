<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
require_once __DIR__ . '/apply-patches.php';
try {
    foreach (\Shabbat\Runtime\PatchSet::process(dirname(__DIR__), false, $argv[1] ?? null) as $library => $status) {
        echo $library . ' verified: ' . $status . "\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
