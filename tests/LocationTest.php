<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Location\LocationException;
use Shabbat\Location\Place;
use Shabbat\Time\FrozenClock;

final class LocationTest extends TestCase
{
    private function clock(): FrozenClock
    {
        return new FrozenClock(new \DateTimeImmutable('2026-10-13T12:00:00Z'));
    }
    private function http(array $responses): JsonClient
    {
        return new class ($responses) implements JsonClient
        {
            public array $calls = [];
            public function __construct(private array $responses)
            {
            }
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                $this->calls[] = [$url, $query];
                $cancellation?->throwIfRequested();
                return array_shift($this->responses) ?? throw new \RuntimeException('unexpected HTTP call');
            }
        };
    }
    private function row(): array
    {
        return ['geonameId' => 281184, 'name' => 'ירושלים', 'countryName' => 'Israel', 'adminName1' => 'Jerusalem', 'lat' => '31.76904', 'lng' => '35.21633'];
    }
    public function testHebrewNormalizationCacheAndPaging(): void
    {
        $http = $this->http([['geonames' => [$this->row()], 'totalResultsCount' => 15]]);
        $geo = new GeoNames($http, $this->clock(), 'fixture');
        $a = $geo->search('  ירושלים   ', 0, '1');
        $b = $geo->search('ירושלים', 0, '2');
        self::assertSame($a, $b);
        self::assertCount(1, $http->calls);
        self::assertSame(1, $a['next_offset']);
        self::assertSame('ירושלים', $http->calls[0][1]['q']);
        self::assertStringStartsWith('https://', $http->calls[0][0]);
    }
    public function testHttp200QuotaErrorIsNotAnEmptyResult(): void
    {
        $geo = new GeoNames($this->http([['status' => ['value' => 18, 'message' => 'quota exceeded']]]), $this->clock(), 'fixture');
        $this->expectException(LocationException::class);
        $this->expectExceptionMessage('18');
        $geo->search('Jerusalem');
    }
    public function testMalformedResponseFails(): void
    {
        $geo = new GeoNames($this->http([['geonames' => 'invalid']]), $this->clock(), 'fixture');
        $this->expectException(LocationException::class);
        $geo->search('Jerusalem');
    }
    public function testMissingAccountFailsBeforeCallingProvider(): void
    {
        $http = $this->http([]);
        $geo = new GeoNames($http, $this->clock(), '');
        self::assertFalse($geo->configured());
        try {
            $geo->search('London');
            self::fail('missing username accepted');
        } catch (LocationException $e) {
            self::assertStringContainsString('GEONAMES_USERNAME', $e->getMessage());
        }
        self::assertCount(0, $http->calls);
    }
    public function testOnlySelectionRequiresTimezoneResolution(): void
    {
        $http = $this->http([['geonames' => [$this->row()]], $this->row(), ['timezoneId' => 'Asia/Jerusalem', 'rawOffset' => 2]]);
        $geo = new GeoNames($http, $this->clock(), 'fixture');
        $geo->search('Jerusalem');
        self::assertCount(1, $http->calls);
        $place = $geo->get(281184);
        self::assertSame('Asia/Jerusalem', $place['timezone_id']);
        self::assertCount(3, $http->calls);
        self::assertStringEndsWith('/timezoneJSON', $http->calls[2][0]);
    }
    public function testReliableTimezoneAvoidsExtraCall(): void
    {
        $row = $this->row() + ['timezone' => ['timeZoneId' => 'Asia/Jerusalem']];
        $http = $this->http([$row]);
        self::assertSame('Asia/Jerusalem', (new GeoNames($http, $this->clock(), 'fixture'))->get(281184)['timezone_id']);
        self::assertCount(1, $http->calls);
    }
    public function testOffsetsCannotReplaceIanaTimezone(): void
    {
        $geo = new GeoNames($this->http([$this->row(), ['rawOffset' => 2, 'gmtOffset' => 3]]), $this->clock(), 'fixture');
        $this->expectException(LocationException::class);
        $geo->get(281184);
    }
    public function testExplicitCancellationPropagates(): void
    {
        $cancel = new \Amp\DeferredCancellation();
        $cancel->cancel();
        $geo = new GeoNames($this->http([]), $this->clock(), 'fixture');
        $this->expectException(\Amp\CancelledException::class);
        $geo->search('London', 0, '1', $cancel->getCancellation());
    }
    public function testRateLimitAndTimeoutAreNotEmptyResults(): void
    {
        $http = $this->http([['geonames' => []]]);
        $geo = new GeoNames($http, $this->clock(), 'fixture');
        $geo->search('London', 0, '1');
        $this->expectException(LocationException::class);
        $geo->search('Paris', 0, '1');
    }
    public function testCoordinatesAndTimezoneAreValidated(): void
    {
        foreach (Place::common() as $place) {
            self::assertSame($place, Place::validate($place));
        }
        $this->expectException(LocationException::class);
        Place::validate(['location_name' => 'invalid', 'latitude' => 91, 'longitude' => 0, 'timezone_id' => 'Asia/Jerusalem']);
    }
    public function testCoordinateTimezoneRequiresReliableIanaAndChecksStatus(): void
    {
        $geo = new GeoNames($this->http([['timezoneId' => 'Europe/London', 'countryName' => 'United Kingdom', 'rawOffset' => 0, 'sunset' => 'untrusted']]), $this->clock(), 'fixture');
        self::assertSame(['timezone_id' => 'Europe/London', 'country' => 'United Kingdom'], $geo->timezone(51.5, -0.1));
        foreach ([['status' => ['value' => 19, 'message' => 'hourly quota']], ['rawOffset' => 3, 'gmtOffset' => 2]] as $response) {
            try {
                (new GeoNames($this->http([$response]), $this->clock(), 'fixture'))->timezone(31.7, 35.2);
                self::fail('unverified timezone accepted');
            } catch (LocationException) {
                self::assertTrue(true);
            }
        }
    }
    public function testAutomaticTimezoneDisabledWithoutAccount(): void
    {
        $http = $this->http([]);
        $geo = new GeoNames($http, $this->clock(), '');
        try {
            $geo->timezone(31.7, 35.2);
            self::fail('missing account accepted');
        } catch (LocationException $e) {
            self::assertStringContainsString('IANA', $e->getMessage());
        }
        self::assertSame([], $http->calls);
    }
    public function testTimeoutIsAnExplicitProviderFailure(): void
    {
        $http = new class implements JsonClient
        {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \Shabbat\Http\ProviderException('request timed out');
            }
        };
        $this->expectException(\Shabbat\Http\ProviderException::class);
        $this->expectExceptionMessage('timed out');
        (new GeoNames($http, $this->clock(), 'fixture'))->search('London');
    }
    public function testDemoAccountIsNotEnabled(): void
    {
        $http = $this->http([]);
        $geo = new GeoNames($http, $this->clock(), 'demo');
        self::assertFalse($geo->configured());
        try {
            $geo->search('London');
            self::fail('demo account accepted');
        } catch (LocationException) {
            self::assertSame([], $http->calls);
        }
    }
    public function testMalformedCoordinatesInSearchAreRejected(): void
    {
        $row = $this->row();
        $row['lat'] = 'not a number';
        $geo = new GeoNames($this->http([['geonames' => [$row]]]), $this->clock(), 'fixture');
        $this->expectException(LocationException::class);
        $geo->search('London');
    }
    public function testInsecureEndpointRejected(): void
    {
        $this->expectException(LocationException::class);
        new GeoNames($this->http([]), $this->clock(), 'fixture', 'http://api.geonames.org');
    }
}
