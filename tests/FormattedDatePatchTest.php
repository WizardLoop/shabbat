<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Shabbat\Runtime\PatchManager;
use Shabbat\Runtime\SingleInstance;

require_once dirname(__DIR__) . '/scripts/apply-patches.php';

final class FormattedDatePatchTest extends TestCase
{
    private string $fixture;
    private string $vendor;
    private string $patches;
    private string $factory;
    private string $class;
    private array $manifest;

    protected function setUp(): void
    {
        $root = dirname(__DIR__);
        $this->fixture = sys_get_temp_dir() . '/shabbat-date-patch-' . bin2hex(random_bytes(8));
        $this->vendor = $this->fixture . '/vendor';
        $this->patches = $this->fixture . '/patches';
        $this->manifest = json_decode((string) file_get_contents($root . '/patches/madelineproto-formatted-date/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->factory = $this->vendor . '/danog/madelineproto/' . $this->manifest['target'];
        $this->class = dirname($this->factory) . '/FormattedDate.php';
        mkdir(dirname($this->factory), 0700, true);
        mkdir($this->vendor . '/composer', 0700);
        mkdir($this->patches, 0700);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/patches/madelineproto-formatted-date', FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root . '/patches/madelineproto-formatted-date') + 1);
            $target = $this->patches . '/' . $relative;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            copy($file->getPathname(), $target);
        }
        copy($this->patches . '/baseline/MessageEntity.php', $this->factory);
        copy($this->patches . '/baseline/TL_telegram_v225.tl', $this->vendor . '/danog/madelineproto/src/TL_telegram_v225.tl');
        $this->installed();
    }

    protected function tearDown(): void
    {
        $resolved = realpath($this->fixture);
        if ($resolved === false || !str_starts_with($resolved, realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'shabbat-date-patch-')) {
            throw new RuntimeException('Date patch fixture cleanup escaped its private directory.');
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isLink() || !$file->isDir() ? unlink($file->getPathname()) : rmdir($file->getPathname());
        }
        rmdir($resolved);
    }

    private function installed(string $version = '8.7.0', string $commit = '7b99dd0f2d964a492c377912ffce9f5ce61aa9ed'): void
    {
        file_put_contents($this->vendor . '/composer/installed.json', json_encode(['packages' => [[
            'name' => 'danog/madelineproto', 'version' => $version, 'source' => ['reference' => $commit],
        ]]], JSON_THROW_ON_ERROR));
    }

    private function manager(): PatchManager
    {
        return new PatchManager(dirname(__DIR__), $this->vendor, $this->patches . '/manifest.json', $this->fixture . '/writer.lock');
    }

    private function refusal(): RuntimeException
    {
        $error = null;
        try {
            $this->manager()->process(true);
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertNotSame('', $error->getMessage());
        return $error;
    }

    public function testPristineInstallRepeatAndVerificationCoverTheWholeSet(): void
    {
        self::assertSame('patched', $this->manager()->process(true));
        self::assertSame($this->manifest['approved'][0]['patched_sha256'], hash_file('sha256', $this->factory));
        self::assertSame($this->manifest['approved'][0]['added_files'][0]['patched_sha256'], hash_file('sha256', $this->class));
        $factory = file_get_contents($this->factory);
        $class = file_get_contents($this->class);
        self::assertSame('already-patched', $this->manager()->process(true));
        self::assertSame('already-patched', $this->manager()->process(false));
        self::assertSame($factory, file_get_contents($this->factory));
        self::assertSame($class, file_get_contents($this->class));
    }

    public function testVerificationRejectsTheEntirePristineSetWithoutMutation(): void
    {
        $before = file_get_contents($this->factory);
        $error = null;
        try {
            $this->manager()->process(false);
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertSame($before, file_get_contents($this->factory));
        self::assertFileDoesNotExist($this->class);
    }

    public function testUnknownVersionAndCommitNeverWrite(): void
    {
        foreach ([['8.6.5', '7b99dd0f2d964a492c377912ffce9f5ce61aa9ed'], ['8.7.0', str_repeat('0', 40)]] as [$version, $commit]) {
            $this->installed($version, $commit);
            self::assertStringContainsString('Unapproved', $this->refusal()->getMessage());
            self::assertSame(file_get_contents($this->patches . '/baseline/MessageEntity.php'), file_get_contents($this->factory));
            self::assertFileDoesNotExist($this->class);
        }
    }

    public function testClassOnlyAndFactoryOnlyStatesAreNotRepaired(): void
    {
        copy($this->patches . '/reviewed/FormattedDate.php', $this->class);
        self::assertStringContainsString('Partial', $this->refusal()->getMessage());
        self::assertSame(file_get_contents($this->patches . '/baseline/MessageEntity.php'), file_get_contents($this->factory));
        unlink($this->class);
        copy($this->patches . '/reviewed/MessageEntity.php', $this->factory);
        self::assertStringContainsString('Partial', $this->refusal()->getMessage());
        self::assertFileDoesNotExist($this->class);
    }

    public function testUnknownClassCannotBecomeUpstreamSupported(): void
    {
        copy($this->patches . '/reviewed/MessageEntity.php', $this->factory);
        file_put_contents($this->class, "<?php // Unknown competing implementation\n");
        self::assertStringContainsString('Unknown', $this->refusal()->getMessage());
    }

    public function testUnknownFactoryAndSchemaPreventAddedFileCreation(): void
    {
        file_put_contents($this->factory, "\n", FILE_APPEND);
        self::assertStringContainsString('Unknown', $this->refusal()->getMessage());
        self::assertFileDoesNotExist($this->class);
        copy($this->patches . '/baseline/MessageEntity.php', $this->factory);
        file_put_contents($this->vendor . '/danog/madelineproto/src/TL_telegram_v225.tl', "\n", FILE_APPEND);
        self::assertStringContainsString('prerequisite', $this->refusal()->getMessage());
        self::assertFileDoesNotExist($this->class);
    }

    public static function artifacts(): array
    {
        return [['baseline/MessageEntity.php'], ['baseline/TL_telegram_v225.tl'], ['reviewed/MessageEntity.php'], ['reviewed/FormattedDate.php'], ['formatted-date-entity.patch'], ['upstream-LICENSE']];
    }

    #[DataProvider('artifacts')]
    public function testEveryCorrespondingArtifactIsVerifiedEvenWhenAlreadyPatched(string $artifact): void
    {
        $this->manager()->process(true);
        file_put_contents($this->patches . '/' . $artifact, "\n", FILE_APPEND);
        self::assertStringContainsString('hash mismatch', $this->refusal()->getMessage());
    }

    public function testActiveWriterPreventsBothFileMutations(): void
    {
        $writer = SingleInstance::acquire($this->fixture . '/writer.lock');
        try {
            self::assertStringContainsString('Another bot or maintenance', $this->refusal()->getMessage());
            self::assertFileDoesNotExist($this->class);
            self::assertSame(file_get_contents($this->patches . '/baseline/MessageEntity.php'), file_get_contents($this->factory));
        } finally {
            $writer->release();
        }
    }

    public function testCompleteReadOnlyVerificationDoesNotRequireMutatingWriter(): void
    {
        $this->manager()->process(true);
        $writer = SingleInstance::acquire($this->fixture . '/writer.lock');
        try {
            self::assertSame('already-patched', $this->manager()->process(false));
            self::assertSame('already-patched', $this->manager()->process(true));
        } finally {
            $writer->release();
        }
    }

    public function testAddedDirectoryIsRefused(): void
    {
        mkdir($this->class);
        self::assertStringContainsString('regular source file', $this->refusal()->getMessage());
    }

    private function createSymlink(string $target, string $link): bool
    {
        // A fresh PHP child has no SDK error handler; unsupported host privileges
        // return an explicit skip status instead of changing global error handlers.
        $process = proc_open(
            [PHP_BINARY, '-r', 'exit(@symlink($argv[1], $argv[2]) ? 0 : 77);', '--', $target, $link],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the isolated symlink capability fixture.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if (!in_array($status, [0, 77], true)) {
            throw new RuntimeException('Symlink capability fixture failed: ' . $errors);
        }
        return $status === 0;
    }
    public function testExistingAndAddedSymlinksCannotBeVerifiedAsInstalledSources(): void
    {
        $outside = $this->fixture . '/outside.php';
        copy($this->patches . '/baseline/MessageEntity.php', $outside);
        unlink($this->factory);
        if (!$this->createSymlink($outside, $this->factory)) {
            copy($outside, $this->factory);
            self::markTestSkipped('This host does not permit creation of file symlink fixtures.');
        }
        self::assertStringContainsString('regular package directory', $this->refusal()->getMessage());
        unlink($this->factory);
        copy($this->patches . '/reviewed/MessageEntity.php', $this->factory);
        copy($this->patches . '/reviewed/FormattedDate.php', $outside);
        self::assertTrue($this->createSymlink($outside, $this->class));
        self::assertStringContainsString('regular package directory', $this->refusal()->getMessage());
    }
}
