<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Http;

use Amp\Cancellation;
use Shabbat\Time\Clock;

use function Amp\async;

final class SharedJsonClient implements JsonClient
{
    private array $cache = [];
    private array $pending = [];
    private array $failures = [];
    public function __construct(private JsonClient $inner, private Clock $clock, private int $ttl = 3600, private int $limit = 256)
    {
        if ($ttl < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Invalid shared provider cache bounds.');
        }
    }
    public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
    {
        $cancellation?->throwIfRequested();
        ksort($query);
        $key = hash('sha256', $url . json_encode($query, JSON_THROW_ON_ERROR));
        $now = $this->clock->now()->getTimestamp();
        $cached = $this->cache[$key] ?? null;
        if ($cached !== null && $cached['created'] <= $now && $cached['expires'] > $now) {
            return $cached['data'];
        }
        $failure = $this->failures[$key] ?? null;
        if ($failure !== null && $failure['created'] <= $now && $failure['until'] > $now) {
            throw new ProviderException('Provider request temporarily in cooldown.');
        }
        if (!isset($this->pending[$key])) {
            if (count($this->pending) >= min(32, $this->limit)) {
                throw new ProviderException('Too many distinct provider requests are already in flight.');
            }
            // A caller cancelling its wait does not cancel the request shared by other groups.
            // Cache/failure recording belongs to the single producer, not every waiter.
            $this->pending[$key] = async(function () use ($url, $query, $key): array {
                try {
                    $data = $this->inner->get($url, $query);
                    if (count($this->cache) >= $this->limit && !isset($this->cache[$key])) {
                        unset($this->cache[array_key_first($this->cache)]);
                    }
                    $now = $this->clock->now()->getTimestamp();
                    $this->cache[$key] = ['created' => $now, 'expires' => $now + $this->ttl, 'data' => $data];
                    unset($this->failures[$key]);
                    return $data;
                } catch (\Amp\CancelledException $error) {
                    throw $error;
                } catch (\Throwable $error) {
                    if (count($this->failures) >= $this->limit && !isset($this->failures[$key])) {
                        unset($this->failures[array_key_first($this->failures)]);
                    }
                    $now = $this->clock->now()->getTimestamp();
                    $this->failures[$key] = ['created' => $now, 'until' => $now + 15];
                    throw $error;
                } finally {
                    unset($this->pending[$key]);
                }
            });
            $this->pending[$key]->ignore();
        }
        return $this->pending[$key]->await($cancellation);
    }
}
