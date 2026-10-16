<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
$root = dirname(__DIR__);
$directory = $root . '/resources/locales';
$required = ['he', 'en', 'ru', 'fr', 'es', 'pt-br'];
$errors = [];
$locales = [];
$flatten = static function (array $data, string $prefix = '') use (&$flatten): array {
    $out = [];
    foreach ($data as $key => $value) {
        $name = $prefix . $key;
        if (is_array($value)) {
            $out += $flatten($value, $name . '.');
        } else {
            $out[$name] = $value;
        }
    }
    return $out;
};
foreach ($required as $code) {
    $file = $directory . '/' . $code . '.json';
    if (!is_file($file)) {
        $errors[] = $code . ': required locale file is missing';
        continue;
    }
    try {
        $value = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($value) || $value === []) {
            throw new RuntimeException('Locale must be a nonempty dictionary.');
        }
        $locales[$code] = $flatten($value);
    } catch (Throwable $error) {
        $errors[] = $code . ': invalid locale JSON: ' . $error->getMessage();
    }
}
$base = $locales['he'] ?? [];
$baseKeys = array_keys($base);
sort($baseKeys);
foreach ($locales as $code => $locale) {
    $keys = array_keys($locale);
    sort($keys);
    if ($keys !== $baseKeys) {
        $missing = array_diff($baseKeys, $keys);
        $extra = array_diff($keys, $baseKeys);
        $errors[] = $code . ': missing keys ' . implode(',', $missing) . '; extra keys ' . implode(',', $extra);
    }
    foreach ($base as $key => $source) {
        $translated = $locale[$key] ?? null;
        if (!is_string($source) || !is_string($translated) || trim($source) === '' || trim($translated) === '') {
            $errors[] = $code . '/' . $key . ': missing, empty or non-string translation';
            continue;
        }
        $pattern = '/(?:\{\{?([a-zA-Z_][a-zA-Z_0-9]*)\}\}?|%(?:\d+\$)?[sd])/u';
        preg_match_all($pattern, $source, $a);
        preg_match_all($pattern, $translated, $b);
        sort($a[0]);
        sort($b[0]);
        if ($a[0] !== $b[0]) {
            $errors[] = $code . '/' . $key . ': placeholder mismatch';
        }
        if (!mb_check_encoding($translated, 'UTF-8')) {
            $errors[] = $code . '/' . $key . ': invalid UTF-8';
        }
    }
}
// Validate the actual public language metadata, without booting MadelineProto.
require_once $root . '/src/UI/Lang.php';
if (!defined(Shabbat\UI\Lang::class . '::CODES') || Shabbat\UI\Lang::CODES !== $required) {
    $errors[] = 'Lang::CODES must list exactly the six required internal locale codes.';
}
if ($errors === []) {
    try {
        $language = new Shabbat\UI\Lang($directory);
        if ($language->languages() !== $required) {
            $errors[] = 'Lang::languages does not match the locale registry.';
        }
        foreach ($required as $code) {
            $telegramCode = $language->telegramLanguageCode($code);
            if ($telegramCode !== ($code === 'pt-br' ? 'pt' : $code)) {
                $errors[] = $code . ': Telegram language code must use the approved two-letter mapping.';
            }
        }
        $keys = $language->keys();
        sort($keys);
        if ($keys !== $baseKeys) {
            $errors[] = 'Lang::keys differs from the checked locale dictionary.';
        }
    } catch (Throwable $error) {
        $errors[] = 'Language service validation failed: ' . $error->getMessage();
    }
}
foreach ($errors as $error) {
    fwrite(STDERR, $error . "\n");
}
echo 'Locales: ' . count($locales) . '/' . count($required) . ' languages, '
    . count($baseKeys) . ' keys, ' . count($errors) . " errors.\n";
exit($errors === [] ? 0 : 1);
