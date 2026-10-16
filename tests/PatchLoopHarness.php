<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
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
// Enable the actual parent-loop state without scheduling a real transport.
$running = new \ReflectionProperty(\danog\Loop\Loop::class, 'running');
$running->setValue($loop, true);
$authorized = new \ReflectionProperty($loop, 'authorizedDc');
$authorized->setValue($loop, 1);
if (str_starts_with($scenario, 'shutdown-')) {
    $api->onCall = static fn() => $loop->stop();
}
try {
    $first = $loop->loop();
    \Revolt\EventLoop::run();
    $callsAfterFirst = $api->calls;
    $second = null;
    if (!str_starts_with($scenario, 'shutdown-')) {
        $second = $loop->loop();
    }
    echo json_encode(['first' => $first, 'second' => $second, 'calls_after_first' => $callsAfterFirst, 'calls' => $api->calls, 'reports' => $api->reports], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    echo json_encode(['exception' => get_class($error), 'calls' => $api->calls, 'reports' => $api->reports], JSON_THROW_ON_ERROR);
}
