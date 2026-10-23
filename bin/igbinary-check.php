<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

// Offline dependency smoke: no API, session, database or credentials are opened.
require dirname(__DIR__) . '/vendor/autoload.php';
$failures = [];
$fullMagic = false;
foreach (['pdo_sqlite', 'mbstring', 'xml', 'dom', 'fileinfo', 'json', 'filter', 'hash', 'zlib', 'openssl', 'iconv', 'ctype', 'tokenizer', 'date', 'igbinary'] as $extension) {
    if (!extension_loaded($extension)) {
        $failures[] = 'Missing PHP extension ' . $extension;
    }
}
if (!function_exists('igbinary_serialize') || !function_exists('igbinary_unserialize')) {
    $failures[] = 'The igbinary serialization functions are unavailable.';
}
if ($failures === []) {
    try {
        $sample = ['id' => PHP_INT_MAX, 'text' => 'session compatibility probe', 'bytes' => "\0binary", 'values' => [true, false, null]];
        if (igbinary_unserialize(igbinary_serialize($sample)) !== $sample) {
            throw new RuntimeException('The igbinary roundtrip failed.');
        }
        // Suppress the optional PHAR update check during this offline process.
        if (!defined('MADELINEPROTO_TEST')) {
            define('MADELINEPROTO_TEST', true);
        }
        \danog\MadelineProto\Magic::start(light: false);
        if (!\danog\MadelineProto\Magic::$can_use_igbinary) {
            throw new RuntimeException('MadelineProto did not enable igbinary.');
        }
        $fullMagic = true;
    } catch (Throwable $error) {
        $failures[] = $error->getMessage();
    }
}
echo json_encode([
    'php' => PHP_VERSION,
    'igbinary' => phpversion('igbinary') ?: null,
    'full_magic' => $fullMagic,
    'api_or_session_opened' => false,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit($failures === [] ? 0 : 1);
