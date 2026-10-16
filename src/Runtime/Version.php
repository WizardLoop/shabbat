<?php

declare(strict_types=1);

namespace Shabbat\Runtime;

// SPDX-License-Identifier: AGPL-3.0-only

final class Version
{
    private static function root(?string $root): string
    {
        return $root ?? dirname(__DIR__, 2);
    }

    public static function current(?string $root = null): string
    {
        $path = self::root($root) . '/VERSION';
        return is_file($path) ? trim((string) file_get_contents($path)) : 'unknown';
    }

    public static function commit(?string $root = null): string
    {
        $env = getenv('BUILD_COMMIT');
        if (is_string($env) && preg_match('/^[a-f0-9]{40}$/i', $env)) {
            return $env;
        }
        $root = self::root($root);
        $metadata = $root . '/build-info.json';
        if (is_file($metadata)) {
            $data = json_decode((string) file_get_contents($metadata), true);
            if (is_array($data) && isset($data['commit']) && preg_match('/^[a-f0-9]{40}$/i', (string) $data['commit'])) {
                return (string) $data['commit'];
            }
        }
        $head = $root . '/.git/HEAD';
        if (is_file($head)) {
            $value = trim((string) file_get_contents($head));
            if (str_starts_with($value, 'ref: ')) {
                $ref = substr($value, 5);
                if (is_file($root . '/.git/' . $ref)) {
                    return trim((string) file_get_contents($root . '/.git/' . $ref));
                }
            } elseif (preg_match('/^[a-f0-9]{40}$/i', $value)) {
                return $value;
            }
        }
        return 'unknown';
    }

    public static function sourceUrl(?string $root = null): string
    {
        $url = getenv('SOURCE_URL');
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://')
            ? $url : '';
    }

    /** @return array{version:string,commit:string,license:string,source_url:string} */
    public static function metadata(?string $root = null): array
    {
        return ['version' => self::current($root), 'commit' => self::commit($root), 'license' => 'AGPL-3.0-only', 'source_url' => self::sourceUrl($root)];
    }
}
