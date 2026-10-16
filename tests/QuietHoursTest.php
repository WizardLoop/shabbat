<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Scheduling\QuietHours;

final class QuietHoursTest extends TestCase
{
    private function at(string $value): int
    {
        return (new \DateTimeImmutable($value))->getTimestamp();
    }
    public function testDayAndOvernightBoundariesAreExclusiveAtEnd(): void
    {
        self::assertFalse(QuietHours::contains($this->at('2026-10-15T09:14:59+03:00'), 'Asia/Jerusalem', '09:15', '10:00'));
        self::assertTrue(QuietHours::contains($this->at('2026-10-15T09:15:00+03:00'), 'Asia/Jerusalem', '09:15', '10:00'));
        self::assertFalse(QuietHours::contains($this->at('2026-10-15T10:00:00+03:00'), 'Asia/Jerusalem', '09:15', '10:00'));
        self::assertTrue(QuietHours::contains($this->at('2026-10-16T01:00:00+03:00'), 'Asia/Jerusalem', '22:00', '07:00'));
        self::assertSame($this->at('2026-10-16T07:00:00+03:00'), QuietHours::nextBoundary($this->at('2026-10-15T23:00:00+03:00'), 'Asia/Jerusalem', '22:00', '07:00'));
        self::assertNull(QuietHours::nextBoundary($this->at('2026-10-15T23:00:00Z'), 'UTC', '00:00', '00:00'));
    }
    public function testMissingDstEndTransitionsToAllowedAtFirstExistingTime(): void
    {
        $at = $this->at('2026-03-08T01:45:00-05:00');
        self::assertTrue(QuietHours::contains($at, 'America/New_York', '01:00', '02:30'));
        self::assertSame($this->at('2026-03-08T03:00:00-04:00'), QuietHours::nextBoundary($at, 'America/New_York', '01:00', '02:30'));
    }
    public function testRepeatedDstHourCanReenterQuietIntervalAndFindSecondEnd(): void
    {
        $zone = 'America/New_York';
        $firstEnd = $this->at('2026-11-01T01:45:00-04:00');
        self::assertFalse(QuietHours::contains($firstEnd, $zone, '01:00', '01:30'));
        $fold = $this->at('2026-11-01T01:00:00-05:00');
        self::assertSame($fold, QuietHours::nextBoundary($firstEnd, $zone, '01:00', '01:30'));
        self::assertTrue(QuietHours::contains($fold, $zone, '01:00', '01:30'));
        self::assertSame($this->at('2026-11-01T01:30:00-05:00'), QuietHours::nextBoundary($fold, $zone, '01:00', '01:30'));
    }
}
