<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Shabbat\Runtime\FreshPeerReader;

/** Synthetic complete deserialized TL constructors; never a captured group. */
final class FreshPeerFixture
{
    public static function info(string $peer, ?array $rights, string $type = 'supergroup'): array
    {
        $basic = $type === 'chat';
        $chat = ['_' => $basic ? 'chat' : 'channel', 'id' => (int)$peer, 'title' => 'Offline fixture',
            'photo' => ['_' => 'chatPhotoEmpty'], 'date' => 1];
        if ($basic) {
            $chat += ['participants_count' => 3, 'version' => 1];
        } else {
            $chat += ['access_hash' => 42, 'megagroup' => $type === 'supergroup'];
        }
        if ($rights !== null) {
            $chat['default_banned_rights'] = $rights;
        }
        $full = ['_' => $basic ? 'chatFull' : 'channelFull', 'id' => (int)$peer,
            'about' => 'Offline fixture', 'notify_settings' => ['_' => 'peerNotifySettings']];
        if ($basic) {
            $full['participants'] = ['_' => 'chatParticipants', 'chat_id' => (int)$peer, 'participants' => [], 'version' => 1];
        } else {
            $full += ['read_inbox_max_id' => 0, 'read_outbox_max_id' => 0, 'unread_count' => 0, 'pts' => 1,
                'chat_photo' => ['_' => 'photoEmpty', 'id' => 0], 'bot_info' => []];
        }
        return ['Chat' => $chat, 'full' => $full, 'type' => $type, 'bot_api_id' => (int)$peer,
            FreshPeerReader::EVIDENCE_KEY => ['_' => 'messages.chatFull', 'full_chat' => $full, 'chats' => [$chat], 'users' => []]];
    }
}
