<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

use Amp\CancelledException;
use Shabbat\Calendar\CalendarDiagnostics;
use Shabbat\Time\SystemClock;

// No application bootstrap, .env, database or Telegram session is read by this tool.
ini_set('zend.exception_ignore_args', '1');
if (!isset($argv)) {
    echo json_encode(['diagnostic' => 'calendar-public-v1', 'ok' => false, 'reason' => 'cli_arguments_unavailable'], JSON_THROW_ON_ERROR) . "\n";
    exit(2);
}
$arguments = array_slice($argv, 1);
if (in_array('--help', $arguments, true)) {
    echo "Usage: php bin/check-calendar.php [--date=YYYY-MM-DD] [--latitude=NUMBER --longitude=NUMBER]\n";
    echo "       [--timezone=IANA_ZONE] [--israel=on|off] [--language=he|en|ru|fr|es|pt-br]\n";
    echo "Defaults: Jerusalem, 31.76904 / 35.21633, Asia/Jerusalem, Israel, Hebrew, 40-minute city candles.\n";
    echo "An explicit date is checked at local noon. Public HTTPS queries only; no credentials or stored data.\n";
    exit(0);
}
try {
    // Some platform-specific vendor polyfills print notices; keep this tool's JSON channel clean.
    ob_start();
    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
    } finally {
        ob_end_clean();
    }
    $options = CalendarDiagnostics::parseArguments($arguments);
    $report = (new CalendarDiagnostics(new SystemClock()))->run($options);
    $exit = $report['ok'] ? 0 : 1;
} catch (CancelledException) {
    $report = ['diagnostic' => 'calendar-public-v1', 'ok' => false, 'reason' => 'cancelled'];
    $exit = 130;
} catch (InvalidArgumentException) {
    $report = ['diagnostic' => 'calendar-public-v1', 'ok' => false, 'reason' => 'invalid_options', 'help' => 'php bin/check-calendar.php --help'];
    $exit = 2;
} catch (Throwable) {
    $report = ['diagnostic' => 'calendar-public-v1', 'ok' => false, 'reason' => 'diagnostic_internal_error'];
    $exit = 1;
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit($exit);
