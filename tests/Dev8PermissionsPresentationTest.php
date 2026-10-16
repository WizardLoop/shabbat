<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\ManualGroupReport;
use Shabbat\UI\Lang;

final class Dev8PermissionsPresentationTest extends TestCase
{
    public static function languages(): array
    {
        return array_map(static fn(string $code): array => [$code], Lang::CODES);
    }

    #[DataProvider('languages')]
    public function testManualResultsDistinguishOldSnapshotFromPostWriteFailure(string $language): void
    {
        $base = ['action' => 'open', 'changed' => 0, 'failed' => 1, 'expires_at' => 1792054800];
        $row = ['group_id' => '-1001', 'title' => 'Test group', 'status' => 'recovery_required', 'changed' => false,
            'error_code' => 'SNAPSHOT_UNVERIFIED', 'recovery_reason' => 'unverified_snapshot'];
        $lang = new Lang();
        $legacy = ManualGroupReport::format($base + ['groups' => [$row]], $language, 'Asia/Jerusalem');
        self::assertStringContainsString($lang->text($language, 'permission_error_unverified'), $legacy);
        $row = ['group_id' => '-1001', 'title' => 'Test group', 'status' => 'conflict', 'changed' => false,
            'mutation_attempted' => true, 'verification_failed' => true, 'different_fields' => ['manage_topics']];
        $readback = ManualGroupReport::format($base + ['groups' => [$row]], $language, 'Asia/Jerusalem');
        self::assertStringContainsString($lang->text($language, 'permission_error_readback'), $readback);
        self::assertStringContainsString($lang->text($language, 'permission_manage_topics'), $readback);
        self::assertStringNotContainsString('manage_topics', $readback);
    }
}
