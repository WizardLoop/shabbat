<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\{GroupMessage,PrivateMessage};
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService,HebcalProvider};
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\{Application,Version};
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;
use Shabbat\UI\Lang;

final class ReplyScopeTest extends TestCase
{
    private Application $app;
    private ReplyScopeFixture $bridge;
    private RuntimeFakeGateway $gateway;
    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-13T12:00:00Z'));
        $this->gateway = new RuntimeFakeGateway();
        $http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('No network in reply scope tests.');
            }
        };
        $this->app = new Application($this->gateway, $clock, $db, new CalendarService(new HebcalProvider($http), $clock), new GeoNames($http, $clock, ''));
        $this->app->settings->getUser('1');
        foreach (Lang::CODES as $index => $code) {
            $group = '-' . (1001 + $index);
            $this->app->settings->ensureGroup($group, 'Locale ' . $code);
            $this->app->settings->updateGroup($group, 1, ['language' => $code], '1');
        }
        $this->bridge = ReplyScopeFixture::create($this->app);
    }
    private function message(bool $private, int $chat, int $sender = 1): Message
    {
        $type = $private ? PrivateMessage::class : GroupMessage::class;
        $class = new \ReflectionClass($type);
        $message = $class->newInstanceWithoutConstructor();
        $class->getProperty('chatId')->getDeclaringClass()->getProperty('chatId')->setValue($message, $chat);
        $class->getProperty('senderId')->getDeclaringClass()->getProperty('senderId')->setValue($message, $sender);
        return $message;
    }
    private function expectedSource(string $code, string $kind): string
    {
        $lang = new Lang();
        $url = Version::sourceUrl();
        $values = ['version' => Version::current(),'commit' => Version::commit(),'license' => 'AGPL-3.0-only','url' => $url !== '' ? $url : '-'];
        $key = 'source_info';
        return $lang->text($code, $key, $values) . ($url === '' ? "\n" . $lang->text($code, 'source_unpublished') : '');
    }
    public function testRealBridgeSourceLicenseVersionAndStatsUseAllSixGroupLanguages(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $index => $code) {
            $message = $this->message(false, -(1001 + $index));
            self::assertSame($code, $this->bridge->language($message));
            foreach (['source','license','version'] as $kind) {
                self::assertSame($this->expectedSource($code, $kind), $this->bridge->sourceForMessage($message, $kind));
            }
            $actual = $this->bridge->statsForMessage($message);
            self::assertStringContainsString(\Shabbat\UI\AdminDirectoryText::text($code, 'stats_overview'), $actual);
            self::assertStringContainsString(\Shabbat\UI\AdminDirectoryText::text($code, 'locking') . ': <b>6</b>', $actual);
            self::assertStringContainsString(\Shabbat\UI\AdminDirectoryText::text($code, 'locked') . ': <b>0</b>', $actual);
            self::assertSame('he', $this->app->settings->getUser('1')['language']);
        }
        self::assertSame([], $this->gateway->sent);
        self::assertSame(0, $this->gateway->mutations);
    }
    public function testPrivateMessagesAndPrivateSourceCallbacksStayInPersonalLanguage(): void
    {
        $private = $this->message(true, 1);
        $group = $this->message(false, -1004);
        self::assertSame('fr', $this->bridge->language($group));
        self::assertSame('he', $this->bridge->language($private));
        self::assertSame($this->expectedSource('he', 'source'), $this->bridge->privateSourceCallback('1'));
        self::assertSame($this->expectedSource('he', 'license'), $this->bridge->sourceForMessage($private, 'license'));
        $user = $this->app->settings->getUser('1');
        $this->app->settings->updateUser('1', $user['revision'], ['language' => 'pt-br']);
        self::assertSame('pt-br', $this->bridge->language($private));
        self::assertSame('fr', $this->bridge->language($group));
        self::assertSame($this->expectedSource('pt-br', 'version'), $this->bridge->privateSourceCallback('1', 'version'));
    }
    public function testUnknownGroupFallsBackToUserAndAnonymousKnownGroupUsesItsLocale(): void
    {
        self::assertSame('he', $this->bridge->language($this->message(false, -999)));
        $anonymous = $this->message(false, -1004, -1004);
        self::assertSame('fr', $this->bridge->language($anonymous));
        self::assertSame($this->expectedSource('fr', 'source'), $this->bridge->sourceForMessage($anonymous, 'source'));
        self::assertSame('he', $this->bridge->language($this->message(false, -999, -999)));
        self::assertSame('he', $this->app->settings->getUser('1')['language']);
    }
}
