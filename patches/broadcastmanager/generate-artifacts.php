<?php

declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-only
// Development-only artifact generator. Edit/review reviewed/BroadcastManager.php first.
// Installers never execute this generator and never fetch mutable source.

$base = __DIR__ . '/';
$approvedInputs = [
    'baseline/BroadcastManager.php' => '2a9a42dc6e3f6d7b51c178e7d9db962a5b5ac424046af18afcfcdb12d41dc6e8',
    'previous/dev3/BroadcastManager.php' => '3d9425f7911b12c69faae5e87954db134dc3fb7c2f7d654a72f972ebeb84e424',
    'previous/dev8/BroadcastManager.php' => '08db35c271f671041257ebe79d1010cdd7c33c4d5868e4553c78612f9e8bf0f3',
];
foreach ($approvedInputs as $path => $hash) {
    if (hash_file('sha256', $base . $path) !== $hash) {
        throw new RuntimeException('Immutable approved source changed: ' . $path);
    }
}
$reviewed = (string)file_get_contents($base . 'reviewed/BroadcastManager.php');
token_get_all($reviewed, TOKEN_PARSE);
$git = PHP_OS_FAMILY === 'Windows' && is_file('C:/Program Files/Git/cmd/git.exe') ? 'C:/Program Files/Git/cmd/git.exe' : 'git';
$makeDiff = static function (string $before, string $after) use ($git): string {
    $process = proc_open([$git, '-c', 'core.autocrlf=false', 'diff', '--no-index', '--no-ext-diff', '--text', '--unified=3', '--', $before, $after], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot run the development diff generator.');
    }
    fclose($pipes[0]);
    $diff = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 1 || !is_string($diff) || !str_contains($diff, '@@ ')) {
        throw new RuntimeException('Cannot generate unified diff: ' . $errors);
    }
    $diff = substr($diff, strpos($diff, '@@ '));
    $lines = [];
    foreach (explode("\n", $diff) as $line) {
        if ($line === ' ') {
            $lines[] = '-';
            $lines[] = '+';
        } else {
            $lines[] = $line;
        }
    }
    return "--- a/src/BroadcastManager.php\n+++ b/src/BroadcastManager.php\n" . implode("\n", $lines);
};
$manifest = json_decode((string)file_get_contents($base . 'manifest.json'), true, 64, JSON_THROW_ON_ERROR);
if ($manifest['approved'][0]['version'] !== '3.2.2' || $manifest['approved'][0]['commit'] !== 'f2792a383416b66bb68e55f09414563e540538d6') {
    throw new RuntimeException('A different dependency needs a separate source and behavior review.');
}
$manifest['reviewed_at'] = '2026-10-16';
$manifest['provenance']['kind'] = 'new-local-presentation-message-transport-and-runtime-lifecycle-hooks';
$manifest['provenance']['note'] = 'Optional lifecycle guard and cancellation, tracked worker/progress draining and bounded flood behavior, retaining exact dev.3 and dev.8 intermediate sources and the pinned upstream baseline.';
$entry = &$manifest['approved'][0];
$entry['patched_sha256'] = hash('sha256', $reviewed);
$entry['intermediates'] = [];
foreach (['baseline/BroadcastManager.php' => 'status-localization-hooks.patch', 'previous/dev3/BroadcastManager.php' => 'dev3-to-transport-hooks.patch', 'previous/dev8/BroadcastManager.php' => 'dev8-to-runtime-lifecycle.patch'] as $source => $patch) {
    $diff = $makeDiff($base . $source, $base . 'reviewed/BroadcastManager.php');
    file_put_contents($base . $patch, $diff);
    if (str_starts_with($source, 'baseline/')) {
        $entry['patch_sha256'] = hash('sha256', $diff);
    } else {
        $entry['intermediates'][] = ['source' => $source, 'sha256' => $approvedInputs[$source], 'patch' => $patch, 'patch_sha256' => hash('sha256', $diff)];
    }
}
file_put_contents($base . 'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo $entry['patched_sha256'], "\n";
