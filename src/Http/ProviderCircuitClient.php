<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Http;

use Amp\Cancellation;
use Shabbat\Time\Clock;

/** Short host-level cooldown below the persistent cache, never a substitute calendar. */
final class ProviderCircuitClient implements JsonClient
{
    private array $hosts = [];
    public function __construct(private JsonClient $inner, private Clock $clock, private int $threshold = 3, private int $cooldown = 30)
    {
        if ($threshold < 1 || $cooldown < 1) {
            throw new \InvalidArgumentException('Invalid provider circuit bounds.');
        }
    }

    public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
    {
        $cancellation?->throwIfRequested();
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $now = $this->clock->now()->getTimestamp();
        $state = $this->hosts[$host] ?? ['failures' => 0, 'until' => 0, 'last' => $now];
        if ($now < $state['last']) {
            $state = ['failures' => 0, 'until' => 0, 'last' => $now];
        }
        if ($state['until'] > $now) {
            throw new ProviderException('Provider temporarily in cooldown after repeated failures.');
        }
        try {
            $result = $this->inner->get($url, $query, $cancellation);
            unset($this->hosts[$host]);
            return $result;
        } catch (\Amp\CancelledException $error) {
            // A deliberate caller/shutdown cancellation is not evidence of an outage.
            if (!$error->getPrevious() instanceof \Amp\TimeoutException) {
                throw $error;
            }
            $this->failed($host);
            throw $error;
        } catch (\Throwable $error) {
            $this->failed($host);
            throw $error;
        }
    }

    private function failed(string $host): void
    {
        $now = $this->clock->now()->getTimestamp();
        $previous = $this->hosts[$host] ?? null;
        $failures = $previous === null || $now < $previous['last'] || $now - $previous['last'] > $this->cooldown
            ? 1 : min($this->threshold, $previous['failures'] + 1);
        if (count($this->hosts) >= 32 && !isset($this->hosts[$host])) {
            unset($this->hosts[array_key_first($this->hosts)]);
        }
        $this->hosts[$host] = ['failures' => $failures, 'last' => $now, 'until' => $failures >= $this->threshold ? $now + $this->cooldown : 0];
    }
}
