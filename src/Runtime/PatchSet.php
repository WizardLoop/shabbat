<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Runtime;

final class PatchSet
{
    private const LIBRARIES = ['madelineproto', 'madelineproto-geopoint', 'madelineproto-formatted-date', 'madelineproto-fresh-peer', 'broadcastmanager'];

    /** @return array<string,string> */
    public static function process(string $root, bool $apply, ?string $vendor = null): array
    {
        $result = [];
        foreach (self::LIBRARIES as $library) {
            $result[$library] = (new PatchManager($root, $vendor, $root . '/patches/' . $library . '/manifest.json'))->process($apply);
        }
        return $result;
    }
}
