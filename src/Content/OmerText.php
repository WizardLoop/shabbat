<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Verified Hebrew/English count data: Hebcal.com, CC-BY-4.0. Traditional blessing is public-domain liturgy.
declare(strict_types=1);

namespace Shabbat\Content;

use Shabbat\UI\Lang;

final class OmerText
{
    private static ?array $counts = null;
    private static ?array $localized = null;
    private static ?Lang $lang = null;
    private static ?array $liturgy = null;
    public static function count(int $day, string $nusach = 'sephardi', string $language = 'he'): string
    {
        $nusach = match ($nusach) {
            'ashkenaz'=>'ashkenazi','sephard'=>'sephardi',default=>$nusach
        };
        if ($day < 1 || $day > 49) {
            throw new \InvalidArgumentException('Omer day must be 1..49.');
        }
        if (!in_array($nusach, ['sephardi','ashkenazi'], true) || !in_array($language, Lang::CODES, true)) {
            throw new \InvalidArgumentException('Unsupported counting text.');
        }
        self::load();
        $ritual = self::$counts[(string)$day]['he'];
        if ($nusach === 'ashkenazi') {
            $ritual = str_replace('לָעֽוֹמֶר', 'בָּעֽוֹמֶר', $ritual);
        }
        if ($language === 'he') {
            return $ritual;
        }
        $summary = $language === 'en' ? self::$counts[(string)$day]['en'] : self::$localized[(string)$day][$language];
        self::$lang ??= new Lang();
        return $ritual . "\n" . self::$lang->text($language, 'omer_translation_note') . "\n" . $summary;
    }
    public static function render(int $day, string $nusach = 'sephardi', bool $full = false, bool $dayCompletion = false, string $language = 'he'): string
    {
        $count = self::count($day, $nusach, $language);
        self::$lang ??= new Lang();
        if ($dayCompletion) {
            return $count . "\n" . self::$lang->text($language, 'omer_day_completion_label');
        }
        if (!$full) {
            return $count;
        }
        $ritual = self::liturgy();
        $variant = in_array($nusach, ['ashkenaz','ashkenazi'], true) ? 'ashkenaz' : 'sephard';
        return implode("\n\n", [
            self::$lang->text($language, 'omer_blessing_eligibility'),$ritual['preparation_' . $variant],
            $ritual['blessing'],$count,$ritual['harachaman'],$ritual['psalm67'],$ritual['ana_bekoach'],
            $ritual['prayer_start'] . ' ' . $ritual['daily_sefira'][(string)$day] . ', ' . $ritual['prayer_end_' . $variant],
            'Hebrew Wikisource contributors · CC-BY-SA-4.0' . "\n"
                . 'https://he.wikisource.org/w/index.php?oldid=' . ($variant === 'ashkenaz' ? '3017054' : '2929975') . "\n"
                . 'https://creativecommons.org/licenses/by-sa/4.0/',
        ]);
    }
    private static function liturgy(): array
    {
        if (self::$liturgy !== null) {
            return self::$liturgy;
        }
        $source = file_get_contents(dirname(__DIR__, 2) . '/resources/templates/omer-liturgy.json');
        if ($source === false) {
            throw new \RuntimeException('Bundled Omer liturgy is missing.');
        }
        $data = json_decode($source, true, 64, JSON_THROW_ON_ERROR);
        foreach (['preparation_ashkenaz','preparation_sephard','blessing','harachaman','psalm67','ana_bekoach','prayer_start','prayer_end_ashkenaz','prayer_end_sephard'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new \RuntimeException('Incomplete verified Omer liturgy.');
            }
        }
        for ($day = 1; $day <= 49; $day++) {
            if (!is_string($data['daily_sefira'][(string)$day] ?? null) || $data['daily_sefira'][(string)$day] === '') {
                throw new \RuntimeException('Incomplete verified Omer day additions.');
            }
        }
        return self::$liturgy = $data;
    }
    private static function load(): void
    {
        if (self::$counts !== null) {
            return;
        }
        $root = dirname(__DIR__, 2) . '/resources/templates/';
        $basic = file_get_contents($root . 'omer-counts.json');
        $translated = file_get_contents($root . 'omer-counts-localized.json');
        if ($basic === false || $translated === false) {
            throw new \RuntimeException('Bundled Omer text resources are missing.');
        }
        $counts = json_decode($basic, true, 64, JSON_THROW_ON_ERROR)['counts'] ?? null;
        $localized = json_decode($translated, true, 64, JSON_THROW_ON_ERROR)['counts'] ?? null;
        if (!is_array($counts) || !is_array($localized)) {
            throw new \RuntimeException('Invalid Omer text resources.');
        }
        for ($day = 1; $day <= 49; $day++) {
            foreach (['he','en'] as $code) {
                if (!is_string($counts[(string)$day][$code] ?? null) || $counts[(string)$day][$code] === '') {
                    throw new \RuntimeException('Incomplete Omer count table.');
                }
            }
            foreach (['ru','fr','es','pt-br'] as $code) {
                if (!is_string($localized[(string)$day][$code] ?? null) || $localized[(string)$day][$code] === '') {
                    throw new \RuntimeException('Incomplete localized Omer count table.');
                }
            }
            if (($localized[(string)$day]['day'] ?? null) !== $day || ($localized[(string)$day]['weeks'] ?? null) !== intdiv($day, 7) || ($localized[(string)$day]['days'] ?? null) !== $day % 7) {
                throw new \RuntimeException('Localized Omer week/day structure is inconsistent.');
            }
        }
        self::$counts = $counts;
        self::$localized = $localized;
    }
}
