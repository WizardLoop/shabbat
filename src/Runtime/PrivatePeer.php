<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Runtime;

/** Native MadelineProto peer IDs are signed integers, not peerUser TL objects. */
final class PrivatePeer
{
    public static function sender(array $message, int $self): ?int
    {
        $sender = $message['from_id'] ?? null;
        $recipient = $message['peer_id'] ?? null;
        if ($self <= 0 || !is_int($sender) || $sender <= 0 || $sender === $self || !is_int($recipient) || $recipient <= 0 || ($message['out'] ?? false) !== false) {
            return null;
        }
        // Installed SDK raw/normalized short messages use recipient=self.
        // A same-sender conversation peer also identifies this private dialog.
        return $recipient === $self || $recipient === $sender ? $sender : null;
    }
    public static function botReply(array $message, int $self, int $user, int $replyId): bool
    {
        return $self > 0 && $user > 0 && $self !== $user && $replyId > 0
            && ($message['id'] ?? null) === $replyId
            && ($message['from_id'] ?? null) === $self
            && ($message['peer_id'] ?? null) === $user
            && is_string($message['message'] ?? null);
    }
}
