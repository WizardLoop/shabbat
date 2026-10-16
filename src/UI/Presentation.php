<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\UI;

/** One visual vocabulary for native MTProto messages, HTML, templates and keyboards. */
final class Presentation
{
    public const ICONS = [
        'settings' => '5944760643524500583',
        'support' => '5945186253308699040',
        'info' => '5945067566182440529',
        'source_code' => '5945275184901529100',
        'groups' => '5944900831257043691',
        'add_to_group' => '5947491022954045554',
        'shabbat_holidays' => '5944845194250690529',
        'tefillin' => '5944970388252402183',
        'daily_zmanim' => '5945067360024011010',
        'daf_yomi' => '5947481135939328427',
        'omer' => '5945033816329428634',
        'brand_coin' => '5947339492212875204',
    ];

    private const EMOJI = [
        '⚙️' => 'settings', '⚙' => 'settings', '🛠' => 'settings',
        '⭐️' => 'support', '⭐' => 'support', '🌟' => 'support',
        'ℹ️' => 'info', 'ℹ' => 'info', '🛡' => 'info', '📊' => 'info',
        '💻' => 'source_code', '👥' => 'groups', '➕' => 'add_to_group',
        '🕯️' => 'shabbat_holidays', '🕯' => 'shabbat_holidays',
        '🔔' => 'tefillin', '🕒' => 'daily_zmanim', '📖' => 'daf_yomi',
        '🌾' => 'omer', '✡️' => 'brand_coin', '✡' => 'brand_coin',
    ];

    public static function enabledFromEnvironment(): bool
    {
        $value = getenv('CUSTOM_EMOJI_ENABLED');
        if ($value === false || trim($value) === '') {
            return true;
        }
        return match (strtolower(trim($value))) {
            '1', 'true', 'on' => true,
            '0', 'false', 'off' => false,
            default => throw new \RuntimeException('CUSTOM_EMOJI_ENABLED must be 1 or 0.'),
        };
    }
    public static function iconForKey(string $key): ?string
    {
        $semantic = match ($key) {
            'preferences', 'settings', 'edit', 'language', 'location', 'schedule', 'status',
            'info_personal_title', 'info_operators_title', 'moderation', 'announcement_custom' => 'settings',
            'donate', 'donate_other', 'support' => 'support',
            'help', 'info', 'info_privacy_title', 'info_intro' => 'info',
            'source', 'source_code' => 'source_code',
            'groups', 'info_groups_title' => 'groups',
            'add' => 'add_to_group',
            'shabbat', 'candles', 'havdalah', 'info_calendar_title' => 'shabbat_holidays',
            'tefillin', 'info_notifications_title' => 'tefillin',
            'zmanim' => 'daily_zmanim',
            'daf' => 'daf_yomi', 'omer' => 'omer', 'welcome', 'home' => 'brand_coin',
            default => null,
        };
        return $semantic === null ? null : self::ICONS[$semantic];
    }

    /** Buttons keep readable Unicode fallbacks; native style.icon adds the animated asset. */
    public static function button(array $button, bool $enabled = true, ?string $key = null, bool $native = false): array
    {
        if (isset($button['text']) && is_string($button['text'])) {
            $button['text'] = str_replace('—', '-', $button['text']);
        }
        $style = $button['style'] ?? ['_' => 'keyboardButtonStyle'];
        if (is_string($style)) {
            $style = \Shabbat\Content\TemplateRenderer::nativeStyle($style);
        }
        if (!is_array($style)) {
            throw new \InvalidArgumentException('Invalid button presentation style.');
        }
        if (!$enabled) {
            if (is_string($button['_presentation_fallback_text'] ?? null)) {
                $button['text'] = $button['_presentation_fallback_text'];
            }
            unset($style['icon'], $button['icon_custom_emoji_id'], $button['_presentation_fallback_text'], $button['_presentation_auto_icon']);
        } elseif (isset($button['icon_custom_emoji_id'])) {
            if (isset($style['icon']) && (string)$style['icon'] !== (string)$button['icon_custom_emoji_id']) {
                throw new \InvalidArgumentException('Conflicting button icons.');
            }
            $style['icon'] = $button['icon_custom_emoji_id'];
            unset($button['icon_custom_emoji_id']);
        } elseif (!isset($style['icon'])) {
            $icon = $key === null ? self::iconForText((string)($button['text'] ?? '')) : self::iconForKey($key);
            if ($icon !== null) {
                $style['icon'] = $icon;
                $button['_presentation_auto_icon'] = true;
            }
        }
        if ($native && $enabled && ($button['_presentation_auto_icon'] ?? false) === true && is_string($button['text'] ?? null)) {
            foreach (self::EMOJI as $emoji => $semantic) {
                if ((string)($style['icon'] ?? '') === self::ICONS[$semantic] && str_starts_with($button['text'], $emoji)) {
                    $label = ltrim(substr($button['text'], strlen($emoji)));
                    if ($label !== '') {
                        $button['_presentation_fallback_text'] = $button['text'];
                        $button['text'] = $label;
                    }
                    break;
                }
            }
            if (
                !isset($button['_presentation_fallback_text'])
                && preg_match('/^(\X)\s*/u', $button['text'], $prefix)
                && preg_match('/[\p{Extended_Pictographic}\p{Regional_Indicator}\x{20E3}]/u', $prefix[1])
            ) {
                $label = substr($button['text'], strlen($prefix[0]));
                if ($label !== '') {
                    $button['_presentation_fallback_text'] = $button['text'];
                    $button['text'] = $label;
                }
            }
            unset($button['_presentation_auto_icon']);
        }
        $button['style'] = $style;
        return $button;
    }

    public static function requiresCatalog(string $text): bool
    {
        return preg_match(self::pattern(), $text) === 1;
    }
    public static function iconForText(string $text): ?string
    {
        foreach (self::EMOJI as $emoji => $semantic) {
            if (str_starts_with(ltrim($text), $emoji)) {
                return self::ICONS[$semantic];
            }
        }
        return null;
    }

    /** Never rewrite HTML attributes, code samples or existing custom-emoji elements. */
    public static function html(string $html, bool $enabled = true, array $alternatives = []): string
    {
        $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new \InvalidArgumentException('Invalid presentation UTF-8.');
        }
        $protected = [];
        $output = '';
        foreach ($parts as $part) {
            if (str_starts_with($part, '<')) {
                if (preg_match('/^<\s*(\/?)(tg-emoji|code|pre)\b/iu', $part, $match)) {
                    $tag = strtolower($match[2]);
                    if ($match[1] === '/') {
                        array_pop($protected);
                    } else {
                        $protected[] = $tag;
                    }
                    if ($tag === 'tg-emoji' && !$enabled) {
                        continue;
                    }
                }
                $output .= $part;
                continue;
            }
            $part = str_replace('—', '-', $part);
            if ($enabled && $protected === []) {
                $part = preg_replace_callback(self::pattern(), static function (array $match) use ($alternatives): string {
                    $id = self::ICONS[self::EMOJI[$match[0]]];
                    $alt = $alternatives[$id] ?? null;
                    if (!is_string($alt) || $alt === '' || (self::EMOJI[$match[0]] === 'brand_coin' && $alt !== $match[0])) {
                        return $match[0];
                    }
                    return '<tg-emoji emoji-id="' . $id . '">' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</tg-emoji>';
                }, $part) ?? throw new \InvalidArgumentException('Invalid presentation text.');
            }
            $output .= $part;
        }
        return $output;
    }

    /** Plain text is unchanged in length; UTF-16 offsets and existing rich entities remain valid. */
    public static function payload(array $payload, bool $enabled = true, array $alternatives = [], bool $html = true): array
    {
        $text = $payload['text'] ?? '';
        if (!is_string($text)) {
            throw new \InvalidArgumentException('Message presentation requires text.');
        }
        if (array_key_exists('entities', $payload)) {
            $html = false;
        }
        $entities = $payload['entities'] ?? [];
        if ($entities === [] && $html) {
            $payload['text'] = self::html($text, $enabled, $alternatives);
            return $payload;
        }
        $payload['text'] = str_replace('—', '-', $text);
        if (!$enabled) {
            $payload['entities'] = array_values(array_filter($entities, static fn(array $entity): bool =>
                !in_array($entity['_'] ?? $entity['type'] ?? '', ['messageEntityCustomEmoji', 'custom_emoji'], true)));
            if ($payload['entities'] === [] && $html) {
                $payload['text'] = htmlspecialchars($payload['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            return $payload;
        }
        preg_match_all(self::pattern(), $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$emoji, $byteOffset]) {
            $offset = self::utf16(substr($text, 0, $byteOffset));
            $length = self::utf16($emoji);
            $protected = false;
            foreach ($entities as $entity) {
                if (
                    in_array($entity['_'] ?? $entity['type'] ?? '', ['messageEntityCustomEmoji', 'custom_emoji', 'messageEntityCode', 'code', 'messageEntityPre', 'pre'], true)
                    && $offset < ($entity['offset'] ?? 0) + ($entity['length'] ?? 0)
                    && $offset + $length > ($entity['offset'] ?? 0)
                ) {
                    $protected = true;
                    break;
                }
            }
            if (!$protected && ($alternatives[self::ICONS[self::EMOJI[$emoji]]] ?? null) === $emoji) {
                $entities[] = ['_' => 'messageEntityCustomEmoji', 'offset' => $offset, 'length' => $length,
                    'document_id' => (int)self::ICONS[self::EMOJI[$emoji]]];
            }
        }
        $payload['entities'] = $entities;
        return $payload;
    }

    private static function pattern(): string
    {
        return '/(?:' . implode('|', array_map(static fn(string $emoji): string => preg_quote($emoji, '/'), array_keys(self::EMOJI))) . ')/u';
    }

    private static function utf16(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
