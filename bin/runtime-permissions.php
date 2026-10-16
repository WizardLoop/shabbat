<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

use Composer\InstalledVersions;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Magic;
use danog\MadelineProto\Settings\Logger as LoggerSettings;
use Shabbat\Runtime\RuntimePaths;

// Offline smoke only: no API/session is constructed and no Python process is run.
$created = [];
$stage = 'arguments';
$failed = false;
$loggerReady = false;
$errorHandler = static function (): never {
    throw new RuntimeException('Runtime permission probe operation failed.');
};
ini_set('display_errors', '0');
set_error_handler($errorHandler);

try {
    $arguments = array_slice($argv, 1);
    if ($arguments !== [] && $arguments !== ['--require-non-root']) {
        throw new RuntimeException('Unsupported runtime permission probe arguments.');
    }
    if ($arguments === ['--require-non-root']) {
        $stage = 'non-root identity';
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
            throw new RuntimeException('A verified non-root identity is required.');
        }
    }

    $root = dirname(__DIR__);
    $stage = 'dependencies';
    ob_start();
    try {
        require_once $root . '/vendor/autoload.php';
    } finally {
        ob_end_clean();
    }
    if (
        InstalledVersions::getPrettyVersion('danog/primemodule') !== '1.0.13'
        || InstalledVersions::getReference('danog/primemodule') !== 'e7ebfc1ea64c24186e49064cd4b76bc76c28600e'
    ) {
        throw new RuntimeException('The reviewed PrimeModule dependency is required.');
    }
    $cwd = getcwd();
    if (!is_string($cwd) || !is_dir($cwd)) {
        throw new RuntimeException('The current working directory is unavailable.');
    }
    $prefix = '.shabbat-permissions-' . bin2hex(random_bytes(16));
    $create = static function (string $path, string $contents) use (&$created): void {
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Exclusive runtime probe creation failed.');
        }
        $created[$path] = fstat($handle);
        try {
            $length = strlen($contents);
            if (($length !== 0 && fwrite($handle, $contents) !== $length) || !fflush($handle)) {
                throw new RuntimeException('Runtime probe write failed.');
            }
        } finally {
            fclose($handle);
        }
        if (file_get_contents($path) !== $contents) {
            throw new RuntimeException('Runtime probe readback failed.');
        }
    };

    $stage = 'working directory and PrimeModule copies';
    // These hashes are the reviewed, unchanged files from PrimeModule 1.0.13.
    $sources = [
        'prime.py' => '5dc87da7b6dec039a55c3a494bd8aee60316ef6b102e4b0aabdc9b3bcccb0e03',
        'alt_prime.py' => 'ec63d5de8cce42908bc8bf1043626c552c7eae5318658217a1093d093b199bfc',
    ];
    foreach ($sources as $name => $approvedHash) {
        $source = $root . '/vendor/danog/primemodule/lib/danog/' . $name;
        if (!is_file($source) || hash_file('sha256', $source) !== $approvedHash) {
            throw new RuntimeException('A PrimeModule source differs from the reviewed version.');
        }
        $contents = file_get_contents($source);
        if (!is_string($contents)) {
            throw new RuntimeException('A reviewed PrimeModule source is unreadable.');
        }
        $create($cwd . '/' . $prefix . '-' . $name, $contents);
    }

    $stage = 'configured data directory';
    $paths = RuntimePaths::resolve($root);
    if (!is_dir($paths->dataDirectory)) {
        throw new RuntimeException('The configured data directory must already exist.');
    }
    $create($paths->dataDirectory . '/' . $prefix . '-data.tmp', 'offline runtime write probe');
    $stage = 'configured session parent';
    $sessionParent = dirname($paths->sessionPath);
    if (!is_dir($sessionParent)) {
        throw new RuntimeException('The configured session parent must already exist.');
    }
    $create($sessionParent . '/' . $prefix . '-session.tmp', 'offline runtime write probe');

    $stage = 'default MadelineProto worker logger';
    $logPath = $cwd . '/MadelineProto.log';
    if (is_link($logPath) || (file_exists($logPath) && !is_file($logPath))) {
        throw new RuntimeException('The default worker log is not a regular file.');
    }
    if (!file_exists($logPath)) {
        $create($logPath, '');
    }
    define('MADELINE_WORKER', true);
    Magic::start(light: true);
    // The bot entrypoint is at /app/bot.php. This bin script must probe its CWD,
    // rather than Magic's backtrace-derived /app/bin fallback for CLI helpers.
    Magic::$script_cwd = $cwd;
    Magic::$cwd = $cwd;
    set_error_handler($errorHandler);
    $settings = new LoggerSettings();
    // Preserve existing operator logs even when larger than the default limit.
    $settings->setMaxSize(-1);
    if ($settings->getType() !== Logger::FILE_LOGGER || $settings->getExtra() !== $logPath) {
        throw new RuntimeException('The worker logger did not select the default CWD log.');
    }
    $logger = Logger::constructorFromSettings($settings);
    $loggerReady = true;
    $logger->logger('Shabbat offline runtime permission probe.');
    Logger::finalize();
    $loggerReady = false;
    Logger::$default = null;
    unset($logger);
    gc_collect_cycles();
    clearstatcache(true, $logPath);
    if (!is_file($logPath) || !is_writable($logPath) || filesize($logPath) === 0) {
        throw new RuntimeException('The default worker log did not accept output.');
    }
} catch (Throwable) {
    $failed = true;
} finally {
    if ($loggerReady) {
        try {
            Logger::finalize();
        } catch (Throwable) {
            $failed = true;
        }
    }
    foreach ($created as $path => $identity) {
        try {
            clearstatcache(true, $path);
            $current = lstat($path);
            // Never remove a replaced file or a pre-existing operator artifact.
            if (
                !is_array($identity)
                || !is_array($current)
                || is_link($path)
                || $current['dev'] !== $identity['dev']
                || $current['ino'] !== $identity['ino']
                || !unlink($path)
            ) {
                $failed = true;
                $stage = 'probe cleanup';
            }
        } catch (Throwable) {
            $failed = true;
            $stage = 'probe cleanup';
        }
    }
}

if ($failed) {
    fwrite(STDERR, 'Offline runtime permission check failed: ' . $stage . ".\n");
    exit(1);
}
fwrite(STDOUT, "Offline runtime permissions verified: working directory, PrimeModule copies, worker logger, data and session.\n");
