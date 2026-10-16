<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use BroadcastTool\BroadcastManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Shabbat\Runtime\BroadcastStatusFormatter;
use Shabbat\Runtime\PatchManager;
use Shabbat\Runtime\SingleInstance;
use Shabbat\UI\Lang;

final class BroadcastPatchTest extends TestCase
{
    private string $root;
    private string $vendor;
    private string $target;
    private string $manifest;
    private string $writer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shabbat-broadcast-patch-' . bin2hex(random_bytes(8));
        $this->vendor = $this->root . '/vendor';
        $this->target = $this->vendor . '/wizardloop/broadcastmanager/src/BroadcastManager.php';
        $this->manifest = $this->root . '/patches/broadcastmanager/manifest.json';
        $this->writer = $this->root . '/application.writer.lock';
        mkdir(dirname($this->target), 0700, true);
        mkdir($this->vendor . '/composer', 0700, true);
        mkdir(dirname($this->manifest) . '/baseline', 0700, true);
        $source = dirname(__DIR__) . '/patches/broadcastmanager/';
        foreach (['manifest.json', 'status-localization-hooks.patch', 'baseline/BroadcastManager.php', 'previous/dev3/BroadcastManager.php', 'dev3-to-transport-hooks.patch', 'previous/dev8/BroadcastManager.php', 'dev8-to-runtime-lifecycle.patch'] as $file) {
            $destination = dirname($this->manifest) . '/' . $file;
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0700, true);
            }
            copy($source . $file, $destination);
        }
        copy($source . 'baseline/BroadcastManager.php', $this->target);
        $this->installed('3.2.2', 'f2792a383416b66bb68e55f09414563e540538d6');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }

    private function installed(string $version, string $commit): void
    {
        file_put_contents($this->vendor . '/composer/installed.json', json_encode(['packages' => [
            ['name' => 'wizardloop/broadcastmanager', 'version' => $version, 'source' => ['reference' => $commit]],
        ]], JSON_THROW_ON_ERROR));
    }

    private function installer(): PatchManager
    {
        return new PatchManager($this->root, $this->vendor, $this->manifest, $this->writer);
    }

    private function rejected(callable $operation): void
    {
        $error = null;
        try {
            $operation();
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(RuntimeException::class, $error);
    }

    public function testCleanInstallRepeatedApplyAndVerifyMatchApprovedOutput(): void
    {
        $this->rejected(fn (): string => $this->installer()->process(false));
        self::assertSame('patched', $this->installer()->process(true));
        $metadata = json_decode(file_get_contents($this->manifest), true, 64, JSON_THROW_ON_ERROR)['approved'][0];
        self::assertSame($metadata['patched_sha256'], hash_file('sha256', $this->target));
        $before = file_get_contents($this->target);
        self::assertSame('already-patched', $this->installer()->process(true));
        self::assertSame('already-patched', $this->installer()->process(false));
        self::assertSame($before, file_get_contents($this->target));
    }

    public function testWrongVersionAndCommitNeverMutatePristineSource(): void
    {
        $before = file_get_contents($this->target);
        foreach ([['3.2.1', 'f2792a383416b66bb68e55f09414563e540538d6'], ['3.2.2', str_repeat('0', 40)]] as [$version, $commit]) {
            $this->installed($version, $commit);
            $this->rejected(fn (): string => $this->installer()->process(true));
            self::assertSame($before, file_get_contents($this->target));
        }
    }

    public function testUnknownAndPartiallyPatchedFilesFailClosedWithoutChanges(): void
    {
        $base = file_get_contents($this->target);
        $patch = file_get_contents(dirname($this->manifest) . '/status-localization-hooks.patch');
        $partial = str_replace('    private ?\\Closure $statusFormatter = null;' . "\n", '', PatchManager::applyDiff($base, $patch));
        foreach ([$base . "\n// unreviewed change\n", $partial] as $changed) {
            file_put_contents($this->target, $changed);
            $this->rejected(fn (): string => $this->installer()->process(true));
            $this->rejected(fn (): string => $this->installer()->process(false));
            self::assertSame($changed, file_get_contents($this->target));
        }
    }

    public function testActiveWriterPreventsApplyingButDoesNotSilentlySkipThePatch(): void
    {
        $before = file_get_contents($this->target);
        $writer = SingleInstance::acquire($this->writer);
        try {
            $this->rejected(fn (): string => $this->installer()->process(true));
            self::assertSame($before, file_get_contents($this->target));
        } finally {
            $writer->release();
        }
        self::assertSame('patched', $this->installer()->process(true));
    }

    private function manager(): BroadcastManager
    {
        return (new ReflectionClass(BroadcastManager::class))->newInstanceWithoutConstructor();
    }

    private function invoke(BroadcastManager $manager, string $method, array $arguments): mixed
    {
        return (new ReflectionMethod($manager, $method))->invokeArgs($manager, $arguments);
    }

    private function state(string $id = 'abcdef0123456789'): array
    {
        return ['id' => $id, 'type' => 'send', 'sent' => 4, 'failed' => 1, 'flood' => 2, 'total' => 10, 'startedAt' => null, 'done' => false];
    }

    public function testDisabledHooksPreserveExactUpstreamProgressAndControls(): void
    {
        $manager = $this->manager();
        $expected = "<b>Broadcast Progress</b>\n\n<code>##########---------- 50%</code>\n\nProcessed: 5 / 10\nSent: 4\nFailed: 1\nFLOOD_WAIT: 2\nPending: 5\nTPS: 0/s";
        self::assertSame($expected, $this->invoke($manager, 'buildProgressText', [$this->state(), 'Broadcast Progress']));
        $controls = ['inline_keyboard' => [[['text' => 'Pause', 'callback_data' => 'bm:pause:abcdef0123456789'], ['text' => 'Cancel', 'callback_data' => 'bm:cancel:abcdef0123456789']]]];
        self::assertSame($controls, $this->invoke($manager, 'buildStatusControls', [$this->state()]));
        $manager->setStatusFormatter(static fn (): string => 'Custom');
        $manager->setStatusFormatter(null);
        $manager->setStatusControlsFormatter(null);
        self::assertSame($expected, $this->invoke($manager, 'buildProgressText', [$this->state(), 'Broadcast Progress']));
        self::assertSame($controls, $this->invoke($manager, 'buildStatusControls', [$this->state()]));
    }

    public function testSixLocalizedPatchedFormattersPreserveCountersAndNativeCallbackBindings(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            $manager = $this->manager();
            $formatter = new BroadcastStatusFormatter($lang, $language);
            $manager->setStatusFormatter($formatter);
            $manager->setStatusControlsFormatter($formatter->controls(...));
            $starting = $this->invoke($manager, 'formatStatus', ['starting', 'Gathering peers...', ['type' => 'send', 'total' => 10]]);
            self::assertStringContainsString($lang->text($language, 'operator_progress'), $starting);
            self::assertNotSame('Gathering peers...', $starting);
            $state = $this->state() + ['paused' => true, 'cancel' => true];
            $text = $this->invoke($manager, 'buildProgressText', [$state, 'Broadcast Progress', true]);
            $values = ['processed' => 5, 'total' => 10, 'sent' => 4, 'edited' => 0, 'unchanged' => 0, 'deleted' => 0, 'unpin' => 0, 'failed' => 1, 'flood' => 2, 'pending' => 5, 'tps' => in_array($language, ['he', 'en'], true) ? '0.00' : '0,00'];
            self::assertStringContainsString($lang->text($language, 'operator_bc_counts', $values), $text);
            self::assertStringContainsString($lang->text($language, 'operator_bc_paused'), $text);
            self::assertStringContainsString($lang->text($language, 'operator_bc_cancelled'), $text);
            $controls = $this->invoke($manager, 'buildStatusControls', [$state]);
            self::assertSame('replyInlineMarkup', $controls['_']);
            self::assertSame('bm:resume:abcdef0123456789', $controls['rows'][0]['buttons'][0]['data']);
            self::assertSame($lang->text($language, 'operator_resume'), $controls['rows'][0]['buttons'][0]['text']);
            self::assertTrue($controls['rows'][0]['buttons'][0]['style']['bg_primary']);
            self::assertSame('bm:cancel:abcdef0123456789', $controls['rows'][0]['buttons'][1]['data']);
            self::assertTrue($controls['rows'][0]['buttons'][1]['style']['bg_danger']);
            self::assertNull($this->invoke($manager, 'buildStatusControls', [array_replace($state, ['done' => true])]));
        }
    }

    public function testFormatterIsPerInstanceAndInvalidTextNeverBecomesAnEnglishFallback(): void
    {
        $one = $this->manager();
        $two = $this->manager();
        $one->setStatusFormatter(new BroadcastStatusFormatter(new Lang(), 'fr'));
        self::assertSame('Cancel', $this->invoke($two, 'formatStatus', ['cancel', 'Cancel', []]));
        self::assertSame((new Lang())->text('fr', 'operator_cancel'), $this->invoke($one, 'formatStatus', ['cancel', 'Cancel', []]));
        $one->setStatusFormatter(static fn (): array => ['wrong']);
        $this->expectException(\InvalidArgumentException::class);
        $this->invoke($one, 'formatStatus', ['starting', 'Gathering peers...', []]);
    }

    public function testLastSummaryLocalizesAvailableNumericIdStateAndPreservesLegacyFile(): void
    {
        $stateProperty = new ReflectionProperty(BroadcastManager::class, 'sharedBroadcastState');
        $dirProperty = new ReflectionProperty(BroadcastManager::class, 'dataDir');
        $oldState = $stateProperty->getValue();
        $oldDirectory = $dirProperty->getValue();
        file_put_contents($this->root . '/LastBrodDATA.txt', 'Original English summary');
        BroadcastManager::setDataDir($this->root);
        $manager = $this->manager();
        $formatter = new BroadcastStatusFormatter(new Lang(), 'es');
        $manager->setStatusFormatter($formatter);
        try {
            $stateProperty->setValue(null, []);
            self::assertSame((new Lang())->text('es', 'operator_bc_legacy_summary'), $manager->lastBroadcastData());
            $stateProperty->setValue(null, ['1234567890123456' => $this->state('1234567890123456')]);
            $text = $manager->lastBroadcastData();
            self::assertStringContainsString('5 / 10', $text);
            self::assertStringNotContainsString('Original English summary', $text);
            self::assertSame('Original English summary', file_get_contents($this->root . '/LastBrodDATA.txt'));
        } finally {
            $stateProperty->setValue(null, $oldState);
            BroadcastManager::setDataDir($oldDirectory);
        }
    }

    public function testPreviousDev3PatchRequiresExplicitOfflineUpgradeThenVerifiesIdempotently(): void
    {
        copy(dirname($this->manifest) . '/previous/dev3/BroadcastManager.php', $this->target);
        $before = file_get_contents($this->target);
        self::assertSame('3d9425f7911b12c69faae5e87954db134dc3fb7c2f7d654a72f972ebeb84e424', hash('sha256', $before));
        $this->rejected(fn (): string => $this->installer()->process(false));
        self::assertSame($before, file_get_contents($this->target));
        self::assertSame('patched', $this->installer()->process(true));
        self::assertSame('already-patched', $this->installer()->process(false));
        self::assertSame('already-patched', $this->installer()->process(true));
        self::assertSame(hash_file('sha256', dirname(__DIR__) . '/patches/broadcastmanager/reviewed/BroadcastManager.php'), hash_file('sha256', $this->target));
    }

    public function testPreviousDev8RuntimeUpgradeIsExplicitAndIdempotent(): void
    {
        copy(dirname($this->manifest) . '/previous/dev8/BroadcastManager.php', $this->target);
        self::assertSame('08db35c271f671041257ebe79d1010cdd7c33c4d5868e4553c78612f9e8bf0f3', hash_file('sha256', $this->target));
        $this->rejected(fn (): string => $this->installer()->process(false));
        self::assertSame('patched', $this->installer()->process(true));
        self::assertSame('already-patched', $this->installer()->process(false));
        self::assertSame('already-patched', $this->installer()->process(true));
        self::assertSame(hash_file('sha256', dirname(__DIR__) . '/patches/broadcastmanager/reviewed/BroadcastManager.php'), hash_file('sha256', $this->target));
    }
    public function testPreviousPatchWriterLockAndModifiedTransitionFailClosed(): void
    {
        copy(dirname($this->manifest) . '/previous/dev3/BroadcastManager.php', $this->target);
        $before = file_get_contents($this->target);
        $lock = SingleInstance::acquire($this->writer);
        try {
            $this->rejected(fn (): string => $this->installer()->process(true));
            self::assertSame($before, file_get_contents($this->target));
        } finally {
            $lock->release();
        }
        file_put_contents(dirname($this->manifest) . '/dev3-to-transport-hooks.patch', "\n// unknown", FILE_APPEND);
        $this->rejected(fn (): string => $this->installer()->process(true));
        self::assertSame($before, file_get_contents($this->target));
    }

    public function testAllApprovedTransitionArtifactsAreVerifiedEvenWhenAlreadyPatched(): void
    {
        $this->installer()->process(true);
        $before = file_get_contents($this->target);
        file_put_contents(dirname($this->manifest) . '/previous/dev3/BroadcastManager.php', "\n// unknown", FILE_APPEND);
        $this->rejected(fn (): string => $this->installer()->process(false));
        self::assertSame($before, file_get_contents($this->target));
    }

    public function testTransportHookPreservesNativeMethodsPayloadsResultsAndPerInstanceIsolation(): void
    {
        $one = $this->manager();
        $two = $this->manager();
        $calls = [];
        $one->setMessageTransport(static function (string $method, array $parameters) use (&$calls): array {
            $calls[] = [$method, $parameters];
            return ['_' => 'updates', 'fixture' => count($calls)];
        });
        $params = ['peer' => '-100123', 'id' => 91, 'random_id' => 1234567890, 'message' => 'Bold 🚀', 'entities' => [['_' => 'messageEntityBold', 'offset' => 0, 'length' => 7]], 'reply_markup' => ['_' => 'replyInlineMarkup', 'rows' => []], 'media' => ['_' => 'inputMediaPhoto', 'id' => 'fixture'], 'multi_media' => [['_' => 'inputSingleMedia', 'random_id' => 9191, 'message' => 'caption']], 'floodWaitLimit' => 172800];
        foreach (['sendMessage', 'sendMedia', 'sendMultiMedia', 'editMessage'] as $index => $method) {
            self::assertSame(['_' => 'updates', 'fixture' => $index + 1], $this->invoke($one, 'callMessage', [$method, $params]));
            self::assertSame([$method, $params], $calls[$index]);
        }
        $property = new ReflectionProperty(BroadcastManager::class, 'messageTransport');
        self::assertNull($property->getValue($two));
        $one->setMessageTransport(null);
        self::assertNull($property->getValue($one));
        $source = file_get_contents(dirname(__DIR__) . '/patches/broadcastmanager/reviewed/BroadcastManager.php');
        self::assertSame(1, substr_count($source, '$this->api->messages->{$method}($parameters)'));
        self::assertStringNotContainsString('$this->api->messages->sendMessage(', $source);
        self::assertStringNotContainsString('$this->api->messages->sendMultiMedia(', $source);
        self::assertStringNotContainsString('$this->api->messages->editMessage(', $source);
        self::assertSame(3, substr_count($source, "\$this->callMessage('editMessage', \$payload)"));
    }

    public function testTransportHookDoesNotSwallowCancellationOrPermitOtherNativeMethods(): void
    {
        $manager = $this->manager();
        $error = new \Amp\CancelledException(new RuntimeException('fixture'));
        $manager->setMessageTransport(static function () use ($error): never {
            throw $error;
        });
        try {
            $this->invoke($manager, 'callMessage', ['sendMessage', ['message' => 'fixture']]);
            self::fail('Transport cancellation swallowed');
        } catch (\Amp\CancelledException $caught) {
            self::assertSame($error, $caught);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->invoke($manager, 'callMessage', ['deleteMessages', []]);
    }

    public function testDisabledTransportUsesUnchangedNativeDynamicDispatchInIsolatedOfflineProcess(): void
    {
        $source = var_export(dirname(__DIR__) . '/patches/broadcastmanager/reviewed/BroadcastManager.php', true);
        $script = <<<'PHP'
namespace danog\MadelineProto {
    final class API {
        public object $messages;
    }
}
namespace {
    require SOURCE_FILE;
    $probe = new class {
        public array $calls = [];
        public function __call(string $method, array $arguments): array {
            $this->calls[] = [$method, $arguments];
            return ['method' => $method, 'parameters' => $arguments[0]];
        }
    };
    $api = new \danog\MadelineProto\API();
    $api->messages = $probe;
    $manager = (new \ReflectionClass(\BroadcastTool\BroadcastManager::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty($manager, 'api'))->setValue($manager, $api);
    $method = new \ReflectionMethod($manager, 'callMessage');
    $payload = ['peer' => '-1001', 'id' => 92, 'random_id' => 818181, 'message' => '🚀 unchanged', 'entities' => [], 'reply_markup' => ['inline_keyboard' => []]];
    $results = [];
    foreach (['sendMessage', 'sendMedia', 'sendMultiMedia', 'editMessage'] as $name) {
        $results[] = $method->invoke($manager, $name, $payload);
    }
    $manager->setMessageTransport(static fn(): string => 'hooked');
    $hooked = $method->invoke($manager, 'sendMessage', $payload);
    $manager->setMessageTransport(null);
    $unhooked = $method->invoke($manager, 'editMessage', $payload);
    echo json_encode(['calls' => $probe->calls, 'results' => $results, 'hooked' => $hooked, 'unhooked' => $unhooked], JSON_THROW_ON_ERROR);
}
PHP;
        $script = str_replace('SOURCE_FILE', $source, $script);
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        $proof = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        self::assertCount(5, $proof['calls']);
        self::assertSame('hooked', $proof['hooked']);
        foreach (['sendMessage', 'sendMedia', 'sendMultiMedia', 'editMessage'] as $index => $name) {
            self::assertSame($name, $proof['calls'][$index][0]);
            self::assertCount(1, $proof['calls'][$index][1]);
            self::assertSame($proof['calls'][$index][1][0], $proof['results'][$index]['parameters']);
        }
        self::assertSame('editMessage', $proof['unhooked']['method']);
        self::assertSame($proof['results'][0]['parameters'], $proof['unhooked']['parameters']);
    }
}
