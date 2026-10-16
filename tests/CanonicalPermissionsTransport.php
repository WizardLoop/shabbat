<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Runtime\MadelineTransport;

/** Models documented official-client representations, never a captured real group. */
final class CanonicalPermissionsTransport implements MadelineTransport
{
    public array $rights;
    public array $writes = [];
    public string $type = 'supergroup';
    public ?\Closure $afterWrite = null;

    public function __construct()
    {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/Fixtures/telegram-default-permissions.json'), true, 32, JSON_THROW_ON_ERROR);
        $this->rights = $fixture['original'];
    }
    public function call(string $method, array $parameters): mixed
    {
        if ($method === 'messages.editChatDefaultBannedRights') {
            $this->writes[] = $parameters['banned_rights'];
            $this->rights = self::readback($parameters['banned_rights']);
            ($this->afterWrite)?->__invoke($this);
        }
        return ['_' => 'updates', 'updates' => []];
    }
    public static function readback(array $request): array
    {
        $request['send_messages'] = false;
        $request['send_media'] = false;
        if (($request['until_date'] ?? 0) === 0) {
            $request['until_date'] = 2147483647;
        }
        return $request;
    }
    public function info(string $peer): array
    {
        return ['type' => $this->type, 'Chat' => ['_' => $this->type === 'chat' ? 'chat' : 'channel', 'id' => 1]];
    }
    public function fullInfo(string $peer): array
    {
        return FreshPeerFixture::info($peer, $this->rights, $this->type);
    }
    public function fileInfo(string $fileId): array
    {
        throw new \LogicException('No files in permission fixture.');
    }
    public function self(): array
    {
        return ['id' => 777, 'username' => 'PermissionFixtureBot', 'bot' => true];
    }
}
