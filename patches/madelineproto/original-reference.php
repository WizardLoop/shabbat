<?php

declare(strict_types=1);

/**
 * Teach the locked MadelineProto update loop to treat only cancellations that
 * were caused by an Amp timeout as the existing transient network timeout.
 *
 * @return 'patched'|'already-patched'
 */
function newSearchPatchMadelineUpdateLoop(string $vendorRoot): string
{
    $vendorRoot = rtrim($vendorRoot, "/\\");
    $updateLoopFile = $vendorRoot . '/danog/madelineproto/src/Loop/Update/UpdateLoop.php';
    if (!is_file($updateLoopFile)) {
        throw new RuntimeException('MadelineProto UpdateLoop compatibility patch prerequisite is missing: ' . $updateLoopFile);
    }

    $source = file_get_contents($updateLoopFile);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Unable to read MadelineProto UpdateLoop source: ' . $updateLoopFile);
    }

    $newline = str_contains($source, "\r\n") ? "\r\n" : "\n";
    $timeoutImport = 'use Amp\\TimeoutException;';
    $cancelledImport = 'use Amp\\CancelledException;';
    $plainCatch = 'catch (TimeoutException)';
    $unionCatch = 'catch (TimeoutException|CancelledException $e)';
    $guard = 'if ($e instanceof CancelledException && !($e->getPrevious() instanceof TimeoutException)) {';
    $report = 'EventLoop::queue($this->API->report(...), "Network issues detected, please check logs!");';

    $originalChannel = implode($newline, [
        '                } catch (TimeoutException) {',
        '                    ' . $report,
        '                    continue;',
        '                }',
    ]);
    $patchedChannel = implode($newline, [
        '                } catch (TimeoutException|CancelledException $e) {',
        '                    ' . $guard,
        '                        throw $e;',
        '                    }',
        '                    ' . $report,
        '                    continue;',
        '                }',
    ]);
    $originalGeneric = implode($newline, [
        '                    } catch (TimeoutException) {',
        '                        ' . $report,
        '                    }',
    ]);
    $patchedGeneric = implode($newline, [
        '                    } catch (TimeoutException|CancelledException $e) {',
        '                        ' . $guard,
        '                            throw $e;',
        '                        }',
        '                        ' . $report,
        '                    }',
    ]);

    $commonLayoutIsExpected = substr_count($source, $timeoutImport) === 1
        && substr_count($source, "methodCallAsyncRead('updates.getChannelDifference'") === 1
        && substr_count($source, "methodCallAsyncRead('updates.getDifference'") === 1
        && substr_count($source, $report) === 2;
    if (!$commonLayoutIsExpected) {
        throw new RuntimeException('Unsupported MadelineProto UpdateLoop layout; expected imports, difference calls, or timeout reports changed.');
    }

    $isFullyPatched = substr_count($source, $cancelledImport) === 1
        && substr_count($source, $plainCatch) === 0
        && substr_count($source, $unionCatch) === 2
        && substr_count($source, $guard) === 2
        && substr_count($source, $patchedChannel) === 1
        && substr_count($source, $patchedGeneric) === 1;
    if ($isFullyPatched) {
        return 'already-patched';
    }

    $isExactOriginal = substr_count($source, $cancelledImport) === 0
        && substr_count($source, $plainCatch) === 2
        && substr_count($source, $unionCatch) === 0
        && substr_count($source, $guard) === 0
        && substr_count($source, $originalChannel) === 1
        && substr_count($source, $originalGeneric) === 1;
    if (!$isExactOriginal) {
        throw new RuntimeException('MadelineProto UpdateLoop is partially patched or has an unsupported upstream layout; refusing an ambiguous patch.');
    }

    $patched = str_replace(
        $timeoutImport,
        $cancelledImport . $newline . $timeoutImport,
        $source,
        $importReplacements,
    );
    $patched = str_replace($originalChannel, $patchedChannel, $patched, $channelReplacements);
    $patched = str_replace($originalGeneric, $patchedGeneric, $patched, $genericReplacements);
    if ($importReplacements !== 1 || $channelReplacements !== 1 || $genericReplacements !== 1) {
        throw new RuntimeException('MadelineProto UpdateLoop compatibility patch did not replace every guarded anchor exactly once.');
    }

    if (substr_count($patched, $cancelledImport) !== 1
        || substr_count($patched, $plainCatch) !== 0
        || substr_count($patched, $unionCatch) !== 2
        || substr_count($patched, $guard) !== 2
        || substr_count($patched, $patchedChannel) !== 1
        || substr_count($patched, $patchedGeneric) !== 1
    ) {
        throw new RuntimeException('MadelineProto UpdateLoop compatibility patch failed its postconditions.');
    }

    try {
        token_get_all($patched, TOKEN_PARSE);
    } catch (ParseError $error) {
        throw new RuntimeException('Patched MadelineProto UpdateLoop is not valid PHP: ' . $error->getMessage(), 0, $error);
    }

    newSearchWriteUpdateLoopCompatibilityFile($updateLoopFile, $patched);
    return 'patched';
}

function newSearchWriteUpdateLoopCompatibilityFile(string $path, string $contents): void
{
    $temporary = $path . '.newsearch-' . bin2hex(random_bytes(6)) . '.tmp';
    try {
        $bytes = file_put_contents($temporary, $contents, LOCK_EX);
        if ($bytes !== strlen($contents)) {
            throw new RuntimeException('Unable to write complete UpdateLoop compatibility file: ' . $path);
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Unable to atomically install UpdateLoop compatibility file: ' . $path);
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $vendorRoot = $argv[1] ?? dirname(__DIR__) . '/vendor';
        $result = newSearchPatchMadelineUpdateLoop($vendorRoot);
        fwrite(STDOUT, "MadelineProto UpdateLoop timeout compatibility: {$result}\n");
    } catch (Throwable $error) {
        fwrite(STDERR, 'ERROR: MadelineProto UpdateLoop timeout compatibility patch failed: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
