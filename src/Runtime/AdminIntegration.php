<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Runtime;

use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\EventHandler\Query\ButtonQuery;
use danog\MadelineProto\EventHandler\Query\ChatButtonQuery;
use Shabbat\Storage\Database;
use Shabbat\Storage\TrackingLinkRepository;
use Shabbat\UI\AdminController;
use Shabbat\UI\BroadcastController;
use Shabbat\UI\CallbackUiGateway;
use Shabbat\UI\Lang;
use Shabbat\UI\SubscriberController;
use Shabbat\UI\SystemLinks;

/** Native event bridge. Frames never grant privileges; current database capabilities do. */
trait AdminIntegration
{
    private function v4Diagnostics(): \Shabbat\UI\DiagnosticsController
    {
        return new \Shabbat\UI\DiagnosticsController($this->v3App());
    }

    private function v4Admin(): AdminController
    {
        $app = $this->v3App();
        return new AdminController(
            $app->db,
            $app->clock,
            $app->settings,
            $app->adminAccess,
            new TrackingLinkRepository($app->db, $app->clock, $app->adminAccess),
            $app->gateway->botUsername(),
            (string)$this->getSelf()['id']
        );
    }

    private function v4Subscribers(): SubscriberController
    {
        $app = $this->v3App();
        return new SubscriberController(
            $app->settings,
            $app->subscribers,
            fn(string $user, string $capability, bool $mutate = false): bool => $mutate
            ? $app->adminAccess->canMutate($user, $capability) : $app->adminAccess->canView($user, $capability)
        );
    }

    private function v4Broadcast(): BroadcastController
    {
        $app = $this->v3App();
        return new BroadcastController(
            $app->settings,
            $this->v3OperatorDraft(),
            $app->clock,
            fn(string $user, string $capability = 'broadcast', bool $mutate = false): bool => $mutate
            ? $app->adminAccess->canMutate($user, $capability) : $app->adminAccess->canView($user, $capability)
        );
    }

    private function v4RememberPanel(string $user, int $messageId): void
    {
        if ($messageId < 1 || $messageId > 2147483647) {
            throw new \InvalidArgumentException('Invalid operator panel.');
        }
        $app = $this->v3App();
        $app->flows->revokeAction($user, 'operator_panel');
        $app->flows->create($user, 'user', null, $app->settings->getUser($user)['revision'], 'operator_panel', 3600, ['message_id' => $messageId]);
    }

    private function v4InputFrame(string $user, array $frame): void
    {
        $app = $this->v3App();
        $panel = $app->flows->latest($user, 'operator_panel')['payload']['message_id'] ?? null;
        if (is_int($panel) && $panel > 0) {
            $app->gateway->editPrivate($user, $panel, $frame['text'], $frame['markup']);
        } else {
            $app->gateway->sendPrivate($user, $frame['text'], $frame['markup']);
        }
    }

    /** Navigation can clear only the authenticated private actor's pending input modes. */
    private function v4ClearCompetingInputs(string $user, string $keep = ''): void
    {
        $app = $this->v3App();
        if ($keep !== 'diagnostics') {
            (new \Shabbat\Storage\DiagnosticsRepository($app->db, $app->clock))->cancelInput($user);
        }
        if ($keep !== 'admin') {
            $this->v4Admin()->cancel($user);
        }
        if ($keep !== 'subscriber') {
            $app->db->pdo()->prepare('UPDATE subscriber_directory_views SET prompt=NULL WHERE viewer_id=?')->execute([$user]);
        }
        if ($keep !== 'broadcast') {
            $app->db->pdo()->prepare("DELETE FROM admin_state WHERE user_id=? AND state_key='grs1.txt'")->execute([$user]);
        }
    }

    private function v4ObservePeer(string $id, bool $private, bool $activity = false, bool $opening = false): void
    {
        $app = $this->v3App();
        try {
            $info = $this->getInfo((int)$id);
            $body = $info['User'] ?? $info['Chat'] ?? [];
            $type = $info['type'] ?? 'unknown';
            if (!in_array($type, ['unknown', 'user', 'bot', 'chat', 'supergroup', 'channel'], true)) {
                $type = 'unknown';
            }
            if (($body['bot'] ?? false) === true) {
                $type = 'bot';
            }
            $metadata = ['type' => $type];
            foreach (['title', 'first_name', 'last_name', 'username', 'language_code'] as $name) {
                if (is_string($body[$name] ?? null)) {
                    $metadata[$name] = $body[$name];
                }
            }
            foreach (['bot', 'deleted', 'premium', 'contact'] as $name) {
                if (is_bool($body[$name] ?? null)) {
                    $metadata[$name] = $body[$name];
                }
            }
            if ($private) {
                $app->subscribers->observePrivate($id, $metadata, $activity, $opening);
            } else {
                $app->subscribers->observe($id, $metadata, $activity, false);
            }
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            // Directory enrichment must never prevent calendar or permission operations.
            if ($private) {
                $app->subscribers->observePrivate($id, [], $activity, $opening);
            }
        }
    }

    private function v4ObserveMessage(Message $message): void
    {
        if ($message instanceof PrivateMessage) {
            $this->v4ObservePeer((string)$message->senderId, true, true, preg_match('/^\/start(?:@\w+)?(?:\s|$)/i', $message->message) === 1);
        } else {
            // A group sender has not granted the bot permission to send private messages.
            $this->v4ObservePeer((string)$message->chatId, false, true);
        }
    }

    private function v4HandleMessage(Message $message, array $event): bool
    {
        $app = $this->v3App();
        $user = (string)$message->senderId;
        if (!($message instanceof PrivateMessage)) {
            return false;
        }
        $text = $message->message;
        if (str_starts_with(ltrim($text), '/')) {
            $this->v4ClearCompetingInputs($user);
        }

        if ($this->v4Diagnostics()->input($event)) {
            return true;
        }
        if ($app->donationUi?->message($event)) {
            return true;
        }
        if (preg_match('/^\/start(?:@\w+)?\s+([A-Za-z0-9_-]{1,64})$/iD', trim($text), $parts)) {
            $parameter = $parts[1];
            try {
                $destination = (new TrackingLinkRepository($app->db, $app->clock, $app->adminAccess))->recordStart($parameter, $user, $message->id, true);
                if ($destination !== null) {
                    $parameter = $destination;
                }
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                // Analytics are best effort and never block an ordinary /start.
            }
            try {
                $action = SystemLinks::resolve($parameter, $user, true, $app->adminAccess);
                if ($action !== null) {
                    return $this->v4OpenDestination($message, $event, $action);
                }
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                $app->gateway->sendPrivate($user, $this->v3Text($user, 'denied'));
                return true;
            }
        }
        if (preg_match('/^\/(adminstats|subscribers|broadcast|managers|systemlinks|trackinglinks)(?:@\w+)?$/iD', trim($text), $parts)) {
            $action = match (strtolower($parts[1])) {
                'adminstats' => 'statistics', 'systemlinks' => 'system_links', 'trackinglinks' => 'tracking_links',
                default => strtolower($parts[1]),
            };
            try {
                return $this->v4OpenDestination($message, $event, $action);
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                $app->gateway->sendPrivate($user, $this->v3Text($user, 'denied'));
                return true;
            }
        }
        if (!$app->adminAccess->isAdmin($user)) {
            return false;
        }
        if (str_starts_with(ltrim($text), '/')) {
            $this->v4Admin()->cancel($user);
            if ($app->adminAccess->canView($user, 'subscribers')) {
                $app->subscribers->saveView($user, ['prompt' => null]);
            }
            if ($app->adminAccess->canMutate($user, 'broadcast')) {
                $this->v3OperatorDraft()->cancel($user);
            }
            return false;
        }
        try {
            $forwarded = $message->fwdInfo->fromId ?? null;
            $frame = $this->v4Admin()->capture($user, $text, is_int($forwarded) && $forwarded > 0 ? (string)$forwarded : null);
            if ($frame !== null) {
                $this->v4InputFrame($user, $frame);
                return true;
            }
            if ($app->adminAccess->canView($user, 'subscribers')) {
                $frame = $this->v4Subscribers()->input($user, $text);
                if ($frame !== null) {
                    $this->v4InputFrame($user, $frame);
                    return true;
                }
            }
            if ($app->adminAccess->canMutate($user, 'broadcast')) {
                $frame = $this->v4Broadcast()->input($user, $text);
                if ($frame !== null) {
                    $this->v4InputFrame($user, $frame);
                    return true;
                }
                $mode = $this->v3OperatorDraft()->mode($user);
                if (in_array($mode, ['broadcast1', 'addBUTTONS'], true)) {
                    return $this->v3OperatorCapture($message, $mode);
                }
            }
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            $this->v4InputFrame($user, ['text' => $this->v3Text($user, 'operator_draft_invalid'), 'markup' => [
                '_' => 'replyInlineMarkup', 'rows' => [['_' => 'keyboardButtonRow', 'buttons' => [
                    ['_' => 'keyboardButtonCallback', 'text' => $this->v3Text($user, 'back'), 'data' => 'חזרהמנהל'],
                ]]],
            ]]);
            return true;
        }
        return false;
    }

    private function v4OpenDestination(Message $message, array $event, string $action): bool
    {
        $app = $this->v3App();
        $user = (string)$message->senderId;
        if (in_array($action, ['admin', 'statistics', 'subscribers', 'broadcast', 'managers', 'system_links', 'tracking_links', 'commands'], true)) {
            if (!$app->adminAccess->isAdmin($user)) {
                throw new \RuntimeException('Bot manager access required.');
            }
            $this->v4Admin()->cancel($user);
            $frame = match ($action) {
                'admin' => $this->v3OperatorUi()->main($user),
                'statistics' => $this->v4Subscribers()->statistics($user),
                'subscribers' => $this->v4Subscribers()->listing($user),
                'broadcast' => $this->v4Broadcast()->main($user),
                'managers' => $this->v4Admin()->managers($user),
                'system_links' => $this->v4Admin()->systemLinks($user),
                'tracking_links' => $this->v4Admin()->trackers($user),
                'commands' => $this->v4CommandsHelp($user),
            };
            $app->gateway->sendPrivate($user, $frame['text'], $frame['markup']);
            return true;
        }
        if ($action === 'donate') {
            $this->v3DonationMenu($user);
            return true;
        }
        if (in_array($action, ['source', 'license', 'version'], true)) {
            $app->gateway->sendPrivate($user, $this->v3Source($action, $user));
            return true;
        }
        $app->ui->handleMessage(array_replace($event, ['text' => '/' . $action]));
        return true;
    }

    private function v4CommandsHelp(string $user): array
    {
        $this->v3App()->adminAccess->require($user, 'commands');
        return ['text' => $this->v3Text($user, 'commands_setup_help') . "\n<code>/setcommands all all preview</code>\n<code>/setcommands all all</code>",
            'markup' => ['_' => 'replyInlineMarkup', 'rows' => [['_' => 'keyboardButtonRow', 'buttons' => [
                ['_' => 'keyboardButtonCallback', 'text' => $this->v3Text($user, 'back'), 'data' => 'חזרהמנהל'],
            ]]]]];
    }

    private function v4HandleCallback(ButtonQuery $query, array $event): bool
    {
        $app = $this->v3App();
        $user = (string)$query->userId;
        $data = $query->data;
        $private = $query instanceof ChatButtonQuery && (string)$query->chatId === $user;
        if ($private) {
            $keep = match (true) {
                str_starts_with($data, 'dz:') => 'diagnostics',
                str_starts_with($data, 'bc:') => 'broadcast',
                str_starts_with($data, 'sd:') => 'subscriber',
                preg_match('/^(?:adm|sys|trk):/', $data) === 1 => 'admin',
                default => '',
            };
            $this->v4ClearCompetingInputs($user, $keep);
        }

        if ($private && !str_starts_with($data, 'sb:')) {
            $app->flows->revokeAction($user, 'settings_wizard');
        }
        if ($private && !str_starts_with($data, 'tpl:') && !str_starts_with($data, 'v3:templates:')) {
            $app->flows->revokeAction($user, 'template_input');
        }
        if ($private && !in_array($data, ['v3:donate', 'v3:donate:custom', 'v3:donate:cancel'], true)) {
            $app->donationUi?->cancel($user);
        }
        if (str_starts_with($data, 'dz:') && $this->v4Diagnostics()->callback($event)) {
            return true;
        }
        if (str_starts_with($data, 'ps:')) {
            if (!$private || !in_array($data, ['ps:overview', 'ps:modules'], true)) {
                $app->gateway->answerCallback((string)$event['query_id'], $this->v3Text($user, 'denied'));
                return true;
            }
            $preferences = $app->settings->getUser($user);
            $frame = (new \Shabbat\UI\StatisticsController($app->db, $app->clock, new Lang()))->frame($preferences['language'], substr($data, 3), $preferences['timezone_id']);
            $target = CallbackUiGateway::fromQuery($app->gateway, $event);
            try {
                $target->sendPrivate($user, $frame['text'], $frame['markup']);
            } catch (\Shabbat\UI\PanelEditException) {
                $target->answerCallback((string)$event['query_id'], $this->v3Text($user, 'callback_panel_unavailable'));
                return true;
            }
            $target->answerCallback((string)$event['query_id'], '');
            return true;
        }
        if (str_starts_with($data, 'v3:donate')) {
            try {
                if ($app->donationUi === null || !$app->donationUi->callback($event)) {
                    throw new \RuntimeException('Donation service unavailable.');
                }
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                $query->answer($this->v3Text($user, 'callback_panel_unavailable'), true);
            }
            return true;
        }
        if (!preg_match('/^(?:adm|sys|trk|sd|bc|opx):/', $data)) {
            if ($private && $app->adminAccess->isAdmin($user)) {
                $this->v4Admin()->cancel($user);
                if (str_starts_with($data, 'sb:') || str_starts_with($data, 'tpl:') || str_starts_with($data, 'v3:')) {
                    if ($app->adminAccess->canMutate($user, 'broadcast')) {
                        $this->v3OperatorDraft()->cancel($user);
                    }
                }
                if ($app->adminAccess->canView($user, 'subscribers')) {
                    $app->subscribers->saveView($user, ['prompt' => null]);
                }
            }
            return false;
        }
        try {
            if (!$private || !$app->adminAccess->isAdmin($user)) {
                throw new \RuntimeException('A private manager panel is required.');
            }
            $target = CallbackUiGateway::fromQuery($app->gateway, $event);
            $this->v4RememberPanel($user, $query->messageId);
            if (!preg_match('/^(?:adm|sys|trk):/', $data)) {
                $this->v4Admin()->cancel($user);
            }
            if (!str_starts_with($data, 'sd:') && $app->adminAccess->canView($user, 'subscribers')) {
                $app->subscribers->saveView($user, ['prompt' => null]);
            }
            if ($data === 'sys:commands') {
                $frame = $this->v4CommandsHelp($user);
            } elseif (str_starts_with($data, 'opx:')) {
                $frame = $this->v4ConfirmAction($user, $data);
            } elseif (str_starts_with($data, 'sd:')) {
                if (preg_match('/^sd:view:(-?[1-9][0-9]{0,19}):/', $data, $match)) {
                    $app->adminAccess->require($user, 'subscribers');
                    $this->v4ObservePeer($match[1], false);
                }
                $frame = $this->v4Subscribers()->callback($user, $data);
                if (($frame['action'] ?? '') === 'authorize_peer') {
                    $frame = $this->v4ActionPrompt($user, $frame);
                }
            } elseif (str_starts_with($data, 'bc:')) {
                $frame = $this->v4Broadcast()->callback($user, $data);
                $action = $frame['action'] ?? null;
                if ($action === 'confirm') {
                    $this->v3OperatorConfirmBroadcast($query);
                    $query->answer('');
                    return true;
                }
                if ($action === 'preview') {
                    $this->v3OperatorPanel($query, 'preview');
                    return true;
                }
                if ($action === 'jobs') {
                    $frame = $this->v4BroadcastJobs($user, (int)$frame['page']);
                } elseif (in_array($action, ['cancel_job', 'cancel_self_destruct'], true)) {
                    $frame = $this->v4ActionPrompt($user, $frame);
                }
            } else {
                $frame = $this->v4Admin()->callback($user, $data, true);
            }
            if ($frame === null || !isset($frame['text'], $frame['markup'])) {
                throw new \InvalidArgumentException('Unknown manager action.');
            }
            $target->sendPrivate($user, $frame['text'], $frame['markup']);
            $target->answerCallback((string)$event['query_id'], '');
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            $query->answer($this->v3Text($user, 'denied'), true);
        }
        return true;
    }

    private function v4ActionPrompt(string $user, array $action): array
    {
        $app = $this->v3App();
        $app->adminAccess->require($user, 'broadcast', true);
        $app->flows->revokeAction($user, 'operator_action');
        $token = $app->flows->create($user, 'user', null, $app->settings->getUser($user)['revision'], 'operator_action', 600, $action);
        $label = match ($action['action']) {
            'authorize_peer' => $this->v3Text($user, 'operator_audience_authorize'),
            'cancel_job', 'cancel_self_destruct' => $this->v3Text($user, 'operator_cancel'),
            'delete_last' => $this->v3Text($user, 'operator_delete_last'),
            'delete_all' => $this->v3Text($user, 'operator_delete_all'),
            'unpin' => $this->v3Text($user, 'operator_unpin'),
            default => throw new \InvalidArgumentException('Invalid manager action.'),
        };
        return ['text' => '<b>' . $this->v3Text($user, 'confirm') . '</b>' . "\n" . $label . "\n<code>" . htmlspecialchars((string)$action['id'], ENT_QUOTES, 'UTF-8') . '</code>',
            'markup' => ['_' => 'replyInlineMarkup', 'rows' => [
                ['_' => 'keyboardButtonRow', 'buttons' => [[
                    '_' => 'keyboardButtonCallback', 'text' => $this->v3Text($user, 'confirm'),
                    'data' => 'opx:confirm:' . $token, 'style' => ['_' => 'keyboardButtonStyle', 'bg_primary' => true],
                ]]],
                ['_' => 'keyboardButtonRow', 'buttons' => [[
                    '_' => 'keyboardButtonCallback', 'text' => $this->v3Text($user, 'cancel'), 'data' => 'opx:cancel:' . $token,
                    'style' => ['_' => 'keyboardButtonStyle', 'bg_danger' => true],
                ]]],
            ]]];
    }

    private function v4ConfirmAction(string $user, string $data): array
    {
        $app = $this->v3App();
        $app->adminAccess->require($user, 'broadcast', true);
        if (!preg_match('/^opx:(confirm|cancel):([A-Za-z0-9_-]{24})$/D', $data, $match)) {
            throw new \InvalidArgumentException('Invalid confirmation.');
        }
        $flow = $app->flows->peek($match[2], $user, 'operator_action');
        if ($flow === null) {
            throw new \RuntimeException('Confirmation expired.');
        }
        if ($match[1] === 'cancel') {
            $app->flows->revoke($match[2], $user, 'operator_action');
            return $this->v4Broadcast()->main($user);
        }
        $action = $flow['payload'];
        $app->flows->confirmAndApply(
            $match[2],
            $user,
            'operator_action',
            static fn(): bool => true,
            [],
            function (\PDO $pdo) use ($user, $action, $app): void {
                $app->adminAccess->require($user, 'broadcast', true);
                if (($action['action'] ?? '') === 'authorize_peer') {
                    $app->subscribers->setBroadcastAuthorizedInTransaction($pdo, (string)$action['id'], (bool)$action['authorized']);
                }
                $pdo->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,?,?,?,?)')->execute([
                    $user, null, 'operator.confirmed.' . $action['action'], Database::encode(['id' => $action['id']]), $app->clock->now()->getTimestamp(),
                ]);
            }
        );
        $app->adminAccess->require($user, 'broadcast', true);
        if ($action['action'] === 'authorize_peer') {
            return $this->v4Subscribers()->profile($user, (string)$action['id']);
        }
        $manager = $this->v3OperatorManager($user);
        if (in_array($action['action'], ['delete_last', 'delete_all', 'unpin'], true)) {
            if (!$app->prioritizePermissions() || Runner::isStopping()) {
                throw new \RuntimeException('Broadcast maintenance stopped during shutdown.');
            }
            $app->adminAccess->require($user, 'broadcast', true);
            $peers = $this->v3KnownPeers();
            match ($action['action']) {
                'delete_last' => $manager->deleteLastBroadcastForAll($peers, (int)$user, 20),
                'delete_all' => $manager->deleteAllBroadcastsForAll($peers, (int)$user, 20),
                'unpin' => $manager->unpinAllMessagesForAll($manager->filterPeers($peers, 'users')['targets'], (int)$user, 20),
            };
            return $this->v4Broadcast()->main($user);
        }
        $ack = $action['action'] === 'cancel_job'
            ? $manager->cancelScheduledBroadcast((string)$action['id'])
            : $manager->cancelSelfDestructJob((string)$action['id']);
        if (!$ack) {
            throw new \RuntimeException('The job is no longer pending.');
        }
        return $this->v4BroadcastJobs($user);
    }

    private function v4BroadcastJobs(string $user, int $page = 0): array
    {
        $this->v3App()->adminAccess->require($user, 'broadcast');
        $manager = $this->v3OperatorManager($user);
        $jobs = [];
        foreach ($manager->listScheduledBroadcasts() as $job) {
            if ($job['status'] === 'scheduled') {
                $jobs[] = ['id' => $job['id'], 'kind' => 'scheduled', 'at' => (int)$job['scheduledAt'], 'targets' => (int)$job['totalUsers']];
            }
        }
        foreach ($manager->listSelfDestructJobs() as $job) {
            if ($job['status'] === 'scheduled') {
                $jobs[] = ['id' => $job['id'], 'kind' => 'self_destruct', 'at' => (int)$job['deleteAt'], 'targets' => (int)$job['totalPeers']];
            }
        }
        usort($jobs, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
        return $this->v4Broadcast()->jobs($user, $jobs, $page);
    }

    private function v4AudienceTargets(string $audience): array
    {
        foreach ($this->v3KnownPeers() as $peer) {
            $this->v4ObservePeer((string)$peer, false);
        }
        return $this->v3App()->subscribers->targets($audience);
    }

    private function v4TefillinCallback(ButtonQuery $query, array $event): void
    {
        $app = $this->v3App();
        try {
            if (
                !$query instanceof ChatButtonQuery || $query->chatId >= 0
                || !preg_match('/^tf:([A-Za-z0-9_-]{24})$/D', $query->data, $match)
                || Runner::isStopping()
            ) {
                throw new \InvalidArgumentException('A native group acknowledgement is required.');
            }
            $user = (string)$query->userId;
            if (!$app->gateway instanceof \Shabbat\Telegram\MessageMaintenanceGateway) {
                throw new \RuntimeException('Sender verification is unavailable.');
            }
            $isBot = $app->gateway->senderIsBot((string)$query->chatId, $user);
            if ($isBot === null) {
                throw new \RuntimeException('The callback sender cannot be verified.');
            }
            $state = $app->tefillinAcknowledgements->toggle(
                $user,
                (string)$query->chatId,
                $query->messageId,
                $match[1],
                (string)$event['query_id'],
                $isBot,
                $query->userId <= 0
            );
            $query->answer(\Shabbat\Scheduling\TefillinAckText::feedback($state['language'], $state['selected'], $state['count']));
            if ($app->gateway instanceof MadelineGateway && !Runner::isStopping()) {
                $app->tefillinAcknowledgements->refresh(
                    (string)$query->chatId,
                    $query->messageId,
                    $app->gateway->editGroupMarkup(...)
                );
            }
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            $query->answer($this->v3Text((string)$query->userId, 'denied'), true);
        }
    }

    private function v4FlushTefillinAcknowledgements(): void
    {
        $app = $this->v3App();
        if ($app->gateway instanceof MadelineGateway && !Runner::isStopping()) {
            $app->tefillinAcknowledgements->flushPending(function (string $group, int $message, array $markup) use ($app): void {
                if (Runner::isStopping()) {
                    throw new \Amp\CancelledException(new \RuntimeException('Acknowledgement editing stopped during shutdown.'));
                }
                $app->gateway->editGroupMarkup($group, $message, $markup);
            });
        }
    }

    private function v4RunBroadcastJobs(): void
    {
        $app = $this->v3App();
        $owner = $app->adminAccess->ownerIds()[0] ?? null;
        if ($owner === null || Runner::isStopping()) {
            return;
        }
        $manager = $this->v3OperatorManager($owner, true);
        $app->broadcastJobs->runDue(
            $manager->listScheduledBroadcasts(...),
            $manager->cancelScheduledBroadcast(...),
            $manager->runDueScheduledBroadcasts(...),
            $manager->runDueSelfDestructJobs(...),
            $app->prioritizePermissions(...),
            Runner::isStopping(...),
            $manager->listSelfDestructJobs(...),
        );
    }
}
