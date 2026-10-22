<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Runtime;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ReleaseInputs
{
    public const REQUIRED = ['bot.php', 'bin/runtime-permissions.php', 'bin/check-calendar.php', 'resources/php/99-shabbat-runtime.ini', 'composer.json', 'composer.lock', 'LICENSE', 'VERSION', 'docs/RELEASE_NOTES.md', 'LICENSES/CC-BY-4.0.txt', 'LICENSES/CC-BY-SA-4.0.txt', 'resources/templates/omer-counts.json', 'resources/templates/omer-counts-localized.json', 'resources/templates/omer-liturgy.json', 'migrations/001_initial.sql', 'migrations/002_admin_state.sql', 'migrations/003_manual_payments.sql', 'migrations/004_expand_languages.sql', 'migrations/005_known_broadcast_peers.sql', 'migrations/006_permission_retry.sql', 'migrations/007_delivery_deferrals.sql', 'migrations/008_group_moderation.sql', 'migrations/009_admin_roles_links.sql', 'migrations/010_subscriber_directory.sql', 'migrations/011_custom_star_amounts.sql', 'migrations/012_tefillin_acknowledgements.sql', 'src/Scheduling/TefillinAcknowledgements.php', 'src/Content/GroupMessageButtons.php', 'src/UI/Presentation.php', 'docs/ADMIN_PANEL.md', 'docs/PERMISSION_RECOVERY.md', 'docs/DIAGNOSTICS.md', 'migrations/013_permission_recovery.sql', 'migrations/014_diagnostic_panels.sql', 'src/Scheduling/PermissionRecovery.php', 'src/UI/PermissionRecoveryController.php', 'src/UI/DiagnosticsController.php', 'src/UI/DiagnosticsGateway.php', 'src/Runtime/DiagnosticReport.php', 'src/Storage/DiagnosticsRepository.php', 'docs/CALENDAR_PRESENTATION.md', 'src/UI/StatisticsController.php', 'src/Runtime/RuntimeClientPool.php', 'docs/TEFILLIN_ACKNOWLEDGEMENTS.md', 'docs/SERVER_TEST_DEPLOYMENT.md', 'resources/locales/he.json', 'resources/locales/en.json', 'resources/locales/ru.json', 'resources/locales/fr.json', 'resources/locales/es.json', 'resources/locales/pt-br.json', 'patches/broadcastmanager/manifest.json', 'patches/madelineproto-fresh-peer/manifest.json', 'migrations/015_permission_snapshot_provenance.sql', 'src/Scheduling/PermissionRecoveryProvenance.php', 'migrations/016_delivery_safety.sql', 'src/Scheduling/DeliverySafety.php', 'src/Scheduling/QuietHours.php', 'src/Http/ProviderCircuitClient.php', 'src/Telegram/GuardedTelegramGateway.php', 'docs/DELIVERY_SAFETY.md', '.github/workflows/ci.yml'];

    public static function application(string $root): array
    {
        $roots = ['.github', 'src', 'migrations', 'resources', 'bin', 'scripts', 'patches', 'docs', 'tests', 'assets', 'LICENSES'];
        $top = ['bot.php', 'composer.json', 'composer.lock', 'Dockerfile', 'docker-compose.yml', 'LICENSE', 'VERSION', 'README.md', 'readme-he.md', 'THIRD_PARTY_NOTICES.md', 'CHANGELOG.md', '.env.example', '.gitignore', '.dockerignore', '.gitattributes', '.editorconfig', 'phpunit.xml.dist', 'phpstan.neon', 'phpcs.xml.dist'];
        $required = self::REQUIRED;
        foreach ($required as $path) {
            if (!file_exists($root . '/' . $path)) {
                throw new RuntimeException('Missing required release input: ' . $path);
            }
        }
        $files = [];
        foreach ($top as $path) {
            if (is_file($root . '/' . $path)) {
                $files[$path] = $root . '/' . $path;
            }
        }
        foreach ($roots as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $files[$relative] = $file->getPathname();
                }
            }
        }
        ksort($files);
        return $files;
    }

    public static function fingerprint(array $files): string
    {
        $hashes = [];
        foreach ($files as $path => $absolute) {
            $hash = hash_file('sha256', $absolute);
            if (!is_string($hash)) {
                throw new RuntimeException('Unable to hash application source: ' . $path);
            }
            $hashes[$path] = $hash;
        }
        ksort($hashes);
        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }
}
