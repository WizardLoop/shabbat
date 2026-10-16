<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Scheduling;

use Amp\CancelledException;
use Closure;
use Shabbat\Calendar\CalendarService;
use Shabbat\Runtime\ManualGroups;
use Shabbat\Storage\DeletionRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Telegram\MessageMaintenanceGateway;
use Shabbat\Telegram\RetryableTelegramException;
use Shabbat\Telegram\TelegramGateway;
use Shabbat\Time\Clock;

/** Incoming moderation and independent, durable post-delivery cleanup. */
final class MessageCleanup
{
    private bool $running = false;
    private bool $stopping = false;
    private readonly Closure $shutdown;

    public function __construct(
        private readonly DeletionRepository $deletions,
        private readonly SettingsRepository $settings,
        private readonly TelegramGateway $gateway,
        private readonly CalendarService $calendar,
        private readonly Clock $clock,
        ?callable $shutdown = null
    ) {
        $this->shutdown = Closure::fromCallable($shutdown ?? static fn(): bool => false);
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    /** Queue received ordinary messages only; never sweep group history. */
    public function receive(string $group, int $messageId, int $sentAt, string $sender, bool $outgoing = false): bool
    {
        if (
            $this->stopped() || !$this->gateway instanceof MessageMaintenanceGateway || $outgoing || $messageId < 1
            || !preg_match('/^-[1-9][0-9]*$/D', $group) || !preg_match('/^[1-9][0-9]*$/D', $sender)
        ) {
            return false;
        }
        $settings = $this->settings->getGroup($group);
        if (!$this->moderationEnabled($settings) || $sender === $this->gateway->selfUserId()) {
            return false;
        }
        $event = $this->holyEvent($settings, $sentAt, $this->clock->now());
        if ($event === null) {
            return false;
        }
        $bot = $this->gateway->senderIsBot($group, $sender);
        if ($bot === null || !$settings[$bot ? 'delete_holy_bot_messages' : 'delete_holy_user_messages']) {
            return false;
        }
        $latest = $this->settings->getGroup($group);
        $now = $this->clock->now()->getTimestamp();
        if ($this->stopped() || $latest === null || $latest['revision'] !== $settings['revision'] || $now >= $event['end_at']) {
            return false;
        }
        $this->deletions->enqueue($group, $messageId, 'holy_message', [
            'original_timestamp' => $sentAt, 'sender_id' => $sender, 'sender_is_bot' => $bot,
            'sender_is_self' => false, 'settings_revision' => $settings['revision'],
        ], $now, $event['end_at']);
        return true;
    }

    public function runOnce(string $owner): ?array
    {
        if ($this->running || $this->stopped() || !$this->gateway instanceof MessageMaintenanceGateway) {
            return null;
        }
        $this->running = true;
        try {
            return $this->execute($owner);
        } finally {
            $this->running = false;
        }
    }

    private function execute(string $owner): ?array
    {
        $job = $this->deletions->claim($owner);
        if ($job === null) {
            return null;
        }
        $id = $job['deletion_id'];
        try {
            $settings = $this->settings->getGroup($job['group_id']);
            if (!$this->allowed($job, $settings)) {
                return $this->discard($id, $owner, 'Cleanup preference, period or current message changed.');
            }
            if (!$this->gateway instanceof MessageMaintenanceGateway || !$this->gateway->botCanDeleteMessages($job['group_id'])) {
                throw new \RuntimeException('Verified delete_messages capability is required.');
            }
            if ($job['purpose'] === 'holy_message' && !Permissions::alreadyLocked($this->gateway->getPermissions($job['group_id']))) {
                return $this->discard($id, $owner, 'Group is actually open.');
            }
            // Native reads may yield: check preferences, holy period and lease before the deletion request.
            $latest = $this->settings->getGroup($job['group_id']);
            if (
                $this->stopped() || $latest === null || $settings === null || $latest['revision'] !== $settings['revision']
                || !$this->allowed($job, $latest) || !$this->deletions->owns($id, $owner)
            ) {
                return $this->discard($id, $owner, 'Cleanup changed before deletion.');
            }
            // Calendar eligibility may await a provider. This final guard performs no network work.
            $current = $this->settings->getGroup($job['group_id']);
            if (
                $this->stopped() || $current === null || $current['revision'] !== $latest['revision']
                || !$this->deletions->owns($id, $owner)
                || ($job['expires_at'] !== null && $this->clock->now()->getTimestamp() >= $job['expires_at'])
            ) {
                return $this->discard($id, $owner, 'Cleanup cutoff or ownership changed.');
            }
            $this->gateway->deleteMessages($job['group_id'], [$job['message_id']]);
            $this->deletions->complete($id, $owner);
            return ['status' => 'deleted', 'deletion_id' => $id];
        } catch (CancelledException $error) {
            if ($this->deletions->owns($id, $owner)) {
                $this->deletions->retry($id, $owner, 'Cleanup interrupted by cancellation.', 30);
            }
            throw $error;
        } catch (RetryableTelegramException $error) {
            if ($this->deletions->owns($id, $owner)) {
                $this->deletions->retry($id, $owner, $error->getMessage(), $error->retryAfterSeconds);
            }
            return ['status' => 'retry', 'deletion_id' => $id];
        } catch (\Throwable $error) {
            if ($this->deletions->owns($id, $owner)) {
                $this->deletions->retry($id, $owner, $error->getMessage(), min(300, 30 * (2 ** min(3, $job['attempts'] - 1))));
            }
            return ['status' => 'retry', 'deletion_id' => $id];
        }
    }

    private function discard(int $id, string $owner, string $reason): array
    {
        $row = $this->deletions->get($id);
        if (
            $row === null || $row['status'] !== 'leased' || $row['lease_owner'] !== $owner
            || $row['lease_until'] <= $this->clock->now()->getTimestamp()
        ) {
            // The next bounded claim expires/reclaims the row; an expired worker writes no success or cancellation.
            return ['status' => 'lease_lost', 'deletion_id' => $id];
        }
        $this->deletions->cancel($id, $owner, $reason);
        return ['status' => 'cancelled', 'deletion_id' => $id];
    }
    private function allowed(array $job, ?array $settings): bool
    {
        if ($settings === null || !$settings['active']) {
            return false;
        }
        if ($job['purpose'] === 'tefillin_previous') {
            return $settings['tefillin_enabled'] && $settings['tefillin_delete_previous'] && $job['sender_is_self']
                && !in_array($job['message_id'], $this->deletions->getLastModuleMessages($job['group_id'], 'tefillin'), true);
        }
        if (
            $job['purpose'] !== 'holy_message' || !$this->moderationEnabled($settings) || $job['sender_is_self']
            || $settings['revision'] !== $job['settings_revision']
            || !$settings[$job['sender_is_bot'] ? 'delete_holy_bot_messages' : 'delete_holy_user_messages']
        ) {
            return false;
        }
        return $this->holyEvent($settings, $job['original_timestamp'], $this->clock->now()) !== null;
    }

    private function moderationEnabled(?array $settings): bool
    {
        return $settings !== null && $settings['active'] && in_array($settings['mode'], ['lock', 'lock_messages'], true)
            && ($settings['delete_holy_user_messages'] || $settings['delete_holy_bot_messages']);
    }

    private function holyEvent(array $settings, int $sentAt, \DateTimeImmutable $instant): ?array
    {
        $now = $instant->getTimestamp();
        if ($sentAt <= 0 || $sentAt > $now) {
            return null;
        }
        $state = $this->calendar->snapshot($settings, $instant);
        if (!$state['is_work_prohibited_now'] || !ManualGroups::desired($settings, $state['should_group_be_locked'], $now)) {
            return null;
        }
        foreach ($state['operational_events'] as $event) {
            if ($sentAt >= $event['start_at'] && $sentAt < $event['end_at'] && $now >= $event['start_at'] && $now < $event['end_at']) {
                return $event;
            }
        }
        return null;
    }

    /** @phpstan-impure Shutdown may be requested during awaited Telegram/provider work. */
    private function stopped(): bool
    {
        return $this->stopping || ($this->shutdown)();
    }
}
