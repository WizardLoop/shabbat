<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Telegram;

use InvalidArgumentException;
use Shabbat\Content\TemplateRenderer;
use Shabbat\Runtime\TelegramInt64;
use Shabbat\UI\Presentation;

/** Convert persisted pre-229, Bot API and current keyboards at the transport boundary. */
final class KeyboardMarkup
{
    private const INLINE = [
        'keyboardButtonUrl' => 'inlineButtonTypeUrl',
        'keyboardButtonUrlAuth' => 'inlineButtonTypeUrlAuth',
        'inputKeyboardButtonUrlAuth' => 'inputInlineButtonTypeUrlAuth',
        'keyboardButtonWebView' => 'inlineButtonTypeWebView',
        'keyboardButtonSimpleWebView' => 'inlineButtonTypeWebView',
        'keyboardButtonCallback' => 'inlineButtonTypeCallback',
        'keyboardButtonGame' => 'inlineButtonTypeGame',
        'keyboardButtonBuy' => 'inlineButtonTypeBuy',
        'keyboardButtonSwitchInline' => 'inlineButtonTypeSwitchInline',
        'keyboardButtonUserProfile' => 'inlineButtonTypeUserProfile',
        'inputKeyboardButtonUserProfile' => 'inputInlineButtonTypeUserProfile',
        'keyboardButtonCopy' => 'inlineButtonTypeCopy',
    ];
    private const REPLY = [
        'keyboardButton' => 'buttonTypeDefault',
        'keyboardButtonRequestPhone' => 'buttonTypeRequestPhone',
        'keyboardButtonRequestGeoLocation' => 'buttonTypeRequestGeoLocation',
        'keyboardButtonRequestPoll' => 'buttonTypeRequestPoll',
        'keyboardButtonRequestPeer' => 'buttonTypeRequestPeer',
        'inputKeyboardButtonRequestPeer' => 'inputButtonTypeRequestPeer',
        'keyboardButtonSimpleWebView' => 'buttonTypeSimpleWebView',
    ];

    public static function normalize(array $markup, bool $customEmojiEnabled = true): array
    {
        if ($markup === []) {
            return [];
        }
        $constructor = $markup['_'] ?? null;
        if ($constructor !== null && !in_array($constructor, ['replyInlineMarkup', 'replyKeyboardMarkup', 'replyKeyboardHide', 'replyKeyboardForceReply'], true)) {
            throw new InvalidArgumentException('Unknown keyboard markup constructor.');
        }
        $selectors = array_intersect(['inline_keyboard', 'keyboard', 'remove_keyboard'], array_keys($markup));
        if (count($selectors) > 1 || ($constructor !== null && $selectors !== [])) {
            throw new InvalidArgumentException('Mixed inline and reply keyboard formats.');
        }
        if (isset($markup['inline_keyboard']) || $constructor === 'replyInlineMarkup') {
            $rows = $markup['inline_keyboard'] ?? $markup['rows'] ?? null;
            $native = ['_' => 'replyInlineMarkup', 'rows' => self::rows($rows, true, $customEmojiEnabled)];
            if (array_key_exists('force_reply', $markup)) {
                $native['force_reply'] = self::boolean($markup['force_reply']);
            }
            return $native;
        }
        if (isset($markup['keyboard']) || $constructor === 'replyKeyboardMarkup') {
            $native = ['_' => 'replyKeyboardMarkup', 'rows' => self::rows($markup['keyboard'] ?? $markup['rows'] ?? null, false, $customEmojiEnabled)];
            foreach (['resize' => 'resize_keyboard', 'single_use' => 'one_time_keyboard', 'persistent' => 'is_persistent', 'selective' => 'selective', 'force_reply' => 'force_reply'] as $field => $alias) {
                if (array_key_exists($field, $markup) || array_key_exists($alias, $markup)) {
                    $native[$field] = self::boolean($markup[$field] ?? $markup[$alias]);
                }
            }
            if (isset($markup['placeholder']) || isset($markup['input_field_placeholder'])) {
                $native['placeholder'] = self::string($markup['placeholder'] ?? $markup['input_field_placeholder'], 64, false);
            }
            return $native;
        }
        if ($constructor === 'replyKeyboardHide' || ($markup['remove_keyboard'] ?? false) === true) {
            return ['_' => 'replyKeyboardHide'] + (isset($markup['selective']) ? ['selective' => self::boolean($markup['selective'])] : []);
        }
        if ($constructor === 'replyKeyboardForceReply' || ($markup['force_reply'] ?? false) === true) {
            $native = ['_' => 'replyKeyboardForceReply'];
            foreach (['selective', 'single_use'] as $flag) {
                if (isset($markup[$flag])) {
                    $native[$flag] = self::boolean($markup[$flag]);
                }
            }
            if (isset($markup['placeholder']) || isset($markup['input_field_placeholder'])) {
                $native['placeholder'] = self::string($markup['placeholder'] ?? $markup['input_field_placeholder'], 64, false);
            }
            return $native;
        }
        throw new InvalidArgumentException('Unsupported message keyboard.');
    }

    /** Shared boundary for regular messages, albums and inline result messages. */
    public static function parameters(array $parameters, bool $customEmojiEnabled = true): array
    {
        if (isset($parameters['reply_markup'])) {
            if (!is_array($parameters['reply_markup'])) {
                throw new InvalidArgumentException('Reply markup must be an object.');
            }
            $parameters['reply_markup'] = self::normalize($parameters['reply_markup'], $customEmojiEnabled);
        }
        foreach ($parameters['multi_media'] ?? [] as $index => $single) {
            if (is_array($single)) {
                $parameters['multi_media'][$index] = self::parameters($single, $customEmojiEnabled);
            }
        }
        foreach ($parameters['results'] ?? [] as $index => $result) {
            if (is_array($result['send_message'] ?? null)) {
                $parameters['results'][$index]['send_message'] = self::parameters($result['send_message'], $customEmojiEnabled);
            }
        }
        return $parameters;
    }
    private static function rows(mixed $rows, bool $inline, bool $custom): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) {
            throw new InvalidArgumentException('Invalid keyboard rows.');
        }
        $result = [];
        $total = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Invalid keyboard row.');
            }
            if (isset($row['_'])) {
                if (!in_array($row['_'], $inline ? ['keyboardButtonRow', 'keyboardInlineButtonRow'] : ['keyboardButtonRow'], true)) {
                    throw new InvalidArgumentException('Inline and reply rows cannot be interchanged.');
                }
                $row = $row['buttons'] ?? null;
            }
            if (!is_array($row) || !array_is_list($row) || count($row) > 8 || $row === []) {
                throw new InvalidArgumentException('Invalid keyboard button row.');
            }
            $buttons = [];
            foreach ($row as $button) {
                if (++$total > 100) {
                    throw new InvalidArgumentException('Too many keyboard buttons.');
                }
                if (is_string($button) && !$inline) {
                    $button = ['text' => $button];
                }
                if (!is_array($button)) {
                    throw new InvalidArgumentException('Invalid keyboard button.');
                }
                self::string($button['text'] ?? null, 128);
                $button = Presentation::button($button, $custom, null, true);
                $action = self::action($button, $inline);
                $native = ['_' => $inline ? 'keyboardInlineButton' : 'keyboardButton', 'text' => $button['text'], 'type' => self::validateAction($action, $inline, in_array($button['_'] ?? null, ['keyboardButtonUrl', 'keyboardInlineButton'], true))];
                if (isset($button['style'])) {
                    $native['style'] = TemplateRenderer::nativeStyle($button['style']);
                    if (isset($native['style']['icon'])) {
                        $native['style']['icon'] = TelegramInt64::fromDecimal($native['style']['icon']);
                    }
                }
                foreach (['_presentation_fallback_text', '_presentation_auto_icon'] as $field) {
                    if (array_key_exists($field, $button)) {
                        $native[$field] = $button[$field];
                    }
                }
                $buttons[] = $native;
            }
            $result[] = ['_' => $inline ? 'keyboardInlineButtonRow' : 'keyboardButtonRow', 'buttons' => $buttons];
        }
        return $result;
    }

    private static function action(array $button, bool $inline): array
    {
        $constructor = $button['_'] ?? null;
        if (isset($button['type'])) {
            if ($constructor !== ($inline ? 'keyboardInlineButton' : 'keyboardButton') || !is_array($button['type'])) {
                throw new InvalidArgumentException('Wrong typed keyboard button context.');
            }
            if (array_intersect(['url', 'callback_data', 'data', 'copy_text', 'switch_inline_query', 'switch_inline_query_current_chat', 'switch_inline_query_chosen_chat', 'query', 'web_app', 'login_url', 'pay', 'callback_game', 'request_contact', 'request_location', 'request_poll'], array_keys($button)) !== []) {
                throw new InvalidArgumentException('Typed and flat button actions cannot be combined.');
            }
            return $button['type'];
        }
        $mapping = $inline ? self::INLINE : self::REPLY;
        if ($constructor !== null) {
            if (!isset($mapping[$constructor])) {
                throw new InvalidArgumentException('Unsupported keyboard button constructor for this context.');
            }
            $action = $button;
            unset($action['text'], $action['style'], $action['_presentation_fallback_text'], $action['_presentation_auto_icon'], $action['icon_custom_emoji_id']);
            $action['_'] = $mapping[$constructor];
            return $action;
        }
        $keys = $inline
            ? ['url', 'callback_data', 'data', 'copy_text', 'switch_inline_query', 'switch_inline_query_current_chat', 'switch_inline_query_chosen_chat', 'query', 'web_app', 'login_url', 'pay', 'callback_game']
            : ['request_contact', 'request_location', 'request_poll', 'web_app'];
        $actions = array_values(array_filter($keys, static fn(string $key): bool => array_key_exists($key, $button)));
        if (count($actions) > 1 || ($inline && $actions === [])) {
            throw new InvalidArgumentException('A button needs exactly one action in its keyboard context.');
        }
        if (!$inline && array_intersect(['url', 'callback_data', 'data', 'copy_text', 'switch_inline_query', 'switch_inline_query_current_chat', 'switch_inline_query_chosen_chat', 'query', 'login_url', 'pay', 'callback_game'], array_keys($button)) !== []) {
            throw new InvalidArgumentException('An inline action cannot be used in a reply keyboard.');
        }
        $key = $actions[0] ?? '';
        return match ($key) {
            'url' => ['_' => 'inlineButtonTypeUrl', 'url' => $button['url']],
            'callback_data', 'data' => ['_' => 'inlineButtonTypeCallback', 'data' => $button[$key]],
            'copy_text' => ['_' => 'inlineButtonTypeCopy', 'copy_text' => is_array($button[$key]) ? ($button[$key]['text'] ?? null) : $button[$key]],
            'switch_inline_query', 'switch_inline_query_current_chat', 'query' => ['_' => 'inlineButtonTypeSwitchInline', 'query' => $button[$key], 'same_peer' => $key === 'switch_inline_query_current_chat' || ($key === 'query' && ($button['same_peer'] ?? false))],
            'switch_inline_query_chosen_chat' => self::chosenChat($button[$key]),
            'web_app' => ['_' => $inline ? 'inlineButtonTypeWebView' : 'buttonTypeSimpleWebView', 'url' => is_array($button[$key]) ? ($button[$key]['url'] ?? null) : null],
            'login_url' => self::login($button[$key]),
            'pay' => self::boolean($button[$key]) ? ['_' => 'inlineButtonTypeBuy'] : throw new InvalidArgumentException('Payment button must be enabled.'),
            'callback_game' => is_array($button[$key]) ? ['_' => 'inlineButtonTypeGame'] : throw new InvalidArgumentException('Invalid game button.'),
            'request_contact' => self::boolean($button[$key]) ? ['_' => 'buttonTypeRequestPhone'] : throw new InvalidArgumentException('Contact request must be enabled.'),
            'request_location' => self::boolean($button[$key]) ? ['_' => 'buttonTypeRequestGeoLocation'] : throw new InvalidArgumentException('Location request must be enabled.'),
            'request_poll' => self::poll($button[$key]),
            default => ['_' => 'buttonTypeDefault'],
        };
    }

    private static function validateAction(array $action, bool $inline, bool $nativeUrl = false): array
    {
        $name = $action['_'] ?? '';
        $fields = match ($name) {
            'inlineButtonTypeUrl' => ['url'],
            'inlineButtonTypeUrlAuth' => ['url', 'button_id', 'fwd_text'],
            'inputInlineButtonTypeUrlAuth' => ['url', 'bot', 'fwd_text', 'request_write_access'],
            'inlineButtonTypeWebView', 'buttonTypeSimpleWebView' => ['url'],
            'inlineButtonTypeCallback' => ['data', 'requires_password'],
            'inlineButtonTypeSwitchInline' => ['query', 'same_peer', 'peer_types'],
            'inlineButtonTypeUserProfile', 'inputInlineButtonTypeUserProfile' => ['user_id'],
            'inlineButtonTypeCopy' => ['copy_text'],
            'buttonTypeRequestPoll' => ['quiz'],
            'buttonTypeRequestPeer' => ['button_id', 'peer_type', 'max_quantity'],
            'inputButtonTypeRequestPeer' => ['button_id', 'peer_type', 'max_quantity', 'name_requested', 'username_requested', 'photo_requested'],
            'inlineButtonTypeGame', 'inlineButtonTypeBuy', 'inlineButtonTypeDisabled', 'buttonTypeDefault', 'buttonTypeRequestPhone', 'buttonTypeRequestGeoLocation' => [],
            default => throw new InvalidArgumentException('Unknown keyboard action.'),
        };
        if ($inline !== (str_starts_with($name, 'inlineButtonType') || str_starts_with($name, 'inputInlineButtonType'))) {
            throw new InvalidArgumentException('Inline and reply button actions cannot be interchanged.');
        }
        foreach (array_keys($action) as $field) {
            if ($field !== '_' && !in_array($field, $fields, true)) {
                throw new InvalidArgumentException('Unsupported keyboard action field.');
            }
        }
        if (in_array('url', $fields, true)) {
            $url = self::string($action['url'] ?? null, 4096);
            $schemes = in_array($name, ['inlineButtonTypeWebView', 'buttonTypeSimpleWebView', 'inlineButtonTypeUrlAuth', 'inputInlineButtonTypeUrlAuth'], true) ? ['https'] : ($nativeUrl ? ['https', 'http', 'tg'] : ['https', 'tg']);
            if (
                !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), $schemes, true)
                || !preg_match('~^(?:' . implode('|', $schemes) . ')://~i', $url) || preg_match('/[\r\n\x00]/', $url)
            ) {
                throw new InvalidArgumentException('Button URL must use an allowed secure scheme.');
            }
        }
        if ($name === 'inlineButtonTypeCallback') {
            $data = $action['data'] ?? null;
            if ($data instanceof \Stringable) {
                $data = (string)$data;
            }
            if (!is_string($data) || strlen($data) < 1 || strlen($data) > 64) {
                throw new InvalidArgumentException('Callback data must be 1..64 bytes.');
            }
            $action['data'] = $data;
        }
        if ($name === 'inlineButtonTypeCopy') {
            self::string($action['copy_text'] ?? null, 256);
        }
        if ($name === 'inlineButtonTypeSwitchInline') {
            self::string($action['query'] ?? null, 256, false);
            if (isset($action['peer_types']) && (!is_array($action['peer_types']) || !array_is_list($action['peer_types']))) {
                throw new InvalidArgumentException('Invalid inline peer selectors.');
            }
            foreach ($action['peer_types'] ?? [] as $peer) {
                if (!is_array($peer) || !in_array($peer['_'] ?? '', ['inlineQueryPeerTypeSameBotPM', 'inlineQueryPeerTypePM', 'inlineQueryPeerTypeChat', 'inlineQueryPeerTypeMegagroup', 'inlineQueryPeerTypeBroadcast', 'inlineQueryPeerTypeBotPM'], true)) {
                    throw new InvalidArgumentException('Invalid inline peer selector.');
                }
            }
        }
        if ($name === 'inlineButtonTypeUserProfile') {
            $action['user_id'] = TelegramInt64::fromDecimal($action['user_id'] ?? '');
            if ($action['user_id'] < 1) {
                throw new InvalidArgumentException('Invalid profile user.');
            }
        }
        if ($name === 'inputInlineButtonTypeUserProfile' && !isset($action['user_id'])) {
            throw new InvalidArgumentException('Missing input profile user.');
        }
        foreach (['same_peer', 'requires_password', 'request_write_access', 'quiz', 'name_requested', 'username_requested', 'photo_requested'] as $flag) {
            if (array_key_exists($flag, $action)) {
                $action[$flag] = self::boolean($action[$flag]);
            }
        }
        if (isset($action['fwd_text'])) {
            self::string($action['fwd_text'], 128);
        }
        if (in_array('button_id', $fields, true) && (!is_int($action['button_id'] ?? null) || $action['button_id'] < -2147483648 || $action['button_id'] > 2147483647)) {
            throw new InvalidArgumentException('Invalid request button identifier.');
        }
        if (in_array('peer_type', $fields, true) && (!is_array($action['peer_type'] ?? null) || !in_array($action['peer_type']['_'] ?? '', ['requestPeerTypeUser', 'requestPeerTypeChat', 'requestPeerTypeBroadcast', 'requestPeerTypeCreateBot'], true))) {
            throw new InvalidArgumentException('Invalid request peer type.');
        }
        if (in_array('max_quantity', $fields, true) && (!is_int($action['max_quantity'] ?? null) || $action['max_quantity'] < 1 || $action['max_quantity'] > 10)) {
            throw new InvalidArgumentException('Invalid peer request quantity.');
        }
        return $action;
    }

    private static function chosenChat(mixed $choice): array
    {
        if (!is_array($choice)) {
            throw new InvalidArgumentException('Invalid chosen-chat switch.');
        }
        $action = ['_' => 'inlineButtonTypeSwitchInline', 'query' => $choice['query'] ?? '', 'same_peer' => false];
        $types = [];
        foreach (['allow_user_chats' => ['inlineQueryPeerTypePM'], 'allow_bot_chats' => ['inlineQueryPeerTypeBotPM'], 'allow_group_chats' => ['inlineQueryPeerTypeChat', 'inlineQueryPeerTypeMegagroup'], 'allow_channel_chats' => ['inlineQueryPeerTypeBroadcast']] as $flag => $constructors) {
            if (array_key_exists($flag, $choice) && self::boolean($choice[$flag])) {
                foreach ($constructors as $constructor) {
                    $types[] = ['_' => $constructor];
                }
            }
        }
        if ($types !== []) {
            $action['peer_types'] = $types;
        }
        return $action;
    }

    private static function login(mixed $login): array
    {
        if (!is_array($login)) {
            throw new InvalidArgumentException('Invalid login URL button.');
        }
        $action = ['_' => 'inputInlineButtonTypeUrlAuth', 'url' => $login['url'] ?? null];
        foreach (['forward_text' => 'fwd_text', 'bot_username' => 'bot', 'request_write_access' => 'request_write_access'] as $from => $to) {
            if (array_key_exists($from, $login)) {
                $action[$to] = $login[$from];
            }
        }
        return $action;
    }

    private static function poll(mixed $poll): array
    {
        if (!is_array($poll) || (isset($poll['type']) && !in_array($poll['type'], ['quiz', 'regular'], true))) {
            throw new InvalidArgumentException('Invalid poll request.');
        }
        return ['_' => 'buttonTypeRequestPoll'] + (isset($poll['type']) ? ['quiz' => $poll['type'] === 'quiz'] : []);
    }

    private static function boolean(mixed $value): bool
    {
        return is_bool($value) ? $value : throw new InvalidArgumentException('Keyboard flags must be booleans.');
    }

    private static function string(mixed $value, int $maximum, bool $nonempty = true): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || ($nonempty && trim($value) === '') || mb_strlen($value) > $maximum) {
            throw new InvalidArgumentException('Invalid keyboard text.');
        }
        return $value;
    }
}
