<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;

final class TracePrivacyTest extends TestCase
{
    public function testNativeMadelineTraceReproducesArgumentLeakWhenProtectionIsDisabled(): void
    {
        $result = $this->probe(['-n', '-d', 'zend.exception_ignore_args=Off']);
        self::assertFalse($result['ignore_args']);
        self::assertTrue($result['contains_sentinel']);
        self::assertTrue($result['contains_function']);
    }

    public function testNativeMadelineTraceKeepsCallSitesWithoutArgumentsWhenProtectionIsEnabled(): void
    {
        $result = $this->probe(['-n', '-d', 'zend.exception_ignore_args=On']);
        self::assertTrue($result['ignore_args']);
        self::assertFalse($result['contains_sentinel']);
        self::assertTrue($result['contains_function']);
    }

    public function testShippedConfigurationProtectsASeparatePhpProcess(): void
    {
        $configuration = dirname(__DIR__) . '/resources/php/99-shabbat-runtime.ini';
        self::assertFileExists($configuration);
        $result = $this->probe(['-c', $configuration]);
        self::assertTrue($result['ignore_args']);
        self::assertFalse($result['contains_sentinel']);
        self::assertTrue($result['contains_function']);
    }

    /**
     * @param list<string> $arguments
     * @return array{ignore_args: bool, contains_sentinel: bool, contains_function: bool}
     */
    private function probe(array $arguments): array
    {
        $trait = dirname(__DIR__) . '/vendor/danog/madelineproto/src/TL/PrettyException.php';
        self::assertFileExists($trait);
        $code = '<?php require ' . var_export($trait, true) . ';' . <<<'PHP'

class OfflineTracePrivacyProbe extends Exception
{
    use danog\MadelineProto\TL\PrettyException;

    public function __construct()
    {
        parent::__construct('Offline trace privacy probe');
        $this->prettifyTL();
    }
}

function createOfflinePrivacyTrace(string $argument): OfflineTracePrivacyProbe
{
    return new OfflineTracePrivacyProbe();
}

$trace = createOfflinePrivacyTrace('OFFLINE_PRIVACY_SENTINEL')->getTLTrace();
echo json_encode([
    'ignore_args' => (bool) ini_get('zend.exception_ignore_args'),
    'contains_sentinel' => str_contains($trace, 'OFFLINE_PRIVACY_SENTINEL'),
    'contains_function' => str_contains($trace, 'createOfflinePrivacyTrace('),
], JSON_THROW_ON_ERROR);
PHP;

        $environment = getenv();
        self::assertIsArray($environment);
        // Exercise this exact configuration without host scan-directory overrides.
        $environment['PHP_INI_SCAN_DIR'] = '';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            $environment,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $errors);
        self::assertSame('', $errors);
        $result = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        return $result;
    }
}
