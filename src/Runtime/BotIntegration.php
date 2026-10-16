<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Runtime;

use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\EventHandler\SimpleFilter\IsNotEdited;
use danog\MadelineProto\EventHandler\CallbackQuery;
use danog\MadelineProto\EventHandler\Query\ChatButtonQuery;
use danog\MadelineProto\EventHandler\Query\InlineButtonQuery;
use danog\MadelineProto\EventHandler\Query\ButtonQuery;
use Shabbat\Content\TemplateEditor;
use Shabbat\UI\Lang;

trait BotIntegration
{
    use AdminIntegration;

    private static ?\WeakMap $applications = null;
    private function v5StopWorkers(): void
    {
        if (isset(self::$applications[$this])) {
            self::$applications[$this]->stop();
        }
        RuntimeClientPool::release($this);
    }
    #[\danog\MadelineProto\EventHandler\Filter\FilterCommand('restart')]
    public function gracefulRestart(Incoming&PrivateMessage&\danog\MadelineProto\EventHandler\SimpleFilter\FromAdmin $message): void
    {
        if (!$this->v3App()->adminAccess->isOwner((string)$message->senderId) || Runner::isStopping()) {
            return;
        }
        Runner::restart(
            fn() => $message->reply($this->v3Text((string)$message->senderId, 'restart_requested'), cancellation: new \Amp\TimeoutCancellation(3)),
            $this->restart(...)
        );
    }
    private function v3App(): Application
    {
        self::$applications ??= new \WeakMap();
        if (!isset(self::$applications[$this])) {
            $app = new Application(new MadelineGateway($this, \Shabbat\UI\Presentation::enabledFromEnvironment()), buttonParser:$this->parseButtons(...), rpc:$this->v3Rpc(...), configuredOwners:$this->v3ConfiguredOwners(...));
            self::$applications[$this] = $app;
            $app->adminAccess->importLegacyOnce($this->getAdminIds());
        }
        return self::$applications[$this];
    }
    private function v3ConfiguredOwners(): array
    {
        // SDK 8.7 resolves configured report-peer usernames and includes only user IDs.
        // Its getAdminIds() is independent of our removable SQLite administrators.
        return $this->getAdminIds();
    }
    private function v3Query(CallbackQuery $query, ?string $data = null): array
    {
        return ['user_id' => (string)$query->userId,'data' => $data ?? ($query instanceof ButtonQuery ? $query->data : ''),'query_id' => (string)$query->queryId,'message_id' => $query instanceof ChatButtonQuery ? $query->messageId : 0,'chat_id' => $query instanceof ChatButtonQuery ? (string)$query->chatId : null,'inline_selection' => $query instanceof InlineButtonQuery,'private' => $query instanceof ChatButtonQuery && (string)$query->chatId === (string)$query->userId];
    }
    private function v3Message(Message $message, ?string $text = null): void
    {
        $app = $this->v3App();
        $private = $message instanceof PrivateMessage;
        $text ??= $message->message;
        $user = (string)$message->senderId;
        $group = (string)$message->chatId;
        if (!$private && $user === $group) {
            if (preg_match('/^\/settings(?:@\w+)?$/i', $text)) {
                $locale = $app->settings->getGroup($group)['language'] ?? 'he';
                $lang = new Lang();
                $message->reply($lang->text($locale, 'permission_required'), replyMarkup:['inline_keyboard' => [[['text' => $lang->text($locale, 'command_settings'),'url' => 'https://t.me/' . $app->gateway->botUsername() . '?start=g_' . $group]]]]);
            }return;
        }
        $refresh = null;
        if (preg_match('/^\/settings(?:@\\w+)?(?:\\s+(-?[0-9]+))?$/i', $text, $selection)) {
            $refresh = $private ? ($selection[1] ?? null) : $group;
        } elseif ($private && preg_match('/^\/start(?:@\\w+)?\\s+g_(-?[0-9]+)$/i', $text, $selection)) {
            $refresh = $selection[1];
        }
        if ($refresh !== null) {
            try {
                GroupRegistration::refresh($app, $user, $refresh, $this->getInfo(...));
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                // The settings UI will show the existing permission/error path.
            }
        }
        $entities = array_map(static fn($entity)=>is_array($entity) ? $entity : $entity->toMTProto(), $message->entities ?? []);
        $event = ['user_id' => $user,'text' => $text,'private' => $private,'group_id' => $private ? null : $group,'message_id' => $message->id,'entities' => $entities,'media' => isset($message->media->botApiFileId) ? ['bot_api_file_id' => $message->media->botApiFileId] : null];
        $app->settings->getUser($user);
        $this->v4ObserveMessage($message);
        if ($this->v4HandleMessage($message, $event)) {
            return;
        }
        if ((new TemplateEditor($app))->message($event)) {
            return;
        }
        if (preg_match('/^\/(source|license|version)(?:@\w+)?$/i', $text, $m)) {
            $app->gateway->send($group, ['text' => $this->v3Source($m[1], $user, $this->replyScopeLanguage($message))], (string)random_int(1, PHP_INT_MAX));
            return;
        }
        if (!$private && preg_match('/^\/(shabat|shabbat|zmanim|daf|omer)(?:@\w+)?(?:\s+(\d{4}-\d{2}-\d{2}))?$/i', $text, $m)) {
            $g = $app->settings->getGroup($group);
            if ($g === null) {
                $message->reply($this->v3Text($user, 'permission_required') . "\n/add — " . $this->v3Text($user, 'add'));
                return;
            }
            $date = $m[2] ?? $app->clock->now()->setTimezone(new \DateTimeZone($g['timezone_id']))->format('Y-m-d');
            try {
                $module = in_array(strtolower($m[1]), ['shabat','shabbat'], true) ? 'shabbat' : strtolower($m[1]);
                $output = $app->ui->renderView($user, $g, $module, $date);
                $buttonKind = $module === 'shabbat' ? 'times_alert' : $module;
                $app->gateway->send($group, ['text' => $output, 'buttons' => $app->groupMessageButtons($g, $buttonKind)], (string)random_int(1, PHP_INT_MAX));
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                $message->reply($this->v3Text($user, 'provider_unavailable'));
            }return;
        }
        $app->ui->handleMessage($event);
    }
    private function v3Rpc(string $method, array $parameters): mixed
    {
        [$namespace,$name] = explode('.', $method, 2);
        if (!in_array($method, ['payments.exportInvoice','messages.setBotPrecheckoutResults','bots.setBotCommands'], true)) {
            throw new \InvalidArgumentException('Unsupported service RPC.');
        }
        if (array_key_exists('query_id', $parameters)) {
            $parameters['query_id'] = TelegramInt64::fromDecimal($parameters['query_id']);
        }
        return $this->{$namespace}->{$name}($parameters);
    }
    private function v3DonationMenu(string $user, ?\Shabbat\UI\UiGateway $ui = null): void
    {
        $app = $this->v3App();
        $app->flows->revokeAction($user, 'settings_wizard');
        $app->flows->revokeAction($user, 'template_input');
        $app->flows->revokeAction($user, 'location_input');
        if ($app->donationUi === null) {
            throw new \RuntimeException('Payment service unavailable.');
        }
        $app->donationUi->menu($user, $ui);
    }
    private function v3Text(string $user, string $key, array $values = []): string
    {
        return (new Lang())->text($this->v3App()->settings->getUser($user)['language'], $key, $values);
    }
    private function v3SetCommands(Message $message): void
    {
        $user = (string)$message->senderId;
        if (!$this->v3App()->adminAccess->canMutate($user, 'commands')) {
            $message->reply($this->v3Text($user, 'commands_forbidden'));
            return;
        }
        $parts = preg_split('/\s+/', trim($message->message));
        $language = strtolower($parts[1] ?? 'all');
        $scope = strtolower($parts[2] ?? 'all');
        $preview = ($parts[3] ?? '') === 'preview';
        try {
            $service = new CommandMenus(
                new Lang(),
                $this->v3Rpc(...),
                fn(string $actor): bool => $this->v3App()->adminAccess->canMutate($actor, 'commands'),
                function (string $target, string $command): bool {
                    $access = $this->v3App()->adminAccess;
                    if ($command === 'admin') {
                        return $access->isAdmin($target);
                    }
                    if ($command === 'restart') {
                        return $access->isOwner($target);
                    }
                    $capability = match ($command) {
                        'adminstats', 'testzmanim' => 'statistics',
                        'subscribers' => 'subscribers', 'broadcast' => 'broadcast',
                        'managers' => 'manage_admins', 'systemlinks' => 'system_links',
                        'trackinglinks' => 'tracking_links', 'setcommands' => 'commands',
                        'closegroups', 'opengroups' => 'group_operations',
                        default => throw new \InvalidArgumentException('Unknown manager command.'),
                    };
                    return in_array($command, ['setcommands', 'closegroups', 'opengroups'], true)
                        ? $access->canMutate($target, $capability) : $access->canView($target, $capability);
                }
            );
            $result = $service->apply($user, array_map('intval', $this->v3App()->adminAccess->adminIds()), $language, $scope, $preview);
            $text = $this->v3Text($user, $preview ? 'commands_setup_preview' : 'commands_setup_result', $preview ? ['total' => $result['total']] : ['total' => $result['total'],'failed' => $result['failed'] + $result['skipped']]);
            $message->reply($text);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\InvalidArgumentException) {
            $message->reply($this->v3Text($user, 'commands_setup_help') . "\n/setcommands [all|he|en|ru|fr|es|pt-br] [all|private|operators|groups|group_admins] [preview]");
        }
    }
    private function v3ManualGroups(Message $message, bool $close): void
    {
        $app = $this->v3App();
        $user = (string)$message->senderId;
        if (!$app->adminAccess->canMutate($user, 'group_operations')) {
            $app->gateway->sendPrivate($user, $this->v3Text($user, 'denied'));
            return;
        }
        $result = $app->manualGroups->apply($close, $user);
        $preferences = $app->settings->getUser($user);
        $message->reply(ManualGroupReport::format($result, $preferences['language'], $preferences['timezone_id']), parseMode: \danog\MadelineProto\ParseMode::HTML);
    }
    private function v3RecordPayment(array $update): void
    {
        $app = $this->v3App();
        $payment = $app->donations?->recordPayment($update);
        if (($payment['status'] ?? '') === 'recorded') {
            $app->gateway->sendPrivate((string)$payment['user_id'], (new Lang())->text($payment['language'], 'donate_thanks', ['amount' => $payment['amount']]));
            foreach ($this->getAdminIds() as $admin) {
                if (!is_int($admin) || $admin <= 0) {
                    continue;
                }
                try {
                    $actor = (string)$admin;
                    $app->gateway->sendPrivate($actor, $this->v3Text($actor, 'donate_received_admin', ['user_id' => $payment['user_id'],'amount' => $payment['amount']]));
                } catch (\Amp\CancelledException $error) {
                    throw $error;
                } catch (\Throwable $error) {
                    \danog\MadelineProto\Logger::log('Donation operator notice failed: ' . get_class($error), \danog\MadelineProto\Logger::ERROR);
                }
            }
        }
    }

    private function v3RawLocation(array $update, ?int $selfId = null, ?callable $replyLookup = null): bool
    {
        $raw = $update['message'] ?? [];
        if (!is_array($raw) || ($raw['media']['_'] ?? null) !== 'messageMediaGeo') {
            return false;
        }
        $selfId ??= $this->getSelf()['id'] ?? null;
        if (!is_int($selfId)) {
            return true;
        }
        $user = PrivatePeer::sender($raw, $selfId);
        if ($user === null) {
            return true;
        }
        $replyId = $raw['reply_to']['reply_to_msg_id'] ?? null;
        $replyText = '';
        $fromBot = false;
        if (is_int($replyId) && $replyId > 0) {
            // The optional lookup is an offline protocol-test seam; production uses this bot's RPC.
            $response = $replyLookup !== null ? $replyLookup($replyId) : $this->messages->getMessages(id:[['_' => 'inputMessageID','id' => $replyId]]);
            $replies = is_array($response) && is_array($response['messages'] ?? null) ? $response['messages'] : [];
            foreach ($replies as $reply) {
                if (is_array($reply) && PrivatePeer::botReply($reply, $selfId, $user, $replyId)) {
                    $replyText = $reply['message'];
                    $fromBot = true;
                    break;
                }
            }
        }
        $geo = $raw['media']['geo'] ?? [];
        $this->v3App()->ui->handleMessage(['user_id' => (string)$user,'text' => '','private' => true,'location' => ['latitude' => $geo['lat'] ?? null,'longitude' => $geo['long'] ?? null],'reply_text' => $replyText,'reply_message_id' => $replyId,'replied_bot' => $fromBot]);
        return true;
    }

    private function replyScopeLanguage(Message $message): string
    {
        $app = $this->v3App();
        if (!$message instanceof PrivateMessage) {
            $group = $app->settings->getGroup((string)$message->chatId);
            if ($group !== null) {
                return $group['language'];
            }
        }
        return $message->senderId > 0 ? $app->settings->getUser((string)$message->senderId)['language'] : 'he';
    }
    private function v3StatsText(string $user, ?string $languageOverride = null): string
    {
        $app = $this->v3App();
        $language = $languageOverride ?? $app->settings->getUser($user)['language'];
        return (new \Shabbat\UI\StatisticsController($app->db, $app->clock, new Lang()))->frame($language)['text'];
    }
    private function v3Statistics(Message $message): void
    {
        $app = $this->v3App();
        $language = $this->replyScopeLanguage($message);
        $preferences = $message instanceof PrivateMessage
            ? $app->settings->getUser((string)$message->senderId)
            : $app->settings->getGroup((string)$message->chatId);
        $frame = (new \Shabbat\UI\StatisticsController($app->db, $app->clock, new Lang()))->frame($language, 'overview', $preferences['timezone_id'] ?? 'Asia/Jerusalem');
        if ($message instanceof PrivateMessage) {
            $app->gateway->sendPrivate((string)$message->senderId, $frame['text'], $frame['markup']);
        } else {
            $app->gateway->send((string)$message->chatId, ['text' => $frame['text']], (string)random_int(1, PHP_INT_MAX));
        }
    }

    private function v3TestZmanim(Message $message): void
    {
        // Defense in depth: no diagnostic data can be emitted by a legacy group route.
        if (!$message instanceof PrivateMessage) {
            return;
        }
        $user = (string)$message->senderId;
        try {
            $args = preg_split('/\s+/', trim($message->message));
            if (count($args) > 2) {
                throw new \InvalidArgumentException('Expected an optional group identifier.');
            }
            $this->v4ClearCompetingInputs($user, 'diagnostics');
            $this->v4Diagnostics()->open($user, $args[1] ?? null);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Shabbat\Storage\DiagnosticThrottled) {
            // The existing panel remains available; repeated commands do not add messages.
        } catch (\Throwable) {
            $this->v3App()->gateway->sendPrivate($user, $this->v3Text($user, 'diagnostics_unavailable'));
        }
    }
    private function v3KnownPeers(): array
    {
        $rows = $this->v3App()->db->pdo()->query('SELECT user_id AS id FROM users UNION SELECT group_id AS id FROM groups WHERE active=1 UNION SELECT peer_id AS id FROM known_broadcast_peers WHERE NOT EXISTS (SELECT 1 FROM groups WHERE groups.group_id=known_broadcast_peers.peer_id AND groups.active=0)')->fetchAll();
        return array_map(static fn(array $row): int=>(int)$row['id'], $rows);
    }
    private function legacySettingsPanelIsCurrent(CallbackQuery $query): bool
    {
        // Old buttons have no user/group/revision token. Their features now have secure SQLite workflows.
        $query->answer($this->v3Text((string)$query->userId, 'denied'), true);
        return false;
    }
    private function legacySystemQueryAllowed(CallbackQuery $query): bool
    {
        if (!$query instanceof ChatButtonQuery || (string)$query->chatId !== (string)$query->userId || !$this->v3App()->adminAccess->isAdmin((string)$query->userId)) {
            $query->answer($this->v3Text((string)$query->userId, 'commands_forbidden'), true);
            return false;
        }
        return true;
    }
    private function v3Source(string $kind = 'source', string $user = '0', ?string $languageOverride = null): string
    {
        $url = Version::sourceUrl();
        $values = ['version' => Version::current(),'commit' => Version::commit(),'license' => 'AGPL-3.0-only','url' => $url !== '' ? $url : '-'];
        // Legacy /version and /license remain aliases of the complete source card.
        $key = 'source_info';
        $lang = new Lang();
        $language = $languageOverride ?? $this->v3App()->settings->getUser($user)['language'];
        return $lang->text($language, $key, $values) . ($url === '' ? "\n" . $lang->text($language, 'source_unpublished') : '');
    }

    private function v3OperatorUi(): \Shabbat\UI\OperatorController
    {
        return new \Shabbat\UI\OperatorController($this->v3App()->settings, new Lang(), $this->v3App()->adminAccess->isAdmin(...), $this->v3App()->adminAccess);
    }
    private function v3OperatorDraft(): \Shabbat\UI\OperatorDraft
    {
        return new \Shabbat\UI\OperatorDraft($this->v3App(), fn(string $user, bool $mutate = false): bool=>$mutate ? $this->v3App()->adminAccess->canMutate($user, 'broadcast') : $this->v3App()->adminAccess->canView($user, 'broadcast'));
    }
    private function v3OperatorManager(string $user, bool $scheduled = false): \BroadcastTool\BroadcastManager
    {
        \BroadcastTool\BroadcastManager::setDataDir(getenv('DATA_DIR') ?: dirname(__DIR__, 2) . '/data');
        if (Runner::isStopping()) {
            throw new \Amp\CancelledException(new \RuntimeException('Broadcast initialization stopped during shutdown.'));
        }
        $client = RuntimeClientPool::get($this, static fn(): \danog\MadelineProto\API => new \danog\MadelineProto\API(
            getenv('SESSION_PATH') ?: dirname(__DIR__, 2) . '/bot.shabbat',
            (new \danog\MadelineProto\Settings\Logger())->setLevel(\danog\MadelineProto\Logger::ERROR)
        ));
        if (Runner::isStopping()) {
            throw new \Amp\CancelledException(new \RuntimeException('Broadcast initialization completed during shutdown.'));
        }
        if (!$client instanceof \danog\MadelineProto\API) {
            throw new \LogicException('Invalid shared broadcast client.');
        }
        $manager = new \BroadcastTool\BroadcastManager($client);
        $app = $this->v3App();
        $manager->setRuntimeAdmission(static fn(callable $work): mixed => $app->broadcastJobs->runExclusive(static function () use ($app, $user, $work, $scheduled): mixed {
            // Waiting for another operation can outlive an operator's authority.
            $app->adminAccess->require($user, 'broadcast', true);
            if ($scheduled && !$app->broadcastJobs->canContinue()) {
                throw new \RuntimeException('Broadcast creator authorization changed while waiting for admission.');
            }
            return $work();
        }));
        $manager->setRuntimeGuard(
            static fn(): bool => !Runner::isStopping() && (!$scheduled || $app->broadcastJobs->canContinue())
                && $app->adminAccess->canMutate($user, 'broadcast'),
            $app->broadcastJobs->cancellation()
        );
        $formatter = new BroadcastStatusFormatter(new Lang(), $this->v3App()->settings->getUser($user)['language']);
        $manager->setStatusFormatter($formatter);
        $manager->setStatusControlsFormatter($formatter->controls(...));
        if ($this->v3App()->gateway instanceof MadelineGateway) {
            $manager->setMessageTransport($this->v3App()->gateway->broadcastCall(...));
        }
        return $manager;
    }
    private function v3OperatorEdit(ChatButtonQuery $query, array $frame): void
    {
        $this->v4RememberPanel((string)$query->userId, $query->messageId);
        \Shabbat\UI\CallbackUiGateway::fromQuery($this->v3App()->gateway, $this->v3Query($query))->sendPrivate((string)$query->userId, $frame['text'], $frame['markup']);
    }
    private function v3OperatorMain(Message $message): void
    {
        $app = $this->v3App();
        $user = (string)$message->senderId;
        if (!$message instanceof PrivateMessage || !$app->adminAccess->isAdmin($user)) {
            $app->gateway->sendPrivate($user, $this->v3Text($user, 'denied'));
            return;
        }
        $this->v4Admin()->cancel($user);
        if ($app->adminAccess->canMutate($user, 'broadcast')) {
            $this->v3OperatorDraft()->cancel($user);
        }
        $frame = $this->v3OperatorUi()->main($user);
        $app->gateway->sendPrivate($user, $frame['text'], $frame['markup']);
    }
    private function v3OperatorPanel(ChatButtonQuery $query, string $kind): void
    {
        $app = $this->v3App();
        $user = (string)$query->userId;
        $target = \Shabbat\UI\CallbackUiGateway::fromQuery($app->gateway, $this->v3Query($query));
        $this->v4RememberPanel($user, $query->messageId);
        if (in_array($kind, ['main', 'clear'], true)) {
            if ($app->adminAccess->canMutate($user, 'broadcast')) {
                $this->v3OperatorDraft()->cancel($user, $kind === 'clear');
            }
            $this->v4Admin()->cancel($user);
            $frame = $this->v3OperatorUi()->main($user);
        } elseif ($kind === 'stats') {
            $frame = $this->v4Subscribers()->statistics($user);
        } elseif (in_array($kind, ['subscribers', 'next', 'previous', 'page'], true)) {
            $app->adminAccess->require($user, 'subscribers');
            $view = $app->subscribers->view($user);
            $page = match ($kind) {
                'subscribers' => 0, 'next' => $view['page'] + 1,
                'previous' => max(0, $view['page'] - 1), default => $view['page'],
            };
            $frame = $this->v4Subscribers()->callback($user, 'sd:list:' . $page);
        } elseif ($kind === 'broadcast') {
            $frame = $this->v4Broadcast()->main($user);
        } elseif ($kind === 'preview') {
            $app->adminAccess->require($user, 'broadcast');
            $state = $this->v3OperatorDraft()->state($user);
            if (!$state['exists']) {
                $frame = $this->v4Broadcast()->draft($user);
            } else {
                $payload = $state['payload'];
                $markup = MadelineGateway::normalizeMarkup(['inline_keyboard' => $payload['buttons'] ?? []]);
                $markup = $markup ?: ['_' => 'replyInlineMarkup', 'rows' => []];
                $markup['rows'][] = ['_' => 'keyboardButtonRow', 'buttons' => [[
                    '_' => 'keyboardButtonCallback', 'text' => $this->v3Text($user, 'back'), 'data' => 'bc:draft',
                ]]];
                $payload['reply_markup'] = $markup;
                $target->preview($payload);
                $query->answer('');
                return;
            }
        } elseif ($kind === 'progress') {
            $app->adminAccess->require($user, 'broadcast');
            $frame = $this->v3OperatorUi()->progress($user, ['active' => false]);
            $record = $app->db->pdo()->query("SELECT details_json FROM audit_log WHERE action='operator.broadcast.completed' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
            if (is_string($record)) {
                $details = json_decode($record, true, 64, JSON_THROW_ON_ERROR);
                $progress = $this->v3OperatorManager($user)->progress((string)$details['id']);
                if ($progress !== null) {
                    $frame = $this->v3OperatorUi()->progress($user, ['active' => !$progress['done'] && !$progress['cancel'], 'page' => (int)$progress['processed'], 'total' => (int)$progress['total']]);
                }
            }
        } else {
            $draft = $this->v3OperatorDraft();
            $mutate = in_array($kind, ['edit', 'buttons', 'pin', 'unpin'], true) || str_starts_with($kind, 'audience:');
            $app->adminAccess->require($user, 'broadcast', $mutate);
            if ($kind === 'edit' || $kind === 'buttons') {
                $frame = $this->v4Broadcast()->callback($user, $kind === 'edit' ? 'bc:content' : 'bc:buttons');
            } elseif ($kind === 'audience') {
                $frame = $this->v4Broadcast()->callback($user, 'bc:audience');
            } else {
                if (str_starts_with($kind, 'audience:')) {
                    $draft->setAudience($user, substr($kind, 9));
                } elseif ($kind === 'pin' || $kind === 'unpin') {
                    $draft->setPin($user, $kind === 'pin');
                } else {
                    $draft->cancel($user);
                }
                $frame = $this->v4Broadcast()->draft($user);
            }
        }
        $this->v3OperatorEdit($query, $frame);
    }
    private function v3OperatorCapture(Message $message, string $mode): bool
    {
        $text = $message->message;
        if (str_starts_with(ltrim($text), '/')) {
            return false;
        }
        $app = $this->v3App();
        $user = (string)$message->senderId;
        if (!$message instanceof PrivateMessage || !$app->adminAccess->canMutate($user, 'broadcast')) {
            return false;
        }
        $draft = $this->v3OperatorDraft();
        if ($draft->mode($user) !== $mode) {
            return false;
        }
        try {
            if ($mode === 'broadcast1') {
                $entities = array_map(static fn($entity) => is_array($entity) ? $entity : $entity->toMTProto(), $message->entities ?? []);
                $media = isset($message->media->botApiFileId) ? (string)$message->media->botApiFileId : null;
                if ($message->media !== null && $media === null) {
                    throw new \InvalidArgumentException('Unsupported message media.');
                }
                $draft->capture($user, $text, $entities, $media);
            } else {
                $draft->buttons($user, $text);
            }
            $frame = $this->v4Broadcast()->draft($user);
            $frame['text'] = $this->v3Text($user, 'operator_draft_saved') . "\n\n" . $frame['text'];
            $this->v4InputFrame($user, $frame);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            $this->v4InputFrame($user, ['text' => $this->v3Text($user, 'operator_draft_invalid'), 'markup' => $this->v4Broadcast()->draft($user)['markup']]);
        }
        return true;
    }
    private function v3OperatorProfile(ChatButtonQuery $query): void
    {
        $user = (string)$query->userId;
        $this->v3App()->adminAccess->require($user, 'subscribers');
        $id = substr($query->data, 5);
        if (!preg_match('/^-?[1-9][0-9]{0,19}$/D', $id) || !in_array((int)$id, $this->v3KnownPeers(), true)) {
            $query->answer($this->v3Text($user, 'denied'), true);
            return;
        }
        $this->v4ObservePeer($id, false);
        $this->v3OperatorEdit($query, $this->v4Subscribers()->profile($user, $id));
    }
    private function v3OperatorMaintenance(ChatButtonQuery $query, string $action): void
    {
        $user = (string)$query->userId;
        $this->v3App()->adminAccess->require($user, 'broadcast', true);
        if (!in_array($action, ['delete_last', 'delete_all', 'unpin'], true)) {
            throw new \InvalidArgumentException('Unknown broadcast maintenance action.');
        }
        $frame = $this->v4ActionPrompt($user, ['action' => $action, 'id' => 'broadcast']);
        $this->v3OperatorEdit($query, $frame);
        $query->answer('');
    }
    private function v3OperatorConfirmBroadcast(ChatButtonQuery $query): void
    {
        $user = (string)$query->userId;
        $draft = $this->v3OperatorDraft();
        $state = $draft->state($user);
        if (!$state['exists']) {
            $query->answer($this->v3Text($user, 'operator_no_draft'), true);
            return;
        }
        $this->v3App()->adminAccess->require($user, 'broadcast', true);
        $targets = $this->v4AudienceTargets($state['audience']);
        if ($targets === []) {
            $query->answer($this->v3Text($user, 'operator_empty_recipients'), true);
            return;
        }
        $token = $draft->confirmation($user, $targets);
        $this->v3OperatorEdit($query, $this->v4Broadcast()->confirmation($user, count($targets), 'op:send:' . $token));
    }
    #[Handler]
    public function operatorBroadcastCallbacks(ChatButtonQuery $query): void
    {
        if (!preg_match('/^(?:op:send:[A-Za-z0-9_-]{24}|bm:(?:pause|resume|cancel):[a-f0-9]{16})$/D', $query->data)) {
            return;
        }
        if (!$this->legacySystemQueryAllowed($query)) {
            return;
        }
        $user = (string)$query->userId;
        if (!$this->v3App()->adminAccess->canMutate($user, 'broadcast')) {
            $query->answer($this->v3Text($user, 'denied'), true);
            return;
        }
        $manager = $this->v3OperatorManager($user);
        try {
            if (str_starts_with($query->data, 'bm:')) {
                [, $action,$id] = explode(':', $query->data);
                $progress = $manager->progress($id);
                if ($progress === null || $progress['done']) {
                    throw new \RuntimeException('Inactive broadcast.');
                }
                $manager->{$action}($id);
                $progress = $manager->progress($id);
                $this->v3OperatorEdit($query, $this->v3OperatorUi()->progress($user, ['active' => !$progress['done'] && !$progress['cancel'],'page' => (int)$progress['processed'],'total' => (int)$progress['total']]));
                $query->answer('');
                return;
            }
            $token = substr($query->data, 8);
            $snapshot = $this->v3OperatorDraft()->claim($user, $token);
            $currentTargets = $this->v4AudienceTargets($snapshot['state']['audience']);
            $targets = array_values(array_intersect($snapshot['targets'], $currentTargets));
            if ($targets === []) {
                throw new \RuntimeException('Recipients are no longer registered.');
            }
            $payload = $snapshot['state']['payload'];
            $message = ['message' => $payload['text'],'entities' => $payload['entities']];
            if ($payload['buttons'] !== []) {
                $message['reply_markup'] = MadelineGateway::normalizeMarkup(['inline_keyboard' => $payload['buttons']]);
            }
            if ($payload['media'] !== null) {
                $file = $this->getFileInfo($payload['media']['bot_api_file_id']);
                $media = $file['InputMedia'] ?? null;
                if (!is_array($media) || !str_starts_with((string)($media['_'] ?? ''), 'inputMedia')) {
                    throw new \InvalidArgumentException('Reusable media is unavailable.');
                }
                $message['media'] = $media;
            }
            if (!$this->v3App()->prioritizePermissions()) {
                throw new \RuntimeException('Shutdown prevents broadcast preparation.');
            }
            if (Runner::isStopping() || !$this->v3App()->adminAccess->canMutate((string)$query->userId, 'broadcast')) {
                throw new \RuntimeException('Broadcast start is unavailable.');
            }
            $query->answer('');
            $state = $snapshot['state'];
            if (($state['operation'] ?? 'send') === 'edit') {
                $html = \danog\MadelineProto\StrTools::entitiesToHtml($payload['text'], $payload['entities'], true);
                $id = $manager->editLastBroadcastForAll($targets, $html, $query->userId, $message['reply_markup'] ?? ['_' => 'replyInlineMarkup', 'rows' => []], $message['media'] ?? null, 20, 'HTML');
            } elseif (($state['scheduled_at'] ?? null) !== null) {
                if ($state['scheduled_at'] <= $this->v3App()->clock->now()->getTimestamp()) {
                    throw new \RuntimeException('The scheduled time elapsed; choose a new time.');
                }
                $id = $manager->scheduleBroadcastForAll($targets, [$message], $state['scheduled_at'], $query->userId, (bool)$state['pin'], 20, $state['self_destruct_hours'] ?? null);
                $this->v3App()->broadcastJobs->recordScheduled($user, $id);
            } else {
                $id = $manager->broadcastWithProgress($targets, [$message], $query->userId, (bool)$state['pin'], 20, $state['self_destruct_hours'] ?? null);
            }
            $this->v3App()->db->pdo()->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,?,?,?,?)')->execute([$user,null,'operator.broadcast.completed',\Shabbat\Storage\Database::encode(['id' => $id,'progress' => $manager->progress($id)]),$this->v3App()->clock->now()->getTimestamp()]);
            $frame = $this->v4Broadcast()->main($user);
            $frame['text'] = $this->v3Text($user, 'operator_complete') . "\n\n" . $frame['text'];
            $this->v3OperatorEdit($query, $frame);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            $query->answer($this->v3Text($user, 'denied'), true);
        }
    }

    #[Handler]
    public function groupMessageCleanup(\danog\MadelineProto\EventHandler\Message\GroupMessage & Incoming & IsNotEdited $message): void
    {
        // The native GroupMessage wrapper covers basic groups and supergroups, excluding service updates.
        $this->v3App()->incomingGroupMessage((string)$message->chatId, $message->id, $message->date, (string)$message->senderId, $message->out);
    }
    #[Handler]
    public function upgradedMessages(Message & Incoming & IsNotEdited $message): void
    {
        // Existing command-specific handlers retain their route. This handler owns newly introduced commands only.
        if (($message instanceof PrivateMessage && !str_starts_with(ltrim($message->message), '/')) || preg_match('/^\/(?:language|lang|zmanim|daf|omer|groups|preferences|help|city|coordinates|location|timezone|configure|viewdate|source|license|version|template|buttons|adminstats|subscribers|broadcast|managers|systemlinks|trackinglinks)(?:@\w+)?(?:\s|$)/i', $message->message)) {
            $this->v3Message($message);
        }
    }
    #[Handler]
    public function upgradedCallbacks(ButtonQuery $query): void
    {
        $data = $query->data;
        if (!preg_match('/^(?:sb|tpl|v3|adm|sys|trk|sd|bc|opx|tf|ps|dz):/', $data)) {
            return;
        }
        $event = $this->v3Query($query);
        $app = $this->v3App();
        if (str_starts_with($data, 'tf:')) {
            $this->v4TefillinCallback($query, $event);
            return;
        }
        if ($query instanceof InlineButtonQuery) {
            if (!str_starts_with($data, 'sb:lp:')) {
                return;
            }$event['private'] = true;
        } // Selection previews only; final writes require a private ChatButtonQuery.
        if ($this->v4HandleCallback($query, $event)) {
            return;
        }
        if ($data === 'v3:source' || $data === 'v3:donate') {
            try {
                $ui = \Shabbat\UI\CallbackUiGateway::fromQuery($app->gateway, $event);
                if ($data === 'v3:source') {
                    $markup = ['_' => 'replyInlineMarkup', 'rows' => [
                        ['_' => 'keyboardButtonRow', 'buttons' => [['_' => 'keyboardButtonUrl', 'text' => $this->v3Text($event['user_id'], 'source'), 'url' => 'https://github.com/WizardLoop/shabbat', 'style' => ['_' => 'keyboardButtonStyle', 'bg_primary' => true]]]],
                        ['_' => 'keyboardButtonRow', 'buttons' => [['_' => 'keyboardButtonCallback', 'text' => $this->v3Text($event['user_id'], 'back'), 'data' => 'sb:start', 'style' => ['_' => 'keyboardButtonStyle']]]],
                    ]];
                    $ui->sendPrivate($event['user_id'], $this->v3Source('source', $event['user_id']), $markup);
                } else {
                    $this->v3DonationMenu($event['user_id'], $ui);
                }
                $ui->answerCallback($event['query_id'], '');
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                $app->gateway->answerCallback($event['query_id'], $this->v3Text($event['user_id'], 'callback_panel_unavailable'));
            }
        } elseif (!(new TemplateEditor($app))->callback($event)) {
            $app->ui->handleCallback($event);
        }
    }
}
