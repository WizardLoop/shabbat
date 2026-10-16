<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Location;

use Amp\Cancellation;
use Shabbat\Http\JsonClient;
use Shabbat\Time\Clock;

/** GeoNames provides location identifiers, never halachic times. */
final class GeoNames
{
    private array $lastRequest = [];
    private array $cache = [];
    public function __construct(private JsonClient $http, private Clock $clock, private string $username, private string $endpoint = 'https://secure.geonames.org')
    {
        if (!str_starts_with($endpoint, 'https://')) {
            throw new LocationException('GeoNames requires verified HTTPS');
        }
    }
    public function configured(): bool
    {
        return trim($this->username) !== '' && strtolower(trim($this->username)) !== 'demo';
    }
    public static function normalize(string $query): string
    {
        if (!mb_check_encoding($query, 'UTF-8')) {
            throw new LocationException('Invalid UTF-8 query');
        }
        $query = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), 'UTF-8');
        if (mb_strlen($query) < 2 || mb_strlen($query) > 120) {
            throw new LocationException('City query must contain 2–120 characters');
        }
        return $query;
    }
    public function search(string $query, int $offset = 0, string $actor = 'public', ?Cancellation $cancellation = null, string $language = 'en'): array
    {
        $query = self::normalize($query);
        if (!$this->configured()) {
            throw new LocationException('GEONAMES_USERNAME is not configured');
        }
        if ($offset < 0 || $offset > 1000) {
            throw new LocationException('Invalid search page');
        }
        $language = $this->providerLanguage($language);
        $key = hash('sha256', $query . ':' . $offset . ':' . $language);
        $now = $this->clock->now()->getTimestamp();
        if (($this->cache[$key]['expires'] ?? 0) > $now) {
            return $this->cache[$key]['value'];
        }
        if (($this->lastRequest[$actor] ?? 0) > $now - 1) {
            throw new LocationException('Please wait before another location search');
        }
        if (count($this->lastRequest) >= 1024) {
            foreach ($this->lastRequest as $keyActor => $last) {
                if ($last < $now - 1) {
                    unset($this->lastRequest[$keyActor]);
                }
            }
            if (count($this->lastRequest) >= 1024) {
                throw new LocationException('Location provider is busy; please try again shortly');
            }
        }
        $this->lastRequest[$actor] = $now;
        $body = $this->http->get($this->endpoint . '/searchJSON', ['q' => $query, 'username' => $this->username, 'maxRows' => 10, 'startRow' => $offset, 'style' => 'FULL', 'featureClass' => 'P', 'lang' => $language], $cancellation);
        $this->assertSuccess($body);
        if (!isset($body['geonames']) || !is_array($body['geonames'])) {
            throw new LocationException('Malformed GeoNames search response');
        }
        $items = [];
        foreach ($body['geonames'] as $row) {
            if (!is_array($row) || empty($row['geonameId']) || empty($row['name']) || !isset($row['lat'], $row['lng'])) {
                throw new LocationException('Malformed GeoNames place');
            }
            if (!is_numeric($row['lat']) || !is_numeric($row['lng']) || !is_finite((float) $row['lat']) || !is_finite((float) $row['lng']) || abs((float) $row['lat']) > 90 || abs((float) $row['lng']) > 180 || !preg_match('/^[1-9][0-9]*$/D', (string) $row['geonameId'])) {
                throw new LocationException('Invalid GeoNames place coordinates or identifier');
            }
            $items[] = ['geoname_id' => (int) $row['geonameId'], 'location_name' => (string) $row['name'], 'country' => (string) ($row['countryName'] ?? $row['countryCode'] ?? ''), 'admin_region' => (string) ($row['adminName1'] ?? ''), 'latitude' => (float) $row['lat'], 'longitude' => (float) $row['lng'], 'timezone_id' => $row['timezone']['timeZoneId'] ?? $row['timezone']['timezoneId'] ?? null, 'location_source' => 'geonames'];
        }
        $total = (int) ($body['totalResultsCount'] ?? count($items));
        $result = ['items' => $items, 'total' => $total, 'next_offset' => $offset + count($items) < min($total, 1010) ? $offset + count($items) : null, 'query_key' => $key];
        if (count($this->cache) >= 128) {
            array_shift($this->cache);
        }
        $this->cache[$key] = ['expires' => $now + 3600, 'value' => $result];
        return $result;
    }
    /** Revalidate only the selected place and fill its IANA zone. */
    public function get(int $id, ?Cancellation $cancellation = null, string $language = 'en'): array
    {
        if ($id < 1 || !$this->configured()) {
            throw new LocationException('GeoNames place cannot be verified');
        }
        $body = $this->http->get($this->endpoint . '/getJSON', ['geonameId' => $id, 'username' => $this->username, 'style' => 'FULL', 'lang' => $this->providerLanguage($language)], $cancellation);
        $this->assertSuccess($body);
        if ((int) ($body['geonameId'] ?? 0) !== $id || !isset($body['lat'], $body['lng'], $body['name'])) {
            throw new LocationException('GeoNames place ID mismatch');
        }
        $zone = $body['timezone']['timeZoneId'] ?? $body['timezone']['timezoneId'] ?? null;
        if (!$zone) {
            $tz = $this->http->get($this->endpoint . '/timezoneJSON', ['lat' => $body['lat'], 'lng' => $body['lng'], 'username' => $this->username], $cancellation);
            $this->assertSuccess($tz);
            $zone = $tz['timezoneId'] ?? null;
        }
        return Place::validate(['geoname_id' => $id, 'location_name' => $body['name'], 'country' => $body['countryName'] ?? $body['countryCode'] ?? '', 'admin_region' => $body['adminName1'] ?? '', 'latitude' => $body['lat'], 'longitude' => $body['lng'], 'timezone_id' => $zone, 'location_source' => 'geonames']);
    }
    /** Only timezoneId is authoritative; provider offsets/sun times are not calculation inputs. */
    public function timezone(float $latitude, float $longitude, ?Cancellation $cancellation = null, string $language = 'en'): array
    {
        if (!$this->configured()) {
            throw new LocationException('GEONAMES_USERNAME is not configured; choose an explicit IANA timezone instead');
        }
        Place::validate(['latitude' => $latitude, 'longitude' => $longitude, 'timezone_id' => 'UTC', 'location_name' => 'Coordinates']);
        $body = $this->http->get($this->endpoint . '/timezoneJSON', ['lat' => $latitude, 'lng' => $longitude, 'username' => $this->username, 'lang' => $this->providerLanguage($language)], $cancellation);
        $this->assertSuccess($body);
        $zone = $body['timezoneId'] ?? null;
        if (!is_string($zone) || !in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            throw new LocationException('GeoNames did not return a verified IANA timezone');
        }
        return ['timezone_id' => $zone, 'country' => (string) ($body['countryName'] ?? $body['countryCode'] ?? '')];
    }
    private function providerLanguage(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        if ($language === 'pt-br') {
            $language = 'pt';
        }
        return in_array($language, ['he','en','ru','fr','es','pt'], true) ? $language : 'en';
    }
    private function assertSuccess(array $body): void
    {
        if (isset($body['status'])) {
            throw new LocationException('GeoNames provider error ' . (int) ($body['status']['value'] ?? 0) . ': ' . (string) ($body['status']['message'] ?? 'unknown'));
        }
    }
}
