<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use Shabbat\Http\JsonClient;

final class FullHebcalFixtureClient implements JsonClient
{
    public array $calls = [];
    public static function read(string $file): array
    {
        return json_decode((string)file_get_contents(__DIR__ . '/Fixtures/' . $file), true, 64, JSON_THROW_ON_ERROR);
    }
    public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
    {
        $cancellation?->throwIfRequested();
        $this->calls[] = [$url,$query];
        $month = match ([$query['start'] ?? '',$query['end'] ?? '']) {
            ['2026-09-23','2026-12-10'] => '10',
            ['2026-03-24','2026-06-09'] => '04',
            ['2026-04-23','2026-07-10'] => '05',
            default => throw new \LogicException('Unexpected fixture request range.'),
        };
        if (str_ends_with($url, '/zmanim')) {
            if ((float)$query['latitude'] !== 31.76904 || (float)$query['longitude'] !== 35.21633 || $query['tzid'] !== 'Asia/Jerusalem') {
                throw new \LogicException('Fixture coordinates/timezone must match the unmodified public response.');
            }
            return self::read($month === '10' ? 'hebcal-jerusalem-default-full-solar-2026-09-23_2026-12-10.json' : 'hebcal-jerusalem-full-solar-2026-' . $month . '.json');
        }
        if (!str_ends_with($url, '/hebcal')) {
            throw new \LogicException('No network or extra provider is permitted in this regression.');
        }
        $language = match ($query['lg']) {
            'he' => 'he','s' => 'en','ru' => 'ru','fr' => 'fr','es' => 'es','pt' => 'pt-br',
            default => throw new \LogicException('Unexpected provider language.'),
        };
        if ($query['i'] === 'off') {
            if ($month !== '05' || $language !== 'he') {
                throw new \LogicException('Missing exact diaspora fixture.');
            }
            return self::read('hebcal-jerusalem-full-calendar-2026-05-diaspora.json');
        }
        if ($month === '10' && $language === 'he') {
            return self::read('hebcal-jerusalem-default-full-calendar-2026-09-23_2026-12-10.json');
        }
        $suffix = $language === 'he' ? '' : '-' . $language;
        return self::read('hebcal-jerusalem-full-calendar-2026-' . $month . $suffix . '.json');
    }
}
