<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\EventHandler\Media\GeoPoint;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class GeoPointCompatibilityTest extends TestCase
{
    public function testOfficialConstructorPreservesFiniteCoordinatesAndJsonRoundtrip(): void
    {
        self::assertSame('float', (string)(new ReflectionProperty(GeoPoint::class, 'long'))->getType());
        self::assertSame('float', (string)(new ReflectionProperty(GeoPoint::class, 'lat'))->getType());
        foreach ([[31.778, 35.235], [-33.8688, -151.2093], [0.0, 0.0], [90.0, -180.0], [0, 0]] as [$latitude, $longitude]) {
            $geo = new GeoPoint(['long' => $longitude, 'lat' => $latitude, 'access_hash' => 123, 'accuracy_radius' => 7]);
            self::assertSame((float)$latitude, $geo->lat);
            self::assertSame((float)$longitude, $geo->long);
            self::assertTrue(is_finite($geo->lat) && is_finite($geo->long));
            self::assertSame(123, $geo->accessHash);
            self::assertSame(7, $geo->accuracyRadius);
            $json = json_decode(json_encode($geo, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame((float)$latitude, $json['lat']);
            self::assertSame((float)$longitude, $json['long']);
            $roundtrip = new GeoPoint(['long' => $json['long'], 'lat' => $json['lat'], 'access_hash' => $json['accessHash'], 'accuracy_radius' => $json['accuracyRadius']]);
            self::assertSame($geo->jsonSerialize(), $roundtrip->jsonSerialize());
        }
        self::assertNull((new GeoPoint(['long' => 0, 'lat' => 0, 'access_hash' => 0]))->accuracyRadius);
    }
}
