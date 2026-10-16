<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

use Shabbat\Storage\SettingsRepository;
use Shabbat\Storage\SubscriberDirectory;

/** Private operator frames; authorization is fresh on every callback and text input. */
final class SubscriberController
{
    private \Closure $authorize;

    public function __construct(private readonly SettingsRepository $settings, private readonly SubscriberDirectory $directory, callable $authorize)
    {
        $this->authorize = \Closure::fromCallable($authorize);
    }

    public function callback(string $viewer, string $data): ?array
    {
        if (!str_starts_with($data, 'sd:')) {
            return null;
        }
        $this->allow($viewer, str_starts_with($data, 'sd:stats') ? 'statistics' : 'subscribers');
        if (preg_match('/^sd:auth:(-?[1-9][0-9]{0,19}):(0|1)$/D', $data, $match)) {
            if (!(($this->authorize)($viewer, 'broadcast', true))) {
                throw new \RuntimeException('Broadcast mutation permission required.');
            }
            return ['action' => 'authorize_peer', 'id' => $match[1], 'authorized' => $match[2] === '1'];
        }
        if (preg_match('/^sd:stats(?::(audience|activity|groups|delivery))?$/D', $data, $match)) {
            return $this->statistics($viewer, $match[1] ?? 'overview');
        }
        if (preg_match('/^sd:list:([0-9]{1,6})$/D', $data, $match)) {
            $this->directory->saveView($viewer, ['page' => (int) $match[1], 'prompt' => null]);
            return $this->listing($viewer);
        }
        if (preg_match('/^sd:view:(-?[1-9][0-9]{0,19}):([0-9]{1,6})$/D', $data, $match)) {
            $this->directory->saveView($viewer, ['page' => (int) $match[2], 'prompt' => null]);
            return $this->profile($viewer, $match[1]);
        }
        if (preg_match('/^sd:value:(-?[1-9][0-9]{0,19}):([0-9]{1,6}):([0-9]{1,6})$/D', $data, $match)) {
            return $this->valuePage($viewer, $match[1], (int) $match[2], (int) $match[3]);
        }
        if (preg_match('/^sd:data:(-?[1-9][0-9]{0,19}):([0-9]{1,6})$/D', $data, $match)) {
            return $this->settingsPage($viewer, $match[1], (int) $match[2]);
        }
        if (preg_match('/^sd:roles:(-?[1-9][0-9]{0,19}):([0-9]{1,6})$/D', $data, $match)) {
            return $this->rolesPage($viewer, $match[1], (int) $match[2]);
        }
        if ($data === 'sd:search' || $data === 'sd:page') {
            $prompt = $data === 'sd:search' ? 'search' : 'page';
            $this->directory->saveView($viewer, ['prompt' => $prompt]);
            return $this->frame($this->t($viewer, $prompt . '_prompt'), [[$this->button($viewer, 'cancel', 'sd:list:' . $this->directory->view($viewer)['page'], 'bg_danger')]]);
        }
        if ($data === 'sd:sort') {
            $view = $this->directory->view($viewer);
            $index = array_search($view['sort'], SubscriberDirectory::SORTS, true);
            $this->directory->saveView($viewer, ['sort' => SubscriberDirectory::SORTS[((int) $index + 1) % count(SubscriberDirectory::SORTS)], 'page' => 0, 'prompt' => null]);
            return $this->listing($viewer);
        }
        if ($data === 'sd:clear') {
            $this->directory->saveView($viewer, ['search' => '', 'page' => 0, 'prompt' => null]);
            return $this->listing($viewer);
        }
        if ($data === 'sd:filters') {
            $rows = [];
            foreach (SubscriberDirectory::FILTERS as $filter) {
                $rows[] = [$this->button($viewer, $filter, 'sd:filter:' . $filter)];
            }
            $rows[] = [$this->button($viewer, 'back', 'sd:list:' . $this->directory->view($viewer)['page'])];
            return $this->frame('<b>' . $this->t($viewer, 'filter') . '</b>', $rows);
        }
        if (preg_match('/^sd:filter:(all|users|groups|channels|bots)$/D', $data, $match)) {
            $this->directory->saveView($viewer, ['filter' => $match[1], 'page' => 0, 'prompt' => null]);
            return $this->listing($viewer);
        }
        throw new \InvalidArgumentException('Unknown subscriber callback.');
    }

    /** Commands always retain their own routes instead of being captured by an old prompt. */
    public function input(string $viewer, string $text): ?array
    {
        if (str_starts_with(ltrim($text), '/')) {
            return null;
        }
        $view = $this->directory->view($viewer);
        if ($view['prompt'] === null) {
            return null;
        }
        $this->allow($viewer, 'subscribers');
        if ($view['prompt'] === 'search') {
            $this->directory->saveView($viewer, ['search' => trim($text) === '-' ? '' : trim($text), 'page' => 0, 'prompt' => null]);
        } else {
            if (!preg_match('/^[1-9][0-9]{0,5}$/D', trim($text))) {
                throw new \InvalidArgumentException('Invalid directory page number.');
            }
            $this->directory->saveView($viewer, ['page' => (int) trim($text) - 1, 'prompt' => null]);
        }
        return $this->listing($viewer);
    }

    public function listing(string $viewer): array
    {
        $this->allow($viewer, 'subscribers');
        $view = $this->directory->view($viewer);
        $page = $this->directory->page((int) $view['page'], 10, $view['search'], $view['sort'], $view['filter']);
        $this->directory->saveView($viewer, ['page' => $page['page']]);
        $text = '<b>' . $this->t($viewer, 'directory') . '</b>
'
            . $this->t($viewer, 'total') . ': <b>' . number_format($page['total']) . '</b>
'
            . $this->t($viewer, 'filter') . ': <b>' . $this->t($viewer, $view['filter']) . '</b>
'
            . $this->t($viewer, 'sort') . ': <b>' . $this->t($viewer, $view['sort']) . '</b>';
        if ($view['search'] !== '') {
            $text .= "
" . $this->t($viewer, 'search') . ': <code>' . self::html($view['search']) . '</code>';
        }
        $rows = [];
        foreach ($page['items'] as $item) {
            $icon = match ($item['type']) {
                'user' => '👤', 'bot' => '🤖', 'channel' => '📢', 'chat', 'supergroup', 'group' => '👥', default => '❔'
            };
            $rows[] = [['text' => $icon . ' ' . mb_substr($item['name'], 0, 55), 'data' => 'sd:view:' . $item['id'] . ':' . $page['page']]];
        }
        if ($rows === []) {
            $text .= "

" . $this->t($viewer, 'empty');
        }
        $nav = [];
        if ($page['page'] > 0) {
            $nav[] = $this->button($viewer, 'previous', 'sd:list:' . ($page['page'] - 1));
        }
        $nav[] = ['text' => ($page['page'] + 1) . '/' . $page['pages'], 'data' => 'sd:page'];
        if ($page['page'] + 1 < $page['pages']) {
            $nav[] = $this->button($viewer, 'next', 'sd:list:' . ($page['page'] + 1));
        }
        $rows[] = $nav;
        $rows[] = [$this->button($viewer, 'search', 'sd:search', 'bg_primary'), $this->button($viewer, 'sort', 'sd:sort')];
        $rows[] = [$this->button($viewer, 'filter', 'sd:filters'), $this->button($viewer, 'clear', 'sd:clear')];
        $rows[] = [$this->button($viewer, 'refresh', 'sd:list:' . $page['page']), $this->button($viewer, 'back', 'חזרהמנהל')];
        return $this->frame(str_replace('\
', "
", $text), $rows);
    }

    public function profile(string $viewer, string $id): array
    {
        $this->allow($viewer, 'subscribers');
        $item = $this->directory->detail($id);
        if ($item === null) {
            return $this->listing($viewer);
        }
        $p = $item['profile'];
        $lines = ['<b>' . $this->t($viewer, 'profile') . '</b>', '<b>' . self::html(mb_substr($item['name'], 0, 64)) . '</b>'];
        foreach (
            ['id' => $item['id'], 'type' => $item['type'], 'username' => $p['username'] ?? null,
            'first_name' => $p['first_name'] ?? null, 'last_name' => $p['last_name'] ?? null,
            'language' => $p['language_code'] ?? null] as $key => $value
        ) {
            $lines[] = $this->field($viewer, $key, $value);
        }
        foreach (
            ['joined' => $item['joined_at'], 'first_activity' => $p['first_activity_at'] ?? null, 'last_activity' => $p['last_activity_at'] ?? null,
            'last_start' => $p['last_start_at'] ?? null] as $key => $value
        ) {
            $lines[] = $this->field($viewer, $key, $this->date($viewer, $value));
        }
        foreach (['activities' => $p['activity_count'] ?? 0, 'starts' => $p['start_count'] ?? 0] as $key => $value) {
            $lines[] = $this->field($viewer, $key, number_format((int) $value));
        }
        foreach (['premium' => 'is_premium', 'deleted' => 'is_deleted', 'contact' => 'is_contact'] as $key => $column) {
            $lines[] = $this->field($viewer, $key, $this->yesNo($viewer, $p[$column] ?? null));
        }
        $lines[] = $this->field($viewer, 'group_admin', $this->t($viewer, $item['known_group_admin'] ? 'yes' : 'unknown'));
        if ($item['preferences'] !== null) {
            foreach (['language' => 'language', 'location' => 'location_name', 'timezone' => 'timezone_id'] as $key => $column) {
                $lines[] = $this->field($viewer, $key, $item['preferences'][$column]);
            }
        }
        if ($item['group'] !== null) {
            $lines[] = $this->field($viewer, 'registered', $this->t($viewer, 'yes'));
            $lines[] = $this->field($viewer, 'enabled', $this->yesNo($viewer, $item['group']['active']));
            $lines[] = $this->field($viewer, 'mode', $item['group']['mode']);
        }
        if (in_array($item['type'], ['chat', 'supergroup', 'group', 'channel'], true)) {
            $lines[] = $this->field($viewer, 'authorized', $this->yesNo($viewer, $item['broadcast_authorized']));
        }
        $lines[] = "
<i>" . $this->t($viewer, 'historical') . '</i>';
        $rows = [];
        if ($item['profile'] !== [] || $item['preferences'] !== null || $item['group'] !== null) {
            $rows[] = [$this->button($viewer, 'profile', 'sd:data:' . $id . ':0')];
        }
        if ($item['group_roles'] !== []) {
            $rows[] = [$this->button($viewer, 'role_observation', 'sd:roles:' . $id . ':0')];
        }
        if (in_array($item['type'], ['chat', 'supergroup', 'group', 'channel'], true) && (($this->authorize)($viewer, 'broadcast', true))) {
            $rows[] = [$this->button($viewer, $item['broadcast_authorized'] ? 'unauthorize' : 'authorize', 'sd:auth:' . $id . ':' . ($item['broadcast_authorized'] ? '0' : '1'), $item['broadcast_authorized'] ? 'bg_danger' : 'bg_primary')];
        }
        $rows[] = [$this->button($viewer, 'refresh', 'sd:view:' . $id . ':' . $this->directory->view($viewer)['page'])];
        $rows[] = [$this->button($viewer, 'back', 'sd:list:' . $this->directory->view($viewer)['page'])];
        return $this->frame(implode("
", $lines), $rows);
    }

    public function statistics(string $viewer, string $tab = 'overview'): array
    {
        $this->allow($viewer, 'statistics');
        $sections = [
            'audience' => ['users','bots','chats','supergroups','groups','channels','unknown','total'],
            'activity' => ['active_24h','active_7d','active_30d','starts','activities'],
            'groups' => ['registered_groups','active_groups','locking','locked','recovery'],
            'delivery' => ['pending_jobs','failed_jobs'],
        ];
        if ($tab !== 'overview' && !isset($sections[$tab])) {
            throw new \InvalidArgumentException('Unknown statistics section.');
        }
        $stats = $this->directory->stats();
        $lines = ['<b>' . $this->t($viewer, 'stats') . '</b>', ''];
        $display = $tab === 'overview' ? [
            'audience' => ['total','users','channels'],
            'activity' => ['active_24h','active_7d'],
            'groups' => ['active_groups','locking','locked'],
            'delivery' => ['pending_jobs','failed_jobs'],
        ] : [$tab => $sections[$tab]];
        foreach ($display as $section => $keys) {
            $lines[] = '<b>' . $this->t($viewer, 'stats_' . $section) . '</b>';
            foreach ($keys as $key) {
                $label = match ($key) {
                    'chats' => 'basic_groups', 'supergroups' => 'supergroups', 'groups' => 'other_groups',
                    default => $key,
                };
                $lines[] = $this->t($viewer, $label) . ': <b>' . number_format($stats[$key === 'activities' ? 'activity' : $key]) . '</b>';
            }
            $lines[] = '';
        }
        $lines[] = '<i>' . $this->t($viewer, 'stats_note') . '</i>';
        $lines[] = '<i>' . $this->t($viewer, 'stats_health_note') . '</i>';
        $rows = [];
        foreach (array_keys($sections) as $section) {
            $rows[] = $this->button($viewer, 'stats_' . $section, 'sd:stats:' . $section, $tab === $section ? 'bg_primary' : null);
        }
        $rows = array_chunk($rows, 2);
        if ($tab !== 'overview') {
            $rows[] = [$this->button($viewer, 'back_summary', 'sd:stats')];
        }
        $rows[] = [$this->button($viewer, 'refresh', 'sd:stats' . ($tab === 'overview' ? '' : ':' . $tab)), $this->button($viewer, 'back', 'חזרהמנהל')];
        return $this->frame(implode("\n", $lines), $rows);
    }

    private function settingsPage(string $viewer, string $id, int $page): array
    {
        $item = $this->directory->detail($id);
        if ($item === null) {
            return $this->listing($viewer);
        }
        $data = $this->profileData($item);
        $pages = max(1, (int) ceil(count($data) / 12));
        $page = min($page, $pages - 1);
        $lines = ['<b>' . self::html(mb_substr($item['name'], 0, 64)) . '</b>', $this->t($viewer, 'page') . ': ' . ($page + 1) . '/' . $pages, ''];
        $rows = [];
        $index = $page * 12;
        foreach (array_slice($data, $page * 12, 12, true) as $key => $value) {
            $value = $value === null ? $this->t($viewer, 'unknown') : (string) $value;
            $lines[] = '<b>' . self::html($key) . '</b>: <code>' . self::html(mb_substr($value, 0, 32)) . (mb_strlen($value) > 32 ? '…' : '') . '</code>';
            if (mb_strlen($value) > 32) {
                $rows[] = [['text' => '🔎 ' . $key, 'data' => 'sd:value:' . $id . ':' . $index . ':0']];
            }
            $index++;
        }
        if ($page > 0) {
            $rows[] = [$this->button($viewer, 'previous', 'sd:data:' . $id . ':' . ($page - 1))];
        }
        if ($page + 1 < $pages) {
            $rows[] = [$this->button($viewer, 'next', 'sd:data:' . $id . ':' . ($page + 1))];
        }
        $rows[] = [$this->button($viewer, 'back', 'sd:view:' . $id . ':' . $this->directory->view($viewer)['page'])];
        return $this->frame(implode("
", $lines), $rows);
    }

    private function rolesPage(string $viewer, string $id, int $page): array
    {
        $item = $this->directory->detail($id);
        if ($item === null) {
            return $this->listing($viewer);
        }
        $roles = $this->directory->roles($id, $page, 5);
        $pages = $roles['pages'];
        $page = min($page, $pages - 1);
        $lines = ['<b>' . $this->t($viewer, 'role_observation') . '</b>', '<i>' . $this->t($viewer, 'historical') . '</i>'];
        foreach ($roles['items'] as $role) {
            $lines[] = "
<b>" . self::html(mb_substr($role['title'] ?: $role['group_id'], 0, 60)) . '</b> <code>' . self::html($role['group_id']) . '</code>'
                . "
" . self::html($role['role']) . ' - ' . $this->yesNo($viewer, $role['is_admin']) . ' - ' . $this->date($viewer, $role['checked_at']);
        }
        $rows = [];
        if ($page > 0) {
            $rows[] = [$this->button($viewer, 'previous', 'sd:roles:' . $id . ':' . ($page - 1))];
        }
        if ($page + 1 < $pages) {
            $rows[] = [$this->button($viewer, 'next', 'sd:roles:' . $id . ':' . ($page + 1))];
        }
        $rows[] = [$this->button($viewer, 'back', 'sd:view:' . $id . ':' . $this->directory->view($viewer)['page'])];
        return $this->frame(implode("
", $lines), $rows);
    }

    private function profileData(array $item): array
    {
        $data = ['name' => $item['name'], 'type' => $item['type'], 'joined_at' => $item['joined_at']];
        foreach (['profile' => $item['profile'], 'preferences' => $item['preferences'] ?? [], 'group' => $item['group'] ?? []] as $prefix => $fields) {
            foreach ($fields as $key => $value) {
                $data[$prefix . '.' . $key] = $value;
            }
        }
        return $data;
    }

    private function valuePage(string $viewer, string $id, int $index, int $page): array
    {
        $item = $this->directory->detail($id);
        if ($item === null) {
            return $this->listing($viewer);
        }
        $data = $this->profileData($item);
        $keys = array_keys($data);
        if (!isset($keys[$index])) {
            throw new \InvalidArgumentException('Unknown subscriber detail field.');
        }
        $key = $keys[$index];
        $value = $data[$key] === null ? $this->t($viewer, 'unknown') : (string) $data[$key];
        $pages = max(1, (int) ceil(mb_strlen($value) / 400));
        $page = min($page, $pages - 1);
        $text = '<b>' . self::html($key) . '</b>\n' . $this->t($viewer, 'page') . ': ' . ($page + 1) . '/' . $pages
            . "\n\n<code>" . self::html(mb_substr($value, $page * 400, 400)) . '</code>';
        $rows = [];
        if ($page > 0) {
            $rows[] = [$this->button($viewer, 'previous', 'sd:value:' . $id . ':' . $index . ':' . ($page - 1))];
        }
        if ($page + 1 < $pages) {
            $rows[] = [$this->button($viewer, 'next', 'sd:value:' . $id . ':' . $index . ':' . ($page + 1))];
        }
        $rows[] = [$this->button($viewer, 'back', 'sd:data:' . $id . ':' . (int) floor($index / 12))];
        return $this->frame(str_replace('\\n', "\n", $text), $rows);
    }

    private function allow(string $viewer, string $capability): void
    {
        if (!(($this->authorize)($viewer, $capability))) {
            throw new \RuntimeException('Administration permission required.');
        }
    }

    private function t(string $viewer, string $key): string
    {
        return AdminDirectoryText::text($this->settings->getUser($viewer)['language'], $key);
    }

    private function field(string $viewer, string $key, mixed $value): string
    {
        return $this->t($viewer, $key) . ': <code>' . self::html($value === null || $value === '' ? $this->t($viewer, 'unknown') : mb_substr((string) $value, 0, 80) . (mb_strlen((string) $value) > 80 ? '…' : '')) . '</code>';
    }

    private function yesNo(string $viewer, mixed $value): string
    {
        return $this->t($viewer, $value === null ? 'unknown' : ((bool) $value ? 'yes' : 'no'));
    }

    private function date(string $viewer, mixed $value): ?string
    {
        return is_numeric($value) && (int) $value > 0 ? (new \DateTimeImmutable('@' . (int) $value))->setTimezone(new \DateTimeZone($this->settings->getUser($viewer)['timezone_id']))->format('Y-m-d H:i T') : null;
    }

    private function button(string $viewer, string $key, string $data, ?string $color = null): array
    {
        return ['text' => $this->t($viewer, $key), 'data' => $data, 'color' => $color];
    }

    private function frame(string $text, array $rows): array
    {
        $markup = ['_' => 'replyInlineMarkup', 'rows' => []];
        foreach ($rows as $row) {
            $buttons = [];
            foreach ($row as $button) {
                if (strlen($button['data']) > 64) {
                    throw new \InvalidArgumentException('Oversize directory callback.');
                }
                $native = ['_' => 'keyboardButtonCallback', 'text' => $button['text'], 'data' => $button['data']];
                if (isset($button['color'])) {
                    $native['style'] = ['_' => 'keyboardButtonStyle', $button['color'] => true];
                }
                $buttons[] = $native;
            }
            $markup['rows'][] = ['_' => 'keyboardButtonRow', 'buttons' => $buttons];
        }
        return ['text' => $text, 'markup' => $markup];
    }

    private static function html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
