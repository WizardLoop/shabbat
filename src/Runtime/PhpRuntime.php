<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Runtime;

/** Core-only gate, available before Composer can load a session or dependency. */
final class PhpRuntime
{
    private const MINIMUM_VERSION_IDS = [
        803 => 80336,
        804 => 80427,
        805 => 80512,
    ];

    public static function supports(int $versionId, int $integerSize): bool
    {
        $minimum = self::MINIMUM_VERSION_IDS[intdiv($versionId, 100)] ?? null;
        return $integerSize === 8 && $minimum !== null && $versionId >= $minimum;
    }

    /** Accept stable upstream releases and distro/build suffixes, but never prereleases. */
    public static function isStableRelease(string $version): bool
    {
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(.*)$/D', $version, $parts)) {
            return false;
        }
        $suffix = $parts[1];
        if ($suffix === '') {
            return true;
        }
        if (!preg_match('/^[-+~][a-z0-9.+_~\-]+$/iD', $suffix)) {
            return false;
        }
        return !preg_match('/(?:^|[-+._~])(?:dev|alpha|beta|rc|snapshot|preview|nightly)[0-9]*(?=$|[-+._~])/i', $suffix);
    }

    /** No configuration, environment variable or Composer option bypasses this gate. */
    public static function assertSupported(): void
    {
        if (!self::supports(PHP_VERSION_ID, PHP_INT_SIZE) || !self::isStableRelease(PHP_VERSION)) {
            throw new \RuntimeException(
                'Unsupported PHP runtime. Require stable 64-bit PHP 8.3.36+, 8.4.27+ or 8.5.12+ '
                . 'within those supported branches; running ' . PHP_VERSION . '.'
            );
        }
    }
}
