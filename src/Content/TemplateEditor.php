<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Content;

use Shabbat\Runtime\Application;
use Shabbat\UI\Lang;

final class TemplateEditor
{
    private const KINDS = ['close','open','times_alert','zmanim','daf','tefillin','omer'];
    private Lang $lang;
    public function __construct(private Application $app)
    {
        $this->lang = new Lang();
    }
    public function callback(array $query): bool
    {
        $data = $query['data'];
        $user = $query['user_id'];
        if (preg_match('/^tpl:ack_preview:[A-Za-z0-9_-]{24}$/D', $data)) {
            $this->app->gateway->answerCallback((string)($query['query_id'] ?? ''), $this->t($user, 'template_ack_preview'));
            return true;
        }
        if (!str_starts_with($data, 'v3:templates:') && !str_starts_with($data, 'tpl:')) {
            return false;
        }
        try {
            $ui = \Shabbat\UI\CallbackUiGateway::fromQuery($this->app->gateway, $query);
            if (!$query['private']) {
                throw new \RuntimeException($this->t($user, 'not_private'));
            }
            if (!preg_match('/^tpl:(?:save|discard):/', $data)) {
                $this->app->flows->revokeAction($user, 'template_input');
                $this->app->flows->revokeAction($user, 'template_review');
            }
            if (str_starts_with($data, 'v3:templates:')) {
                [$group, $requestedKind] = array_pad(explode(':', substr($data, 13), 2), 2, null);
                if ($requestedKind !== null && !in_array($requestedKind, self::KINDS, true)) {
                    throw new \RuntimeException($this->t($user, 'denied'));
                }
                $g = $this->app->settings->getGroup($group);
                if ($g === null || !$this->app->gateway->isGroupAdmin($user, $group)) {
                    throw new \RuntimeException($this->t($user, 'denied'));
                }
                $rows = [];
                $choices = [];
                foreach ($requestedKind === null ? self::KINDS : [$requestedKind] as $kind) {
                    $token = $this->app->flows->create($user, 'group', $group, $g['revision'], 'template', 600, ['kind' => $kind,'template_revision' => $this->app->templates->get($group, $kind)['revision'] ?? 0]);
                    if ($requestedKind !== null) {
                        return $this->callback(array_replace($query, ['data' => 'tpl:menu:' . $token]));
                    }
                    $choices[] = ['text' => $this->t($user, 'template_kind_' . $kind),'data' => 'tpl:menu:' . $token];
                }
                $rows = array_chunk($choices, 2);
                $panel = $this->app->flows->create($user, 'group', $group, $g['revision'], 'panel');
                $rows[] = [['text' => $this->t($user, 'back'),'data' => 'sb:group:' . $panel]];
                $ui->sendPrivate($user, $this->t($user, 'template_panel', ['title' => $g['title']]) . "\n\n" . $this->t($user, 'template_panel_hint'), $this->markup($rows));
                $ui->answerCallback((string)($query['query_id'] ?? ''), '');
                return true;
            }
            if (!preg_match('/^tpl:([a-z_]+):([A-Za-z0-9_-]{24})$/D', $data, $parts)) {
                throw new \RuntimeException($this->t($user, 'denied'));
            }
            $action = $parts[1];
            $token = $parts[2];
            $flowAction = in_array($action, ['save','discard'], true) ? 'template_review' : 'template';
            $flow = $this->app->flows->peek($token, $user, $flowAction);
            if ($flow === null || !$this->app->gateway->isGroupAdmin($user, $flow['group_id'])) {
                throw new \RuntimeException($this->t($user, 'denied'));
            }
            $g = $this->app->settings->getGroup($flow['group_id']);
            if ($g['revision'] !== $flow['revision']) {
                throw new \RuntimeException($this->t($user, 'denied'));
            }
            $kind = $flow['payload']['kind'];
            $template = $this->app->templates->get($flow['group_id'], $kind);
            if ($action === 'menu') {
                $this->showMenu($ui, $user, $g, $kind, $token);
            } elseif (in_array($action, ['edit','buttons','add_link','advanced_buttons'], true)) {
                $mode = match ($action) {
                    'add_link' => 'link_text', 'advanced_buttons' => 'buttons', default => $action
                };
                $input = $this->app->flows->create($user, 'group', $g['group_id'], $g['revision'], 'template_input', 600, ['mode' => $mode, 'panel_id' => $ui->messageId()]);
                $child = $this->app->flows->create($user, 'group', $g['group_id'], $g['revision'], 'template', 600, [
                    'kind' => $kind, 'template_revision' => $template['revision'] ?? 0, 'source_template_input_token' => $input,
                ]);
                $this->app->flows->updatePayload($input, $user, 'template_input', ['mode' => $mode, 'panel_id' => $ui->messageId(), 'template_token' => $child]);
                $rows = [];
                if ($action === 'buttons') {
                    $rows[] = [['text' => $this->t($user, 'template_add_link'), 'data' => 'tpl:add_link:' . $token, 'color' => 'bg_primary']];
                    $rows[] = [['text' => $this->t($user, 'template_advanced_buttons'), 'data' => 'tpl:advanced_buttons:' . $token]];
                }
                $rows[] = [['text' => $this->t($user, 'back'), 'data' => 'tpl:menu:' . $token]];
                $key = match ($action) {
                    'edit' => 'template_edit_guided_prompt', 'add_link' => 'template_link_text_prompt', 'buttons' => 'template_buttons_simple_prompt',
                    default => 'template_buttons_guided_prompt',
                };
                $ui->sendPrivate($user, $this->t($user, $key), $this->markup($rows));
            } elseif ($action === 'save') {
                $pending = $flow['payload']['pending_content'] ?? throw new \RuntimeException('Template review expired.');
                (new TemplateRenderer())->validateStoredPayload($pending);
                $this->app->flows->confirmAndApply(
                    $token,
                    $user,
                    'template_review',
                    fn($group, $actor) => $this->app->gateway->isGroupAdmin($actor, $group),
                    [],
                    fn($pdo) => $this->app->templates->saveInTransaction($pdo, $g['group_id'], $kind, $pending, $user, $flow['payload']['template_revision'])
                );
                $this->app->flows->revokeAction($user, 'template_input');
                $this->app->flows->revokeAction($user, 'template_review');
                $this->freshMenu($ui, $user, $g['group_id'], $kind, 'template_saved');
            } elseif ($action === 'discard') {
                $this->app->flows->revoke($token, $user, 'template_review');
                $this->app->flows->revokeAction($user, 'template_input');
                $this->freshMenu($ui, $user, $g['group_id'], $kind, 'template_cancelled');
            } elseif ($action === 'preview') {
                $payload = $this->app->payload($g, $kind, $this->app->clock->now());
                unset($payload['topic_id']);
                $payload = $this->withInertAcknowledgement($payload, $g, $kind, $token);
                $markup = \Shabbat\Runtime\MadelineGateway::normalizeMarkup(['inline_keyboard' => $payload['buttons'] ?? []]);
                $markup = $markup ?: ['_' => 'replyInlineMarkup', 'rows' => []];
                $markup['rows'][] = $this->markup([[['text' => $this->t($user, 'back'), 'data' => 'tpl:menu:' . $token]]])['rows'][0];
                $payload['reply_markup'] = $markup;
                $ui->preview($payload);
            } elseif ($action === 'reset') {
                $this->app->flows->confirmAndApply($token, $user, 'template', fn($group, $actor)=>$this->app->gateway->isGroupAdmin($actor, $group), [], fn($pdo)=>$this->app->templates->saveInTransaction($pdo, $g['group_id'], $kind, ['text' => '','entities' => [],'buttons' => [],'media' => null,'buttons_mode' => 'inherit'], $user, $flow['payload']['template_revision']));
                $this->freshMenu($ui, $user, $g['group_id'], $kind, 'template_cleared');
            } elseif (in_array($action, ['remove_text','remove_media','remove_buttons','default_buttons'], true)) {
                $content = $template ?? ['text' => '','entities' => [],'media' => null,'buttons' => []];
                if ($action === 'remove_text') {
                    $content['text'] = '';
                    $content['entities'] = [];
                }
                if ($action === 'remove_media') {
                    $content['media'] = null;
                }
                if ($action === 'remove_buttons') {
                    $content['buttons'] = [];
                    $content['buttons_mode'] = 'none';
                }
                if ($action === 'default_buttons') {
                    $content['buttons'] = [];
                    $content['buttons_mode'] = 'inherit';
                }
                $this->app->flows->confirmAndApply($token, $user, 'template', fn($group, $actor)=>$this->app->gateway->isGroupAdmin($actor, $group), [], fn($pdo)=>$this->app->templates->saveInTransaction($pdo, $g['group_id'], $kind, $content, $user, $flow['payload']['template_revision']));
                $notice = $action === 'remove_text' ? (($content['media'] ?? null) === null ? 'template_text_restored' : 'template_caption_removed') : 'template_saved';
                $this->freshMenu($ui, $user, $g['group_id'], $kind, $notice);
            } elseif ($action === 'test') {
                $confirm = $this->app->flows->create($user, 'group', $g['group_id'], $g['revision'], 'template', 600, ['kind' => $kind,'test' => true,'template_revision' => $template['revision'] ?? 0]);
                $rows = [[['text' => $this->t($user, 'template_confirm_test'),'data' => 'tpl:sendtest:' . $confirm,'color' => 'bg_primary']],[['text' => $this->t($user, 'cancel'),'data' => 'tpl:cancel:' . $confirm,'color' => 'bg_danger']]];
                $ui->sendPrivate($user, $this->t($user, 'template_test_confirm', ['title' => $g['title']]), $this->markup($rows));
            } elseif ($action === 'cancel') {
                $this->app->flows->revoke($token, $user, 'template');
                $this->freshMenu($ui, $user, $g['group_id'], $kind, 'template_cancelled');
            } elseif ($action === 'sendtest' && ($flow['payload']['test'] ?? false)) {
                if (($template['revision'] ?? 0) !== $flow['payload']['template_revision']) {
                    throw new \RuntimeException($this->t($user, 'denied'));
                }
                $recent = $this->app->db->pdo()->prepare("SELECT created_at FROM audit_log WHERE group_id=? AND action='template.test' ORDER BY created_at DESC LIMIT 1");
                $recent->execute([$g['group_id']]);
                if (($recent->fetchColumn() ?: 0) > $this->app->clock->now()->getTimestamp() - 300) {
                    throw new \RuntimeException($this->t($user, 'template_test_wait'));
                }
                $this->app->flows->confirm($token, $user, 'template', fn($group, $actor)=>$this->app->gateway->isGroupAdmin($actor, $group));
                $payload = $this->app->payload($g, $kind, $this->app->clock->now());
                $payload = $this->withInertAcknowledgement($payload, $g, $kind, $token);
                $prefix = '[TEST / בדיקה] ';
                $payload['text'] = $prefix . ($payload['text'] ?? '');
                if (array_key_exists('entities', $payload)) {
                    $offset = self::utf16Length($prefix);
                    foreach ($payload['entities'] as &$entity) {
                        $entity['offset'] += $offset;
                    }
                    unset($entity);
                }
                $this->app->gateway->send($g['group_id'], $payload, (string)random_int(1, PHP_INT_MAX));
                $this->app->db->pdo()->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,?,?,?,?)')->execute([$user,$g['group_id'],'template.test','{}',$this->app->clock->now()->getTimestamp()]);
                $this->freshMenu($ui, $user, $g['group_id'], $kind, 'template_saved');
            } else {
                throw new \RuntimeException($this->t($user, 'denied'));
            }
            $ui->answerCallback((string)($query['query_id'] ?? ''), '');
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->app->gateway->answerCallback((string)($query['query_id'] ?? ''), $this->t($user, $error instanceof \Shabbat\UI\PanelEditException || $error instanceof \InvalidArgumentException ? 'callback_panel_unavailable' : 'denied'));
        }
        return true;
    }
    private function freshMenu(\Shabbat\UI\UiGateway $ui, string $user, string $groupId, string $kind, string $notice): void
    {
        $group = $this->app->settings->getGroup($groupId) ?? throw new \RuntimeException('The group no longer exists.');
        $token = $this->app->flows->create($user, 'group', $groupId, $group['revision'], 'template', 600, [
            'kind' => $kind, 'template_revision' => $this->app->templates->get($groupId, $kind)['revision'] ?? 0,
        ]);
        $this->showMenu($ui, $user, $group, $kind, $token, $notice);
    }

    private function showMenu(\Shabbat\UI\UiGateway $ui, string $user, array $group, string $kind, string $token, ?string $notice = null): void
    {
        $template = $this->app->templates->get($group['group_id'], $kind);
        $rows = [];
        foreach ([['edit', 'buttons'], ['preview', 'test'], ['remove_text', 'remove_media'], ['default_buttons', 'remove_buttons'], ['reset']] as $actions) {
            $row = [];
            foreach ($actions as $action) {
                $key = $action === 'remove_text' ? (($template['media'] ?? null) === null ? 'template_restore_text' : 'template_remove_caption') : 'template_' . $action;
                $row[] = ['text' => $this->t($user, $key), 'data' => 'tpl:' . $action . ':' . $token,
                    'color' => ($action === 'reset' || in_array($action, ['remove_media', 'remove_buttons'], true)) ? 'bg_danger'
                        : (in_array($action, ['edit', 'buttons'], true) ? 'bg_primary' : null)];
            }
            $rows[] = $row;
        }
        $panel = $this->app->flows->create($user, 'group', $group['group_id'], $group['revision'], 'panel');
        $screen = match ($kind) {
            'close', 'open' => 'shabbat_messages', 'times_alert' => 'announcement', default => $kind,
        };
        $rows[] = [
            ['text' => $this->t($user, 'messages'), 'data' => 'v3:templates:' . $group['group_id']],
            ['text' => $this->t($user, 'back'), 'data' => 'sb:v:' . $panel . ':' . $screen],
        ];
        $text = $this->t($user, 'template_menu', ['title' => $group['title'], 'kind' => $this->t($user, 'template_kind_' . $kind)])
            . "\n\n" . $this->t($user, 'template_current_summary', [
                'text' => $this->t($user, ($template['text'] ?? '') === '' ? (($template['media'] ?? null) === null ? 'template_text_default' : 'off') : 'template_text_custom'),
                'media' => $this->t($user, ($template['media'] ?? null) === null ? 'off' : 'on'),
                'buttons' => $this->t($user, 'template_buttons_' . ($template['buttons_mode'] ?? 'inherit')),
            ]);
        $ui->sendPrivate($user, ($notice === null ? '' : $this->t($user, $notice) . "\n\n") . $text, $this->markup($rows));
    }

    public function message(array $message): bool
    {
        if (!$message['private']) {
            return false;
        }
        $user = $message['user_id'];
        $legacy = preg_match('/^\/(template|buttons) ([A-Za-z0-9_-]{24})\s*\n(.*)$/sD', $message['text'], $m) === 1;
        $input = $this->app->flows->latest($user, 'template_input');
        $target = $this->app->gateway;
        if (!$legacy) {
            if (str_starts_with(ltrim($message['text']), '/')) {
                $this->app->flows->revokeAction($user, 'template_input');
                return false;
            }
            if ($input === null) {
                return false;
            }
            $mode = $input['payload']['mode'];
            $m = ['', in_array($mode, ['buttons','link_text','link_url'], true) ? $mode : 'template', $input['payload']['template_token'], $message['text']];
            $target = new \Shabbat\UI\CallbackUiGateway($this->app->gateway, $user, $input['payload']['panel_id']);
        }
        try {
            $flow = $this->app->flows->peek($m[2], $user, 'template');
            if ($flow === null || !array_key_exists('template_revision', $flow['payload'])) {
                throw new \RuntimeException('Template editing expired.');
            }
            $g = $this->app->settings->getGroup($flow['group_id']);
            if ($g === null) {
                throw new \RuntimeException('The group no longer exists.');
            }
            $current = $this->app->templates->get($g['group_id'], $flow['payload']['kind']) ?? ['text' => '', 'entities' => [], 'buttons' => [], 'media' => null, 'revision' => 0];
            if (!$this->app->gateway->isGroupAdmin($user, $g['group_id']) || $g['revision'] !== $flow['revision']) {
                throw new \RuntimeException('Current group administrator permission is required.');
            }
            if (!$legacy && !$this->activeCapture($user, $flow, $m[2])) {
                return true;
            }
            if (!$legacy) {
                $this->app->flows->revokeAction($user, 'template_review');
            }
            if ($m[1] === 'link_text') {
                if (trim($m[3]) === '' || self::utf16Length($m[3]) > 128 || preg_match('/[\x00-\x1f\x7f]/u', $m[3])) {
                    throw new \InvalidArgumentException('A short one-line button label is required.');
                }
                $payload = $input['payload'];
                $payload['mode'] = 'link_url';
                $payload['link_label'] = $m[3];
                $this->app->flows->updatePayload($flow['payload']['source_template_input_token'], $user, 'template_input', $payload);
                $target->sendPrivate($user, $this->t($user, 'template_link_url_prompt'), $this->markup([
                    [['text' => $this->t($user, 'cancel'), 'data' => 'v3:templates:' . $g['group_id'] . ':' . $flow['payload']['kind']]],
                ]));
                return true;
            }
            if ($m[1] === 'link_url') {
                $button = ['text' => $input['payload']['link_label'], 'url' => trim($m[3]), 'style' => 'primary'];
                TemplateRenderer::validateButtons([[$button]]);
                $existing = $this->app->groupMessageButtons($g, $flow['payload']['kind']);
                $current['buttons'] = [...$existing, [$button]];
                $current['buttons_mode'] = 'custom';
            } elseif ($m[1] === 'buttons') {
                try {
                    $current['buttons'] = json_decode($m[3], true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $current['buttons'] = $this->app->parseCustomButtons($m[3]);
                }
                TemplateRenderer::validateButtons($current['buttons']);
                $current['buttons_mode'] = 'custom';
            } else {
                $prefix = $legacy ? substr($message['text'], 0, strlen($message['text']) - strlen($m[3])) : '';
                $offset = self::utf16Length($prefix);
                $length = self::utf16Length($m[3]);
                $current['text'] = $m[3];
                $current['entities'] = [];
                foreach ($message['entities'] ?? [] as $entity) {
                    if (!isset($entity['offset'], $entity['length']) || !is_int($entity['offset']) || !is_int($entity['length'])) {
                        throw new \InvalidArgumentException('Invalid Telegram entity offsets.');
                    }
                    $start = max($offset, $entity['offset']);
                    $end = min($offset + $length, $entity['offset'] + $entity['length']);
                    if ($end > $start) {
                        $entity['offset'] = $start - $offset;
                        $entity['length'] = $end - $start;
                        $current['entities'][] = $entity;
                    }
                }
                if (array_key_exists('media', $message)) {
                    $current['media'] = $message['media'];
                }
            }
            (new TemplateRenderer())->validateStoredPayload($current);
            if (!$legacy) {
                $this->stageReview($user, $flow, $m[2], $current, $target);
                return true;
            }
            $this->app->flows->confirmAndApply(
                $m[2],
                $user,
                'template',
                fn($group, $actor) => $this->app->gateway->isGroupAdmin($actor, $group),
                [],
                fn($pdo) => $this->app->templates->saveInTransaction($pdo, $g['group_id'], $flow['payload']['kind'], $current, $user, $flow['payload']['template_revision'])
            );
            $this->app->flows->revokeAction($user, 'template_input');
            $target->sendPrivate($user, $this->t($user, 'template_saved'), $this->markup([
                [['text' => $this->t($user, 'back'), 'data' => 'v3:templates:' . $g['group_id'] . ':' . $flow['payload']['kind']]],
            ]));
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            if (!$legacy) {
                $latest = $this->app->flows->latest($user, 'template_input');
                if ($latest === null || ($latest['payload']['template_token'] ?? null) !== $m[2]) {
                    // A cancelled or replaced prompt owns the panel now; never repaint it.
                    return true;
                }
            }
            // Never echo arbitrary parser/transport exceptions into a user conversation.
            $target->sendPrivate($user, $this->t($user, 'denied'), $this->markup([
                [['text' => $this->t($user, 'back'), 'data' => 'sb:groups']],
            ]));
        }
        return true;
    }
    private function activeCapture(string $user, array $flow, string $capture): bool
    {
        $source = $flow['payload']['source_template_input_token'] ?? null;
        $parent = is_string($source) ? $this->app->flows->peek($source, $user, 'template_input') : null;
        return $parent !== null && $parent['scope'] === $flow['scope']
            && $parent['group_id'] === $flow['group_id'] && $parent['revision'] === $flow['revision']
            && ($parent['payload']['template_token'] ?? null) === $capture;
    }

    private function stageReview(string $user, array $flow, string $capture, array $content, \Shabbat\UI\UiGateway $target): void
    {
        $review = $this->app->db->transaction(function () use ($user, $flow, $capture, $content): string {
            if (!$this->activeCapture($user, $flow, $capture)) {
                throw new \RuntimeException('The editing question expired or was replaced.');
            }
            $this->app->flows->revokeAction($user, 'template_review');
            return $this->app->flows->create($user, 'group', $flow['group_id'], $flow['revision'], 'template_review', 600, [
                'kind' => $flow['payload']['kind'], 'template_revision' => $flow['payload']['template_revision'],
                'source_template_input_token' => $flow['payload']['source_template_input_token'], 'pending_content' => $content,
            ]);
        });
        $group = $this->app->settings->getGroup($flow['group_id']) ?? throw new \RuntimeException('The group no longer exists.');
        $payload = $this->app->payload($group, $flow['payload']['kind'], $this->app->clock->now(), templateOverride: $content);
        unset($payload['topic_id']);
        $payload = $this->withInertAcknowledgement($payload, $group, $flow['payload']['kind'], $review);
        $markup = \Shabbat\Runtime\MadelineGateway::normalizeMarkup(['inline_keyboard' => $payload['buttons'] ?? []]);
        $markup = $markup ?: ['_' => 'replyInlineMarkup', 'rows' => []];
        $controls = $this->markup([
            [['text' => $this->t($user, 'template_save_review'), 'data' => 'tpl:save:' . $review, 'color' => 'bg_primary']],
            [['text' => $this->t($user, 'cancel'), 'data' => 'tpl:discard:' . $review, 'color' => 'bg_danger']],
        ]);
        array_push($markup['rows'], ...$controls['rows']);
        $payload['reply_markup'] = $markup;
        if (!$this->activeCapture($user, $flow, $capture) || $this->app->flows->peek($review, $user, 'template_review') === null) {
            return;
        }
        if (!$target instanceof \Shabbat\UI\CallbackUiGateway) {
            throw new \RuntimeException('A private review panel is required.');
        }
        $target->preview($payload);
    }

    /** Preview and explicit TEST counters never create rounds, votes or tf callbacks. */
    private function withInertAcknowledgement(array $payload, array $group, string $kind, string $token): array
    {
        if ($kind !== 'tefillin' || !($group['tefillin_ack_enabled'] ?? true) || ($payload['buttons_mode'] ?? 'inherit') !== 'inherit') {
            return $payload;
        }
        $payload['buttons'] ??= [];
        $payload['buttons'][] = [['text' => ($group['tefillin_ack_label'] ?? \Shabbat\Scheduling\TefillinAckText::label($group['language'])) . ' (0)',
            'callback_data' => 'tpl:ack_preview:' . $token,
            'style' => ['_' => 'keyboardButtonStyle','bg_primary' => true,'icon' => \Shabbat\Scheduling\TefillinAcknowledgements::ICON]]];
        return $payload;
    }
    private static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
    private function t(string $user, string $key, array $values = []): string
    {
        return $this->lang->text($this->app->settings->getUser($user)['language'], $key, $values);
    }
    private function markup(array $rows): array
    {
        $result = ['_' => 'replyInlineMarkup','rows' => []];
        foreach ($rows as $row) {
            $buttons = [];
            foreach ($row as $b) {
                $style = ['_' => 'keyboardButtonStyle'];
                if (($b['color'] ?? null) !== null) {
                    $style[$b['color']] = true;
                }
                $buttons[] = ['_' => 'keyboardButtonCallback','text' => $b['text'],'data' => $b['data'],'style' => $style];
            }$result['rows'][] = ['_' => 'keyboardButtonRow','buttons' => $buttons];
        }
        return $result;
    }
}
