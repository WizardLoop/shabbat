<?php

declare(strict_types=1);

/**
 * Broadcast Manager Tool
 *
 * @author    - WizardLoop <wizardloop.com>
 * @copyright - WizardLoop <wizardloop.com>
 * @license   - https://opensource.org/licenses/AGPL-3.0 AGPLv3
 */

namespace BroadcastTool;

use danog\MadelineProto\API;
use danog\MadelineProto\RPCErrorException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SplQueue;
use Throwable;

class BroadcastManager
{
    private const MAX_ATTEMPTS = 3;
    private const DEFAULT_CONCURRENCY = 10;
    private const MAX_CONCURRENCY = 30;
    private const PROGRESS_UPDATE_INTERVAL = 5.0;
    private const WORKER_IDLE_SLEEP = 1.0;
    private const WORKER_RETRY_SLEEP = 1.0;
    private const WORKER_PAUSED_SLEEP = 1.5;
    private const WORKER_AFTER_JOB_SLEEP = 0.75;
    private const SEND_MESSAGE_SLEEP = 0.35;
    private const SEND_CHUNK_SLEEP = 0.75;

    private const SEND_HARD_FAIL_RPCS = [
        'INPUT_USER_DEACTIVATED',
        'USER_IS_BOT',
        'CHAT_WRITE_FORBIDDEN',
        'USER_IS_BLOCKED',
        'PEER_ID_INVALID',
    ];

    private const EDIT_HARD_FAIL_RPCS = [
        'INPUT_USER_DEACTIVATED',
        'USER_IS_BOT',
        'CHAT_WRITE_FORBIDDEN',
        'USER_IS_BLOCKED',
        'PEER_ID_INVALID',
        'MESSAGE_ID_INVALID',
        'MESSAGE_EDIT_TIME_EXPIRED',
        'MESSAGE_AUTHOR_REQUIRED',
    ];

    private const DELETE_HARD_FAIL_RPCS = [
        'INPUT_USER_DEACTIVATED',
        'USER_IS_BOT',
        'CHAT_WRITE_FORBIDDEN',
        'USER_IS_BLOCKED',
        'PEER_ID_INVALID',
        'MESSAGE_ID_INVALID',
    ];

    private API $api;
    private array $currentBroadcastState = [];
    private static array $sharedBroadcastState = [];
    private static string $dataDir = '';

    public function __construct(API $api)
    {
        $this->api = $api;
    }

    /**
     * Set data dir.
     */
    public static function setDataDir(string $path): void
    {
        self::$dataDir = rtrim($path, '/\\');
    }

    /**
     * Get data dir.
     */
    private static function getDataDir(): string
    {
        if (!self::$dataDir) {
            self::$dataDir = __DIR__ . '/../data';
        }

        return self::$dataDir;
    }

    /**
     * Send broadcast.
     *
     * @return string ID that can be used for progress/pause/resume/cancel.
     */
    public function broadcastWithProgress(
        array $allUsers,
        array $messages,
        $chatId = null,
        bool $pin = false,
        int $concurrency = self::DEFAULT_CONCURRENCY,
        ?int $selfDestructHours = null
    ): string {
        $this->validateSelfDestructHours($selfDestructHours);

        $concurrency = $this->clampConcurrency($concurrency);
        $total = count($allUsers);
        $broadcastId = $this->createId();
        $state = $this->createState($broadcastId, 'send', $total, [
            'selfDestruct' => [
                'enabled' => $selfDestructHours !== null,
                'hours' => $selfDestructHours,
                'deleteAt' => null,
                'deleteJobId' => null,
            ],
        ]);

        $this->registerCurrentState($broadcastId, $state);
        $this->enqueuePeers($state, $allUsers);
        $this->initializeBroadcastMetadata($broadcastId, 'send', $total, $selfDestructHours);

        $statusId = $this->sendStatusMessage($chatId, 'Gathering peers...', $this->buildStatusControls($state));
        $this->startProgressLoop($chatId, $statusId, $state, 'Broadcast Progress');

        $this->startQueueWorkers(
            $state,
            $concurrency,
            function (array $job, array &$state) use ($messages, $pin, $broadcastId): void {
                $peer = (string) $job['peer'];
                $messageIds = $this->sendMessagesToPeer($peer, $messages);
                $lastMessageId = $messageIds ? end($messageIds) : null;

                if ($pin && $lastMessageId) {
                    $this->api->messages->updatePinnedMessage([
                        'peer' => $peer,
                        'id' => $lastMessageId,
                        'unpin' => false,
                    ]);
                }

                $state['sent']++;
                $state['lastMessageIds'][$peer] = $lastMessageId ? (string) $lastMessageId : '';

                if ($messageIds) {
                    $this->savePeerMessageIds($peer, $messageIds);
                }

                $this->saveBroadcastPeerMessageIds(
                    $broadcastId,
                    $peer,
                    $messageIds,
                    (int) $state['sent'],
                    (int) $state['failed']
                );
            },
            self::SEND_HARD_FAIL_RPCS
        );

        $this->waitForCompletion($state);
        $state['status'] = $state['cancel'] ? 'cancelled' : 'done';

        $this->finalizeBroadcastMetadata(
            $broadcastId,
            (string) $state['status'],
            (int) $state['sent'],
            (int) $state['failed']
        );

        $selfDestructJobId = null;
        if ($selfDestructHours !== null) {
            $selfDestructJobId = $this->createSelfDestructJob($broadcastId, $selfDestructHours, $chatId, $concurrency);
            if ($selfDestructJobId !== null) {
                $metadata = $this->loadBroadcastMetadata($broadcastId);
                $state['selfDestruct'] = $metadata['selfDestruct'] ?? $state['selfDestruct'];
            }

            if ($selfDestructJobId !== null && $selfDestructHours === 0) {
                $this->runSelfDestructJob($selfDestructJobId);
            }
        }

        $finalText = $this->buildProgressText($state, 'Broadcast Progress', true);
        $this->editStatusMessage($chatId, $statusId, $finalText);
        $this->writeLastBroadcastData($finalText);

        return $broadcastId;
    }

    /**
     * Edit the last saved broadcast message for all users.
     */
    public function editLastBroadcastForAll(
        array $allUsers,
        string $newText,
        $chatId = null,
        ?array $buttons = null,
        $media = null,
        int $concurrency = self::DEFAULT_CONCURRENCY,
        string $parseMode = 'HTML',
        ?string $broadcastId = null
    ): string {
        $concurrency = $this->clampConcurrency($concurrency);
        $targetBroadcastId = $this->normalizeOptionalId($broadcastId);
        $targets = $targetBroadcastId !== null && $allUsers === []
            ? $this->broadcastMetadataPeers($targetBroadcastId)
            : $allUsers;
        $operationId = $this->createId();
        $state = $this->createState($operationId, 'edit', count($targets), [
            'targetBroadcastId' => $targetBroadcastId,
        ]);

        $this->registerCurrentState($operationId, $state);
        $this->enqueuePeers($state, $targets);

        $statusId = $this->sendStatusMessage($chatId, 'Editing last broadcast...', $this->buildStatusControls($state));
        $this->startProgressLoop($chatId, $statusId, $state, 'Editing Last Broadcast');

        $this->startQueueWorkers(
            $state,
            $concurrency,
            function (array $job, array &$state) use ($newText, $buttons, $media, $parseMode, $targetBroadcastId): void {
                $peer = (string) $job['peer'];
                $lastMessageId = $targetBroadcastId !== null
                    ? $this->readBroadcastLastMessageId($targetBroadcastId, $peer)
                    : $this->readLastBroadcastMessageId($peer);

                if ($lastMessageId <= 0) {
                    $state['failed']++;
                    return;
                }

                $payload = [
                    'peer' => $peer,
                    'id' => $lastMessageId,
                    'message' => $newText,
                    'parse_mode' => $parseMode,
                    'floodWaitLimit' => 172800,
                ];

                if ($buttons !== null) {
                    $payload['reply_markup'] = $buttons;
                }

                if ($media !== null) {
                    $payload['media'] = $media;
                }

                $this->api->messages->editMessage($payload);
                $state['edited']++;
            },
            self::EDIT_HARD_FAIL_RPCS,
            function (RPCErrorException $e, array &$job, array &$state): bool {
                unset($job);

                if (($e->rpc ?? '') === 'MESSAGE_NOT_MODIFIED' || str_contains($e->getMessage(), 'MESSAGE_NOT_MODIFIED')) {
                    $state['unchanged']++;
                    return true;
                }

                return false;
            }
        );

        $this->waitForCompletion($state);
        $state['status'] = $state['cancel'] ? 'cancelled' : 'done';

        $this->editStatusMessage($chatId, $statusId, $this->buildProgressText($state, 'Editing Last Broadcast', true));

        return $operationId;
    }

    /**
     * Schedule a broadcast. Due broadcasts can be run with runDueScheduledBroadcasts().
     */
    public function scheduleBroadcastForAll(
        array $allUsers,
        array $messages,
        int $scheduledAt,
        $chatId = null,
        bool $pin = false,
        int $concurrency = self::DEFAULT_CONCURRENCY,
        ?int $selfDestructHours = null
    ): string {
        $this->validateSelfDestructHours($selfDestructHours);
        $this->assertJsonEncodable($allUsers, 'allUsers');
        $this->assertJsonEncodable($messages, 'messages');

        $concurrency = $this->clampConcurrency($concurrency);
        $scheduleId = $this->createId('schedule');
        $jobs = $this->loadScheduledBroadcasts();

        $jobs[$scheduleId] = [
            'id' => $scheduleId,
            'status' => 'scheduled',
            'scheduledAt' => $scheduledAt,
            'createdAt' => time(),
            'allUsers' => array_values($allUsers),
            'messages' => array_values($messages),
            'chatId' => $chatId,
            'pin' => $pin,
            'concurrency' => $concurrency,
            'selfDestructHours' => $selfDestructHours,
            'broadcastId' => null,
            'error' => null,
        ];

        $this->saveScheduledBroadcasts($jobs);

        if ($scheduledAt <= time()) {
            $this->runScheduledBroadcast($scheduleId);
        }

        return $scheduleId;
    }

    /**
     * Run all scheduled broadcasts whose scheduledAt timestamp has passed.
     */
    public function runDueScheduledBroadcasts(): array
    {
        $jobs = $this->loadScheduledBroadcasts();
        $results = [];
        $now = time();

        foreach ($jobs as $scheduleId => $job) {
            if (($job['status'] ?? null) !== 'scheduled') {
                continue;
            }

            if ((int) ($job['scheduledAt'] ?? 0) > $now) {
                continue;
            }

            $results[$scheduleId] = $this->runScheduledBroadcast((string) $scheduleId);
        }

        return $results;
    }

    public function cancelScheduledBroadcast(string $scheduleId): bool
    {
        $jobs = $this->loadScheduledBroadcasts();

        if (!isset($jobs[$scheduleId]) || ($jobs[$scheduleId]['status'] ?? null) !== 'scheduled') {
            return false;
        }

        $jobs[$scheduleId]['status'] = 'cancelled';
        $jobs[$scheduleId]['cancelledAt'] = time();
        $this->saveScheduledBroadcasts($jobs);

        return true;
    }

    public function listScheduledBroadcasts(): array
    {
        $items = [];

        foreach ($this->loadScheduledBroadcasts() as $id => $job) {
            $items[$id] = [
                'id' => (string) ($job['id'] ?? $id),
                'status' => (string) ($job['status'] ?? 'unknown'),
                'scheduledAt' => (int) ($job['scheduledAt'] ?? 0),
                'createdAt' => (int) ($job['createdAt'] ?? 0),
                'broadcastId' => $job['broadcastId'] ?? null,
                'pin' => (bool) ($job['pin'] ?? false),
                'concurrency' => (int) ($job['concurrency'] ?? self::DEFAULT_CONCURRENCY),
                'selfDestructHours' => $job['selfDestructHours'] ?? null,
                'totalUsers' => is_array($job['allUsers'] ?? null) ? count($job['allUsers']) : 0,
                'totalMessages' => is_array($job['messages'] ?? null) ? count($job['messages']) : 0,
                'error' => $job['error'] ?? null,
            ];
        }

        return $items;
    }

    /**
     * Deletes the last broadcast message for all users.
     *
     * @return string ID that can be used for progress/pause/resume/cancel.
     */
    public function deleteLastBroadcastForAll(
        array $allUsers,
        $chatId = null,
        int $concurrency = self::DEFAULT_CONCURRENCY,
        ?string $broadcastId = null
    ): string {
        $concurrency = $this->clampConcurrency($concurrency);
        $targetBroadcastId = $this->normalizeOptionalId($broadcastId);
        $targets = $targetBroadcastId !== null && $allUsers === []
            ? $this->broadcastMetadataPeers($targetBroadcastId)
            : $allUsers;
        $operationId = $this->createId();
        $state = $this->createState($operationId, 'deletelast', count($targets), [
            'targetBroadcastId' => $targetBroadcastId,
        ]);

        $this->registerCurrentState($operationId, $state);
        $this->enqueuePeers($state, $targets);

        $statusId = $this->sendStatusMessage($chatId, 'Deleting last broadcast...', $this->buildStatusControls($state));
        $this->startProgressLoop($chatId, $statusId, $state, 'Deleting Last Broadcast');

        $this->startQueueWorkers(
            $state,
            $concurrency,
            function (array $job, array &$state) use ($targetBroadcastId): void {
                $peer = (string) $job['peer'];
                $lastMessageId = $targetBroadcastId !== null
                    ? $this->readBroadcastLastMessageId($targetBroadcastId, $peer)
                    : $this->readLastBroadcastMessageId($peer);

                if ($lastMessageId <= 0) {
                    $state['failed']++;
                    return;
                }

                $this->api->messages->deleteMessages([
                    'peer' => $peer,
                    'id' => [$lastMessageId],
                    'revoke' => true,
                ]);

                if ($targetBroadcastId !== null) {
                    $this->markBroadcastPeerMessageDeleted($targetBroadcastId, $peer, $lastMessageId);
                } else {
                    $this->deleteFile($this->peerDataPath($peer, 'lastBroadcast.txt'));
                }

                $state['deleted']++;
            },
            self::DELETE_HARD_FAIL_RPCS
        );

        $this->waitForCompletion($state);
        $state['status'] = $state['cancel'] ? 'cancelled' : 'done';

        $this->editStatusMessage($chatId, $statusId, $this->buildProgressText($state, 'Deleting Last Broadcast', true));

        return $operationId;
    }

    /**
     * Deletes all broadcast messages for all users.
     *
     * @return string ID that can be used for progress/pause/resume/cancel.
     */
    public function deleteAllBroadcastsForAll(
        array $allUsers,
        $chatId = null,
        int $concurrency = self::DEFAULT_CONCURRENCY
    ): string {
        $concurrency = $this->clampConcurrency($concurrency);
        $broadcastId = $this->createId();
        $state = $this->createState($broadcastId, 'deleteall', count($allUsers));

        $this->registerCurrentState($broadcastId, $state);
        $this->enqueuePeers($state, $allUsers);

        $statusId = $this->sendStatusMessage($chatId, 'Deleting all broadcasts...', $this->buildStatusControls($state));
        $this->startProgressLoop($chatId, $statusId, $state, 'Deleting All Broadcasts');

        $this->startQueueWorkers(
            $state,
            $concurrency,
            function (array $job, array &$state): void {
                $peer = (string) $job['peer'];
                $file = $this->peerDataPath($peer, 'messages.txt');

                if (!is_file($file)) {
                    $state['failed']++;
                    return;
                }

                $msgIds = array_values(array_filter(
                    array_map('intval', explode("\n", trim((string) file_get_contents($file)))),
                    static fn (int $id): bool => $id > 0
                ));

                if (!$msgIds) {
                    $state['failed']++;
                    return;
                }

                $userDeleted = false;

                foreach ($msgIds as $messageId) {
                    try {
                        $this->api->messages->deleteMessages([
                            'peer' => $peer,
                            'id' => [$messageId],
                            'revoke' => true,
                        ]);
                        $userDeleted = true;
                    } catch (RPCErrorException $e) {
                        $message = $e->getMessage();

                        if ($this->parseFloodWait($e) !== null) {
                            throw $e;
                        }

                        if (str_contains($message, 'USER_IS_BLOCKED') || str_contains($message, 'PEER_ID_INVALID')) {
                            continue;
                        }

                        throw $e;
                    }
                }

                if ($userDeleted) {
                    $state['deleted']++;
                } else {
                    $state['failed']++;
                }

                $this->deleteFile($this->peerDataPath($peer, 'lastBroadcast.txt'));
                $this->deleteFile($file);
            },
            self::DELETE_HARD_FAIL_RPCS,
            null,
            true
        );

        $this->waitForCompletion($state);
        $state['status'] = $state['cancel'] ? 'cancelled' : 'done';

        $this->editStatusMessage($chatId, $statusId, $this->buildProgressText($state, 'Deleting All Broadcasts', true));

        return $broadcastId;
    }

    /**
     * Unpin all messages for all users.
     *
     * @return string ID that can be used for progress/pause/resume/cancel.
     */
    public function unpinAllMessagesForAll(
        array $allUsers,
        $chatId = null,
        int $concurrency = self::DEFAULT_CONCURRENCY
    ): string {
        $concurrency = $this->clampConcurrency($concurrency);
        $broadcastId = $this->createId();
        $state = $this->createState($broadcastId, 'unpin', count($allUsers));

        $this->registerCurrentState($broadcastId, $state);
        $this->enqueuePeers($state, $allUsers);

        $statusId = $this->sendStatusMessage($chatId, 'Starting unpin...', $this->buildStatusControls($state));
        $this->startProgressLoop($chatId, $statusId, $state, 'Unpinning Messages');

        $this->startQueueWorkers(
            $state,
            $concurrency,
            function (array $job, array &$state): void {
                $this->api->messages->unpinAllMessages([
                    'peer' => (string) $job['peer'],
                ]);

                $state['unpin']++;
            },
            self::SEND_HARD_FAIL_RPCS
        );

        $this->waitForCompletion($state);
        $state['status'] = $state['cancel'] ? 'cancelled' : 'done';

        $this->editStatusMessage($chatId, $statusId, $this->buildProgressText($state, 'Unpinning Messages', true));

        return $broadcastId;
    }

    public function runDueSelfDestructJobs(): array
    {
        $jobs = $this->loadSelfDestructJobs();
        $results = [];
        $now = time();

        foreach ($jobs as $jobId => $job) {
            if (($job['status'] ?? null) !== 'scheduled') {
                continue;
            }

            if ((int) ($job['deleteAt'] ?? 0) > $now) {
                continue;
            }

            $results[$jobId] = $this->runSelfDestructJob((string) $jobId);
        }

        return $results;
    }

    public function cancelSelfDestructJob(string $jobId): bool
    {
        $jobs = $this->loadSelfDestructJobs();

        if (!isset($jobs[$jobId]) || ($jobs[$jobId]['status'] ?? null) !== 'scheduled') {
            return false;
        }

        $jobs[$jobId]['status'] = 'cancelled';
        $jobs[$jobId]['cancelledAt'] = time();
        $this->saveSelfDestructJobs($jobs);

        return true;
    }

    public function listSelfDestructJobs(): array
    {
        $items = [];

        foreach ($this->loadSelfDestructJobs() as $id => $job) {
            $items[$id] = [
                'id' => (string) ($job['id'] ?? $id),
                'broadcastId' => (string) ($job['broadcastId'] ?? ''),
                'status' => (string) ($job['status'] ?? 'unknown'),
                'deleteAt' => (int) ($job['deleteAt'] ?? 0),
                'createdAt' => (int) ($job['createdAt'] ?? 0),
                'concurrency' => (int) ($job['concurrency'] ?? self::DEFAULT_CONCURRENCY),
                'chatId' => $job['chatId'] ?? null,
                'totalPeers' => (int) ($job['totalPeers'] ?? 0),
                'stats' => $job['stats'] ?? null,
                'error' => $job['error'] ?? null,
            ];
        }

        return $items;
    }

    /**
     * Pause running broadcast.
     */
    public function pause(string $id): void
    {
        if (isset(self::$sharedBroadcastState[$id])) {
            self::$sharedBroadcastState[$id]['paused'] = true;
        }

        if (isset($this->currentBroadcastState[$id])) {
            $this->currentBroadcastState[$id]['paused'] = true;
        }
    }

    /**
     * Resume running broadcast.
     */
    public function resume(string $id): void
    {
        if (isset(self::$sharedBroadcastState[$id])) {
            self::$sharedBroadcastState[$id]['paused'] = false;
        }

        if (isset($this->currentBroadcastState[$id])) {
            $this->currentBroadcastState[$id]['paused'] = false;
        }
    }

    /**
     * Cancel running broadcast.
     */
    public function cancel(string $id): void
    {
        if (isset(self::$sharedBroadcastState[$id])) {
            self::$sharedBroadcastState[$id]['cancel'] = true;
        }

        if (isset($this->currentBroadcastState[$id])) {
            $this->currentBroadcastState[$id]['cancel'] = true;
        }
    }

    /**
     * Check if broadcast is paused.
     */
    public function isPaused(string $id): bool
    {
        return self::$sharedBroadcastState[$id]['paused'] ?? $this->currentBroadcastState[$id]['paused'] ?? false;
    }

    /**
     * Check if broadcast is cancelled.
     */
    public function isCancelled(string $id): bool
    {
        return self::$sharedBroadcastState[$id]['cancel'] ?? $this->currentBroadcastState[$id]['cancel'] ?? false;
    }

    /**
     * Check if broadcast is active.
     */
    public function isActive(?string $id = null): bool
    {
        if (!$id || (!isset(self::$sharedBroadcastState[$id]) && !isset($this->currentBroadcastState[$id]))) {
            return false;
        }

        $state = self::$sharedBroadcastState[$id] ?? $this->currentBroadcastState[$id];

        return (
            empty($state['done'])
            && empty($state['cancel'])
            && empty($state['paused'])
        );
    }

    /**
     * Check if there is a last broadcast message saved for deletion.
     */
    public function hasLastBroadcast(): bool
    {
        foreach (glob(self::getDataDir() . '/*/lastBroadcast.txt') ?: [] as $file) {
            if (is_file($file) && trim((string) file_get_contents($file)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if there are broadcast messages saved for deletion.
     */
    public function hasAllBroadcast(): bool
    {
        foreach (glob(self::getDataDir() . '/*/messages.txt') ?: [] as $file) {
            if (is_file($file) && trim((string) file_get_contents($file)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get current broadcast progress.
     */
    public function progress(?string $id = null): ?array
    {
        if (!$id || (!isset(self::$sharedBroadcastState[$id]) && !isset($this->currentBroadcastState[$id]))) {
            return null;
        }

        $state = $this->normalizeBroadcastState(self::$sharedBroadcastState[$id] ?? $this->currentBroadcastState[$id]);

        $sent = (int) $state['sent'];
        $deleted = (int) $state['deleted'];
        $unpin = (int) $state['unpin'];
        $edited = (int) $state['edited'];
        $unchanged = (int) $state['unchanged'];
        $scheduled = (int) $state['scheduled'];
        $failed = (int) $state['failed'];
        $flood = (int) $state['flood'];

        $processed = $this->processedCount($state);
        $success = $sent + $deleted + $unpin + $edited + $unchanged;
        $pending = $this->pendingCount($state, $processed);
        $total = (int) $state['total'];
        $elapsed = $this->elapsedSeconds($state);
        $tps = $elapsed > 0 ? round($success / $elapsed, 2) : 0.0;

        $progressPercent = $total > 0
            ? round(($processed / $total) * 100, 2)
            : 0.0;

        return [
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'pending' => $pending,
            'flood' => $flood,
            'progressPercent' => $progressPercent,
            'breakdown' => [
                'sent' => $sent,
                'deleted' => $deleted,
                'unpin' => $unpin,
                'edited' => $edited,
                'unchanged' => $unchanged,
                'scheduled' => $scheduled,
            ],
            'edited' => $edited,
            'unchanged' => $unchanged,
            'scheduled' => $scheduled,
            'selfDestruct' => $state['selfDestruct'],
            'type' => $state['type'],
            'total' => $total,
            'elapsed' => $elapsed,
            'tps' => $tps,
            'done' => (bool) $state['done'],
            'paused' => (bool) $state['paused'],
            'cancel' => (bool) $state['cancel'],
            'startedAt' => $state['startedAt'],
        ];
    }

    /**
     * Last broadcast data.
     */
    public function lastBroadcastData(): string|false
    {
        $dir = self::getDataDir();

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/LastBrodDATA.txt';

        if (!is_file($path)) {
            return false;
        }

        return (string) file_get_contents($path);
    }

    /**
     * Filter peers.
     *
     * allowedTypes: all / users / groups / channels
     *
     * @return array{targets: array, failed: int, total: int}
     */
    public function filterPeers(
        array $allUsers,
        string $filterType = 'users'
    ): array {
        $allowedTypes = [
            'all' => ['user', 'chat', 'supergroup', 'channel'],
            'users' => ['user'],
            'groups' => ['chat', 'supergroup'],
            'channels' => ['channel'],
        ];

        $targets = [];
        $failedCount = 0;

        foreach ($allUsers as $peer) {
            try {
                $info = $this->api->getInfo($peer);
                $type = $info['type'] ?? 'user';

                if (in_array($type, $allowedTypes[$filterType] ?? ['user'], true)) {
                    $targets[] = (string) $peer;
                }
            } catch (Throwable $e) {
                if ($filterType === 'all') {
                    $targets[] = (string) $peer;
                } else {
                    $failedCount++;
                    $this->logError('Failed to inspect peer type.', $e, ['peer' => (string) $peer]);
                }
            }
        }

        return [
            'targets' => $targets,
            'failed' => $failedCount,
            'total' => count($targets),
        ];
    }

    private function createState(string $id, string $type, int $total, array $extra = []): array
    {
        return $extra + [
            'id' => $id,
            'type' => $type,
            'status' => 'running',
            'total' => max(0, $total),
            'sent' => 0,
            'deleted' => 0,
            'unpin' => 0,
            'edited' => 0,
            'unchanged' => 0,
            'scheduled' => 0,
            'failed' => 0,
            'flood' => 0,
            'queue' => new SplQueue(),
            'inFlight' => [],
            'lastMessageIds' => [],
            'paused' => false,
            'cancel' => false,
            'done' => false,
            'startedAt' => microtime(true),
            'selfDestruct' => null,
        ];
    }

    private function registerCurrentState(string $id, array &$state): void
    {
        $this->currentBroadcastState[$id] =& $state;
        self::$sharedBroadcastState[$id] =& $state;
    }

    private function enqueuePeers(array &$state, array $peers): void
    {
        foreach ($peers as $peer) {
            $state['queue']->enqueue([
                'peer' => (string) $peer,
                'attempts' => 0,
                'startedAt' => null,
                'availableAt' => 0.0,
            ]);
        }
    }

    private function clampConcurrency(int $concurrency): int
    {
        return max(1, min(self::MAX_CONCURRENCY, $concurrency));
    }

    private function startQueueWorkers(
        array &$state,
        int $concurrency,
        callable $handler,
        array $hardFailRpcs = [],
        ?callable $rpcHandler = null,
        bool $retryThrowable = false
    ): void {
        $concurrency = $this->clampConcurrency($concurrency);

        for ($i = 0; $i < $concurrency; $i++) {
            \Amp\async(function () use (&$state, $handler, $hardFailRpcs, $rpcHandler, $retryThrowable): void {
                while (!$state['cancel'] && !$state['done']) {
                    if ($state['queue']->isEmpty()) {
                        $this->api->sleep(self::WORKER_IDLE_SLEEP);
                        continue;
                    }

                    if ($state['paused']) {
                        $this->api->sleep(self::WORKER_PAUSED_SLEEP);
                        continue;
                    }

                    $job = $state['queue']->dequeue();

                    if (($job['availableAt'] ?? 0) > microtime(true)) {
                        $state['queue']->enqueue($job);
                        $this->api->sleep(self::WORKER_RETRY_SLEEP);
                        continue;
                    }

                    while ($state['paused'] && !$state['cancel'] && !$state['done']) {
                        $this->api->sleep(self::WORKER_PAUSED_SLEEP);
                    }

                    if ($state['cancel'] || $state['done']) {
                        continue;
                    }

                    $peer = (string) $job['peer'];
                    $job['startedAt'] = microtime(true);
                    $state['inFlight'][$peer] = $job;

                    try {
                        $handler($job, $state);
                        unset($state['inFlight'][$peer]);
                    } catch (RPCErrorException $e) {
                        unset($state['inFlight'][$peer]);

                        if ($rpcHandler !== null && $rpcHandler($e, $job, $state)) {
                            continue;
                        }

                        if ($this->handleRpcRetry($e, $job, $state, $hardFailRpcs)) {
                            continue;
                        }

                        $this->logError('RPC error during broadcast job.', $e, [
                            'type' => (string) ($state['type'] ?? ''),
                            'peer' => $peer,
                        ]);
                        $this->retryOrFail($job, $state);
                    } catch (Throwable $e) {
                        unset($state['inFlight'][$peer]);
                        $this->logError('Unexpected error during broadcast job.', $e, [
                            'type' => (string) ($state['type'] ?? ''),
                            'peer' => $peer,
                        ]);

                        if ($retryThrowable) {
                            $this->retryOrFail($job, $state);
                        } else {
                            $state['failed']++;
                        }
                    }

                    $this->api->sleep(self::WORKER_AFTER_JOB_SLEEP);
                }
            });
        }
    }

    private function waitForCompletion(array &$state): void
    {
        while (true) {
            if ($state['queue']->isEmpty() && empty($state['inFlight'])) {
                break;
            }

            if ($state['cancel'] && empty($state['inFlight'])) {
                break;
            }

            $this->api->sleep(1);
        }

        $state['done'] = true;
    }

    private function handleRpcRetry(RPCErrorException $e, array &$job, array &$state, array $hardFailRpcs): bool
    {
        if ($this->isHardFailRpc((string) ($e->rpc ?? ''), $hardFailRpcs)) {
            $state['failed']++;
            return true;
        }

        $floodWait = $this->parseFloodWait($e);
        if ($floodWait !== null) {
            $state['flood']++;
            $job['attempts']++;
            $job['availableAt'] = microtime(true) + $floodWait;
            $job['startedAt'] = null;

            if ($job['attempts'] >= self::MAX_ATTEMPTS) {
                $state['failed']++;
            } else {
                $state['queue']->enqueue($job);
            }

            return true;
        }

        return false;
    }

    private function isHardFailRpc(string $rpc, array $hardFailRpcs = self::SEND_HARD_FAIL_RPCS): bool
    {
        return in_array($rpc, $hardFailRpcs, true);
    }

    private function parseFloodWait(RPCErrorException $e): ?int
    {
        if (preg_match('/FLOOD_WAIT_(\d+)/', $e->getMessage(), $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function retryOrFail(array &$job, array &$state): void
    {
        $job['attempts']++;
        $job['startedAt'] = null;

        if ($job['attempts'] >= self::MAX_ATTEMPTS) {
            $state['failed']++;
            return;
        }

        $state['queue']->enqueue($job);
    }

    private function sendMessagesToPeer(string $peer, array $messages): array
    {
        $messageIds = [];
        $albumMessages = [];

        foreach ($messages as $message) {
            if (isset($message['albumFile']) && is_file((string) $message['albumFile'])) {
                $decoded = json_decode((string) file_get_contents((string) $message['albumFile']), true);
                $albumMessages = is_array($decoded) ? $decoded : [];
            }
        }

        if ($albumMessages) {
            foreach (array_chunk($albumMessages, 10) as $chunk) {
                $multi = [];

                foreach ($chunk as $item) {
                    $media = ($item['media']['type'] ?? null) === 'photo'
                        ? ['_' => 'inputMediaPhoto', 'id' => $item['media']['botApiFileId']]
                        : ['_' => 'inputMediaDocument', 'id' => $item['media']['botApiFileId']];

                    $multi[] = [
                        '_' => 'inputSingleMedia',
                        'media' => $media,
                        'message' => $item['caption'] ?? '',
                        'entities' => $item['entities'] ?? [],
                    ];
                }

                foreach ($this->api->messages->sendMultiMedia(['peer' => $peer, 'multi_media' => $multi]) as $update) {
                    $messageId = (int) $this->api->extractMessageId($update);

                    if ($messageId > 0) {
                        $messageIds[] = $messageId;
                    }
                }

                $this->api->sleep(self::SEND_CHUNK_SLEEP);
            }

            return $messageIds;
        }

        foreach ($messages as $message) {
            $method = isset($message['media']) ? 'sendMedia' : 'sendMessage';
            $payload = $message + [
                'peer' => $peer,
                'floodWaitLimit' => 172800,
            ];

            if (isset($message['buttons'])) {
                $payload['reply_markup'] = $message['buttons'];
            }

            $result = $this->api->messages->{$method}($payload);
            $messageId = (int) $this->api->extractMessageId($result);

            if ($messageId > 0) {
                $messageIds[] = $messageId;
            }

            $this->api->sleep(self::SEND_MESSAGE_SLEEP);
        }

        return $messageIds;
    }

    private function savePeerMessageIds(string $peer, array $messageIds): void
    {
        $messageIds = array_values(array_filter(array_map('intval', $messageIds), static fn (int $id): bool => $id > 0));

        if (!$messageIds) {
            return;
        }

        $dir = self::getDataDir() . '/' . $peer;
        $this->ensureDirectory($dir);

        try {
            file_put_contents($dir . '/messages.txt', implode("\n", $messageIds) . "\n", FILE_APPEND | LOCK_EX);
            file_put_contents($dir . '/lastBroadcast.txt', (string) end($messageIds), LOCK_EX);
        } catch (Throwable $e) {
            $this->logError('Failed to save peer message ids.', $e, ['peer' => $peer]);
        }
    }

    private function initializeBroadcastMetadata(string $broadcastId, string $type, int $total, ?int $selfDestructHours): void
    {
        $metadata = [
            'id' => $broadcastId,
            'type' => $type,
            'createdAt' => time(),
            'status' => 'running',
            'total' => $total,
            'sent' => 0,
            'failed' => 0,
            'peers' => [],
            'selfDestruct' => [
                'enabled' => $selfDestructHours !== null,
                'hours' => $selfDestructHours,
                'deleteAt' => null,
                'deleteJobId' => null,
            ],
        ];

        $this->saveBroadcastMetadata($broadcastId, $metadata);
    }

    private function saveBroadcastPeerMessageIds(
        string $broadcastId,
        string $peer,
        array $messageIds,
        int $sent,
        int $failed
    ): void {
        $metadata = $this->loadBroadcastMetadata($broadcastId);
        $messageIds = array_values(array_filter(array_map('intval', $messageIds), static fn (int $id): bool => $id > 0));

        $metadata['sent'] = $sent;
        $metadata['failed'] = $failed;
        if (!isset($metadata['peers']) || !is_array($metadata['peers'])) {
            $metadata['peers'] = [];
        }

        $metadata['peers'][$peer] = [
            'lastMessageId' => $messageIds ? end($messageIds) : null,
            'messageIds' => $messageIds,
            'status' => 'sent',
        ];

        $this->saveBroadcastMetadata($broadcastId, $metadata);
    }

    private function finalizeBroadcastMetadata(string $broadcastId, string $status, int $sent, int $failed): void
    {
        $metadata = $this->loadBroadcastMetadata($broadcastId);
        $metadata['status'] = $status;
        $metadata['sent'] = $sent;
        $metadata['failed'] = $failed;
        $metadata['finishedAt'] = time();

        $this->saveBroadcastMetadata($broadcastId, $metadata);
    }

    private function saveBroadcastMetadata(string $broadcastId, array $metadata): void
    {
        try {
            $this->writeJsonFileAtomic($this->broadcastMetadataPath($broadcastId), $metadata);
        } catch (Throwable $e) {
            $this->logError('Failed to save broadcast metadata.', $e, ['broadcastId' => $broadcastId]);
        }
    }

    private function loadBroadcastMetadata(string $broadcastId): array
    {
        return $this->readJsonFile($this->broadcastMetadataPath($broadcastId), [
            'id' => $broadcastId,
            'type' => 'send',
            'createdAt' => time(),
            'status' => 'running',
            'total' => 0,
            'sent' => 0,
            'failed' => 0,
            'peers' => [],
            'selfDestruct' => [
                'enabled' => false,
                'hours' => null,
                'deleteAt' => null,
                'deleteJobId' => null,
            ],
        ]);
    }

    private function readJsonFile(string $path, array $default = []): array
    {
        if (!is_file($path)) {
            return $default;
        }

        try {
            $content = (string) file_get_contents($path);

            if (trim($content) === '') {
                return $default;
            }

            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : $default;
        } catch (Throwable $e) {
            $this->logError('Failed to read JSON file.', $e, ['path' => $path]);
            return $default;
        }
    }

    private function writeJsonFileAtomic(string $path, array $data): void
    {
        $this->ensureDirectory(dirname($path));

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
                throw new RuntimeException('Unable to write temporary JSON file.');
            }

            if (!@rename($tmp, $path)) {
                if (is_file($path)) {
                    @unlink($path);
                }

                if (!@rename($tmp, $path)) {
                    throw new RuntimeException('Unable to rename temporary JSON file.');
                }
            }
        } catch (Throwable $e) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }

            $this->logError('Failed to write JSON file atomically.', $e, ['path' => $path]);
            throw $e;
        }
    }

    private function logError(string $message, ?Throwable $e = null, array $context = []): void
    {
        try {
            $this->ensureDirectory(self::getDataDir());
            $entry = [
                'time' => date('c'),
                'message' => $message,
                'error' => $e ? $e->getMessage() : null,
                'rpc' => $e instanceof RPCErrorException ? ($e->rpc ?? null) : null,
                'context' => $context,
            ];

            file_put_contents(
                self::getDataDir() . '/broadcast-errors.log',
                json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable) {
            // Logging must never break bot execution.
        }
    }

    private function runScheduledBroadcast(string $scheduleId): array
    {
        $jobs = $this->loadScheduledBroadcasts();

        if (!isset($jobs[$scheduleId])) {
            return ['status' => 'missing'];
        }

        if (($jobs[$scheduleId]['status'] ?? null) !== 'scheduled') {
            return [
                'status' => (string) ($jobs[$scheduleId]['status'] ?? 'unknown'),
                'broadcastId' => $jobs[$scheduleId]['broadcastId'] ?? null,
            ];
        }

        $jobs[$scheduleId]['status'] = 'running';
        $jobs[$scheduleId]['startedAt'] = time();
        $this->saveScheduledBroadcasts($jobs);

        try {
            $job = $jobs[$scheduleId];
            $broadcastId = $this->broadcastWithProgress(
                $job['allUsers'] ?? [],
                $job['messages'] ?? [],
                $job['chatId'] ?? null,
                (bool) ($job['pin'] ?? false),
                (int) ($job['concurrency'] ?? self::DEFAULT_CONCURRENCY),
                $job['selfDestructHours'] ?? null
            );

            $progress = $this->progress($broadcastId);
            $jobs = $this->loadScheduledBroadcasts();
            $jobs[$scheduleId]['status'] = ($progress['cancel'] ?? false) ? 'cancelled' : 'done';
            $jobs[$scheduleId]['broadcastId'] = $broadcastId;
            $jobs[$scheduleId]['finishedAt'] = time();
            $jobs[$scheduleId]['error'] = null;
            $this->saveScheduledBroadcasts($jobs);

            return [
                'status' => $jobs[$scheduleId]['status'],
                'broadcastId' => $broadcastId,
            ];
        } catch (Throwable $e) {
            $jobs = $this->loadScheduledBroadcasts();
            $jobs[$scheduleId]['status'] = 'failed';
            $jobs[$scheduleId]['error'] = substr($e->getMessage(), 0, 500);
            $jobs[$scheduleId]['failedAt'] = time();
            $this->saveScheduledBroadcasts($jobs);
            $this->logError('Scheduled broadcast failed.', $e, ['scheduleId' => $scheduleId]);

            return [
                'status' => 'failed',
                'error' => $jobs[$scheduleId]['error'],
            ];
        }
    }

    private function createSelfDestructJob(string $broadcastId, int $hours, $chatId, int $concurrency): ?string
    {
        $metadata = $this->loadBroadcastMetadata($broadcastId);
        $peers = array_filter(
            $metadata['peers'] ?? [],
            static fn ($peerData): bool => is_array($peerData) && !empty($peerData['messageIds'])
        );

        if (!$peers) {
            return null;
        }

        $jobId = $this->createId('selfdestruct');
        $deleteAt = time() + ($hours * 3600);
        $jobs = $this->loadSelfDestructJobs();

        $jobs[$jobId] = [
            'id' => $jobId,
            'broadcastId' => $broadcastId,
            'status' => 'scheduled',
            'deleteAt' => $deleteAt,
            'createdAt' => time(),
            'concurrency' => $this->clampConcurrency($concurrency),
            'chatId' => $chatId,
            'totalPeers' => count($peers),
            'stats' => null,
            'error' => null,
        ];

        $this->saveSelfDestructJobs($jobs);

        $metadata['selfDestruct'] = [
            'enabled' => true,
            'hours' => $hours,
            'deleteAt' => $deleteAt,
            'deleteJobId' => $jobId,
        ];
        $this->saveBroadcastMetadata($broadcastId, $metadata);

        return $jobId;
    }

    private function runSelfDestructJob(string $jobId): array
    {
        $jobs = $this->loadSelfDestructJobs();

        if (!isset($jobs[$jobId])) {
            return ['status' => 'missing'];
        }

        if (($jobs[$jobId]['status'] ?? null) !== 'scheduled') {
            return [
                'status' => (string) ($jobs[$jobId]['status'] ?? 'unknown'),
                'stats' => $jobs[$jobId]['stats'] ?? null,
            ];
        }

        $jobs[$jobId]['status'] = 'running';
        $jobs[$jobId]['startedAt'] = time();
        $this->saveSelfDestructJobs($jobs);

        try {
            $job = $jobs[$jobId];
            $metadata = $this->loadBroadcastMetadata((string) $job['broadcastId']);
            $peerJobs = [];

            foreach (($metadata['peers'] ?? []) as $peer => $peerData) {
                if (!is_array($peerData)) {
                    continue;
                }

                $messageIds = array_values(array_filter(
                    array_map('intval', $peerData['messageIds'] ?? []),
                    static fn (int $id): bool => $id > 0
                ));

                if (!$messageIds) {
                    continue;
                }

                $peerJobs[] = [
                    'peer' => (string) $peer,
                    'messageIds' => $messageIds,
                    'attempts' => 0,
                    'startedAt' => null,
                    'availableAt' => 0.0,
                ];
            }

            $state = $this->createState($jobId, 'selfdestruct', count($peerJobs), [
                'selfDestruct' => [
                    'jobId' => $jobId,
                    'broadcastId' => (string) $job['broadcastId'],
                    'deleteAt' => (int) $job['deleteAt'],
                ],
            ]);
            $this->registerCurrentState($jobId, $state);

            foreach ($peerJobs as $peerJob) {
                $state['queue']->enqueue($peerJob);
            }

            $this->startQueueWorkers(
                $state,
                (int) ($job['concurrency'] ?? self::DEFAULT_CONCURRENCY),
                function (array $peerJob, array &$state): void {
                    $messageIds = array_values(array_filter(
                        array_map('intval', $peerJob['messageIds'] ?? []),
                        static fn (int $id): bool => $id > 0
                    ));

                    if (!$messageIds) {
                        return;
                    }

                    $this->api->messages->deleteMessages([
                        'peer' => (string) $peerJob['peer'],
                        'id' => $messageIds,
                        'revoke' => true,
                    ]);

                    $state['deleted']++;
                },
                self::DELETE_HARD_FAIL_RPCS
            );

            $this->waitForCompletion($state);
            $state['status'] = $state['cancel'] ? 'cancelled' : 'done';

            $stats = [
                'deleted' => (int) $state['deleted'],
                'failed' => (int) $state['failed'],
                'flood' => (int) $state['flood'],
                'total' => (int) $state['total'],
            ];

            $jobs = $this->loadSelfDestructJobs();
            $jobs[$jobId]['status'] = $state['cancel'] ? 'cancelled' : 'done';
            $jobs[$jobId]['finishedAt'] = time();
            $jobs[$jobId]['stats'] = $stats;
            $jobs[$jobId]['error'] = null;
            $this->saveSelfDestructJobs($jobs);

            return [
                'status' => $jobs[$jobId]['status'],
                'stats' => $stats,
            ];
        } catch (Throwable $e) {
            $jobs = $this->loadSelfDestructJobs();
            $jobs[$jobId]['status'] = 'failed';
            $jobs[$jobId]['failedAt'] = time();
            $jobs[$jobId]['error'] = substr($e->getMessage(), 0, 500);
            $this->saveSelfDestructJobs($jobs);
            $this->logError('Self-destruct job failed.', $e, ['jobId' => $jobId]);

            return [
                'status' => 'failed',
                'error' => $jobs[$jobId]['error'],
            ];
        }
    }

    private function normalizeBroadcastState(array $state): array
    {
        return [
            'id' => $state['id'] ?? null,
            'type' => $state['type'] ?? null,
            'sent' => $state['sent'] ?? 0,
            'deleted' => $state['deleted'] ?? 0,
            'unpin' => $state['unpin'] ?? 0,
            'edited' => $state['edited'] ?? 0,
            'unchanged' => $state['unchanged'] ?? 0,
            'scheduled' => $state['scheduled'] ?? 0,
            'failed' => $state['failed'] ?? 0,
            'flood' => $state['flood'] ?? 0,
            'total' => $state['total'] ?? null,
            'queue' => $state['queue'] ?? null,
            'inFlight' => $state['inFlight'] ?? [],
            'done' => $state['done'] ?? false,
            'paused' => $state['paused'] ?? false,
            'cancel' => $state['cancel'] ?? false,
            'startedAt' => $state['startedAt'] ?? null,
            'selfDestruct' => $state['selfDestruct'] ?? null,
        ];
    }

    private function processedCount(array $state): int
    {
        return (int) $state['sent']
            + (int) $state['deleted']
            + (int) $state['unpin']
            + (int) $state['edited']
            + (int) $state['unchanged']
            + (int) $state['scheduled']
            + (int) $state['failed'];
    }

    private function pendingCount(array $state, int $processed): int
    {
        if (isset($state['total']) && is_int($state['total'])) {
            return max(0, $state['total'] - $processed);
        }

        return ($state['queue'] instanceof SplQueue) ? $state['queue']->count() : 0;
    }

    private function elapsedSeconds(array $state): float
    {
        if (!isset($state['startedAt']) || !$state['startedAt']) {
            return 0.0;
        }

        return max(0.0, microtime(true) - (float) $state['startedAt']);
    }

    private function buildProgressText(array $state, string $title, bool $final = false): string
    {
        $normalized = $this->normalizeBroadcastState($state);
        $processed = $this->processedCount($normalized);
        $total = (int) ($normalized['total'] ?? $processed);
        $pending = $this->pendingCount($normalized, $processed);
        $success = (int) $normalized['sent']
            + (int) $normalized['deleted']
            + (int) $normalized['unpin']
            + (int) $normalized['edited']
            + (int) $normalized['unchanged'];
        $elapsed = $this->elapsedSeconds($normalized);
        $tps = $elapsed > 0 ? round($success / $elapsed, 2) : 0.0;

        $lines = [
            '<b>' . $title . '</b>',
            '',
            '<code>' . $this->progressBar($processed, max(1, $total)) . '</code>',
            '',
            'Processed: ' . $processed . ' / ' . $total,
        ];

        foreach (['sent' => 'Sent', 'edited' => 'Edited', 'unchanged' => 'Unchanged', 'deleted' => 'Deleted', 'unpin' => 'Unpinned'] as $key => $label) {
            if ((int) $normalized[$key] > 0 || $normalized['type'] === $key || ($key === 'edited' && $normalized['type'] === 'edit')) {
                $lines[] = $label . ': ' . (int) $normalized[$key];
            }
        }

        $lines[] = 'Failed: ' . (int) $normalized['failed'];
        $lines[] = 'FLOOD_WAIT: ' . (int) $normalized['flood'];
        $lines[] = 'Pending: ' . $pending;
        $lines[] = 'TPS: ' . $tps . '/s';

        if ($normalized['paused']) {
            $lines[] = '<b>Paused</b>';
        }

        if ($normalized['cancel']) {
            $lines[] = '<b>Cancelled</b>';
        } elseif ($final) {
            $lines[] = '<b>Finished</b>';
        }

        return implode("\n", $lines);
    }

    /**
     * Progress bar.
     */
    private function progressBar(int $current, int $total): string
    {
        $len = 20;
        $filled = (int) round($current / max($total, 1) * $len);

        return str_repeat('#', $filled)
            . str_repeat('-', max(0, $len - $filled))
            . ' '
            . round(($current / max($total, 1)) * 100)
            . '%';
    }

    private function buildStatusControls(array $state): ?array
    {
        if (($state['done'] ?? false) || empty($state['id'])) {
            return null;
        }

        $id = (string) $state['id'];
        $toggleAction = !empty($state['paused']) ? 'resume' : 'pause';
        $toggleText = !empty($state['paused']) ? 'Resume' : 'Pause';

        return [
            'inline_keyboard' => [
                [
                    ['text' => $toggleText, 'callback_data' => 'bm:' . $toggleAction . ':' . $id],
                    ['text' => 'Cancel', 'callback_data' => 'bm:cancel:' . $id],
                ],
            ],
        ];
    }

    private function sendStatusMessage($chatId, string $message, ?array $replyMarkup = null): ?int
    {
        if (!$chatId) {
            return null;
        }

        try {
            $payload = [
                'peer' => $chatId,
                'message' => $message,
                'parse_mode' => 'HTML',
            ];

            if ($replyMarkup !== null) {
                $payload['reply_markup'] = $replyMarkup;
            }

            $status = $this->api->messages->sendMessage($payload);

            return (int) $this->api->extractMessageId($status);
        } catch (Throwable $e) {
            $this->logError('Failed to send status message.', $e);
            return null;
        }
    }

    private function startProgressLoop($chatId, ?int $statusId, array &$state, string $title): void
    {
        if (!$chatId || !$statusId) {
            return;
        }

        \Amp\async(function () use ($chatId, $statusId, &$state, $title): void {
            $last = '';
            $loggedFailures = 0;

            while (!$state['done']) {
                $text = $this->buildProgressText($state, $title);
                $replyMarkup = $this->buildStatusControls($state);
                $fingerprint = $text . "\n" . json_encode($replyMarkup);

                if ($fingerprint !== $last) {
                    try {
                        $payload = [
                            'peer' => $chatId,
                            'id' => $statusId,
                            'message' => $text,
                            'parse_mode' => 'HTML',
                        ];

                        if ($replyMarkup !== null) {
                            $payload['reply_markup'] = $replyMarkup;
                        }

                        $this->api->messages->editMessage($payload);
                        $last = $fingerprint;
                    } catch (RPCErrorException $e) {
                        if (
                            ($e->rpc ?? '') === 'MESSAGE_NOT_MODIFIED'
                            || str_contains($e->getMessage(), 'MESSAGE_NOT_MODIFIED')
                        ) {
                            $last = $fingerprint;
                            $this->api->sleep(self::PROGRESS_UPDATE_INTERVAL);
                            continue;
                        }

                        if ($loggedFailures < 3) {
                            $loggedFailures++;
                            $this->logError('Failed to update status message.', $e);
                        }
                    } catch (Throwable $e) {
                        if ($loggedFailures < 3) {
                            $loggedFailures++;
                            $this->logError('Failed to update status message.', $e);
                        }
                    }
                }

                $this->api->sleep(self::PROGRESS_UPDATE_INTERVAL);
            }
        });
    }

    private function editStatusMessage($chatId, ?int $statusId, string $text, ?array $replyMarkup = null): void
    {
        if (!$chatId || !$statusId) {
            return;
        }

        try {
            $payload = [
                'peer' => $chatId,
                'id' => $statusId,
                'message' => $text,
                'parse_mode' => 'HTML',
            ];

            if ($replyMarkup !== null) {
                $payload['reply_markup'] = $replyMarkup;
            }

            $this->api->messages->editMessage($payload);
        } catch (RPCErrorException $e) {
            if (
                ($e->rpc ?? '') === 'MESSAGE_NOT_MODIFIED'
                || str_contains($e->getMessage(), 'MESSAGE_NOT_MODIFIED')
            ) {
                return;
            }

            $this->logError('Failed to edit final status message.', $e);
        } catch (Throwable $e) {
            $this->logError('Failed to edit final status message.', $e);
        }
    }

    private function writeLastBroadcastData(string $text): void
    {
        try {
            $this->ensureDirectory(self::getDataDir());
            file_put_contents(self::getDataDir() . '/LastBrodDATA.txt', $text, LOCK_EX);
        } catch (Throwable $e) {
            $this->logError('Failed to save last broadcast data.', $e);
        }
    }

    private function readLastBroadcastMessageId(string $peer): int
    {
        $file = $this->peerDataPath($peer, 'lastBroadcast.txt');

        if (!is_file($file)) {
            return 0;
        }

        return (int) trim((string) file_get_contents($file));
    }

    private function readBroadcastLastMessageId(string $broadcastId, string $peer): int
    {
        $metadata = $this->loadBroadcastMetadata($broadcastId);
        $peerData = $metadata['peers'][$peer] ?? null;

        if (!is_array($peerData)) {
            return 0;
        }

        $lastMessageId = (int) ($peerData['lastMessageId'] ?? 0);
        if ($lastMessageId > 0) {
            return $lastMessageId;
        }

        $messageIds = array_values(array_filter(
            array_map('intval', $peerData['messageIds'] ?? []),
            static fn (int $id): bool => $id > 0
        ));

        return $messageIds ? (int) end($messageIds) : 0;
    }

    private function broadcastMetadataPeers(string $broadcastId): array
    {
        $metadata = $this->loadBroadcastMetadata($broadcastId);

        if (!isset($metadata['peers']) || !is_array($metadata['peers'])) {
            return [];
        }

        return array_values(array_map('strval', array_keys($metadata['peers'])));
    }

    private function markBroadcastPeerMessageDeleted(string $broadcastId, string $peer, int $messageId): void
    {
        $metadata = $this->loadBroadcastMetadata($broadcastId);
        $peerData = $metadata['peers'][$peer] ?? null;

        if (!is_array($peerData)) {
            return;
        }

        $messageIds = array_values(array_filter(
            array_map('intval', $peerData['messageIds'] ?? []),
            static fn (int $id): bool => $id > 0 && $id !== $messageId
        ));

        $metadata['peers'][$peer]['messageIds'] = $messageIds;
        $metadata['peers'][$peer]['lastMessageId'] = $messageIds ? end($messageIds) : null;
        $metadata['peers'][$peer]['status'] = $messageIds ? 'partial' : 'deleted';

        $this->saveBroadcastMetadata($broadcastId, $metadata);
    }

    private function peerDataPath(string $peer, string $file): string
    {
        return self::getDataDir() . '/' . $peer . '/' . $file;
    }

    private function broadcastMetadataPath(string $broadcastId): string
    {
        return self::getDataDir() . '/broadcasts/' . $broadcastId . '.json';
    }

    private function scheduledBroadcastsPath(): string
    {
        return self::getDataDir() . '/scheduled-broadcasts.json';
    }

    private function selfDestructJobsPath(): string
    {
        return self::getDataDir() . '/self-destruct-jobs.json';
    }

    private function loadScheduledBroadcasts(): array
    {
        return $this->readJsonFile($this->scheduledBroadcastsPath(), []);
    }

    private function saveScheduledBroadcasts(array $jobs): void
    {
        $this->writeJsonFileAtomic($this->scheduledBroadcastsPath(), $jobs);
    }

    private function loadSelfDestructJobs(): array
    {
        return $this->readJsonFile($this->selfDestructJobsPath(), []);
    }

    private function saveSelfDestructJobs(array $jobs): void
    {
        $this->writeJsonFileAtomic($this->selfDestructJobsPath(), $jobs);
    }

    private function validateSelfDestructHours(?int $hours): void
    {
        if ($hours === null) {
            return;
        }

        if ($hours < 0 || $hours > 48) {
            throw new InvalidArgumentException('selfDestructHours must be null or an integer between 0 and 48.');
        }
    }

    private function normalizeOptionalId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $id = trim($id);

        return $id === '' ? null : $id;
    }

    private function assertJsonEncodable(mixed $value, string $name): void
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException($name . ' must be JSON encodable: ' . $e->getMessage(), 0, $e);
        }
    }

    private function createId(string $prefix = ''): string
    {
        $id = bin2hex(random_bytes(8));

        return $prefix === '' ? $id : $prefix . '_' . $id;
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create directory: ' . $dir);
        }
    }

    private function deleteFile(string $file): void
    {
        try {
            if (is_file($file)) {
                @unlink($file);
            }
        } catch (Throwable $e) {
            $this->logError('Failed to delete file.', $e, ['file' => $file]);
        }
    }
}
