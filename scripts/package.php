<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
$root = dirname(__DIR__);
if (($argv[1] ?? '') === '--development') {
    require __DIR__ . '/package-development.php';
    exit(0);
}
require_once __DIR__ . '/apply-patches.php';
\Shabbat\Runtime\PatchSet::process($root, false);
$python = getenv('PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
$process = proc_open([$python, __DIR__ . '/package-release.py', ...array_slice($argv, 1)], [STDIN, STDOUT, STDERR], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Python 3 is required for release packaging.');
}
exit(proc_close($process));
