<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Runtime;

use InvalidArgumentException;
use Shabbat\UI\Lang;

/** Presentation adapter for the reviewed BroadcastManager hook; no RPC or file access. */
final class BroadcastStatusFormatter
{
    public function __construct(private readonly Lang $lang, private readonly string $language)
    {
        if (!in_array($language, Lang::CODES, true)) {
            throw new InvalidArgumentException('Unsupported broadcast status language.');
        }
    }

    public function __invoke(string $event, array $context): string
    {
        return match ($event) {
            'starting' => $this->text('operator_bc_starting', ['operation' => $this->operation($context['type'] ?? null)]),
            'progress' => $this->progress($context),
            'pause', 'resume', 'cancel' => $this->text('operator_' . $event),
            'last_data' => is_array($context['progress'] ?? null)
                ? $this->progress($context['progress'] + ['final' => (bool) ($context['progress']['done'] ?? false)])
                : $this->text('operator_bc_legacy_summary'),
            default => throw new InvalidArgumentException('Unknown broadcast presentation event.'),
        };
    }

    /** Convert only upstream controls; preserve operation identifiers byte for byte. */
    public function controls(array $default, array $context): array
    {
        $id = $context['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,96}$/D', $id)) {
            throw new InvalidArgumentException('Invalid broadcast control context.');
        }
        $rows = $default['inline_keyboard'] ?? null;
        if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0]) || count($rows[0]) !== 2) {
            throw new InvalidArgumentException('Unknown broadcast controls.');
        }
        $buttons = [];
        foreach ($rows[0] as $button) {
            $data = $button['callback_data'] ?? null;
            $text = $button['text'] ?? null;
            if (!is_string($data) || !is_string($text) || !preg_match('/^bm:(pause|resume|cancel):' . preg_quote($id, '/') . '$/D', $data, $matches)) {
                throw new InvalidArgumentException('Broadcast callback binding changed.');
            }
            $flag = match ($matches[1]) {
                'resume' => 'bg_primary',
                'cancel' => 'bg_danger',
                'pause' => null,
            };
            $native = ['_' => 'keyboardButtonCallback', 'text' => $text, 'data' => $data];
            if ($flag !== null) {
                $native['style'] = ['_' => 'keyboardButtonStyle', $flag => true];
            }
            $buttons[] = $native;
        }
        return ['_' => 'replyInlineMarkup', 'rows' => [['_' => 'keyboardButtonRow', 'buttons' => $buttons]]];
    }

    private function progress(array $context): string
    {
        $breakdown = is_array($context['breakdown'] ?? null) ? $context['breakdown'] : [];
        $context = $context + $breakdown;
        $values = [];
        foreach (['processed', 'total', 'sent', 'edited', 'unchanged', 'deleted', 'unpin', 'failed', 'flood', 'pending'] as $key) {
            $value = $context[$key] ?? 0;
            if (!is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Invalid broadcast status counter.');
            }
            $values[$key] = (string) $value;
        }
        $rate = $context['tps'] ?? 0;
        if ((!is_int($rate) && !is_float($rate)) || !is_finite((float) $rate) || $rate < 0) {
            throw new InvalidArgumentException('Invalid broadcast status rate.');
        }
        $decimal = in_array($this->language, ['fr', 'es', 'ru', 'pt-br'], true) ? ',' : '.';
        $values['tps'] = number_format((float) $rate, 2, $decimal, '');
        $total = (int) $values['total'];
        $percent = $total > 0 ? min(100, (int) round(((int) $values['processed'] / $total) * 100)) : 0;
        $filled = (int) round($percent / 5);
        $bar = str_repeat('#', $filled) . str_repeat('-', 20 - $filled) . ' ' . $percent . '%';
        $lines = ['<b>' . $this->escape($this->operation($context['type'] ?? null)) . '</b>', '<code>' . $bar . '</code>', $this->text('operator_bc_counts', $values)];
        if ($context['paused'] ?? false) {
            $lines[] = '<b>' . $this->text('operator_bc_paused') . '</b>';
        }
        if ($context['cancel'] ?? false) {
            $lines[] = '<b>' . $this->text('operator_bc_cancelled') . '</b>';
        } elseif (($context['final'] ?? false) || ($context['done'] ?? false)) {
            $lines[] = '<b>' . $this->text('operator_bc_finished') . '</b>';
        }
        return implode("\n", $lines);
    }

    private function operation(mixed $type): string
    {
        return $this->text(match ($type) {
            'edit' => 'operator_bc_edit',
            'deletelast' => 'operator_delete_last',
            'deleteall' => 'operator_delete_all',
            'unpin' => 'operator_unpin',
            'selfdestruct' => 'operator_bc_selfdestruct',
            default => 'operator_progress',
        });
    }

    private function text(string $key, array $values = []): string
    {
        return $this->lang->text($this->language, $key, $values);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
