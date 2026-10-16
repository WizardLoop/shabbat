<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

use Shabbat\Storage\SettingsRepository;
use Shabbat\Storage\SubscriberDirectory;
use Shabbat\Time\Clock;

/** Frames plus guarded draft controls; returned actions are executed by the runtime after confirmation. */
final class BroadcastController
{
    private \Closure $authorize;

    public function __construct(private readonly SettingsRepository $settings, private readonly OperatorDraft $drafts, private readonly Clock $clock, callable $authorize)
    {
        $this->authorize = \Closure::fromCallable($authorize);
    }

    public function main(string $user): array
    {
        $this->allow($user);
        $rows = [];
        if ($this->drafts->state($user)['exists']) {
            $rows[] = [$this->button($user, 'resume_draft', 'bc:draft', 'bg_primary')];
        }
        $rows[] = [$this->button($user, 'progress', 'LastBrodDATA'), $this->button($user, 'jobs', 'bc:jobs:0')];
        if ($this->can($user, true)) {
            $rows[] = [$this->button($user, 'send', 'bc:new', 'bg_primary'), $this->button($user, 'edit', 'bc:edit', 'bg_primary')];
            $rows[] = [$this->button($user, 'delete_last', 'deleteLastBroadcast', 'bg_danger'), $this->button($user, 'delete_all', 'deleteAllBroadcast', 'bg_danger')];
            $rows[] = [$this->button($user, 'unpin', 'cancelPinned')];
        }
        $rows[] = [$this->button($user, 'back', 'חזרהמנהל')];
        return $this->frame('<b>' . $this->t($user, 'broadcast') . '</b>\n\n' . $this->t($user, 'workflow') . '\n\n<i>' . $this->t($user, 'send_note') . '</i>', $rows);
    }

    public function draft(string $user): array
    {
        $this->allow($user);
        $state = $this->drafts->state($user);
        $text = '<b>' . $this->t($user, 'broadcast') . '</b>\n\n'
            . $this->t($user, 'operation') . ': <b>' . $this->t($user, $state['operation']) . '</b>\n'
            . $this->t($user, 'audience') . ': <b>' . $this->t($user, $state['audience']) . '</b>\n'
            . $this->t($user, 'pin') . ': <b>' . $this->t($user, $state['pin'] ? 'on' : 'off') . '</b>';
        if ($state['operation'] === 'send') {
            $text .= "\n" . $this->t($user, 'self_destruct') . ': <b>' . ($state['self_destruct_hours'] === null ? $this->t($user, 'off') : $state['self_destruct_hours'] . ' ' . $this->t($user, 'hours')) . '</b>'
                . "\n" . $this->t($user, 'schedule') . ': <b>' . ($state['scheduled_at'] === null ? $this->t($user, 'now') : $this->date($user, $state['scheduled_at'])) . '</b>';
        }
        $text .= "\n\n<b>" . $this->t($user, $state['exists'] ? 'content_ready' : 'content_missing') . '</b>';
        if ($state['exists']) {
            $preview = mb_substr($state['payload']['text'], 0, 180);
            $text .= "\n<blockquote>" . self::html($preview === '' ? '📎' : $preview)
                . (mb_strlen($state['payload']['text']) > 180 ? '…' : '') . '</blockquote>';
            $text .= "\n" . $this->t($user, 'button_count') . ': <b>' . array_sum(array_map('count', $state['payload']['buttons'])) . '</b>';
        }
        $text .= "\n\n<i>" . $this->t($user, $state['operation'] === 'edit' ? 'edit_note' : 'send_note') . '</i>';
        if (!$state['exists']) {
            $text .= "\n\n" . $this->t($user, 'no_draft');
        }
        $rows = [];
        if ($this->can($user, true)) {
            $rows[] = [$this->button($user, 'content', 'bc:content', 'bg_primary'), $this->button($user, 'buttons', 'bc:buttons')];
            $rows[] = [$this->button($user, 'pin', $state['pin'] ? 'bc:pin:off' : 'bc:pin:on', $state['pin'] ? 'bg_primary' : null), $this->button($user, 'audience', 'bc:audience')];
            if ($state['operation'] === 'send') {
                $rows[] = [$this->button($user, 'self_destruct', 'bc:sd'), $this->button($user, 'schedule', 'bc:schedule')];
            }
            if ($state['exists']) {
                $rows[] = [$this->button($user, 'preview', 'bc:preview'), $this->button($user, 'confirm', 'bc:confirm', 'bg_primary')];
            }
        }
        $rows[] = [$this->button($user, 'cancel', 'bc:main', 'bg_danger')];
        return $this->frame($text, $rows);
    }

    /** Returns an action envelope only for runtime-owned RPC/job operations. */
    public function callback(string $user, string $data): ?array
    {
        if (!str_starts_with($data, 'bc:')) {
            return null;
        }
        $this->allow($user);
        if ($data === 'bc:main') {
            if ($this->can($user, true)) {
                $this->drafts->cancel($user);
            }
            return $this->main($user);
        }
        if ($data === 'bc:draft') {
            if ($this->can($user, true)) {
                $this->drafts->cancel($user);
            }
            return $this->draft($user);
        }
        if (preg_match('/^bc:jobs:([0-9]{1,6})$/D', $data, $match)) {
            return ['action' => 'jobs', 'page' => (int) $match[1]];
        }
        $this->allow($user, true);
        if ($data === 'bc:new' || $data === 'bc:edit') {
            $this->drafts->setOperation($user, $data === 'bc:edit' ? 'edit' : 'send');
            $this->drafts->begin($user, 'broadcast1');
            return $this->prompt($user, 'content_prompt');
        }
        if ($data === 'bc:buttons') {
            $this->drafts->cancel($user);
            return $this->buttonMenu($user);
        }
        if ($data === 'bc:button:add') {
            $this->drafts->begin($user, 'broadcast_button_label');
            return $this->prompt($user, 'button_label_prompt', 'bc:buttons');
        }
        if ($data === 'bc:button:clear') {
            $this->drafts->clearButtons($user);
            return $this->buttonMenu($user);
        }
        if ($data === 'bc:content' || $data === 'bc:buttons:advanced') {
            $this->drafts->begin($user, $data === 'bc:content' ? 'broadcast1' : 'addBUTTONS');
            return $this->prompt($user, $data === 'bc:content' ? 'content_prompt' : 'advanced_buttons_prompt');
        }
        if (in_array($data, ['bc:confirm', 'bc:preview'], true)) {
            return ['action' => $data === 'bc:confirm' ? 'confirm' : 'preview'];
        }
        if (preg_match('/^bc:canceljob:([A-Za-z0-9_-]{1,40})$/D', $data, $match)) {
            return ['action' => 'cancel_job', 'id' => $match[1]];
        }
        if (preg_match('/^bc:canceldelete:([A-Za-z0-9_-]{1,35})$/D', $data, $match)) {
            return ['action' => 'cancel_self_destruct', 'id' => $match[1]];
        }
        if ($data === 'bc:audience') {
            $rows = [];
            foreach (SubscriberDirectory::AUDIENCES as $audience) {
                $rows[] = [$this->button($user, $audience, 'bc:audience:' . $audience)];
            }
            $rows = array_chunk(array_merge(...$rows), 2);
            $rows[] = [$this->button($user, 'back', 'bc:draft')];
            return $this->frame('<b>' . $this->t($user, 'audience') . '</b>\n\n' . $this->t($user, 'audience_note'), $rows);
        }
        if (str_starts_with($data, 'bc:audience:')) {
            $this->drafts->setAudience($user, substr($data, 12));
            return $this->draft($user);
        }
        if ($data === 'bc:pin:on' || $data === 'bc:pin:off') {
            $this->drafts->setPin($user, $data === 'bc:pin:on');
            return $this->draft($user);
        }
        if ($data === 'bc:sd') {
            $rows = [[$this->button($user, 'off', 'bc:sd:off')]];
            foreach ([[1, 6, 12], [24, 48]] as $hours) {
                $rows[] = array_map(fn(int $hour): array => ['text' => $hour . ' ' . $this->t($user, 'hours'), 'data' => 'bc:sd:' . $hour], $hours);
            }
            $rows[] = [$this->button($user, 'custom', 'bc:sd:custom', 'bg_primary')];
            $rows[] = [$this->button($user, 'back', 'bc:draft')];
            return $this->frame('<b>' . $this->t($user, 'self_destruct') . '</b>', $rows);
        }
        if ($data === 'bc:schedule') {
            $rows = [[$this->button($user, 'now', 'bc:schedule:off')]];
            foreach ([[10, 30, 60], [360, 1440]] as $minutes) {
                $rows[] = array_map(fn(int $minute): array => ['text' => '+' . $minute . ' ' . $this->t($user, 'minutes'), 'data' => 'bc:schedule:' . $minute], $minutes);
            }
            $rows[] = [$this->button($user, 'custom', 'bc:schedule:custom', 'bg_primary')];
            $rows[] = [$this->button($user, 'back', 'bc:draft')];
            return $this->frame('<b>' . $this->t($user, 'schedule') . '</b>\n' . self::html($this->settings->getUser($user)['timezone_id']), $rows);
        }
        if ($data === 'bc:sd:custom' || $data === 'bc:schedule:custom') {
            $isSchedule = $data === 'bc:schedule:custom';
            $this->drafts->begin($user, $isSchedule ? 'broadcast_schedule_custom' : 'broadcast_sd_custom');
            return $this->prompt($user, $isSchedule ? 'schedule_prompt' : 'sd_prompt');
        }
        if (preg_match('/^bc:sd:(off|[0-9]{1,2})$/D', $data, $match)) {
            $this->drafts->setSelfDestructHours($user, $match[1] === 'off' ? null : (int) $match[1]);
            return $this->draft($user);
        }
        if (preg_match('/^bc:schedule:(off|[0-9]{1,6})$/D', $data, $match)) {
            $timestamp = $this->drafts->parseSchedule($user, $match[1]);
            if ($timestamp !== null && $timestamp <= $this->clock->now()->getTimestamp()) {
                throw new \InvalidArgumentException('Scheduled time elapsed.');
            }
            $this->drafts->setScheduledAt($user, $timestamp);
            return $this->draft($user);
        }
        throw new \InvalidArgumentException('Unknown broadcast callback.');
    }

    public function input(string $user, string $text): ?array
    {
        $this->allow($user);
        $button = $this->drafts->buttonInput($user, $text);
        if ($button !== null) {
            return $button === 'url' ? $this->prompt($user, 'button_url_prompt', 'bc:buttons') : $this->buttonMenu($user);
        }
        return $this->drafts->controlInput($user, $text) ? $this->draft($user) : null;
    }

    /** Safe local job snapshot; runtime supplies status and enforces actor ownership on cancellation. */
    public function jobs(string $user, array $jobs, int $page = 0): array
    {
        $this->allow($user);
        if ($page < 0) {
            throw new \InvalidArgumentException('Invalid jobs page.');
        }
        $pages = max(1, (int) ceil(count($jobs) / 10));
        $page = min($page, $pages - 1);
        $lines = ['<b>' . $this->t($user, 'jobs') . '</b>', $this->t($user, 'total') . ': <b>' . count($jobs) . '</b>'];
        $rows = [];
        foreach (array_slice(array_values($jobs), $page * 10, 10) as $job) {
            $id = $job['id'] ?? null;
            $kind = $job['kind'] ?? 'scheduled';
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,35}$/D', $id) || !in_array($kind, ['scheduled', 'self_destruct'], true)) {
                throw new \InvalidArgumentException('Invalid job snapshot.');
            }
            $timestamp = $job['at'] ?? $job['scheduled_at'] ?? null;
            if (!is_int($timestamp) || $timestamp < 1) {
                throw new \InvalidArgumentException('Invalid job time.');
            }
            $lines[] = "\n<b>" . $this->t($user, $kind === 'scheduled' ? 'schedule' : 'self_destruct') . '</b> <code>' . self::html($id) . '</code>'
                . "\n" . $this->date($user, $timestamp)
                . (isset($job['targets']) && is_int($job['targets']) ? ' - ' . number_format($job['targets']) . ' ' . $this->t($user, 'subscribers') : '');
            if ($this->can($user, true)) {
                $rows[] = [['text' => $this->t($user, 'cancel') . ' ' . $id, 'data' => ($kind === 'scheduled' ? 'bc:canceljob:' : 'bc:canceldelete:') . $id, 'color' => 'bg_danger']];
            }
        }
        if ($page > 0) {
            $rows[] = [$this->button($user, 'previous', 'bc:jobs:' . ($page - 1))];
        }
        if ($page + 1 < $pages) {
            $rows[] = [$this->button($user, 'next', 'bc:jobs:' . ($page + 1))];
        }
        $rows[] = [$this->button($user, 'refresh', 'bc:jobs:' . $page), $this->button($user, 'back', 'bc:main')];
        return $this->frame(implode("\n", $lines), $rows);
    }

    public function confirmation(string $user, int $recipients, string $callback): array
    {
        $this->allow($user, true);
        if ($recipients < 1 || preg_match('/^op:send:[A-Za-z0-9_-]{24}$/D', $callback) !== 1) {
            throw new \InvalidArgumentException('Invalid broadcast confirmation.');
        }
        $draft = $this->draft($user);
        return $this->frame(
            '<b>' . $this->t($user, 'confirm_title') . "</b>\n"
            . $this->t($user, 'recipients') . ': <b>' . number_format($recipients) . "</b>\n\n" . $draft['text'],
            [[$this->button($user, 'send', $callback, 'bg_primary')], [$this->button($user, 'back', 'bc:draft')]]
        );
    }

    private function buttonMenu(string $user): array
    {
        $payload = $this->drafts->payload($user);
        $text = '<b>' . $this->t($user, 'buttons') . "</b>\n\n" . $this->t($user, 'buttons_note')
            . "\n\n" . $this->t($user, 'button_count') . ': <b>' . array_sum(array_map('count', $payload['buttons'])) . '</b>';
        foreach (array_slice($payload['buttons'], 0, 10) as $row) {
            $text .= "\n" . implode(' · ', array_map(static fn(array $button): string => self::html(mb_substr($button['text'], 0, 32)), array_slice($row, 0, 2)));
        }
        return $this->frame($text, [
            [$this->button($user, 'add_button', 'bc:button:add', 'bg_primary')],
            [$this->button($user, 'advanced_buttons', 'bc:buttons:advanced'), $this->button($user, 'clear_buttons', 'bc:button:clear', 'bg_danger')],
            [$this->button($user, 'back', 'bc:draft')],
        ]);
    }

    private function prompt(string $user, string $key, string $back = 'bc:draft'): array
    {
        return $this->frame('<b>' . $this->t($user, 'broadcast') . "</b>\n\n" . $this->t($user, $key), [[$this->button($user, 'cancel', $back, 'bg_danger')]]);
    }

    private function allow(string $user, bool $mutate = false): void
    {
        if (!$this->can($user, $mutate)) {
            throw new \RuntimeException('Broadcast permission required.');
        }
    }

    private function can(string $user, bool $mutate): bool
    {
        return (bool) ($this->authorize)($user, 'broadcast', $mutate);
    }

    private function date(string $user, int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($this->settings->getUser($user)['timezone_id']))->format('Y-m-d H:i T');
    }

    private function t(string $user, string $key): string
    {
        return AdminDirectoryText::text($this->settings->getUser($user)['language'], $key);
    }

    private function button(string $user, string $key, string $data, ?string $color = null): array
    {
        return ['text' => $this->t($user, $key), 'data' => $data, 'color' => $color];
    }

    private function frame(string $text, array $rows): array
    {
        $markup = ['_' => 'replyInlineMarkup', 'rows' => []];
        foreach ($rows as $row) {
            $buttons = [];
            foreach ($row as $button) {
                if (strlen($button['data']) > 64) {
                    throw new \InvalidArgumentException('Oversize broadcast callback.');
                }
                $native = ['_' => 'keyboardButtonCallback', 'text' => $button['text'], 'data' => $button['data']];
                if (isset($button['color'])) {
                    $native['style'] = ['_' => 'keyboardButtonStyle', $button['color'] => true];
                }
                $buttons[] = $native;
            }
            $markup['rows'][] = ['_' => 'keyboardButtonRow', 'buttons' => $buttons];
        }
        return ['text' => str_replace('\\n', "\n", $text), 'markup' => $markup];
    }

    private static function html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
