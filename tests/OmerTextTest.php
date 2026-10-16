<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Content\OmerText;
use Shabbat\UI\Lang;

final class OmerTextTest extends TestCase
{
    public function testFullVariantsHaveVerifiedDistinctAdditionsInTheCorrectOrder(): void
    {
        $ashkenaz = OmerText::render(7, 'ashkenaz', true);
        $sephard = OmerText::render(7, 'sephard', true);
        self::assertStringNotContainsString('לשם יחוד', $ashkenaz);
        self::assertStringContainsString('לשם יחוד קודשא בריך הוא', $sephard);
        self::assertStringNotContainsString('ועל ידי זה יושפע שפע רב', $ashkenaz);
        self::assertStringContainsString('ועל ידי זה יושפע שפע רב', $sephard);
        foreach ([$ashkenaz,$sephard] as $text) {
            $last = -1;
            foreach (['הנני מוכן','ברוך אתה','הַיּוֹם','הרחמן','למנצח בנגינות','אנא בכוח','ריבונו של עולם','מלכות שבחסד'] as $part) {
                $position = mb_strpos($text, $part);
                self::assertNotFalse($position);
                self::assertGreaterThan($last, $position);
                $last = $position;
            }
            self::assertStringContainsString('ברכם טהרם רחמם', $text);
            self::assertStringContainsString('ברוך שם כבוד מלכותו לעולם ועד', $text);
        }
    }
    public function testFullFortyNineTextsFitTelegramAndDayCompletionNeverContainsTheBlessingOrPreparations(): void
    {
        foreach (Lang::CODES as $language) {
            foreach (['ashkenaz','sephard'] as $nusach) {
                for ($day = 1; $day <= 49; $day++) {
                    $full = OmerText::render($day, $nusach, true, false, $language);
                    self::assertLessThan(3700, strlen(mb_convert_encoding($full, 'UTF-16LE', 'UTF-8')) / 2);
                    $dayText = OmerText::render($day, $nusach, true, true, $language);
                    self::assertStringNotContainsString('ברוך אתה', $dayText);
                    self::assertStringNotContainsString('לשם יחוד', $dayText);
                }
            }
        }
        self::assertStringContainsString('מלכות שבמלכות', OmerText::render(49, 'ashkenaz', true));
        self::assertStringContainsString('חסד שבגבורה', OmerText::render(8, 'sephard', true));
    }
    public function testResourcesRetainPinnedSourceLicenseAndDoNotUseIncompleteWebCountList(): void
    {
        $resource = json_decode(file_get_contents(dirname(__DIR__) . '/resources/templates/omer-liturgy.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('CC-BY-SA-4.0', $resource['license']);
        self::assertCount(4, $resource['sources']);
        foreach ($resource['sources'] as $source) {
            self::assertStringContainsString('oldid=' . $source['revision'], $source['url']);
        }
        self::assertCount(49, $resource['daily_sefira']);
        self::assertStringContainsString('Hebcal', $resource['notes']);
    }
}
