<?php

declare(strict_types=1);

namespace Shabbat\Runtime;

use RuntimeException;

// SPDX-License-Identifier: AGPL-3.0-only

/**
 * Exact-source, fail-closed patching. This file intentionally does not load
 * MadelineProto: build steps must never start or connect a bot.
 */
final class PatchManager
{
    private array $manifest;
    private string $packageName;
    private string $target;
    private string $patchDirectory;

    public function __construct(private string $root, private ?string $vendor = null, ?string $manifestFile = null, private ?string $writerLockPath = null)
    {
        $this->vendor ??= $root . '/vendor';
        $manifestFile ??= $root . '/patches/madelineproto/manifest.json';
        $this->patchDirectory = dirname($manifestFile);
        $this->manifest = json_decode(self::read($manifestFile), true, flags: JSON_THROW_ON_ERROR);
        $this->packageName = $this->manifest['package'] ?? 'danog/madelineproto';
        if (!preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $this->packageName)) {
            throw new RuntimeException('Invalid patch package identity.');
        }
        $this->target = self::relativePath($this->manifest['target'] ?? 'src/Loop/Update/UpdateLoop.php');
    }

    public function process(bool $apply): string
    {
        $installed = json_decode(self::read($this->vendor . '/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        $package = null;
        foreach ($installed['packages'] ?? $installed as $candidate) {
            if (($candidate['name'] ?? '') === $this->packageName) {
                $package = $candidate;
                break;
            }
        }
        if ($package === null) {
            throw new RuntimeException($this->packageName . ' is not installed. Run composer install from the committed lock.');
        }
        $reference = $package['source']['reference'] ?? $package['dist']['reference'] ?? '';
        $version = ltrim((string) $package['version'], 'v');
        $entry = null;
        foreach ($this->manifest['approved'] as $candidate) {
            if ($candidate['version'] === $version && $candidate['commit'] === $reference) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) {
            throw new RuntimeException('Unapproved ' . $this->packageName . ' version/commit: ' . $version . ' ' . $reference);
        }
        if (array_key_exists('added_files', $entry)) {
            if ($entry['mode'] !== 'patch') {
                throw new RuntimeException('Added-file review schema requires patch mode and complete file verification.');
            }
            return (new AddedFilePatchManager(
                $this->vendor . '/' . $this->packageName,
                $this->target,
                $this->patchDirectory,
                $entry,
                $this->writerLockPath ?? $this->defaultWriterLockPath()
            ))->process($apply);
        }
        $path = $this->vendor . '/' . $this->packageName . '/' . $this->target;
        if (is_link($path)) {
            throw new RuntimeException('Library target must be a regular source file, not a symlink.');
        }
        $source = self::read($path);
        $hash = hash('sha256', $source);
        if ($entry['mode'] === 'official') {
            if (!hash_equals($entry['sha256'], $hash)) {
                throw new RuntimeException('Official library file is not the reviewed source.');
            }
            return 'official-verified';
        }
        if ($entry['mode'] !== 'patch') {
            throw new RuntimeException('Unknown approved patch mode.');
        }
        $baseline = self::read($this->patchDirectory . '/' . self::relativePath($entry['baseline']));
        if (!hash_equals($entry['original_sha256'], hash('sha256', $baseline))) {
            throw new RuntimeException('Corresponding-source baseline hash mismatch.');
        }
        $patch = self::read($this->patchDirectory . '/' . self::relativePath($entry['patch']));
        if (!hash_equals($entry['patch_sha256'], hash('sha256', $patch))) {
            throw new RuntimeException('Patch artifact hash mismatch.');
        }
        $knownSources = [$entry['original_sha256'] => $patch];
        $intermediates = $entry['intermediates'] ?? [];
        if (!is_array($intermediates)) {
            throw new RuntimeException('Invalid approved intermediate source list.');
        }
        foreach ($intermediates as $intermediate) {
            if (
                !is_array($intermediate) || !is_string($intermediate['sha256'] ?? null)
                || !is_string($intermediate['patch_sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $intermediate['sha256'])
                || !preg_match('/^[a-f0-9]{64}$/D', $intermediate['patch_sha256'])
                || isset($knownSources[$intermediate['sha256']])
            ) {
                throw new RuntimeException('Invalid or ambiguous approved intermediate source.');
            }
            $previous = self::read($this->patchDirectory . '/' . self::relativePath($intermediate['source']));
            $transition = self::read($this->patchDirectory . '/' . self::relativePath($intermediate['patch']));
            if (
                !hash_equals($intermediate['sha256'], hash('sha256', $previous))
                || !hash_equals($intermediate['patch_sha256'], hash('sha256', $transition))
            ) {
                throw new RuntimeException('Approved intermediate source or transition artifact hash mismatch.');
            }
            if (!hash_equals($entry['patched_sha256'], hash('sha256', self::applyDiff($previous, $transition)))) {
                throw new RuntimeException('Approved transition does not produce the reviewed output.');
            }
            $knownSources[$intermediate['sha256']] = $transition;
        }
        if (hash_equals($entry['patched_sha256'], $hash)) {
            return 'already-patched';
        }
        if (!isset($knownSources[$hash])) {
            throw new RuntimeException('Unknown, partial or modified library source; refusing to continue.');
        }
        $patch = $knownSources[$hash];
        if (!$apply) {
            throw new RuntimeException('Required library patch is absent. Run php scripts/apply-patches.php before startup.');
        }
        // A build may verify a running installation, but may never mutate it.
        // Use the exact lock shared by Runner and SQLite maintenance commands.
        $writer = SingleInstance::acquire($this->writerLockPath ?? $this->defaultWriterLockPath());
        try {
            if (!hash_equals($hash, hash('sha256', self::read($path)))) {
                throw new RuntimeException('Library source changed while acquiring the installer lock.');
            }
            return $this->install($path, $source, $patch, $entry);
        } finally {
            $writer->release();
        }
    }

    private function defaultWriterLockPath(): string
    {
        return RuntimePaths::resolve($this->root)->writerLockPath();
    }
    private function install(string $path, string $source, string $patch, array $entry): string
    {
        $patched = self::applyDiff($source, $patch);
        if (!hash_equals($entry['patched_sha256'], hash('sha256', $patched))) {
            throw new RuntimeException('Patch output does not match the approved SHA256.');
        }
        $tokens = token_get_all($patched, TOKEN_PARSE);
        if ($tokens === [] || !is_array($tokens[0]) || $tokens[0][0] !== T_OPEN_TAG) {
            throw new RuntimeException('Patch output must be a complete PHP source file.');
        }
        $temporary = $path . '.shabbat-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (file_put_contents($temporary, $patched, LOCK_EX) !== strlen($patched) || !rename($temporary, $path)) {
                throw new RuntimeException('Unable to atomically install patch.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        return 'patched';
    }

    public static function applyDiff(string $source, string $patch): string
    {
        $sourceLines = explode("\n", $source);
        $lines = explode("\n", $patch);
        $result = [];
        $cursor = 0;
        $index = 0;
        $hunks = 0;
        while ($index < count($lines)) {
            $line = $lines[$index++];
            if (!str_starts_with($line, '@@ ')) {
                continue;
            }
            if (!preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
                throw new RuntimeException('Invalid unified-diff hunk.');
            }
            $start = (int) $matches[1] === 0 && $matches[2] === '0' ? 0 : (int) $matches[1] - 1;
            $oldCount = $matches[2] !== '' ? (int) $matches[2] : 1;
            $newCount = isset($matches[4]) ? (int) $matches[4] : 1;
            if ($start < $cursor || $start > count($sourceLines)) {
                throw new RuntimeException('Overlapping or out-of-range patch hunk.');
            }
            while ($cursor < $start) {
                $result[] = $sourceLines[$cursor++];
            }
            $old = 0;
            $new = 0;
            while ($index < count($lines) && !str_starts_with($lines[$index], '@@ ')) {
                $diffLine = $lines[$index];
                if ($diffLine === '') {
                    $index++;
                    break;
                }
                $kind = $diffLine[0];
                $text = substr($diffLine, 1);
                if ($kind !== ' ' && $kind !== '+' && $kind !== '-') {
                    break;
                }
                $index++;
                if ($kind === ' ' || $kind === '-') {
                    if (($sourceLines[$cursor] ?? null) !== $text) {
                        throw new RuntimeException('Patch context differs from the exact approved source.');
                    }
                    $cursor++;
                    $old++;
                }
                if ($kind === ' ' || $kind === '+') {
                    $result[] = $text;
                    $new++;
                }
            }
            if ($old !== $oldCount || $new !== $newCount) {
                throw new RuntimeException('Patch hunk counts differ.');
            }
            $hunks++;
        }
        if ($hunks === 0) {
            throw new RuntimeException('Patch has no hunks.');
        }
        return implode("\n", array_merge($result, array_slice($sourceLines, $cursor)));
    }

    private static function relativePath(string $path): string
    {
        if (
            $path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':')
            || str_starts_with($path, '/') || str_contains($path, '//') || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path)
        ) {
            throw new RuntimeException('Patch paths must be contained relative paths.');
        }
        return $path;
    }

    private static function read(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeException('Missing or unreadable patch prerequisite: ' . $path);
        }
        return $contents;
    }
}
