<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Runtime;

use RuntimeException;

/** One reviewed existing file and its new files form a single fail-closed state. */
final class AddedFilePatchManager
{
    public function __construct(
        private string $packageRoot,
        private string $target,
        private string $patchDirectory,
        private array $entry,
        private string $writerLockPath
    ) {
    }

    public function process(bool $apply): string
    {
        $prepared = $this->prepare();
        $state = $this->state($prepared);
        if ($state === 'patched') {
            return 'already-patched';
        }
        if (!$apply) {
            throw new RuntimeException('Required library patch set is absent. Run php scripts/apply-patches.php before startup.');
        }
        $writer = SingleInstance::acquire($this->writerLockPath);
        try {
            if ($this->state($prepared) !== 'pristine') {
                throw new RuntimeException('Library patch set changed while acquiring the installer lock.');
            }
            $this->install($prepared);
            if ($this->state($prepared) !== 'patched') {
                throw new RuntimeException('Installed library patch set failed final verification.');
            }
            return 'patched';
        } finally {
            $writer->release();
        }
    }

    private function prepare(): array
    {
        if (!is_array($this->entry['added_files']) || $this->entry['added_files'] === []) {
            throw new RuntimeException('An added-file patch must declare its complete reviewed file set.');
        }
        foreach ($this->entry['license_files'] ?? [] as $license) {
            $this->artifact($license['file'], $license['sha256']);
        }
        $baseline = $this->artifact($this->entry['baseline'], $this->entry['original_sha256']);
        $patch = $this->artifact($this->entry['patch'], $this->entry['patch_sha256']);
        $sections = self::sections($patch);
        if (!isset($sections[$this->target]) || $sections[$this->target]['old'] !== $this->target) {
            throw new RuntimeException('Existing-file diff header differs from the approved target.');
        }
        $prepared = [$this->target => ['original' => $baseline,
            'output' => PatchManager::applyDiff($baseline, $sections[$this->target]['diff'])]];
        self::output($prepared[$this->target]['output'], $this->entry['patched_sha256']);
        if (
            isset($this->entry['reviewed_output'])
            && $prepared[$this->target]['output'] !== $this->artifact($this->entry['reviewed_output'], $this->entry['patched_sha256'])
        ) {
            throw new RuntimeException('Existing-file diff differs from its reviewed output.');
        }
        foreach ($this->entry['added_files'] as $file) {
            if (($file['original_state'] ?? '') !== 'absent') {
                throw new RuntimeException('Unreviewed original added-file state.');
            }
            $target = self::relative($file['target']);
            if (isset($prepared[$target]) || !isset($sections[$target]) || $sections[$target]['old'] !== null) {
                throw new RuntimeException('Added-file diff header is missing, duplicated or not an addition.');
            }
            $output = PatchManager::applyDiff('', $sections[$target]['diff']);
            self::output($output, $file['patched_sha256']);
            if ($output !== $this->artifact($file['reviewed_output'], $file['patched_sha256'])) {
                throw new RuntimeException('Added-file diff differs from its reviewed output.');
            }
            $prepared[$target] = ['original' => null, 'output' => $output];
        }
        $actual = array_keys($sections);
        $expected = array_keys($prepared);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Unified diff contains an undeclared library target.');
        }
        $this->prerequisites();
        return $prepared;
    }

    private function prerequisites(): void
    {
        if (!isset($this->entry['prerequisites']) || !is_array($this->entry['prerequisites']) || $this->entry['prerequisites'] === []) {
            throw new RuntimeException('Added-file patch requires exact reviewed source/schema prerequisites.');
        }
        foreach ($this->entry['prerequisites'] as $prerequisite) {
            if (isset($prerequisite['baseline'])) {
                $this->artifact($prerequisite['baseline'], $prerequisite['sha256']);
            }
            $path = $this->path(self::relative($prerequisite['target']));
            if (!is_file($path) || !hash_equals($prerequisite['sha256'], hash('sha256', self::read($path)))) {
                throw new RuntimeException('Library schema/source prerequisite differs from the approved source.');
            }
        }
    }

    private function state(array $prepared): string
    {
        $this->prerequisites();
        $states = [];
        foreach ($prepared as $target => $file) {
            $path = $this->path($target);
            if (!file_exists($path)) {
                if ($file['original'] !== null) {
                    throw new RuntimeException('Approved original library file is absent.');
                }
                $states[] = 'pristine';
                continue;
            }
            if (!is_file($path)) {
                throw new RuntimeException('Library target is not a regular source file.');
            }
            $contents = self::read($path);
            if ($contents === $file['output']) {
                $states[] = 'patched';
            } elseif ($file['original'] !== null && $contents === $file['original']) {
                $states[] = 'pristine';
            } else {
                throw new RuntimeException('Unknown or modified library patch-set source; refusing to continue.');
            }
        }
        if (count(array_unique($states)) !== 1) {
            throw new RuntimeException('Partial library patch set; refusing repair or silent continuation.');
        }
        return $states[0];
    }

    private function install(array $prepared): void
    {
        $temporary = [];
        try {
            foreach ($prepared as $target => $file) {
                $path = $this->path($target);
                $temp = $path . '.shabbat-' . bin2hex(random_bytes(6)) . '.tmp';
                $temporary[$target] = $temp;
                if (file_put_contents($temp, $file['output'], LOCK_EX) !== strlen($file['output'])) {
                    throw new RuntimeException('Unable to stage a complete library patch-set file.');
                }
            }
            // Install additions before dispatch. A crash leaves a detectable partial set.
            foreach ($prepared as $target => $file) {
                if ($file['original'] !== null) {
                    continue;
                }
                $path = $this->path($target);
                if (!@link($temporary[$target], $path)) {
                    throw new RuntimeException('Unable to exclusively install the reviewed added library file.');
                }
                unlink($temporary[$target]);
                unset($temporary[$target]);
            }
            $path = $this->path($this->target);
            if (!rename($temporary[$this->target], $path)) {
                throw new RuntimeException('Unable to atomically install reviewed library dispatch.');
            }
            unset($temporary[$this->target]);
        } finally {
            foreach ($temporary as $temp) {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
        }
    }

    private function path(string $relative): string
    {
        $relative = self::relative($relative);
        $path = $this->packageRoot . '/' . $relative;
        $root = realpath($this->packageRoot);
        $parent = realpath(dirname($path));
        if (
            $root === false || $parent === false || is_link($this->packageRoot) || is_link($path)
            || ($parent !== $root && !str_starts_with($parent, $root . DIRECTORY_SEPARATOR))
        ) {
            throw new RuntimeException('Library patch-set target escaped its regular package directory.');
        }
        return $path;
    }

    private function artifact(string $relative, string $sha256): string
    {
        $path = $this->patchDirectory . '/' . self::relative($relative);
        if (is_link($path)) {
            throw new RuntimeException('Reviewed patch artifacts must be regular contained files.');
        }
        $contents = self::read($path);
        if (!hash_equals($sha256, hash('sha256', $contents))) {
            throw new RuntimeException('Reviewed patch artifact or baseline hash mismatch.');
        }
        return $contents;
    }

    private static function output(string $contents, string $sha256): void
    {
        if (!hash_equals($sha256, hash('sha256', $contents))) {
            throw new RuntimeException('Unified diff output differs from its approved SHA256.');
        }
        $tokens = token_get_all($contents, TOKEN_PARSE);
        if ($tokens === [] || !is_array($tokens[0]) || $tokens[0][0] !== T_OPEN_TAG) {
            throw new RuntimeException('Patch-set output must be a complete PHP source file.');
        }
    }

    private static function sections(string $patch): array
    {
        $lines = explode("\n", $patch);
        $starts = [];
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '--- ')) {
                $starts[] = $index;
            }
        }
        $sections = [];
        foreach ($starts as $position => $start) {
            $old = substr($lines[$start], 4);
            $new = $lines[$start + 1] ?? '';
            if (!str_starts_with($new, '+++ b/') || ($old !== '/dev/null' && !str_starts_with($old, 'a/'))) {
                throw new RuntimeException('Invalid reviewed unified-diff file headers.');
            }
            $target = self::relative(substr($new, 6));
            $oldTarget = $old === '/dev/null' ? null : self::relative(substr($old, 2));
            if (($oldTarget !== null && $oldTarget !== $target) || isset($sections[$target])) {
                throw new RuntimeException('Renamed or duplicate patch-set target.');
            }
            $end = $starts[$position + 1] ?? count($lines);
            $sections[$target] = ['old' => $oldTarget, 'diff' => implode("\n", array_slice($lines, $start, $end - $start))];
        }
        return $sections;
    }

    private static function relative(string $path): string
    {
        if (
            $path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':')
            || str_starts_with($path, '/') || str_contains($path, '//') || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path)
        ) {
            throw new RuntimeException('Patch-set paths must be contained relative paths.');
        }
        return $path;
    }

    private static function read(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeException('Missing or unreadable patch-set prerequisite: ' . $path);
        }
        return $contents;
    }
}
