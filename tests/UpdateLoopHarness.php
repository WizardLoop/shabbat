<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

require_once __DIR__ . '/Fixtures/PatchLoop/API.php';
require_once __DIR__ . '/Fixtures/PatchLoop/MTProto.php';
require_once __DIR__ . '/Fixtures/PatchLoop/InternalLoop.php';
require_once __DIR__ . '/Fixtures/PatchLoop/FeedLoop.php';
ob_start();
require dirname(__DIR__) . '/vendor/autoload.php';
ob_end_clean();
$scenario = $argv[1];
$channel = $argv[2] === 'channel' ? 42 : 0;
$api = new \danog\MadelineProto\MTProto($scenario);
$loop = new \danog\MadelineProto\Loop\Update\UpdateLoop($api, $channel);
$api->updaters[$channel] = $loop;
(new \ReflectionProperty($loop, 'authorizedDc'))->setValue($loop, 1);
$shutdown = str_starts_with($scenario, 'shutdown-');
if ($shutdown) {
    $api->onCall = static function () use ($api, $loop): void {
        $api->stopped = true;
        $loop->stop();
    };
}
$errors = [];
\Revolt\EventLoop::setErrorHandler(static function (\Throwable $error) use (&$errors): void {
    $errors[] = get_class($error);
});
$timedOut = false;
$monitor = null;
$deadline = \Revolt\EventLoop::delay(3.0, static function () use (&$timedOut, &$monitor, $loop): void {
    $timedOut = true;
    $loop->stop();
    if ($monitor !== null) {
        \Revolt\EventLoop::cancel($monitor);
    }
});
$monitor = \Revolt\EventLoop::repeat(0.005, static function () use ($api, $loop, $deadline, &$monitor): void {
    if ($api->calls > 0 && $loop->isPaused()) {
        $loop->stop();
        \Revolt\EventLoop::cancel($deadline);
        \Revolt\EventLoop::cancel($monitor);
    }
});
$started = hrtime(true);
$loop->start();
\Revolt\EventLoop::run();
echo json_encode(['calls' => $api->calls, 'calls_after_stop' => $api->callsAfterStop,
    'reports' => $api->reports, 'errors' => $errors, 'timed_out' => $timedOut,
    'running' => $loop->isRunning(), 'paused' => $loop->isPaused(),
    'elapsed' => (hrtime(true) - $started) / 1e9], JSON_THROW_ON_ERROR);
