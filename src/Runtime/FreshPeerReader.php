<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Runtime;

/** Reads permission evidence from this call's native RPC response, never the peer cache. */
final class FreshPeerReader
{
    public const EVIDENCE_KEY = '_shabbat_fresh_full_peer_v1';

    public static function read(string $peer, \danog\MadelineProto\Settings $settings, callable $fullInfo): array
    {
        if ($settings->getPeer()->getFullInfoCacheTime() !== 0) {
            throw new \RuntimeException('Fresh permissions require the configured zero full-info cache lifetime.');
        }
        return self::verified($peer, $fullInfo($peer));
    }

    /** Validate the per-call evidence added by the exact, reviewed PeerHandler patch. */
    public static function verified(string $peer, array $result): array
    {
        if (!preg_match('/^-[1-9][0-9]*$/D', $peer) || (string)(int)$peer !== $peer) {
            throw new \RuntimeException('A canonical signed Telegram group ID is required.');
        }
        $reply = $result[self::EVIDENCE_KEY] ?? null;
        $full = is_array($reply) ? ($reply['full_chat'] ?? null) : null;
        if (
            !is_array($reply) || ($reply['_'] ?? '') !== 'messages.chatFull'
            || !is_array($reply['chats'] ?? null) || !array_is_list($reply['chats'])
            || !is_array($reply['users'] ?? null) || !array_is_list($reply['users'])
            || !is_array($full) || !is_int($full['id'] ?? null) || (string)$full['id'] !== $peer
            || !in_array($full['_'] ?? '', ['chatFull', 'channelFull'], true)
            || !is_string($full['about'] ?? null) || !is_array($full['notify_settings'] ?? null)
            || ($full['notify_settings']['_'] ?? '') !== 'peerNotifySettings'
        ) {
            throw new \RuntimeException('Telegram did not return verifiable current full-peer evidence.');
        }
        $basic = $full['_'] === 'chatFull';
        if ($basic) {
            if (!is_array($full['participants'] ?? null) || !in_array($full['participants']['_'] ?? '', ['chatParticipants', 'chatParticipantsForbidden'], true)) {
                throw new \RuntimeException('Telegram returned incomplete basic-group information.');
            }
        } else {
            foreach (['read_inbox_max_id', 'read_outbox_max_id', 'unread_count', 'pts'] as $field) {
                if (!is_int($full[$field] ?? null)) {
                    throw new \RuntimeException('Telegram returned incomplete channel information.');
                }
            }
            if (!is_array($full['chat_photo'] ?? null) || !is_array($full['bot_info'] ?? null) || !array_is_list($full['bot_info'])) {
                throw new \RuntimeException('Telegram returned incomplete channel information.');
            }
        }
        $matches = [];
        foreach ($reply['chats'] as $candidate) {
            if (is_array($candidate) && (string)($candidate['id'] ?? '') === $peer) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw new \RuntimeException('Telegram returned missing or ambiguous target peer evidence.');
        }
        $chat = $matches[0];
        if (
            ($chat['_'] ?? '') !== ($basic ? 'chat' : 'channel') || !is_int($chat['id'] ?? null)
            || !is_string($chat['title'] ?? null) || !is_array($chat['photo'] ?? null) || !is_int($chat['date'] ?? null)
            || !in_array($chat['photo']['_'] ?? '', ['chatPhoto', 'chatPhotoEmpty'], true)
            || ($chat['min'] ?? false) !== false || ($chat['deactivated'] ?? false) !== false
            || ($chat['left'] ?? false) !== false || isset($chat['migrated_to']) || ($chat['monoforum'] ?? false) !== false
        ) {
            throw new \RuntimeException('Telegram returned incomplete, inaccessible or migrated target peer evidence.');
        }
        if ($basic && (!is_int($chat['participants_count'] ?? null) || !is_int($chat['version'] ?? null))) {
            throw new \RuntimeException('Telegram returned incomplete basic-group information.');
        }
        // Absence is meaningful only in this verified, non-min native Chat constructor.
        // Never substitute cached default rights, including when Telegram removed them.
        if (array_key_exists('default_banned_rights', $chat)) {
            $rights = $chat['default_banned_rights'];
            if (!is_array($rights) || ($rights['_'] ?? '') !== 'chatBannedRights' || !is_int($rights['until_date'] ?? null)) {
                throw new \RuntimeException('Telegram returned malformed current default permissions.');
            }
            \Shabbat\Scheduling\Permissions::validate($rights);
        }
        $result['Chat'] = $chat;
        $result['full'] = $full;
        $result['bot_api_id'] = $chat['id'];
        $result['type'] = $basic ? 'chat' : (($chat['megagroup'] ?? false) ? 'supergroup' : 'channel');
        return $result;
    }
}
