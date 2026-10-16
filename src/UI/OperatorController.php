<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

use Shabbat\Storage\SettingsRepository;
use Shabbat\Storage\AdminAccessRepository;

/** Translated operator frames; existing guarded handlers execute the actual operations. */
final class OperatorController
{
    private \Closure $isOperator;
    public function __construct(private SettingsRepository $settings, private Lang $lang, callable $isOperator, private ?AdminAccessRepository $access = null)
    {
        $this->isOperator = \Closure::fromCallable($isOperator);
    }
    public function main(string $user): array
    {
        $this->authorize($user);
        if ($this->access !== null) {
            return $this->permissionPanel($user);
        }
        return $this->frame($user, 'operator_panel', [
            [$this->button($user, 'operator_statistics', 'סטטיסטיקות')],
            [$this->button($user, 'operator_subscribers', 'רשימתמשתמשים')],
            [$this->button($user, 'operator_broadcast', 'Broadcast')],
            [$this->button($user, 'back', 'sb:start')],
        ]);
    }
    public function broadcast(string $user, bool $active = false): array
    {
        $this->authorize($user, 'broadcast', false);
        $text = $this->t($user, 'operator_broadcast');
        if ($active) {
            $text .= "\n" . $this->t($user, 'operator_active_broadcast');
        }
        return $this->result($text, [
            [$this->button($user, 'operator_progress', 'LastBrodDATA')],
            [$this->button($user, 'operator_edit_text', 'setBroadcast')],
            [$this->button($user, 'operator_delete_last', 'deleteLastBroadcast', 'bg_danger')],
            [$this->button($user, 'operator_delete_all', 'deleteAllBroadcast', 'bg_danger')],
            [$this->button($user, 'operator_unpin', 'cancelPinned')],
            [$this->button($user, 'back', 'חזרהמנהל')],
        ]);
    }
    public function draft(string $user, array $state = []): array
    {
        $this->authorize($user, 'broadcast', false);
        if (!($state['exists'] ?? false)) {
            return $this->frame($user, 'operator_no_draft', [[$this->button($user, 'operator_edit_text', 'setBroadcast')],[$this->button($user, 'back', 'Broadcast')]]);
        }
        $pin = (bool)($state['pin'] ?? false);
        $audience = $state['audience'] ?? 'all';
        $key = match ($audience) {
            'private','users'=>'operator_recipients_private','channels'=>'operator_recipients_channels','groups'=>'operator_recipients_groups',default=>'operator_recipients_all'
        };
        $text = $this->t($user, 'operator_broadcast') . "\n" . $this->t($user, $key) . "\n" . $this->t($user, 'operator_pin') . ': ' . $this->t($user, $pin ? 'on' : 'off');
        return $this->result($text, [
            [$this->button($user, 'operator_pin', $pin ? 'נעץהודעהללא' : 'נעץהודעה', $pin ? 'bg_primary' : 'bg_danger')],
            [$this->button($user, $key, 'מצבתפוצה')],
            [$this->button($user, 'operator_buttons', 'הוספתכפתורים'),$this->button($user, 'operator_preview', 'צפהבכפתוריםמנהל')],
            [$this->button($user, 'operator_send', 'שדרהודעה', 'bg_primary')],
            [$this->button($user, 'operator_cancel', 'חזרהמנהל2', 'bg_danger')],
        ]);
    }
    public function audience(string $user): array
    {
        $this->authorize($user, 'broadcast', false);
        return $this->frame($user, 'operator_broadcast', [
            [$this->button($user, 'operator_recipients_private', 'מצבתפוצה1')],
            [$this->button($user, 'operator_recipients_channels', 'מצבתפוצה2')],
            [$this->button($user, 'operator_recipients_groups', 'מצבתפוצה3')],
            [$this->button($user, 'operator_recipients_all', 'מצבתפוצה4')],
            [$this->button($user, 'back', 'חזרהתפריטשידור')],
        ]);
    }
    public function statistics(string $user, array $counts, array $subscribers = []): array
    {
        $this->authorize($user, 'statistics', false);
        $values = [];
        foreach (['known','registered','locking','locked','recovery'] as $key) {
            $values[$key] = number_format($this->count($counts[$key] ?? 0), 0, '.', ',');
        }
        $text = $this->t($user, 'operator_statistics') . "\n" . $this->t($user, 'operator_group_stats', $values);
        if ($subscribers !== []) {
            $values = [];
            $detailed = isset($subscribers['chats'], $subscribers['supergroups']);
            foreach ($detailed ? ['users','chats','supergroups','channels','bots','total'] : ['users','groups','channels','bots','total'] as $key) {
                $values[$key] = number_format($this->count($subscribers[$key] ?? 0), 0, '.', ',');
            }$text .= "\n\n" . $this->t($user, $detailed ? 'operator_subscriber_stats_full' : 'operator_subscriber_stats', $values);
        }
        return $this->result($text, [[$this->button($user, 'back', 'חזרהמנהל')]]);
    }
    public function subscribers(string $user, array $items, int $page, int $pages): array
    {
        $this->authorize($user, 'subscribers', false);
        if ($page < 1 || $pages < 1 || $page > $pages || count($items) > 20) {
            throw new \InvalidArgumentException('Invalid subscriber page');
        }
        $rows = [];
        foreach ($items as $item) {
            $id = (string)($item['id'] ?? $item['user_id'] ?? '');
            if (!preg_match('/^-?[0-9]{1,20}$/D', $id)) {
                throw new \InvalidArgumentException('Invalid subscriber identifier');
            }$name = (string)($item['name'] ?? $item['title'] ?? $id);
            $rows[] = [['text' => mb_substr($name, 0, 64),'data' => 'openprofile_' . $id]];
        }
        $nav = [];
        if ($page > 1) {
            $nav[] = $this->button($user, 'previous', 'רשימתמנוייםהקודם');
        }if ($page < $pages) {
            $nav[] = $this->button($user, 'next', 'רשימתמנוייםהמשך');
        }if ($nav !== []) {
            $rows[] = $nav;
        }
        $rows[] = [['text' => $this->t($user, 'operator_page', ['page' => $page,'total' => $pages]),'data' => 'var_cat']];
        $rows[] = [$this->button($user, 'back', 'חזרהמנהל')];
        return $this->frame($user, 'operator_subscribers', $rows);
    }
    public function progress(string $user, array $state): array
    {
        $this->authorize($user, 'broadcast', false);
        $text = $this->t($user, 'operator_progress');
        if ($state['active'] ?? false) {
            $text .= "\n" . $this->t($user, 'operator_active_broadcast');
        }
        if (isset($state['page'], $state['total'])) {
            $text .= "\n" . $this->t($user, 'operator_page', ['page' => $this->count($state['page']),'total' => $this->count($state['total'])]);
        }
        return $this->result($text, [[$this->button($user, 'back', 'Broadcast')]]);
    }
    public function confirmSend(string $user, int $recipients, string $confirmationCallback): array
    {
        $this->authorize($user, 'broadcast', true);
        return $this->result($this->t($user, 'operator_confirm_send', ['count' => $this->count($recipients)]), [[$this->button($user, 'operator_send', $confirmationCallback, 'bg_primary')],[$this->button($user, 'operator_cancel', 'חזרהמנהל2', 'bg_danger')]]);
    }
    public function prompt(string $user, string $kind, ?string $token = null): array
    {
        $this->authorize($user, 'broadcast', true);
        $key = match ($kind) {
            'buttons'=>'operator_buttons','media'=>'operator_edit_media',default=>'operator_edit_text'
        };
        $text = $this->t($user, $key);
        if ($token !== null) {
            if (!preg_match('/^[A-Za-z0-9_-]{24}$/D', $token)) {
                throw new \InvalidArgumentException('Invalid editor token');
            }$text .= "\n" . $this->t($user, $kind === 'buttons' ? 'template_buttons_prompt' : 'template_edit_prompt', ['token' => $token]);
        }
        return $this->result($text, [[$this->button($user, 'operator_cancel', 'חזרהמנהל2', 'bg_danger')]]);
    }
    public function notice(string $user, string $key, array $values = []): array
    {
        $this->authorize($user);
        if (!str_starts_with($key, 'operator_')) {
            throw new \InvalidArgumentException('Invalid operator notice');
        }
        return $this->result($this->t($user, $key, $values), [[$this->button($user, 'back', 'חזרהמנהל')]]);
    }
    public function frame(string $user, string $key, array $rows, array $values = []): array
    {
        $this->authorize($user);
        return $this->result($this->t($user, $key, $values), $rows);
    }
    private function permissionPanel(string $user): array
    {
        $rows = [];
        foreach (['statistics' => 'sd:stats', 'subscribers' => 'sd:list:0', 'broadcast' => 'Broadcast', 'manage_admins' => 'adm:list:0', 'system_links' => 'sys:list:0', 'tracking_links' => 'trk:list:0'] as $cap => $callback) {
            if ($this->access->canView($user, $cap)) {
                $rows[] = [['text' => AdminText::text($this->settings->getUser($user)['language'], $cap === 'manage_admins' ? 'managers' : $cap), 'data' => $callback, 'color' => $cap === 'broadcast' ? 'bg_primary' : null]];
            }
        }
        if ($this->access->canMutate($user, 'add_admins')) {
            $rows[] = [['text' => AdminText::text($this->settings->getUser($user)['language'], 'add'), 'data' => 'adm:add', 'color' => 'bg_primary']];
        }
        $text = '<b>' . $this->t($user, 'operator_panel') . "</b>\n\n";
        $commands = ['admin' => null, 'adminstats' => 'statistics', 'subscribers' => 'subscribers', 'broadcast' => 'broadcast', 'managers' => 'manage_admins', 'systemlinks' => 'system_links', 'trackinglinks' => 'tracking_links', 'setcommands' => 'commands', 'testzmanim' => 'statistics', 'closegroups' => 'group_operations', 'opengroups' => 'group_operations'];
        foreach ($commands as $command => $cap) {
            $allowed = $cap === null || (in_array($command, ['setcommands', 'closegroups', 'opengroups'], true)
                ? $this->access->canMutate($user, $cap) : $this->access->canView($user, $cap));
            if ($allowed) {
                $text .= '<code>/' . $command . '</code>' . ($cap === null ? '' : ' - ' . AdminText::text($this->settings->getUser($user)['language'], $cap)) . "\n";
            }
        }
        if ($this->access->isOwner($user)) {
            $text .= '<code>/restart</code> - ' . $this->t($user, 'command_restart') . "\n";
        }
        $text .= "\n<i>" . AdminText::text($this->settings->getUser($user)['language'], 'commands_note') . '</i>';
        $rows[] = [$this->button($user, 'back', 'sb:start')];
        return $this->result($text, $rows);
    }
    private function authorize(string $user, ?string $permission = null, bool $mutation = false): void
    {
        if ($this->access !== null) {
            if (!$this->access->isAdmin($user)) {
                throw new \RuntimeException('Bot administrator access is required.');
            }
            if ($permission !== null) {
                $this->access->require($user, $permission, $mutation);
            }
            return;
        }
        if (!($this->isOperator)($user)) {
            throw new \RuntimeException($this->lang->text($this->settings->getUser($user)['language'], 'commands_forbidden'));
        }
    }
    private function t(string $user, string $key, array $values = []): string
    {
        return $this->lang->text($this->settings->getUser($user)['language'], $key, $values);
    }
    private function count(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('Invalid statistics count');
        }return $value;
    }
    private function button(string $user, string $key, string $data, ?string $color = null): array
    {
        $color ??= in_array($key, ['operator_broadcast','operator_edit_text','operator_buttons','operator_send'], true) ? 'bg_primary' : null;
        return ['text' => $this->t($user, $key),'data' => $data,'color' => $color];
    }
    private function result(string $text, array $rows): array
    {
        $markup = ['_' => 'replyInlineMarkup','rows' => []];
        foreach ($rows as $row) {
            $buttons = [];
            foreach ($row as $b) {
                if (!isset($b['data'], $b['text']) || strlen($b['data']) > 64) {
                    throw new \InvalidArgumentException('Invalid operator button');
                }$button = ['_' => 'keyboardButtonCallback','text' => $b['text'],'data' => $b['data']];
                if (isset($b['color'])) {
                    if (!in_array($b['color'], ['bg_primary','bg_danger'], true)) {
                        throw new \InvalidArgumentException('Invalid operator action color.');
                    }
                    $button['style'] = ['_' => 'keyboardButtonStyle',$b['color'] => true];
                }
                $buttons[] = $button;
            }$markup['rows'][] = ['_' => 'keyboardButtonRow','buttons' => $buttons];
        }
        return ['text' => $text,'markup' => $markup];
    }
}
