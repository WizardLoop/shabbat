<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Runtime\{Application, BotIntegration};

/** Production callback handler with only the application/session boundary replaced. */
final class CallbackRoutingFixture
{
    use BotIntegration;

    public function __construct(private readonly Application $app)
    {
    }

    private function v3App(): Application
    {
        return $this->app;
    }

    public function getSelf(): array
    {
        return ['id' => 999];
    }
}
