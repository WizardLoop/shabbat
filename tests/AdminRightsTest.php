<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Telegram\AdminRights;

final class AdminRightsTest extends TestCase
{
    public function testDiagnosticCatalogExactlyMatchesTheLockedNativeConstructor(): void
    {
        $schema = file_get_contents(dirname(__DIR__) . '/vendor/danog/madelineproto/src/TL_telegram_v225.tl');
        self::assertSame(1, preg_match('/^chatAdminRights#[^\r\n]+/m', $schema, $match));
        preg_match_all('/\b([a-z_]+):flags\.\d+\?true/', $match[0], $flags);
        self::assertSame($flags[1], AdminRights::FLAGS);
        foreach (AdminRights::FLAGS as $flag) {
            $normalized = AdminRights::normalize(['_' => 'chatAdminRights', $flag => true]);
            self::assertTrue($normalized[$flag]);
            self::assertCount(17, $normalized);
        }
    }

    public function testUnknownNativeFlagIsNotSilentlyDropped(): void
    {
        $this->expectException(\RuntimeException::class);
        AdminRights::normalize(['_' => 'chatAdminRights', 'future_unreviewed_right' => true]);
    }

    public function testDiagnosticDoesNotAcceptStringsAsGrantedRights(): void
    {
        $this->expectException(\RuntimeException::class);
        AdminRights::normalize(['_' => 'chatAdminRights', 'delete_messages' => 'true']);
    }
}
