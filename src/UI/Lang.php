<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

final class Lang
{
    public const CODES = ['he','en','ru','fr','es','pt-br'];
    public const NATIVE_NAMES = ['he' => '🇮🇱 עברית','en' => '🇬🇧 English','ru' => '🇷🇺 Русский','fr' => '🇫🇷 Français','es' => '🇪🇸 Español','pt-br' => '🇧🇷 Português (Brasil)'];
    private array $locales = [];
    public function __construct(?string $directory = null)
    {
        $directory ??= dirname(__DIR__, 2) . '/resources/locales';
        foreach (self::CODES as $language) {
            $file = $directory . '/' . $language . '.json';
            if (!is_file($file)) {
                throw new \RuntimeException('Missing required locale: ' . $language);
            }
            $data = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid locale dictionary: ' . $language);
            }
            $this->locales[$language] = $data;
        }
        $this->validate();
    }
    public static function canonical(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        if ($language === 'pt') {
            $language = 'pt-br';
        }
        return in_array($language, self::CODES, true) ? $language : 'he';
    }
    public static function telegramLanguageCode(string $language): string
    {
        $language = self::canonical($language);
        return $language === 'pt-br' ? 'pt' : $language;
    }
    public function languages(): array
    {
        return self::CODES;
    }
    public function text(string $language, string $key, array $values = []): string
    {
        $language = self::canonical($language);
        $text = $this->locales[$language][$key] ?? $this->locales['he'][$key] ?? throw new \InvalidArgumentException('Missing translation ' . $key);
        preg_match_all('/\{\{([a-z_]+)\}\}/', $text, $matches);
        foreach (array_unique($matches[1]) as $name) {
            if (!array_key_exists($name, $values)) {
                throw new \InvalidArgumentException('Missing placeholder ' . $name);
            }
            $text = str_replace('{{' . $name . '}}', htmlspecialchars((string)$values[$name], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $text);
        }
        return $text;
    }
    public function validate(): void
    {
        $keys = array_keys($this->locales['he']);
        sort($keys);
        foreach (self::CODES as $language) {
            $other = array_keys($this->locales[$language]);
            sort($other);
            if ($keys !== $other) {
                throw new \RuntimeException('Translation keys differ for ' . $language);
            }
            foreach ($keys as $key) {
                $value = $this->locales[$language][$key];
                if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8')) {
                    throw new \RuntimeException('Empty/invalid translation: ' . $language . '/' . $key);
                }
                preg_match_all('/\{\{([a-z_]+)\}\}/', $this->locales['he'][$key], $a);
                preg_match_all('/\{\{([a-z_]+)\}\}/', $value, $b);
                sort($a[1]);
                sort($b[1]);
                if ($a[1] !== $b[1]) {
                    throw new \RuntimeException('Translation placeholders differ: ' . $language . '/' . $key);
                }
            }
        }
    }
    public function keys(): array
    {
        return array_keys($this->locales['he']);
    }
}
