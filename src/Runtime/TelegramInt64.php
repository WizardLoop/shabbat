<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Runtime;

/** MTProto long fields must be native integers, not ambiguous eight-byte strings. */
final class TelegramInt64
{
    public static function fromDecimal(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Expected a canonical decimal int64.');
        }
        $negative = str_starts_with($value, '-');
        $digits = $negative ? substr($value, 1) : $value;
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new \InvalidArgumentException('Decimal integer is outside signed int64 range.');
        }
        return (int) $value;
    }
}
