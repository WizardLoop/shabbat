<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Tests\Analysis;

/** Uses the real bridge trait for analysis without loading the legacy bot entry point. */
abstract class IntegrationAnalysis extends \danog\MadelineProto\SimpleEventHandler
{
    use \Shabbat\Runtime\BotIntegration;

    /** Contract copied from the existing entry handler, without running it. */
    private function parseButtons(string $input): array
    {
        return [];
    }
}
