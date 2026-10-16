<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Content;

use Shabbat\UI\{Lang, Presentation};

/** The same inline-share action used by the original group announcements. */
final class GroupMessageButtons
{
    public static function defaults(string $kind, string $language): array
    {
        $module = match ($kind) {
            'times_alert', 'shabbat', 'shabat' => 'shabbat',
            'zmanim', 'daf' => $kind,
            default => null,
        };
        if ($module === null) {
            return [];
        }
        return [[Presentation::button([
            'text' => (new Lang())->text($language, 'share'),
            'switch_inline_query' => $module === 'shabbat' ? 'shabat' : $module,
        ], key: $module)]];
    }
}
