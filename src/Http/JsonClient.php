<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Http;

interface JsonClient
{
    public function get(string $url, array $query = [], ?\Amp\Cancellation $cancellation = null): array;
}
