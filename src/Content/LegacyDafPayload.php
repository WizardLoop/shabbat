<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Content;

/** Compatibility for the exact generated two-line Daf HTML stored by 3.1.0. */
final class LegacyDafPayload
{
    public static function repair(array $payload): ?array
    {
        if (array_key_exists('entities', $payload) || !is_string($payload['text'] ?? null)) {
            return null;
        }
        if (preg_match('~\A([^\r\n<>]+ - \d{4}-\d{2}-\d{2})\n(https://www\.sefaria\.org/[^\s<>"\']+\?[^\s<>"\']+)\z~u', $payload['text'], $matches) !== 1) {
            return null;
        }
        // The old generator escaped its entire heading already. Recognize that
        // representation without interpreting arbitrary user-supplied HTML.
        if (htmlspecialchars(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') !== $matches[1]) {
            return null;
        }
        $link = htmlspecialchars($matches[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        if ($link === $matches[2]) {
            return null;
        }
        $payload['text'] = $matches[1] . "\n" . $link;
        return $payload;
    }
}
