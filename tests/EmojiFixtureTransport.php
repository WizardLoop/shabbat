<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Runtime\MadelineTransport;
use Shabbat\UI\Presentation;

/** Synthetic documented alt metadata; never contacts Telegram or loads a session. */
final class EmojiFixtureTransport implements MadelineTransport
{
    public array $calls = [];
    public array $failures = [];
    public array $documents = [];

    public function __construct()
    {
        foreach (self::alternatives() as $id => $alt) {
            $this->documents[] = ['_' => 'document', 'id' => (int)$id,
                'attributes' => [['_' => 'documentAttributeCustomEmoji', 'alt' => $alt]]];
        }
    }

    public static function alternatives(): array
    {
        return array_combine(array_values(Presentation::ICONS), ['⚙️','⭐','ℹ️','💻','👥','➕','🕯','🔔','🕒','📖','🌾','✡️']);
    }

    public function call(string $method, array $parameters): mixed
    {
        $this->calls[] = [$method, $parameters];
        $this->failures[$method] ??= [];
        $error = array_shift($this->failures[$method]);
        if ($error instanceof \Throwable) {
            throw $error;
        }
        return $method === 'messages.getCustomEmojiDocuments' ? $this->documents : ['_' => 'updates', 'updates' => []];
    }

    public function info(string $peer): array
    {
        return ['type' => 'supergroup', 'Chat' => ['id' => 123]];
    }

    public function fullInfo(string $peer): array
    {
        return ['type' => 'supergroup', 'Chat' => ['id' => 123]];
    }

    public function fileInfo(string $fileId): array
    {
        return ['InputMedia' => ['_' => 'inputMediaPhoto', 'id' => ['_' => 'inputPhoto', 'id' => 42, 'access_hash' => 88, 'file_reference' => 'fixture']]];
    }

    public function self(): array
    {
        return ['id' => 777, 'username' => 'FixtureShabbatBot', 'bot' => true];
    }
}
