<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
$root = dirname(__DIR__);
$paths = ['src', 'scripts', 'bin', 'tests', 'bot.php'];
$errors = 0;
$checked = 0;
foreach ($paths as $path) {
    $absolute = $root . '/' . $path;
    $files = is_file($absolute) ? [new SplFileInfo($absolute)] : (is_dir($absolute) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)) : []);
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        try {
            token_get_all((string) $source, TOKEN_PARSE);
        } catch (ParseError $error) {
            fwrite(STDERR, $file->getPathname() . ': ' . $error->getMessage() . "\n");
            $errors++;
        }
        if (str_starts_with((string) $source, "\xEF\xBB\xBF") || !str_starts_with((string) $source, '<?php')) {
            fwrite(STDERR, $file->getPathname() . ': PHP source must start with an opening tag without BOM.' . "\n");
            $errors++;
        }
        $checked++;
    }
}
echo "PHP syntax and source encoding: {$checked} files, {$errors} errors.\n";
exit($errors > 0 ? 1 : 0);
