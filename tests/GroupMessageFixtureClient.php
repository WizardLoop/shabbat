<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use Shabbat\Http\JsonClient;

/** Consumer-only daily fixture derived explicitly from full captured calendar events. */
final class GroupMessageFixtureClient implements JsonClient
{
    private FullHebcalFixtureClient $full;
    public function __construct()
    {
        $this->full = new FullHebcalFixtureClient();
    }
    public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
    {
        if (str_ends_with($url, '/hebcal') && ($query['start'] ?? '') === '2026-10-15' && ($query['end'] ?? '') === '2026-10-15') {
            $data = $this->full->get($url, array_replace($query, ['start' => '2026-09-23', 'end' => '2026-12-10']), $cancellation);
            $data['range'] = ['start' => '2026-10-15', 'end' => '2026-10-15'];
            $data['items'] = array_values(array_filter($data['items'], static fn(array $item): bool => $item['date'] === '2026-10-15'));
            return $data;
        }
        return $this->full->get($url, $query, $cancellation);
    }
}
