<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 WizardLoop.
// Local compatibility implementation for MadelineProto's layer-225 entity contract.
// Distributed under the GNU Affero General Public License, version 3 or later.
// This program is provided without any warranty; see the GNU Affero General Public License shipped with MadelineProto.

declare(strict_types=1);

namespace danog\MadelineProto\EventHandler\Message\Entities;

/** A Unix timestamp whose native formatting is performed by Telegram clients. */
final class FormattedDate extends MessageEntity
{
    public function __construct(
        int $offset,
        int $length,
        public readonly int $date,
        public readonly bool $relative = false,
        public readonly bool $shortTime = false,
        public readonly bool $longTime = false,
        public readonly bool $shortDate = false,
        public readonly bool $longDate = false,
        public readonly bool $dayOfWeek = false
    ) {
        foreach ([$offset, $length, $date] as $integer) {
            if ($integer < 0 || $integer > 2147483647) {
                throw new \InvalidArgumentException('Formatted-date fields require nonnegative native int32 values.');
            }
        }
        if (($relative && ($shortTime || $longTime || $shortDate || $longDate || $dayOfWeek))
            || ($shortTime && $longTime) || ($shortDate && $longDate)) {
            throw new \InvalidArgumentException('Contradictory native formatted-date flags.');
        }
        parent::__construct($offset, $length);
    }

    public static function fromMTProto(array $entity): self
    {
        if (($entity['_'] ?? null) !== 'messageEntityFormattedDate') {
            throw new \InvalidArgumentException('Expected the native formatted-date constructor.');
        }
        return new self(
            self::integer($entity, 'offset'),
            self::integer($entity, 'length'),
            self::integer($entity, 'date'),
            self::flag($entity, 'relative'),
            self::flag($entity, 'short_time'),
            self::flag($entity, 'long_time'),
            self::flag($entity, 'short_date'),
            self::flag($entity, 'long_date'),
            self::flag($entity, 'day_of_week')
        );
    }

    public static function fromBotAPI(array $entity): self
    {
        $format = array_key_exists('date_time_format', $entity) ? $entity['date_time_format'] : '';
        if (($entity['type'] ?? null) !== 'date_time' || !is_string($format)
            || preg_match('/^(?:r|w?[dD]?[tT]?)$/D', $format) !== 1) {
            throw new \InvalidArgumentException('Expected an official date_time entity and valid date_time_format.');
        }
        return new self(
            self::integer($entity, 'offset'),
            self::integer($entity, 'length'),
            self::integer($entity, 'unix_time'),
            $format === 'r',
            str_contains($format, 't'),
            str_contains($format, 'T'),
            str_contains($format, 'd'),
            str_contains($format, 'D'),
            str_contains($format, 'w')
        );
    }

    #[\Override]
    public function toMTProto(): array
    {
        return ['_' => 'messageEntityFormattedDate', 'offset' => $this->offset, 'length' => $this->length,
            'date' => $this->date, 'relative' => $this->relative, 'short_time' => $this->shortTime,
            'long_time' => $this->longTime, 'short_date' => $this->shortDate,
            'long_date' => $this->longDate, 'day_of_week' => $this->dayOfWeek];
    }

    #[\Override]
    public function toBotAPI(): array
    {
        $format = $this->relative ? 'r' : ($this->dayOfWeek ? 'w' : '')
            . ($this->shortDate ? 'd' : ($this->longDate ? 'D' : ''))
            . ($this->shortTime ? 't' : ($this->longTime ? 'T' : ''));
        return ['type' => 'date_time', 'offset' => $this->offset, 'length' => $this->length,
            'unix_time' => $this->date, 'date_time_format' => $format];
    }

    private static function integer(array $entity, string $field): int
    {
        if (!array_key_exists($field, $entity) || !is_int($entity[$field])) {
            throw new \InvalidArgumentException('Formatted-date field must be a native integer: ' . $field);
        }
        return $entity[$field];
    }

    private static function flag(array $entity, string $field): bool
    {
        $value = array_key_exists($field, $entity) ? $entity[$field] : false;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('Formatted-date flag must be a native boolean: ' . $field);
        }
        return $value;
    }
}
