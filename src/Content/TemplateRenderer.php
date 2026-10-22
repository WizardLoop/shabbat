<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Content;

final class TemplateRenderer
{
    private const VARIABLES = ['location','date','hdate','sunset','remaining','candles','havdalah','close','open','daf','omer','source'];
    /** Validate an editable source without expanding or rewriting its stored text/entities. */
    public function validateStoredPayload(array $payload): void
    {
        $this->renderPayload($payload, array_fill_keys(self::VARIABLES, 'value'));
        if (self::utf16((string)($payload['text'] ?? '')) > 4096) {
            throw new \InvalidArgumentException('Template text exceeds the UTF-16 message limit.');
        }
        self::validateButtons($payload['buttons'] ?? []);
    }
    public function render(string $template, array $values): string
    {
        if (mb_strlen($template) > 4096) {
            throw new \InvalidArgumentException('Template is too long.');
        }
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $template, $matches);
        foreach ($matches[1] as $name) {
            if (!in_array($name, self::VARIABLES, true) || !array_key_exists($name, $values) || (!is_scalar($values[$name]) && $values[$name] !== null)) {
                throw new \InvalidArgumentException('Unknown/unavailable template variable: ' . $name);
            }
        }
        $result = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn(array $m): string=>htmlspecialchars((string)$values[$m[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $template);
        if ($result === null || mb_strlen($result) > 4096) {
            throw new \InvalidArgumentException('Rendered template exceeds Telegram limits.');
        }
        return $result;
    }
    /** Expand plain text with MTProto UTF-16 entity offsets, or escaped HTML without entities. */
    public function renderPayload(array $payload, array $values): array
    {
        $text = $payload['text'] ?? '';
        if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 4096) {
            throw new \InvalidArgumentException('Invalid template UTF-8 text.');
        }
        $entities = $payload['entities'] ?? [];
        if (!is_array($entities)) {
            throw new \InvalidArgumentException('Invalid template entities.');
        }
        if (!array_key_exists('entities', $payload)) {
            $payload['text'] = $this->render($text, $values);
            return $payload;
        }
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches, PREG_OFFSET_CAPTURE);
        $replacements = [];
        $expanded = '';
        $cursor = 0;
        foreach ($matches[0] as $index => $match) {
            [$placeholder,$byteStart] = $match;
            $name = $matches[1][$index][0];
            if (!in_array($name, self::VARIABLES, true) || !array_key_exists($name, $values) || (!is_scalar($values[$name]) && $values[$name] !== null)) {
                throw new \InvalidArgumentException('Unknown/unavailable template variable: ' . $name);
            }
            $value = (string)$values[$name];
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new \InvalidArgumentException('Invalid template variable UTF-8.');
            }
            $start = self::utf16(substr($text, 0, $byteStart));
            $oldLength = self::utf16($placeholder);
            $replacements[] = ['start' => $start,'end' => $start + $oldLength,'length' => self::utf16($value)];
            $expanded .= substr($text, $cursor, $byteStart - $cursor) . $value;
            $cursor = $byteStart + strlen($placeholder);
        }
        $expanded .= substr($text, $cursor);
        if (self::utf16($expanded) > 4096) {
            throw new \InvalidArgumentException('Rendered template exceeds Telegram limits.');
        }
        $total = self::utf16($text);
        $adjusted = [];
        $boundaries = [0 => true];
        $position = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $position += self::utf16($character);
            $boundaries[$position] = true;
        }
        foreach ($entities as $entity) {
            if (
                !is_array($entity) || !is_int($entity['offset'] ?? null) || !is_int($entity['length'] ?? null)
                || $entity['offset'] < 0 || $entity['length'] < 1 || $entity['offset'] + $entity['length'] > $total
                || !isset($boundaries[$entity['offset']], $boundaries[$entity['offset'] + $entity['length']])
            ) {
                throw new \InvalidArgumentException('Entity lies outside template text.');
            }
            $entity = DateEntity::normalize($entity);
            $start = self::mapPosition($entity['offset'], $replacements, false);
            $end = self::mapPosition($entity['offset'] + $entity['length'], $replacements, true);
            if ($end > $start) {
                $entity['offset'] = $start;
                $entity['length'] = $end - $start;
                $adjusted[] = $entity;
            }
        }
        $payload['text'] = $expanded;
        $payload['entities'] = $adjusted;
        return $payload;
    }
    private static function utf16(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
    private static function mapPosition(int $position, array $replacements, bool $end): int
    {
        $shift = 0;
        foreach ($replacements as $replacement) {
            if ($position < $replacement['start']) {
                break;
            }
            if ($position === $replacement['start']) {
                return $position + $shift;
            }
            if ($position < $replacement['end']) {
                return $replacement['start'] + $shift + ($end ? $replacement['length'] : 0);
            }
            $shift += $replacement['length'] - ($replacement['end'] - $replacement['start']);
        }
        return $position + $shift;
    }
    public static function nativeStyle(mixed $style): array
    {
        if (is_string($style)) {
            $flag = match ($style) {
                'primary'=>'bg_primary','success'=>'bg_success','danger'=>'bg_danger',default=>throw new \InvalidArgumentException('Unsupported button color.')
            };
            return ['_' => 'keyboardButtonStyle',$flag => true];
        }
        if (!is_array($style) || ($style['_'] ?? 'keyboardButtonStyle') !== 'keyboardButtonStyle') {
            throw new \InvalidArgumentException('Invalid button style.');
        }
        $active = 0;
        foreach ($style as $key => $value) {
            if ($key === '_') {
                continue;
            }
            if (in_array($key, ['bg_primary','bg_success','bg_danger'], true)) {
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException('Button color flags must be booleans.');
                }
                if ($value) {
                    $active++;
                }
            } elseif ($key === 'icon') {
                $decimal = is_int($value) ? (string)$value : $value;
                if (!is_string($decimal) || !preg_match('/^[1-9][0-9]{0,18}$/D', $decimal) || (strlen($decimal) === 19 && strcmp($decimal, '9223372036854775807') > 0)) {
                    throw new \InvalidArgumentException('Button icon must be a positive int64 identifier.');
                }
            } else {
                throw new \InvalidArgumentException('Unsupported button style field.');
            }
        }
        if ($active > 1) {
            throw new \InvalidArgumentException('Choose one background color per button.');
        }
        return ['_' => 'keyboardButtonStyle'] + $style;
    }
    public static function validateButtons(array $rows): void
    {
        if (!array_is_list($rows) || count($rows) > 20) {
            throw new \InvalidArgumentException('Invalid keyboard rows.');
        }
        // Validation uses the same action/context contract as the native transport.
        // The caller retains its exact persisted representation and rich metadata.
        \Shabbat\Telegram\KeyboardMarkup::normalize(['inline_keyboard' => $rows]);
    }
}
