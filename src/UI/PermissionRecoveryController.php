<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\UI;

use Shabbat\Scheduling\PermissionRecovery;
use Shabbat\Scheduling\Permissions;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;

/** Private, named-group review screens; the service owns one-use recovery execution. */
final class PermissionRecoveryController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly FlowTokenRepository $flows,
        private readonly UiGateway $gateway,
        private readonly PermissionRecovery $recovery,
        private readonly Lang $lang
    ) {
    }

    public function show(string $user, array $context, ?string $notice = null): void
    {
        $group = $this->groupId($context);
        $frame = $this->recovery->inspect($group, $user);
        if (($frame['status'] ?? '') === 'forbidden') {
            throw new \RuntimeException('Current administrator permission is required.');
        }
        $this->render($user, $context, $frame, $notice);
    }

    public function prepare(string $user, array $context, string $action): void
    {
        if (!in_array($action, ['adopt', 'restore'], true)) {
            throw new \InvalidArgumentException('Unknown permission recovery action.');
        }
        $this->flows->revokeAction($user, 'permission_recovery_confirm');
        $frame = $this->recovery->prepare($this->groupId($context), $user, $action);
        if (!is_string($frame['token'] ?? null) || $frame['token'] === '') {
            $this->render($user, $context, $frame, $this->resultKey($frame));
            return;
        }
        $token = $this->flows->create($user, 'group', $this->groupId($context), (int)$context['revision'], 'permission_recovery_confirm', 300, [
            'core_token' => $frame['token'], 'action' => $action, 'return_screen' => $this->parent($context),
        ]);
        $text = $this->summary($user, $context, $frame) . "\n\n" . $this->t($user, 'recovery_preview_' . $action)
            . "\n\n" . $this->t($user, 'recovery_preview_notice');
        $rows = [
            [$this->button($user, 'recovery_confirm_' . $action, 'sb:pc:' . $token, 'bg_primary')],
            [$this->button($user, 'cancel', 'sb:px:' . $token, 'bg_danger')],
        ];
        $this->send($user, $text, $rows);
    }

    public function confirm(string $user, array $context, string $token): void
    {
        $flow = $context['_flow'];
        $coreToken = $flow['payload']['core_token'] ?? null;
        if (!is_string($coreToken) || $coreToken === '') {
            throw new \InvalidArgumentException('Missing reviewed recovery action.');
        }
        // Revoking the UI wrapper prevents replay even when the external call fails.
        // The durable service token independently binds actor, state and remote rights.
        $this->flows->revoke($token, $user, 'permission_recovery_confirm');
        $result = $this->recovery->confirm($coreToken, $user);
        $fresh = $this->settings->getGroup($this->groupId($context));
        if ($fresh === null) {
            throw new \RuntimeException('Unknown recovery group.');
        }
        $fresh['_flow']['payload']['return_screen'] = $this->parent($context);
        if (($result['status'] ?? '') === 'forbidden') {
            throw new \RuntimeException('Current administrator permission is required.');
        }
        $this->show($user, $fresh, $this->resultKey($result));
    }

    public function cancel(string $user, array $context, string $token): void
    {
        $this->flows->revoke($token, $user, 'permission_recovery_confirm');
        $this->show($user, $context, 'recovery_cancelled');
    }

    private function render(string $user, array $context, array $frame, ?string $notice): void
    {
        $token = $this->flows->create($user, 'group', $this->groupId($context), (int)$context['revision'], 'panel', 600, ['return_screen' => $this->parent($context)]);
        $rows = [];
        if (($frame['can_adopt'] ?? false) === true) {
            $rows[] = [$this->button($user, 'recovery_adopt', 'sb:pa:' . $token . ':adopt', 'bg_primary')];
        }
        if (($frame['can_restore'] ?? false) === true) {
            $rows[] = [$this->button($user, 'recovery_restore', 'sb:pa:' . $token . ':restore')];
        }
        $parent = $this->parent($context);
        $rows[] = [$this->button($user, 'recovery_refresh', 'sb:pr:' . $token),
            $this->button($user, 'back', $parent === 'group' ? 'sb:group:' . $token : 'sb:v:' . $token . ':' . $parent)];
        $text = $this->summary($user, $context, $frame);
        if ($notice !== null) {
            $text .= "\n\n" . $this->t($user, $notice);
        }
        $this->send($user, $text, $rows);
    }

    private function summary(string $user, array $context, array $frame): string
    {
        $text = $this->t($user, 'recovery_title', ['title' => mb_substr((string)($context['title'] ?: $context['group_id']), 0, 100), 'group' => $this->groupId($context)])
            . "\n\n" . $this->t($user, 'recovery_intro');
        $reason = $frame['block_reason'] ?? null;
        if (is_string($reason)) {
            $reason = in_array($reason, ['active_lock', 'no_problem', 'missing_snapshot', 'locked_current', 'unverified_snapshot'], true) ? $reason : 'unknown';
            $text .= "\n\n" . $this->t($user, 'recovery_block_' . $reason);
        } elseif (($frame['can_adopt'] ?? false) || ($frame['can_restore'] ?? false)) {
            $text .= "\n\n" . $this->t($user, 'recovery_state_problem');
        }
        if ($reason === null) {
            foreach (['adopt_block_reason', 'restore_block_reason'] as $key) {
                $blocked = $frame[$key] ?? null;
                if (in_array($blocked, ['locked_current', 'missing_snapshot', 'unverified_snapshot'], true)) {
                    $text .= "\n\n" . $this->t($user, 'recovery_block_' . $blocked);
                }
            }
        }
        if ($reason !== null && $reason !== 'no_problem' && is_array($frame['original'] ?? null) && ($frame['original_verified'] ?? false) === false) {
            $text .= "\n\n" . $this->t($user, 'recovery_block_unverified_snapshot');
        }
        $current = $frame['current'] ?? null;
        $original = $frame['original'] ?? null;
        if (is_array($current) && is_array($original)) {
            // Compare the current state to the original, not to the expected lock mask.
            $fields = Permissions::differentFields($current, $original);
            $text .= "\n\n" . $this->t($user, 'recovery_compare');
            foreach (array_slice($fields, 0, 8) as $field) {
                if (in_array($field, ['unknown', 'until_date'], true)) {
                    $text .= "\n• " . $this->t($user, 'permission_' . $field);
                } else {
                    $text .= "\n" . $this->t($user, 'recovery_difference', [
                        'permission' => $this->t($user, 'permission_' . $field),
                        'current' => $this->t($user, ($current[$field] ?? false) ? 'recovery_blocked' : 'recovery_allowed'),
                        'saved' => $this->t($user, ($original[$field] ?? false) ? 'recovery_blocked' : 'recovery_allowed'),
                    ]);
                }
            }
            if ($fields === []) {
                $text .= "\n" . $this->t($user, 'recovery_no_differences');
            } elseif (count($fields) > 8) {
                $text .= "\n" . $this->t($user, 'recovery_more', ['count' => count($fields) - 8]);
            }
        }
        return $text;
    }

    private function resultKey(array $result): string
    {
        $status = $result['status'] ?? 'error';
        return match ($status) {
            'adopted', 'restored', 'forbidden', 'conflict', 'retry_wait' => 'recovery_result_' . $status,
            'stale', 'expired' => 'recovery_result_stale',
            'busy' => 'recovery_result_busy',
            'active_lock', 'locked_current', 'missing_snapshot', 'unverified_snapshot' => 'recovery_block_' . $status,
            default => 'recovery_result_error',
        };
    }

    private function parent(array $context): string
    {
        $parent = $context['_flow']['payload']['return_screen'] ?? 'group';
        return in_array($parent, ['shabbat', 'moderation', 'restrictions', 'botpermissions', 'status'], true) ? $parent : 'group';
    }

    private function groupId(array $context): string
    {
        return isset($context['group_id']) ? (string)$context['group_id'] : throw new \InvalidArgumentException('Group recovery requires a group context.');
    }

    private function t(string $user, string $key, array $values = []): string
    {
        return $this->lang->text($this->settings->getUser($user)['language'], $key, $values);
    }

    private function button(string $user, string $key, string $data, ?string $color = null): array
    {
        if (strlen($data) > 64) {
            throw new \LogicException('Recovery callback exceeds Telegram limit.');
        }
        $style = ['_' => 'keyboardButtonStyle'];
        if ($color !== null) {
            $style[$color] = true;
        }
        return Presentation::button(['_' => 'keyboardButtonCallback', 'text' => $this->t($user, $key), 'data' => $data, 'style' => $style]);
    }

    private function send(string $user, string $text, array $rows): void
    {
        $this->gateway->sendPrivate($user, $text, ['_' => 'replyInlineMarkup', 'rows' => array_map(static fn(array $buttons): array => ['_' => 'keyboardButtonRow', 'buttons' => $buttons], $rows)]);
    }
}
