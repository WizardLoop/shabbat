<?php

declare(strict_types=1);

namespace Shabbat\Runtime;

// SPDX-License-Identifier: AGPL-3.0-only

use Amp\CancelledException;
use Amp\TimeoutException;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use Revolt\EventLoop;

final class Runner
{
    private static bool $stopping = false;
    private static bool $restartPending = false;
    private static ?\danog\MadelineProto\EventHandler $handler = null;
    private static ?\Closure $quiesce = null;

    public static function attachHandler(\danog\MadelineProto\EventHandler $handler, ?callable $quiesce = null): void
    {
        self::$handler = $handler;
        self::$quiesce = $quiesce === null ? null : \Closure::fromCallable($quiesce);
        // MP installs its callback before onStart; bind the application policy
        // after attachment so it remains the active event-loop error handler.
        self::bindErrorHandler();
    }

    public static function bindErrorHandler(): void
    {
        EventLoop::setErrorHandler(static fn (\Throwable $error) => self::handleEventError($error, self::$stopping));
    }

    /** @phpstan-impure Shutdown signals can change this value during async work. */
    public static function isStopping(): bool
    {
        return self::$stopping;
    }

    public static function requestShutdown(): void
    {
        if (self::$stopping) {
            return;
        }
        self::$stopping = true;
        (self::$quiesce)?->__invoke();
    }

    /** Optional startup reporting must not delay the update loop indefinitely. */
    public static function notifyStartup(callable $notify, float $timeout = 3.0): bool
    {
        if (self::$stopping) {
            return false;
        }
        if ($timeout <= 0 || $timeout > 30) {
            throw new \InvalidArgumentException('Invalid startup notification deadline.');
        }
        try {
            $notify(new \Amp\TimeoutCancellation($timeout));
            return true;
        } catch (CancelledException $error) {
            if (!$error->getPrevious() instanceof TimeoutException) {
                throw $error;
            }
        } catch (\Throwable $error) {
            error_log('Startup notification failed: ' . get_class($error));
        }
        return false;
    }

    /** Stop application work before completing the SDK loop; only one restart may be pending. */
    public static function restart(callable $acknowledge, callable $restart): bool
    {
        if (self::$stopping || self::$restartPending) {
            return false;
        }
        self::$restartPending = true;
        try {
            try {
                $acknowledge();
            } catch (CancelledException $error) {
                if (!$error->getPrevious() instanceof TimeoutException) {
                    throw $error;
                }
                // The bounded acknowledgement timed out. Restart does not depend on sending it.
            } catch (\Throwable $error) {
                error_log('Restart acknowledgement failed: ' . get_class($error));
            }
            try {
                self::requestShutdown();
            } finally {
                $restart();
            }
            return true;
        } finally {
            self::$restartPending = false;
        }
    }

    /**
     * Reference runBot setup, with typed cancellation checks instead of the
     * reference's blanket cancellation-message suppression.
     */
    public static function handleEventError(\Throwable $error, bool $stopping = false): void
    {
        if ($error instanceof CancelledException) {
            if ($stopping && !($error->getPrevious() instanceof TimeoutException)) {
                return;
            }
            throw $error;
        }
        $text = $error->getMessage() . "\n" . $error->getTraceAsString();
        if (
            $error instanceof \Error && str_contains($text, 'ReferenceDatabase.php')
            && str_contains($error->getMessage(), 'Trying to access array offset on null')
        ) {
            error_log('MadelineProto ReferenceDatabase error suppressed by the documented reference compatibility handler.');
            return;
        }
        if ($error instanceof \Error && $error->getMessage() === 'Operation is no longer pending') {
            error_log('MadelineProto completed-operation error suppressed by the documented reference compatibility handler.');
            return;
        }
        throw $error;
    }

    public static function settings(): Settings
    {
        $settings = new Settings();
        $settings->setLogger((new Settings\Logger())->setLevel(Logger::ERROR));
        $settings->setConnection((new Settings\Connection())->setTimeout(600.0)->setRetry(true));
        $settings->setPeer((new Settings\Peer())->setFullInfoCacheTime(0));
        // Official RPC settings clamp to five seconds; helper calls cannot take
        // per-call floodWaitLimit. Longer waits remain typed scheduler retries.
        $settings->setRPC((new Settings\RPC())->setFloodTimeout(5));
        return $settings;
    }

    /** Keep maintenance excluded until the SDK has flushed its pending API destructors. */
    public static function finalizeSession(?SingleInstance $lock, callable $flush): void
    {
        try {
            $flush();
        } finally {
            $lock?->release();
        }
    }

    /** @param class-string<\danog\MadelineProto\EventHandler> $handlerClass */
    public static function run(string $handlerClass, ?string $root = null): int
    {
        try {
            PhpRuntime::assertSupported();
        } catch (\RuntimeException $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            return 1;
        }
        $root ??= dirname(__DIR__, 2);
        $lock = null;
        $signals = [];
        $exitCode = 1;
        self::$stopping = false;
        try {
            // MP formats PHP trace arguments before our outer error boundary.
            // Docker also sets this globally for separately spawned IPC workers.
            if (ini_set('zend.exception_ignore_args', '1') === false) {
                throw new \RuntimeException('Unable to disable exception argument collection.');
            }
            require_once $root . '/scripts/apply-patches.php';
            PatchSet::process($root, false);
            $env = is_file($root . '/.env') ? parse_ini_file($root . '/.env', false, INI_SCANNER_RAW) : [];
            if (!is_array($env)) {
                throw new \RuntimeException('Invalid .env file.');
            }
            $value = static function (string $name, string $default = '') use ($env): string {
                $environment = getenv($name);
                return is_string($environment) && $environment !== '' ? $environment : (string) ($env[$name] ?? $default);
            };
            $apiId = $value('API_ID');
            $apiHash = $value('API_HASH');
            $botToken = $value('BOT_TOKEN');
            if (!ctype_digit($apiId) || (int) $apiId <= 0 || !preg_match('/^[a-f0-9]{32}$/i', $apiHash) || $botToken === '') {
                throw new \RuntimeException('API_ID, API_HASH and BOT_TOKEN must be configured.');
            }
            // Make configured values available consistently to services.
            foreach ($env as $name => $configured) {
                if (getenv((string) $name) === false && is_scalar($configured)) {
                    putenv($name . '=' . $configured);
                }
            }
            $paths = RuntimePaths::resolve($root, $env);
            $paths->export();
            $lock = SingleInstance::acquire($paths->writerLockPath());
            self::bindErrorHandler();
            if (PHP_OS_FAMILY !== 'Windows') {
                foreach ([SIGTERM, SIGINT] as $signal) {
                    $signals[] = EventLoop::onSignal($signal, static function (): void {
                        try {
                            self::requestShutdown();
                        } finally {
                            // SDK stop completes its main loop; finalization below
                            // persists the session while retaining writer ownership.
                            self::$handler?->stop();
                        }
                    });
                }
            }
            $settings = self::settings();
            $settings->setAppInfo((new Settings\AppInfo())->setApiId((int) $apiId)->setApiHash($apiHash));


            $session = $paths->sessionPath;
            error_log('WizardLoop Shabbat ' . Version::current($root) . ' ' . Version::commit($root));
            $handlerClass::startAndLoopBot($session, $botToken, $settings);
            $exitCode = 0;
        } catch (\Throwable $error) {
            if ($error instanceof CancelledException && self::$stopping && !($error->getPrevious() instanceof TimeoutException)) {
                $exitCode = 0;
            } else {
                // Do not log credentials or arbitrary exception payloads.
                fwrite(STDERR, 'Bot stopped: ' . get_class($error) . '. Run the self-check and inspect secured operator logs.' . "\n");
            }
        } finally {
            try {
                self::requestShutdown();
            } catch (\Throwable $error) {
                fwrite(STDERR, 'Worker shutdown failed: ' . get_class($error) . "\n");
                $exitCode = 1;
            } finally {
                foreach ($signals as $signal) {
                    EventLoop::cancel($signal);
                }
                self::$handler = null;
                self::$quiesce = null;
                try {
                    // Public @internal SDK method: locked 8.11.10 contract is tested.
                    self::finalizeSession($lock, \danog\MadelineProto\API::finalize(...));
                } catch (\Throwable $error) {
                    fwrite(STDERR, 'Session finalization failed: ' . get_class($error) . "\n");
                    $exitCode = 1;
                }
            }
        }
        return $exitCode;
    }
}
