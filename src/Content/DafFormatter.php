<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Content;

use Shabbat\UI\Lang;

/** Formats already-loaded provider data without network access or error suppression. */
final class DafFormatter
{
    public function __construct(private readonly Lang $lang = new Lang())
    {
    }

    /** Raw substitution value: HTML templates escape it once; native entities use it unchanged. */
    public function plain(array $context, array $data, string $date): string
    {
        return $this->title($context, $data) . ' - ' . $date . "\n" . ($data['link'] ?? '');
    }

    /** The gateway applies Presentation's animated Daf icon and its existing fallback. */
    public function html(array $context, array $data, string $date): string
    {
        $language = $context['language'] ?? 'he';
        $header = $this->lang->text($language, 'date_view', [
            'module' => $this->lang->text($language, 'daf'),
            'date' => $date,
            'location' => $context['location_name'],
            'timezone' => $context['timezone_id'],
            'hdate' => $data['hdate'] ?? '-',
            'content' => '',
        ]);
        $content = '<b>' . self::escape($this->title($context, $data)) . '</b>';
        if (is_string($data['link'] ?? null) && parse_url($data['link'], PHP_URL_SCHEME) === 'https') {
            $content .= "\n" . '<a href="' . self::escape($data['link']) . '">'
                . self::escape($this->lang->text($language, 'daf_open_page')) . '</a>';
        }
        return $header . $content;
    }

    private function title(array $context, array $data): string
    {
        return ($context['language'] ?? 'he') === 'he' ? ($data['hebrew'] ?? $data['title']) : $data['title'];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
