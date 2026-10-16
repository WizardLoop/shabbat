<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Http;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;

final class AmpJsonClient implements JsonClient
{
    private HttpClient $client;
    public function __construct(?HttpClient $client = null)
    {
        $this->client = $client ?? HttpClientBuilder::buildDefault();
    }
    public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new ProviderException('Providers require HTTPS.');
        }
        $request = new Request($url . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
        $request->setHeader('Accept', 'application/json');
        $request->setTransferTimeout(10);
        $request->setInactivityTimeout(5);
        $request->setBodySizeLimit(2097152);
        $response = $this->client->request($request, $cancellation);
        if ($response->getStatus() !== 200) {
            throw new ProviderException('Provider HTTP status ' . $response->getStatus());
        }
        try {
            $data = json_decode($response->getBody()->buffer($cancellation), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException('Invalid provider JSON.', 0, $e);
        }
        if (!is_array($data)) {
            throw new ProviderException('Provider did not return an object.');
        }
        return $data;
    }
}
