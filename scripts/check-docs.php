<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
$root = dirname(__DIR__);
$errors = [];
$files = [];
foreach (['README.md', 'readme-he.md', 'THIRD_PARTY_NOTICES.md', 'CHANGELOG.md'] as $file) {
    if (is_file($root . '/' . $file)) {
        $files[] = $root . '/' . $file;
    }
}
if (is_dir($root . '/docs')) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/docs', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $files[] = $file->getPathname();
        }
    }
}
foreach ($files as $file) {
    preg_match_all('/!?\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/u', (string) file_get_contents($file), $matches);
    foreach ($matches[1] as $link) {
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|#|/)~i', $link)) {
            continue;
        }
        $path = rawurldecode(explode('#', $link, 2)[0]);
        if ($path !== '' && !file_exists(dirname($file) . '/' . $path)) {
            $errors[] = basename($file) . ': missing local link ' . $link;
        }
    }
}
foreach ($errors as $error) {
    fwrite(STDERR, $error . "\n");
}
echo 'Documentation links: ' . count($files) . ' documents, ' . count($errors) . " errors.\n";
exit($errors === [] ? 0 : 1);
