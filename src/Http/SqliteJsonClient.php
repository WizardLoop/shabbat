<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Http;

use Amp\Cancellation;
use Shabbat\Storage\Database;
use Shabbat\Time\Clock;

final class SqliteJsonClient implements JsonClient
{
    public function __construct(private JsonClient $inner, private Database $db, private Clock $clock, private int $ttl = 21600)
    {
        if ($ttl < 1) {
            throw new \InvalidArgumentException('Cache TTL must be positive.');
        }
    }
    public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
    {
        $cancellation?->throwIfRequested();
        ksort($query);
        $key = hash('sha256', 'json-v1:' . $url . json_encode($query, JSON_THROW_ON_ERROR));
        $get = $this->db->pdo()->prepare('SELECT * FROM calendar_cache WHERE cache_key=?');
        $get->execute([$key]);
        $cached = $get->fetch();
        $now = $this->clock->now()->getTimestamp();
        if ($cached !== false && $cached['expires_at'] > $now) {
            return $this->cached($cached, false);
        }
        try {
            $data = $this->inner->get($url, $query, $cancellation);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            // Provider consumers still validate coverage/events. This fallback is visible, never "no event".
            if ($cached !== false && parse_url($url, PHP_URL_HOST) === 'www.hebcal.com') {
                return $this->cached($cached, true) + ['__shabbat_refresh_error' => 'Provider refresh failed; using previously fetched data after coverage validation.'];
            }
            throw $error;
        }
        $cancellation?->throwIfRequested();
        unset($data['__shabbat_cache'], $data['__shabbat_refresh_error']);
        $this->db->pdo()->prepare('INSERT INTO calendar_cache(cache_key,provider,payload_json,fetched_at,expires_at,coverage_start,coverage_end) VALUES(?,?,?,?,?,?,?) ON CONFLICT(cache_key) DO UPDATE SET payload_json=excluded.payload_json,fetched_at=excluded.fetched_at,expires_at=excluded.expires_at,coverage_start=excluded.coverage_start,coverage_end=excluded.coverage_end')
            ->execute([$key,parse_url($url, PHP_URL_HOST),json_encode($data, JSON_THROW_ON_ERROR),$now,$now + $this->ttl,$query['start'] ?? null,$query['end'] ?? null]);
        return $data + ['__shabbat_cache' => ['fetched_at' => $now,'expires_at' => $now + $this->ttl,'stale' => false]];
    }
    private function cached(array $row, bool $stale): array
    {
        try {
            $data = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ProviderException('Cached provider JSON is corrupt.', 0, $error);
        }
        if (!is_array($data)) {
            throw new ProviderException('Cached provider response is malformed.');
        }
        return $data + ['__shabbat_cache' => ['fetched_at' => (int)$row['fetched_at'],'expires_at' => (int)$row['expires_at'],'stale' => $stale]];
    }
}
