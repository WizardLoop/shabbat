<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-only
require_once __DIR__ . '/original-reference.php';
$source = (string) file_get_contents(__DIR__ . '/baseline/UpdateLoop.php');
if (hash('sha256', $source) !== '2fbcbb73822258102837f53f4a231b87526d66809be7acd6339156ea778a8c2f') {
    throw new RuntimeException('Reviewed upstream baseline changed.');
}
$fixture = sys_get_temp_dir() . '/shabbat-reference-' . bin2hex(random_bytes(8));
mkdir($fixture . '/danog/madelineproto/src/Loop/Update', 0700, true);
$target = $fixture . '/danog/madelineproto/src/Loop/Update/UpdateLoop.php';
file_put_contents($target, $source);
try {
    newSearchPatchMadelineUpdateLoop($fixture);
    $reference = (string) file_get_contents($target);
} finally {
    unlink($target);
    foreach (['/danog/madelineproto/src/Loop/Update', '/danog/madelineproto/src/Loop', '/danog/madelineproto/src', '/danog/madelineproto', '/danog', ''] as $part) {
        rmdir($fixture . $part);
    }
}
$patched = $reference;
$guard = 'if ($e instanceof CancelledException && !($e->getPrevious() instanceof TimeoutException)) {';
foreach ([20, 24] as $indent) {
    $space = str_repeat(' ', $indent);
    $patched = str_replace("\n" . $space . $guard, "\n" . $space . 'if (!$this->isRunning() || $this->authorizedDc === null) {' . "\n" . $space . '    return self::STOP;' . "\n" . $space . '}' . "\n" . $space . $guard, $patched, $count);
    if ($count !== 1) {
        throw new RuntimeException('Unexpected reference patch layout.');
    }
}
$report = 'EventLoop::queue($this->API->report(...), "Network issues detected, please check logs!");';
$patched = str_replace('                    ' . $report . "\n" . '                    continue;', '                    ' . $report . "\n" . '                    return self::DEFAULT_TIMEOUT;', $patched, $count);
if ($count !== 1) {
    throw new RuntimeException('Unexpected channel recovery layout.');
}
$patched = str_replace('                        ' . $report . "\n", '                        ' . $report . "\n" . '                        return self::DEFAULT_TIMEOUT;' . "\n", $patched, $count);
if ($count !== 1) {
    throw new RuntimeException('Unexpected generic recovery layout.');
}
$diff = static function (string $before, string $after): string {
    $old = explode("\n", rtrim($before, "\n"));
    $new = explode("\n", rtrim($after, "\n"));
    return "--- a/vendor/danog/madelineproto/src/Loop/Update/UpdateLoop.php\n+++ b/vendor/danog/madelineproto/src/Loop/Update/UpdateLoop.php\n"
        . '@@ -1,' . count($old) . ' +1,' . count($new) . " @@\n"
        . implode("\n", array_map(static fn (string $line): string => '-' . $line, $old)) . "\n"
        . implode("\n", array_map(static fn (string $line): string => '+' . $line, $new)) . "\n";
};
file_put_contents(__DIR__ . '/original-reference.patch', $diff($source, $reference));
$patch = $diff($source, $patched);
file_put_contents(__DIR__ . '/update-loop-timeout-cancellation.patch', $patch);
$manifest = ['schema_version' => 1, 'reviewed_at' => '2026-10-13', 'package' => 'danog/madelineproto', 'target' => 'src/Loop/Update/UpdateLoop.php',
    'original_reference' => ['path' => 'local-reference/newsearch-v2/docker/patch-madeline-update-loop.php', 'also_found' => 'local-reference/psy new/release-r21-work/docker/patch-madeline-update-loop.php', 'sha256' => hash_file('sha256', __DIR__ . '/original-reference.php'), 'note' => 'Historical artifact was a guarded PHP patch script; reference unified diff is reproduced from that script. Paths are logical local-reference identifiers, not public download URLs.'],
    'approved' => [['version' => '8.7.0', 'commit' => '7b99dd0f2d964a492c377912ffce9f5ce61aa9ed', 'mode' => 'patch', 'baseline' => 'baseline/UpdateLoop.php',
        'source_url' => 'https://github.com/danog/MadelineProto/blob/7b99dd0f2d964a492c377912ffce9f5ce61aa9ed/src/Loop/Update/UpdateLoop.php',
        'original_sha256' => hash('sha256', $source), 'patched_sha256' => hash('sha256', $patched), 'patch' => 'update-loop-timeout-cancellation.patch', 'patch_sha256' => hash('sha256', $patch)]],
    'official_versions' => [], 'notes' => 'No released official solution was verified. New versions require source, call-chain and behavioral review before admission.'];
file_put_contents(__DIR__ . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "Reviewed patch artifacts regenerated.\n";