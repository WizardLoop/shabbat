<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Runtime;

final class BuildMetadata
{
    public static function write(string $root, string $commit = ''): array
    {
        $path = $root . '/build-info.json';
        $metadata = is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
        if (!is_array($metadata)) {
            throw new \RuntimeException('Build metadata must be a JSON object.');
        }
        $resolved = $commit !== '' ? $commit : ($metadata['commit'] ?? '');
        if (!is_string($resolved) || !preg_match('/^[a-f0-9]{40}$/iD', $resolved)) {
            throw new \RuntimeException('Pass an explicit 40-hex BUILD_COMMIT for a checkout, or retain valid archive build-info.json.');
        }
        $fingerprint = ReleaseInputs::fingerprint(ReleaseInputs::application($root));
        if (
            isset($metadata['application_source_sha256']) && (!is_string($metadata['application_source_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D', $metadata['application_source_sha256'])
            || !hash_equals($metadata['application_source_sha256'], $fingerprint))
        ) {
            throw new \RuntimeException('Application source differs from retained archive metadata; regenerate the archive before building.');
        }
        $metadata['commit'] = strtolower($resolved);
        $metadata['version'] = Version::current($root);
        $metadata['application_source_sha256'] = $fingerprint;
        $metadata['local_changes'] ??= true;
        $contents = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !rename($temporary, $path)) {
                throw new \RuntimeException('Unable to write verified build metadata.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        return $metadata;
    }
}
