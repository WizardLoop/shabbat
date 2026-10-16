<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

use Shabbat\Storage\{AdminAccessRepository, Database, SettingsRepository, StaleSettings, TrackingLinkRepository};
use Shabbat\Time\Clock;

/** Private native frames only. Runtime edits the existing callback panel. No Telegram side effects. */
final class AdminController
{
    public function __construct(private Database $db, private Clock $clock, private SettingsRepository $settings, private AdminAccessRepository $access, private TrackingLinkRepository $tracking, private string $username, private ?string $botId = null)
    {
        SystemLinks::url($username, 'home');
    }
    public function callback(string $user, string $data, bool $private = true): ?array
    {
        if (!preg_match('/^(?:adm|sys|trk):/', $data)) {
            return null;
        }
        $this->guard($user, $private);
        if (preg_match('/^adm:list:([0-9]{1,6})$/D', $data, $m)) {
            $this->clear($user);
            return $this->managers($user, (int) $m[1]);
        }
        if ($data === 'adm:add') {
            $this->access->require($user, 'add_admins', true);
            $this->flow($user, 'add_input', []);
            return $this->frame($this->t($user, 'prompt_admin'), [[$this->b($user, 'cancel', $this->access->canView($user, 'manage_admins') ? 'adm:list:0' : 'חזרהמנהל', 'bg_danger')]]);
        }
        if (preg_match('/^adm:(view|edit|remove):([1-9][0-9]{0,18})$/D', $data, $m)) {
            $target = $m[2];
            $this->access->require($user, 'manage_admins', $m[1] !== 'view');
            $p = $this->access->profile($target) ?? throw new StaleSettings('Administrator no longer exists.');
            if ($m[1] === 'view') {
                $this->clear($user);
                return $this->profile($user, $target);
            }
            if ($this->access->isOwner($target) || $target === $user) {
                throw new \RuntimeException('Recovery administrators and your own access cannot be changed.');
            }
            $nonce = $this->flow($user, $m[1] === 'edit' ? 'edit' : 'remove', ['id' => $target, 'role' => $p['role'], 'view_only' => $p['view_only'], 'permissions' => $p['permissions'], 'revision' => $p['revision']]);
            return $m[1] === 'edit' ? $this->editor($user, $nonce) : $this->frame($this->t($user, 'confirm_remove') . "\n<code>" . $target . '</code>', [[$this->b($user, 'confirm', 'adm:save:' . $nonce, 'bg_danger')], [$this->b($user, 'cancel', 'adm:view:' . $target)]]);
        }
        if (preg_match('/^adm:(role|viewonly|perm|save):([a-f0-9]{16})(?::([a-z0-9_]+))?$/D', $data, $m)) {
            $flow = $this->getFlow($user, $m[2]);
            if (!in_array($flow['kind'], ['add', 'edit', 'remove'], true)) {
                throw new StaleSettings('Invalid administrator editor.');
            }
            $this->access->require($user, $flow['kind'] === 'add' ? 'add_admins' : 'manage_admins', true);
            $p = $flow['payload'];
            if ($m[1] === 'save') {
                $this->clear($user);
                if ($flow['kind'] === 'remove') {
                    $this->access->remove($user, $p['id'], $p['revision']);
                    return $this->managers($user);
                }
                if ($flow['kind'] === 'add') {
                    $this->access->add($user, $p['id'], $p['role'], $p['view_only'], $p['permissions']);
                } else {
                    $this->access->update($user, $p['id'], $p['role'], $p['view_only'], $p['permissions'], $p['revision']);
                }
                return $this->access->canView($user, 'manage_admins') ? $this->profile($user, $p['id']) : $this->frame('<b>' . $this->t($user, 'add') . '</b><code>' . $p['id'] . '</code>', [[$this->b($user, 'back', 'חזרהמנהל')]]);
            }
            if ($flow['kind'] === 'remove') {
                throw new StaleSettings('Invalid removal confirmation.');
            }
            if ($m[1] === 'role') {
                if (!in_array($m[3] ?? '', ['full', 'limited'], true) || ($m[3] === 'full' && $this->access->profile($user)['role'] !== 'full')) {
                    throw new \RuntimeException('Invalid delegated administrator role.');
                }
                $p['role'] = $m[3];
                $p['view_only'] = $m[3] === 'limited';
            } elseif ($m[1] === 'viewonly') {
                if ($p['role'] !== 'limited') {
                    throw new \RuntimeException('View-only applies to selected permissions.');
                }
                $p['view_only'] = !$p['view_only'];
            } else {
                $permission = $m[3] ?? '';
                if ($p['role'] !== 'limited' || !$this->access->canView($user, $permission)) {
                    throw new \RuntimeException('Cannot delegate unavailable permission.');
                }
                $p['permissions'][$permission] = !($p['permissions'][$permission] ?? false);
            }
            $this->updateFlow($user, $m[2], $p);
            return $this->editor($user, $m[2]);
        }
        if (preg_match('/^sys:list:([0-9]{1,6})$/D', $data, $m)) {
            $this->clear($user);
            return $this->systemLinks($user, (int) $m[1]);
        }
        if (preg_match('/^trk:list:([0-9]{1,6})$/D', $data, $m)) {
            $this->clear($user);
            return $this->trackers($user, (int) $m[1]);
        }
        if ($data === 'trk:add') {
            $this->access->require($user, 'tracking_links', true);
            $this->flow($user, 'tracking_input', []);
            return $this->frame($this->t($user, 'prompt_tracking'), [[$this->b($user, 'cancel', 'trk:list:0', 'bg_danger')]]);
        }
        if (preg_match('/^trk:(view|reset|delete):([1-9][0-9]{0,9})$/D', $data, $m)) {
            $this->access->require($user, 'tracking_links', $m[1] !== 'view');
            $p = $this->tracking->get($user, (int) $m[2]) ?? throw new StaleSettings('Tracking link no longer exists.');
            if ($m[1] === 'view') {
                $this->clear($user);
                return $this->tracker($user, (int) $m[2]);
            }
            $nonce = $this->flow($user, 'tracking_' . $m[1], ['id' => (int) $m[2], 'revision' => (int) $p['revision']]);
            return $this->frame($this->t($user, 'confirm_tracking') . "\n<code>" . self::h($p['param']) . '</code>', [[$this->b($user, 'confirm', 'trk:save:' . $nonce, 'bg_danger')], [$this->b($user, 'cancel', 'trk:view:' . $m[2])]]);
        }
        if (preg_match('/^trk:(destination|save):([a-f0-9]{16})(?::([a-z_]+))?$/D', $data, $m)) {
            $this->access->require($user, 'tracking_links', true);
            $flow = $this->getFlow($user, $m[2]);
            $p = $flow['payload'];
            if ($m[1] === 'destination') {
                $dest = $m[3] ?? '';
                if ($flow['kind'] !== 'tracking_add' || !isset(SystemLinks::DEFINITIONS[$dest]) || isset(SystemLinks::DEFINITIONS[$dest]['permission'])) {
                    throw new \InvalidArgumentException('Invalid tracking destination.');
                }
                $p['destination'] = $dest;
                $this->updateFlow($user, $m[2], $p);
                return $this->trackingEditor($user, $m[2]);
            }
            if (!in_array($flow['kind'], ['tracking_add', 'tracking_reset', 'tracking_delete'], true)) {
                throw new StaleSettings('Invalid tracking confirmation.');
            }
            $this->clear($user);
            if ($flow['kind'] === 'tracking_add') {
                $record = $this->tracking->create($user, $p['param'], $p['label'], $p['destination']);
                return $this->tracker($user, (int) $record['id']);
            }
            if ($flow['kind'] === 'tracking_reset') {
                $this->tracking->reset($user, $p['id'], $p['revision']);
                return $this->tracker($user, $p['id']);
            }
            $this->tracking->delete($user, $p['id'], $p['revision']);
            return $this->trackers($user);
        }
        throw new \InvalidArgumentException('Unknown administrator callback.');
    }
    public function capture(string $user, string $text, ?string $forwardedUserId = null, bool $private = true): ?array
    {
        if (!$private || str_starts_with(ltrim($text), '/')) {
            return null;
        }
        $flow = $this->current($user);
        if ($flow === null || !in_array($flow['kind'], ['add_input', 'tracking_input'], true)) {
            return null;
        }
        $this->guard($user, $private);
        if ($flow['kind'] === 'add_input') {
            $this->access->require($user, 'add_admins', true);
            $id = $forwardedUserId ?? trim($text);
            if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id) || $id === $user || $id === $this->botId || $this->access->profile($id) !== null) {
                throw new \InvalidArgumentException('Send an eligible numeric Telegram user ID.');
            }
            $nonce = $this->flow($user, 'add', ['id' => $id, 'role' => 'limited', 'view_only' => true, 'permissions' => array_fill_keys(AdminAccessRepository::PERMISSIONS, false)]);
            return $this->editor($user, $nonce);
        }
        $this->access->require($user, 'tracking_links', true);
        $parts = preg_split('/\s+/u', trim($text), 2) ?: [];
        $param = TrackingLinkRepository::normalize($parts[0] ?? '');
        $label = $parts[1] ?? $param;
        if (mb_strlen($label) > 80 || preg_match('/[\x00-\x1f]/u', $label)) {
            throw new \InvalidArgumentException('Tracking labels must be one line up to 80 characters.');
        }
        $nonce = $this->flow($user, 'tracking_add', ['param' => $param, 'label' => $label, 'destination' => 'home']);
        return $this->trackingEditor($user, $nonce);
    }
    public function cancel(string $user): void
    {
        $this->clear($user);
    }
    public function managers(string $user, int $page = 0): array
    {
        $this->access->require($user, 'manage_admins');
        $ids = $this->access->adminIds();
        $pages = max(1, (int) ceil(count($ids) / 8));
        $page = max(0, min($page, $pages - 1));
        $rows = [];
        foreach (array_slice($ids, $page * 8, 8) as $id) {
            $p = $this->access->profile((string) $id);
            $rows[] = [['text' => ($this->access->isOwner((string) $id) ? '🔒 ' : '👤 ') . $id . ' - ' . $this->t($user, $p['role']), 'data' => 'adm:view:' . $id]];
        }
        $rows = array_merge($rows, $this->navigation($user, 'adm:list:', $page, $pages));
        if ($this->access->canMutate($user, 'add_admins')) {
            $rows[] = [$this->b($user, 'add', 'adm:add', 'bg_primary')];
        }
        $rows[] = [$this->b($user, 'back', 'חזרהמנהל')];
        return $this->frame('<b>' . $this->t($user, 'managers') . '</b> · ' . count($ids), $rows);
    }
    public function profile(string $user, string $target): array
    {
        $this->access->require($user, 'manage_admins');
        $p = $this->access->profile($target) ?? throw new StaleSettings('Administrator no longer exists.');
        $text = '<b>' . $this->t($user, 'managers') . '</b>\n<code>' . self::h($target) . '</code>\n' . $this->t($user, $p['role']) . "\n" . $this->t($user, 'view_only') . ': ' . ($p['view_only'] ? '✅' : '❌');
        if ($this->access->isOwner($target)) {
            $text .= "\n🔒 " . $this->t($user, 'immutable');
        }
        foreach (AdminAccessRepository::PERMISSIONS as $key) {
            $text .= "\n" . (($p['role'] === 'full' || ($p['permissions'][$key] ?? false)) ? '✅ ' : '▫️ ') . $this->t($user, $key);
        }
        $rows = [];
        if ($target !== $user && !$this->access->isOwner($target) && $this->access->canMutate($user, 'manage_admins')) {
            $rows[] = [$this->b($user, 'edit', 'adm:edit:' . $target, 'bg_primary'), $this->b($user, 'remove', 'adm:remove:' . $target, 'bg_danger')];
        }
        $rows[] = [$this->b($user, 'back', 'adm:list:0')];
        return $this->frame(str_replace('\\n', "\n", $text), $rows);
    }
    public function systemLinks(string $user, int $page = 0): array
    {
        $this->access->require($user, 'system_links');
        $items = array_filter(SystemLinks::DEFINITIONS, fn(array $d): bool => !isset($d['permission']) || ($d['permission'] === 'panel' ? $this->access->isAdmin($user) : $this->access->canView($user, $d['permission'])));
        $pages = max(1, (int) ceil(count($items) / 6));
        $page = max(0, min($page, $pages - 1));
        $text = '<b>' . $this->t($user, 'system_links') . "</b>\n\n";
        $rows = [];
        foreach (array_slice($items, $page * 6, 6, true) as $param => $item) {
            $url = SystemLinks::url($this->username, $param);
            $text .= '<b>' . $this->t($user, $item['label']) . "</b>\n<code>" . self::h($url) . "</code>\n\n";
            $rows[] = [['text' => '📋 ' . $this->t($user, $item['label']), 'copy' => $url]];
        }
        $text .= '<i>' . $this->t($user, 'commands_note') . '</i>';
        $rows = array_merge($rows, $this->navigation($user, 'sys:list:', $page, $pages), [[$this->b($user, 'back', 'חזרהמנהל')]]);
        return $this->frame($text, $rows);
    }
    public function trackers(string $user, int $page = 0): array
    {
        $p = $this->tracking->page($user, $page);
        $rows = [];
        foreach ($p['items'] as $item) {
            $rows[] = [['text' => '🔗 ' . mb_substr($item['label'], 0, 36) . ' · ' . $item['total_clicks'] . '/' . $item['unique_users'], 'data' => 'trk:view:' . $item['id']]];
        }
        $rows = array_merge($rows, $this->navigation($user, 'trk:list:', $p['page'], $p['pages']));
        if ($this->access->canMutate($user, 'tracking_links')) {
            $rows[] = [$this->b($user, 'create_tracking', 'trk:add', 'bg_primary')];
        }
        $rows[] = [$this->b($user, 'refresh', 'trk:list:' . $p['page'])];
        $rows[] = [$this->b($user, 'back', 'חזרהמנהל')];
        return $this->frame('<b>' . $this->t($user, 'tracking_links') . '</b> · ' . $p['total'] . "\n\n<i>" . $this->t($user, 'analytics_note') . '</i>', $rows);
    }
    public function tracker(string $user, int $id): array
    {
        $p = $this->tracking->get($user, $id) ?? throw new StaleSettings('Tracking link no longer exists.');
        $text = '<b>' . self::h($p['label']) . "</b>\n<code>" . SystemLinks::url($this->username, $p['param']) . "</code>\n" . $this->t($user, 'destination') . ': ' . $this->t($user, SystemLinks::DEFINITIONS[$p['destination']]['label']);
        foreach (['total' => 'total_clicks', 'unique' => 'unique_users', 'repeat' => 'repeat_clicks'] as $label => $key) {
            $text .= "\n" . $this->t($user, $label) . ': <code>' . number_format((int) $p[$key]) . '</code>';
        }
        foreach (['created' => 'created_at', 'first' => 'first_click_at', 'last' => 'last_click_at'] as $label => $key) {
            $text .= "\n" . $this->t($user, $label) . ': <code>' . ($p[$key] === null ? '-' : gmdate('Y-m-d H:i', (int) $p[$key]) . ' UTC') . '</code>';
        }
        $text .= "\n\n<b>" . $this->t($user, 'days') . "</b>\n";
        foreach ($p['days'] as $day) {
            $text .= '<code>' . self::h($day['day']) . '</code>: ' . number_format((int) $day['clicks']) . "\n";
        }
        $text .= "\n<i>" . $this->t($user, 'analytics_note') . '</i>';
        $rows = [[['text' => '📋 ' . $p['param'], 'copy' => SystemLinks::url($this->username, $p['param'])]]];
        if ($this->access->canMutate($user, 'tracking_links')) {
            $rows[] = [$this->b($user, 'reset', 'trk:reset:' . $id, 'bg_danger'), $this->b($user, 'delete', 'trk:delete:' . $id, 'bg_danger')];
        }
        $rows[] = [$this->b($user, 'refresh', 'trk:view:' . $id)];
        $rows[] = [$this->b($user, 'back', 'trk:list:0')];
        return $this->frame($text, $rows);
    }
    private function editor(string $user, string $nonce): array
    {
        $f = $this->getFlow($user, $nonce);
        $p = $f['payload'];
        $text = '<b>' . $this->t($user, $f['kind'] === 'add' ? 'add' : 'edit') . "</b>\n<code>" . $p['id'] . "</code>\n" . $this->t($user, $p['role']) . "\n" . $this->t($user, 'view_only') . ': ' . ($p['view_only'] ? '✅' : '❌');
        $rows = [];
        if ($this->access->profile($user)['role'] === 'full') {
            $rows[] = [$this->b($user, 'full', 'adm:role:' . $nonce . ':full', $p['role'] === 'full' ? 'bg_primary' : null)];
        }
        $rows[] = [$this->b($user, 'limited', 'adm:role:' . $nonce . ':limited', $p['role'] === 'limited' ? 'bg_primary' : null)];
        if ($p['role'] === 'limited') {
            $rows[] = [['text' => ($p['view_only'] ? '✅ ' : '❌ ') . $this->t($user, 'view_only'), 'data' => 'adm:viewonly:' . $nonce]];
            foreach (AdminAccessRepository::PERMISSIONS as $key) {
                if ($this->access->canView($user, $key)) {
                    $rows[] = [['text' => (($p['permissions'][$key] ?? false) ? '✅ ' : '▫️ ') . $this->t($user, $key), 'data' => 'adm:perm:' . $nonce . ':' . $key]];
                }
            }
        }
        $rows[] = [$this->b($user, 'save', 'adm:save:' . $nonce, 'bg_primary')];
        $rows[] = [$this->b($user, 'cancel', $this->access->canView($user, 'manage_admins') ? 'adm:list:0' : 'חזרהמנהל', 'bg_danger')];
        return $this->frame($text, $rows);
    }
    private function trackingEditor(string $user, string $nonce): array
    {
        $p = $this->getFlow($user, $nonce)['payload'];
        $rows = [];
        foreach (SystemLinks::DEFINITIONS as $param => $def) {
            if (!isset($def['permission'])) {
                $rows[] = [['text' => ($p['destination'] === $param ? '✅ ' : '') . $this->t($user, $def['label']), 'data' => 'trk:destination:' . $nonce . ':' . $param]];
            }
        }
        $rows[] = [$this->b($user, 'save', 'trk:save:' . $nonce, 'bg_primary')];
        $rows[] = [$this->b($user, 'cancel', 'trk:list:0', 'bg_danger')];
        return $this->frame('<b>' . $this->t($user, 'create_tracking') . "</b>\n" . self::h($p['label']) . "\n<code>" . SystemLinks::url($this->username, $p['param']) . "</code>\n\n" . $this->t($user, 'destination'), $rows);
    }
    private function guard(string $user, bool $private): void
    {
        if (!$private || !$this->access->isAdmin($user)) {
            throw new \RuntimeException('Private bot administrator access is required.');
        }
    }
    private function flow(string $user, string $kind, array $payload): string
    {
        $nonce = bin2hex(random_bytes(8));
        $this->db->pdo()->prepare('INSERT INTO admin_panel_flows VALUES(?,?,?,?,?) ON CONFLICT(actor_id) DO UPDATE SET nonce=excluded.nonce,kind=excluded.kind,payload_json=excluded.payload_json,expires_at=excluded.expires_at')->execute([$user, $nonce, $kind, Database::encode($payload), $this->clock->now()->getTimestamp() + 600]);
        return $nonce;
    }
    private function current(string $user): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT * FROM admin_panel_flows WHERE actor_id=? AND expires_at>?');
        $q->execute([$user, $this->clock->now()->getTimestamp()]);
        $f = $q->fetch();
        if ($f === false) {
            return null;
        }
        $f['payload'] = json_decode($f['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        return $f;
    }
    private function getFlow(string $user, string $nonce): array
    {
        $f = $this->current($user);
        if ($f === null || !hash_equals($f['nonce'], $nonce)) {
            throw new StaleSettings('Administrator panel expired or was replaced.');
        }
        return $f;
    }
    private function updateFlow(string $user, string $nonce, array $payload): void
    {
        $this->getFlow($user, $nonce);
        $this->db->pdo()->prepare('UPDATE admin_panel_flows SET payload_json=? WHERE actor_id=? AND nonce=?')->execute([Database::encode($payload), $user, $nonce]);
    }
    private function clear(string $user): void
    {
        $this->db->pdo()->prepare('DELETE FROM admin_panel_flows WHERE actor_id=?')->execute([$user]);
    }
    private function t(string $user, string $key): string
    {
        return AdminText::text($this->settings->getUser($user)['language'], $key);
    }
    private function b(string $user, string $key, string $data, ?string $color = null): array
    {
        return ['text' => $this->t($user, $key), 'data' => $data, 'color' => $color];
    }
    private static function h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    private function navigation(string $user, string $prefix, int $page, int $pages): array
    {
        if ($pages <= 1) {
            return [];
        }
        $row = [];
        if ($page > 0) {
            $row[] = $this->b($user, 'previous', $prefix . ($page - 1));
        }
        $row[] = ['text' => ($page + 1) . '/' . $pages, 'data' => $prefix . $page];
        if ($page + 1 < $pages) {
            $row[] = $this->b($user, 'next', $prefix . ($page + 1));
        }
        return [$row];
    }
    private function frame(string $text, array $rows): array
    {
        $markup = ['_' => 'replyInlineMarkup', 'rows' => []];
        foreach ($rows as $row) {
            $buttons = [];
            foreach ($row as $b) {
                if (isset($b['copy'])) {
                    $button = ['_' => 'keyboardButtonCopy', 'text' => $b['text'], 'copy_text' => $b['copy']];
                } else {
                    if (strlen($b['data']) > 64) {
                        throw new \InvalidArgumentException('Administrator callback too long.');
                    }
                    $button = ['_' => 'keyboardButtonCallback', 'text' => $b['text'], 'data' => $b['data']];
                }
                if (isset($b['color'])) {
                    $button['style'] = ['_' => 'keyboardButtonStyle', $b['color'] => true];
                }
                $buttons[] = $button;
            }
            $markup['rows'][] = ['_' => 'keyboardButtonRow', 'buttons' => $buttons];
        }
        return ['text' => $text, 'markup' => $markup];
    }
}
