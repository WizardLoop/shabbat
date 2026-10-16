<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
require_once dirname(__DIR__) . '/src/Runtime/RuntimePaths.php';
require_once dirname(__DIR__) . '/src/Runtime/SingleInstance.php';
require_once dirname(__DIR__) . '/src/Runtime/AddedFilePatchManager.php';
require_once dirname(__DIR__) . '/src/Runtime/PatchManager.php';
require_once dirname(__DIR__) . '/src/Runtime/PatchSet.php';

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        foreach (\Shabbat\Runtime\PatchSet::process(dirname(__DIR__), true, $argv[1] ?? null) as $library => $status) {
            echo $library . ': ' . $status . "\n";
        }
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
