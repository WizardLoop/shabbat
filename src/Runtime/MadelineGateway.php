<?php

declare(strict_types=1);

namespace Shabbat\Runtime;

// SPDX-License-Identifier: AGPL-3.0-only

use Amp\CancelledException;
use Amp\TimeoutException;
use danog\MadelineProto\RPCError\RateLimitError;
use Shabbat\Telegram\RetryableTelegramException;
use Shabbat\Telegram\TelegramGateway;
use Shabbat\Telegram\MessageMaintenanceGateway;
use Shabbat\Telegram\AdminRights;
use Shabbat\Telegram\SentMessageIds;
use Shabbat\UI\UiGateway;
use Shabbat\UI\Presentation;

final class MadelineGateway implements TelegramGateway, \Shabbat\Telegram\GuardedTelegramGateway, UiGateway, MessageMaintenanceGateway, \Shabbat\UI\PanelPayloadGateway, \Shabbat\UI\DiagnosticsGateway
{
    private MadelineTransport $transport;

    public function __construct(\danog\MadelineProto\SimpleEventHandler|MadelineTransport $bot, private bool $customEmojiEnabled = true, private ?array $customEmojiAlternatives = null)
    {
        $this->transport = $bot instanceof MadelineTransport ? $bot : new EventHandlerTransport($bot);
    }

    private function call(string $method, array $parameters, ?callable $dispatchGuard = null, bool $translateErrors = true): mixed
    {
        if (Runner::isStopping() && in_array($method, ['messages.sendMessage','messages.sendMedia','messages.sendMultiMedia','messages.editMessage','messages.setInlineBotResults'], true)) {
            throw new CancelledException(new \RuntimeException('Shutdown has stopped new Telegram message operations.'));
        }
        $parameters = \Shabbat\Telegram\KeyboardMarkup::parameters($parameters, $this->customEmojiEnabled);
        $plainParameters = self::stripPresentationMetadata(self::plainEmojiParameters($parameters));
        $parameters = self::stripPresentationMetadata($parameters);
        try {
            return $this->dispatch($method, $parameters, $dispatchGuard, $translateErrors);
        } catch (\danog\MadelineProto\RPCErrorException $error) {
            if (
                !in_array($error->rpc, ['PREMIUM_ACCOUNT_REQUIRED', 'CUSTOM_EMOJI_INVALID'], true)
                || !self::hasCustomEmoji($parameters)
                || !in_array($method, ['messages.sendMessage', 'messages.sendMedia', 'messages.editMessage', 'messages.setInlineBotResults', 'messages.sendMultiMedia'], true)
            ) {
                throw $error;
            }
            // A definitive server rejection did not accept the first operation.
            // Retry once with the same random_id, edit target or inline query ID.
            if (Runner::isStopping()) {
                throw new CancelledException($error);
            }
            $this->customEmojiEnabled = false;
            $parameters = $plainParameters;
            return $this->dispatch($method, $parameters, $dispatchGuard, $translateErrors);
        }
    }

    /** No application await is allowed between the final fence and transport entry. */
    private function dispatch(string $method, array $parameters, ?callable $guard, bool $translateErrors): mixed
    {
        $operation = function () use ($method, $parameters, $guard): mixed {
            if (Runner::isStopping() && in_array($method, ['messages.sendMessage','messages.sendMedia','messages.sendMultiMedia','messages.editMessage','messages.setInlineBotResults'], true)) {
                throw new CancelledException(new \RuntimeException('Shutdown has stopped new Telegram message operations.'));
            }
            if ($guard !== null) {
                $guard();
            }
            return $this->transport->call($method, $parameters + ['floodWaitLimit' => 0]);
        };
        return $translateErrors ? $this->invoke($operation) : $operation();
    }

    /** Optional per-instance BroadcastManager transport, with native identities intact. */
    public function broadcastCall(string $method, array $parameters): mixed
    {
        $method = str_starts_with($method, 'messages.') ? $method : 'messages.' . $method;
        if (!in_array($method, ['messages.sendMessage','messages.sendMedia','messages.sendMultiMedia','messages.editMessage'], true)) {
            throw new \InvalidArgumentException('Unsupported broadcast message operation.');
        }
        if (Runner::isStopping()) {
            throw new CancelledException(new \RuntimeException('Shutdown has stopped broadcast message operations.'));
        }
        if (isset($parameters['message'])) {
            $parameters = $this->presentNativeParameters($parameters);
        }
        foreach ($parameters['multi_media'] ?? [] as $index => $single) {
            $parameters['multi_media'][$index] = $this->presentNativeParameters($single);
        }
        if (isset($parameters['reply_markup'])) {
            $parameters['reply_markup'] = self::normalizeMarkup($parameters['reply_markup'], $this->customEmojiEnabled);
        }
        // The native broadcast manager owns its per-peer retry clock. Never let
        // an inherited SDK floodWaitLimit suspend this transport for days.
        $parameters['floodWaitLimit'] = 0;
        return $this->call($method, $parameters, null, false);
    }

    private function presentNativeParameters(array $parameters): array
    {
        $mode = strtolower((string)($parameters['parse_mode'] ?? ''));
        if ($mode !== '' && $mode !== 'html') {
            return $parameters; // Preserve an existing non-HTML parser contract.
        }
        $input = ['text' => $parameters['message'] ?? ''];
        if (array_key_exists('entities', $parameters)) {
            $input['entities'] = $parameters['entities'];
            unset($parameters['parse_mode']);
        }
        $payload = $this->present($input, $mode === '');
        $parameters['message'] = $payload['text'];
        if (isset($payload['entities'])) {
            $parameters['entities'] = $payload['entities'];
        }
        return $parameters;
    }
    private function present(array $payload, bool $plain = false): array
    {
        if (
            $this->customEmojiEnabled && $this->customEmojiAlternatives === null
            && Presentation::requiresCatalog((string)($payload['text'] ?? ''))
        ) {
            $this->customEmojiAlternatives = [];
            try {
                $documents = $this->call('messages.getCustomEmojiDocuments', ['document_id' => array_map(
                    static fn(string $id): int => TelegramInt64::fromDecimal($id),
                    array_values(Presentation::ICONS)
                )]);
                if (!is_array($documents) || !array_is_list($documents)) {
                    throw new \RuntimeException('Invalid custom emoji document catalog.');
                }
                foreach ($documents as $document) {
                    $id = (string)($document['id'] ?? '');
                    if (($document['_'] ?? '') !== 'document' || !in_array($id, Presentation::ICONS, true)) {
                        continue;
                    }
                    foreach ($document['attributes'] ?? [] as $attribute) {
                        $alt = $attribute['alt'] ?? null;
                        if (
                            ($attribute['_'] ?? '') === 'documentAttributeCustomEmoji'
                            && is_string($alt) && preg_match('/^\X$/uD', $alt) === 1 && trim($alt) !== ''
                        ) {
                            $this->customEmojiAlternatives[$id] = $alt;
                        }
                    }
                }
                if ($this->customEmojiAlternatives === []) {
                    $this->customEmojiEnabled = false;
                }
            } catch (CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                // Lookup is a presentation enhancement, never a reason to lose a panel.
                $this->customEmojiEnabled = false;
            }
        }
        return Presentation::payload($payload, $this->customEmojiEnabled, $this->customEmojiAlternatives ?? [], !$plain);
    }

    private static function hasCustomEmoji(array $parameters): bool
    {
        if (is_string($parameters['message'] ?? null) && str_contains($parameters['message'], '<tg-emoji')) {
            return true;
        }
        foreach ($parameters['entities'] ?? [] as $entity) {
            if (in_array($entity['_'] ?? $entity['type'] ?? '', ['messageEntityCustomEmoji', 'custom_emoji'], true)) {
                return true;
            }
        }
        foreach ($parameters['reply_markup']['rows'] ?? [] as $row) {
            foreach ($row['buttons'] ?? [] as $button) {
                if (isset($button['style']['icon'])) {
                    return true;
                }
            }
        }
        foreach ($parameters['multi_media'] ?? [] as $single) {
            if (is_array($single) && self::hasCustomEmoji($single)) {
                return true;
            }
        }
        foreach ($parameters['results'] ?? [] as $result) {
            if (is_array($result['send_message'] ?? null) && self::hasCustomEmoji($result['send_message'])) {
                return true;
            }
        }
        return false;
    }

    /** Internal prefix provenance is removed before every native RPC, never sent as TL metadata. */
    private static function stripPresentationMetadata(array $parameters): array
    {
        unset($parameters['_presentation_fallback_text'], $parameters['_presentation_auto_icon']);
        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                $parameters[$key] = self::stripPresentationMetadata($value);
            }
        }
        return $parameters;
    }
    private static function plainEmojiParameters(array $parameters): array
    {
        if (is_string($parameters['message'] ?? null) && strcasecmp((string)($parameters['parse_mode'] ?? ''), 'HTML') === 0) {
            $parameters['message'] = Presentation::html($parameters['message'], false);
        }
        if (isset($parameters['entities'])) {
            $parameters['entities'] = array_values(array_filter($parameters['entities'], static fn(array $entity): bool =>
                !in_array($entity['_'] ?? $entity['type'] ?? '', ['messageEntityCustomEmoji', 'custom_emoji'], true)));
        }
        if (isset($parameters['reply_markup'])) {
            $parameters['reply_markup'] = self::normalizeMarkup($parameters['reply_markup'], false);
        }
        foreach ($parameters['multi_media'] ?? [] as $index => $single) {
            if (is_array($single)) {
                $parameters['multi_media'][$index] = self::plainEmojiParameters($single);
            }
        }
        foreach ($parameters['results'] ?? [] as $index => $result) {
            if (is_array($result['send_message'] ?? null)) {
                $parameters['results'][$index]['send_message'] = self::plainEmojiParameters($result['send_message']);
            }
        }
        return $parameters;
    }
    private function invoke(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RateLimitError $error) {
            throw new RetryableTelegramException('Telegram flood wait', max(1, $error->getWaitTimeLeft()), $error);
        } catch (TimeoutException $error) {
            throw new RetryableTelegramException('Telegram request timeout', 30, $error);
        } catch (CancelledException $error) {
            if ($error->getPrevious() instanceof TimeoutException && !Runner::isStopping()) {
                throw new RetryableTelegramException('Telegram request timeout cancellation', 30, $error);
            }
            throw $error;
        }
    }

    public function isGroupAdmin(string $userId, string $groupId): bool
    {
        if (!ctype_digit($userId) || (int) $userId <= 0) {
            return false;
        }
        try {
            $info = $this->transport->info($groupId);
            if (($info['type'] ?? '') === 'chat') {
                $full = $this->invoke(fn () => $this->transport->fullInfo($groupId));
                foreach ($full['full']['participants']['participants'] ?? [] as $participant) {
                    if ((string) ($participant['user_id'] ?? '') === $userId) {
                        return in_array($participant['_'] ?? '', ['chatParticipantAdmin', 'chatParticipantCreator'], true);
                    }
                }
                return false;
            }
            $response = $this->call('channels.getParticipant', ['channel' => $groupId, 'participant' => $userId]);
            return in_array($response['participant']['_'] ?? '', ['channelParticipantAdmin', 'channelParticipantCreator'], true);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            // No network error or unverifiable identity grants permission.
            return false;
        }
    }

    public function getPermissions(string $groupId): array
    {
        $full = $this->invoke(fn () => $this->transport->fullInfo($groupId));
        $current = FreshPeerReader::verified($groupId, $full);
        return $current['Chat']['default_banned_rights'] ?? ['_' => 'chatBannedRights', 'until_date' => 0];
    }
    public function setPermissions(string $groupId, array $permissions): void
    {
        if (($permissions['_'] ?? '') !== 'chatBannedRights') {
            throw new \InvalidArgumentException('Invalid permissions snapshot.');
        }
        if (Runner::isStopping()) {
            throw new CancelledException(new \RuntimeException('Shutdown has stopped permission changes.'));
        }
        if (($permissions['view_messages'] ?? false) === true) {
            throw new \InvalidArgumentException('Telegram forbids view_messages in global default permissions.');
        }
        try {
            if ($this->call('messages.editChatDefaultBannedRights', ['peer' => $groupId, 'banned_rights' => $permissions]) === false) {
                throw new \RuntimeException('Telegram did not confirm the permission change.');
            }
        } catch (\danog\MadelineProto\RPCErrorException $error) {
            // A racing request may have applied the same mask. The coordinator
            // still reads and compares all fresh rights after this response.
            if ($error->rpc !== 'CHAT_NOT_MODIFIED') {
                throw $error;
            }
        }
    }

    public function send(string $peer, array $payload, string $randomId): array
    {
        return $this->sendPrepared($peer, $payload, $randomId, null);
    }

    public function sendGuarded(string $peer, array $payload, string $randomId, callable $guard): array
    {
        return $this->sendPrepared($peer, $payload, $randomId, $guard);
    }

    private function sendPrepared(string $peer, array $payload, string $randomId, ?callable $dispatchGuard): array
    {
        if (Runner::isStopping()) {
            throw new \RuntimeException('Shutdown has stopped new Telegram sends.');
        }
        if (!preg_match('/^-?\d+$/', $randomId)) {
            throw new \InvalidArgumentException('MTProto random_id must be an integer string.');
        }
        $payload = $this->present($payload);
        $parameters = ['peer' => $peer, 'random_id' => TelegramInt64::fromDecimal($randomId), 'message' => $payload['text'] ?? '', 'no_webpage' => true];
        if (array_key_exists('entities', $payload)) {
            $parameters['entities'] = $payload['entities'];
        } else {
            $parameters['parse_mode'] = 'HTML';
        }
        if (!empty($payload['buttons'])) {
            $parameters['reply_markup'] = self::normalizeMarkup(['inline_keyboard' => $payload['buttons']], $this->customEmojiEnabled);
        }
        if (isset($payload['reply_markup'])) {
            $parameters['reply_markup'] = self::normalizeMarkup($payload['reply_markup'], $this->customEmojiEnabled);
        }
        if (!empty($payload['topic_id'])) {
            $topicId = (int) $payload['topic_id'];
            $topic = $this->call('messages.getForumTopicsByID', ['peer' => $peer, 'topics' => [$topicId]]);
            $found = false;
            foreach ($topic['topics'] ?? [] as $item) {
                if ((int) ($item['id'] ?? 0) === $topicId && !($item['closed'] ?? false)) {
                    $found = true;
                }
            }
            if (!$found) {
                throw new \RuntimeException('Selected forum topic is missing or closed.');
            }
            $parameters['reply_to'] = ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => $topicId, 'top_msg_id' => $topicId];
        }
        if (!empty($payload['media'])) {
            $media = $payload['media'];
            if (is_array($media) && isset($media['bot_api_file_id'])) {
                $media = (string) $media['bot_api_file_id'];
            }
            if (is_string($media)) {
                $decoded = $this->transport->fileInfo($media);
                $media = $decoded['InputMedia'] ?? null;
            }
            if (!is_array($media) || !str_starts_with((string) ($media['_'] ?? ''), 'inputMedia')) {
                throw new \InvalidArgumentException('Media must be a reusable Telegram file ID or MTProto InputMedia.');
            }
            $parameters['media'] = $media;
            unset($parameters['no_webpage']);
            return $this->call('messages.sendMedia', $parameters, $dispatchGuard);
        }
        return $this->call('messages.sendMessage', $parameters, $dispatchGuard);
    }

    public static function normalizeMarkup(array $markup, bool $customEmojiEnabled = true): array
    {
        return \Shabbat\Telegram\KeyboardMarkup::normalize($markup, $customEmojiEnabled);
    }

    public function sendDiagnosticPanel(string $user, string $text, array $markup): int
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $user)) {
            throw new \InvalidArgumentException('A private diagnostic peer is required.');
        }
        $random = (string)random_int(1, PHP_INT_MAX);
        $result = $this->call('messages.sendMessage', ['peer' => $user, 'random_id' => (int)$random,
            'message' => $this->present(['text' => $text])['text'], 'parse_mode' => 'HTML',
            'reply_markup' => self::normalizeMarkup($markup, $this->customEmojiEnabled), 'no_webpage' => true]);
        $ids = is_array($result) ? SentMessageIds::extract($result, $random, $user) : [];
        if (count($ids) !== 1) {
            throw new \RuntimeException('The diagnostic panel acknowledgement is ambiguous.');
        }
        return $ids[0];
    }

    /** Pinned 8.7 TL InputFile serialization calls upload(ReadableStream); no filesystem path. */
    public function sendDiagnosticDocument(string $user, string $json, string $caption, string $randomId): void
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $user) || strlen($json) > 1048576 || mb_strlen($caption) > 500) {
            throw new \InvalidArgumentException('Invalid private diagnostic export.');
        }
        $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['schema'] ?? '') !== 'shabbat-diagnostics-1' || ($payload['read_only'] ?? false) !== true) {
            throw new \InvalidArgumentException('A diagnostic snapshot is required.');
        }
        $random = TelegramInt64::fromDecimal($randomId);
        if ($random <= 0) {
            throw new \InvalidArgumentException('Invalid diagnostic export identity.');
        }
        $this->call('messages.sendMedia', ['peer' => $user, 'random_id' => $random, 'message' => $caption,
            'media' => ['_' => 'inputMediaUploadedDocument', 'force_file' => true,
                'file' => new \Amp\ByteStream\ReadableBuffer($json), 'mime_type' => 'application/json',
                'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => 'shabbat-diagnostics.json']]]]);
    }
    public function sendPrivate(string $userId, string $text, array $markup = []): void
    {
        $this->call('messages.sendMessage', ['peer' => $userId, 'random_id' => random_int(1, PHP_INT_MAX), 'message' => $this->present(['text' => $text])['text'], 'parse_mode' => 'HTML', 'reply_markup' => (self::normalizeMarkup($markup, $this->customEmojiEnabled) ?: null), 'no_webpage' => true]);
    }

    /** Caller must verify the persisted group/message binding before editing a counter. */
    public function editGroupMarkup(string $groupId, int $messageId, array $markup): void
    {
        if (!preg_match('/^-[1-9][0-9]{0,19}$/D', $groupId) || $messageId < 1 || $messageId > 2147483647) {
            throw new \InvalidArgumentException('A recorded group message is required.');
        }
        $parameters = ['peer' => $groupId, 'id' => $messageId,
            'reply_markup' => self::normalizeMarkup($markup, $this->customEmojiEnabled) ?: ['_' => 'replyInlineMarkup', 'rows' => []]];
        try {
            $this->call('messages.editMessage', $parameters);
        } catch (\danog\MadelineProto\RPCErrorException $error) {
            if ($error->rpc !== 'MESSAGE_NOT_MODIFIED') {
                throw $error;
            }
        }
    }
    public function editPrivate(string $userId, int $messageId, string $text, array $markup = []): void
    {
        $this->editPrivatePayload($userId, $messageId, ['text' => $text, 'reply_markup' => $markup]);
    }

    public function editPrivatePayload(string $userId, int $messageId, array $payload): void
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $userId) || $messageId < 1 || $messageId > 2147483647) {
            throw new \InvalidArgumentException('A private bot panel is required.');
        }
        $payload = $this->present($payload);
        $parameters = ['peer' => $userId, 'id' => $messageId, 'message' => $payload['text'] ?? '', 'no_webpage' => true];
        if (array_key_exists('entities', $payload)) {
            $parameters['entities'] = $payload['entities'];
        } else {
            $parameters['parse_mode'] = 'HTML';
        }
        $markup = $payload['reply_markup'] ?? (isset($payload['buttons']) ? ['inline_keyboard' => $payload['buttons']] : []);
        $parameters['reply_markup'] = self::normalizeMarkup($markup, $this->customEmojiEnabled) ?: ['_' => 'replyInlineMarkup', 'rows' => []];
        if (!empty($payload['media'])) {
            $media = $payload['media'];
            if (is_array($media) && isset($media['bot_api_file_id'])) {
                $media = $media['bot_api_file_id'];
            }
            if (is_string($media)) {
                $media = $this->transport->fileInfo($media)['InputMedia'] ?? null;
            }
            if (!is_array($media) || !str_starts_with((string)($media['_'] ?? ''), 'inputMedia')) {
                throw new \InvalidArgumentException('A reusable Telegram file is required for a preview.');
            }
            $parameters['media'] = $media;
            unset($parameters['no_webpage']);
        }
        try {
            $this->call('messages.editMessage', $parameters);
        } catch (\danog\MadelineProto\RPCErrorException $error) {
            if ($error->rpc !== 'MESSAGE_NOT_MODIFIED') {
                throw $error;
            }
            // Repeating the same navigation selection is already the desired result.
        }
    }

    public function answerCallback(string $queryId, string $text): void
    {
        $this->call('messages.setBotCallbackAnswer', ['query_id' => TelegramInt64::fromDecimal($queryId), 'message' => str_replace('—', '-', $text), 'alert' => true, 'cache_time' => 0]);
    }

    public function answerInline(string $queryId, array $results, array $options = []): void
    {
        foreach ($results as &$result) {
            foreach (['title','description'] as $label) {
                if (is_string($result[$label] ?? null)) {
                    $result[$label] = str_replace('—', '-', $result[$label]);
                }
            }
            if (!is_array($result['send_message'] ?? null)) {
                continue;
            }
            $message = &$result['send_message'];
            $input = ['text' => $message['message'] ?? ''];
            if (array_key_exists('entities', $message)) {
                $input['entities'] = $message['entities'];
                unset($message['parse_mode']);
            }
            $payload = $this->present($input);
            $message['message'] = $payload['text'];
            if (($payload['entities'] ?? []) !== [] || isset($message['entities'])) {
                $message['entities'] = $payload['entities'] ?? [];
            }
            if (isset($message['reply_markup'])) {
                $message['reply_markup'] = self::normalizeMarkup($message['reply_markup'], $this->customEmojiEnabled);
            }
            unset($message);
        }
        unset($result);
        $this->call('messages.setInlineBotResults', ['query_id' => TelegramInt64::fromDecimal($queryId), 'results' => $results] + $options + ['cache_time' => 0, 'private' => true]);
    }

    public function deleteMessages(string $peer, array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }
        if (count($messageIds) > 100 || !array_is_list($messageIds)) {
            throw new \InvalidArgumentException('Deletion expects at most 100 recorded message IDs.');
        }
        foreach ($messageIds as $id) {
            if (!is_int($id) || $id < 1 || $id > 2147483647) {
                throw new \InvalidArgumentException('Deletion message IDs must be positive native int32 values.');
            }
        }
        if (Runner::isStopping()) {
            throw new CancelledException(new \RuntimeException('Shutdown has stopped new message deletions.'));
        }
        $info = $this->invoke(fn () => $this->transport->info($peer));
        $type = $info['type'] ?? '';
        if (!in_array($type, ['chat', 'supergroup', 'channel'], true)) {
            throw new \RuntimeException('Deletion target is not a verified Telegram group.');
        }
        if (Runner::isStopping()) {
            throw new CancelledException(new \RuntimeException('Shutdown has stopped new message deletions.'));
        }
        $ids = array_values(array_unique($messageIds));
        $response = $type === 'chat'
            ? $this->call('messages.deleteMessages', ['revoke' => true, 'id' => $ids])
            : $this->call('channels.deleteMessages', ['channel' => $peer, 'id' => $ids]);
        if (
            !is_array($response) || ($response['_'] ?? '') !== 'messages.affectedMessages'
            || !is_int($response['pts'] ?? null) || $response['pts'] < 0
            || !is_int($response['pts_count'] ?? null) || $response['pts_count'] < 0
        ) {
            throw new \RuntimeException('Telegram did not acknowledge message deletion.');
        }
    }

    public function senderIsBot(string $group, string $senderId): ?bool
    {
        try {
            $id = TelegramInt64::fromDecimal($senderId);
            if ($id <= 0) {
                return null; // Anonymous/channel senders are not user accounts.
            }
            $info = $this->invoke(fn () => $this->transport->info($senderId));
            $user = $info['User'] ?? null;
            if (
                !in_array($info['type'] ?? '', ['user', 'bot'], true) || !is_array($user)
                || ($user['_'] ?? '') !== 'user' || (string) ($user['id'] ?? '') !== $senderId
                || !is_bool($user['min'] ?? false) || !is_bool($user['deleted'] ?? false)
                || ($user['min'] ?? false) === true || ($user['deleted'] ?? false) === true
            ) {
                return null;
            }
            $bot = $user['bot'] ?? false;
            if (!is_bool($bot) || ($info['type'] === 'bot') !== $bot) {
                return null;
            }
            return $bot;
        } catch (CancelledException | RetryableTelegramException $error) {
            throw $error;
        } catch (\Throwable) {
            return null;
        }
    }

    public function botCapabilities(string $group): array
    {
        $info = $this->invoke(fn () => $this->transport->info($group));
        $full = $this->invoke(fn () => $this->transport->fullInfo($group));
        $chat = $full['Chat'] ?? null;
        $type = $full['type'] ?? '';
        if (
            !in_array($type, ['chat', 'supergroup', 'channel'], true) || !is_array($chat)
            || ($chat['_'] ?? '') !== ($type === 'chat' ? 'chat' : 'channel')
            || (string) ($chat['id'] ?? '') !== (string) ($info['Chat']['id'] ?? '')
            || !isset($chat['id']) || !is_bool($chat['min'] ?? false) || ($chat['min'] ?? false) === true
        ) {
            throw new \RuntimeException('Telegram did not return verifiable bot administrator capabilities.');
        }
        $creator = $chat['creator'] ?? false;
        if (!is_bool($creator) || (isset($chat['admin_rights']) && !is_array($chat['admin_rights']))) {
            throw new \RuntimeException('Malformed bot administrator metadata.');
        }
        $rights = AdminRights::normalize($chat['admin_rights'] ?? null);
        return ['verified' => true, 'group_id' => $group, 'peer_type' => $type,
            'creator' => $creator, 'is_admin' => $creator || isset($chat['admin_rights']),
            'rights' => $rights, 'can_delete_messages' => $creator || $rights['delete_messages']];
    }

    public function botCanDeleteMessages(string $group): bool
    {
        return $this->botCapabilities($group)['can_delete_messages'];
    }

    public function selfUserId(): string
    {
        $self = $this->transport->self();
        $id = TelegramInt64::fromDecimal($self['id'] ?? null);
        if ($id <= 0) {
            throw new \RuntimeException('Telegram did not return a verified self user ID.');
        }
        return (string) $id;
    }

    public function extractSentMessageIds(array $response, ?string $expectedRandomId = null, ?string $expectedPeer = null): array
    {
        return SentMessageIds::extract($response, $expectedRandomId, $expectedPeer);
    }

    public function botUsername(): string
    {
        return (string) ($this->transport->self()['username'] ?? '');
    }
}
