<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Scheduling;

use Closure;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Telegram\TelegramGateway;
use Shabbat\Telegram\RetryableTelegramException;
use Shabbat\Time\Clock;

final class OutboxWorker
{
    private bool $stopping = false;
    private bool $running = false;
    private readonly Closure $eligibility;
    private readonly ?Closure $payloadFactory;
    private readonly ?Closure $afterDelivery;
    private readonly ?Closure $dispatchEligibility;
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly SettingsRepository $settings,
        private readonly TelegramGateway $gateway,
        private readonly Clock $clock,
        callable $eligibility,
        ?callable $payloadFactory = null,
        ?callable $afterDelivery = null,
        ?callable $dispatchEligibility = null
    ) {
        $this->eligibility = Closure::fromCallable($eligibility);
        $this->payloadFactory = $payloadFactory === null ? null : Closure::fromCallable($payloadFactory);
        $this->afterDelivery = $afterDelivery === null ? null : Closure::fromCallable($afterDelivery);
        $this->dispatchEligibility = $dispatchEligibility === null ? null : Closure::fromCallable($dispatchEligibility);
    }
    public function stop(): void
    {
        $this->stopping = true;
    }
    public function runOnce(string $owner): ?array
    {
        if ($this->running || $this->stopping) {
            return null;
        }
        $this->running = true;
        try {
            return $this->executeOnce($owner);
        } finally {
            $this->running = false;
        }
    }
    private function executeOnce(string $owner): ?array
    {
        if ($this->stopping) {
            return null;
        }
        $job = $this->jobs->lease($owner);
        if ($job === null) {
            return null;
        }
        try {
            $settings = $this->settings->getGroup($job['group_id']);
            if (!$this->allowed($job, $settings) || !($this->eligibility)($job, $settings, $this->clock->now())) {
                $this->jobs->cancel($job['job_id'], $owner, 'delivery no longer eligible');
                return ['status' => 'cancelled','job_id' => $job['job_id']];
            }
            if ($this->payloadFactory !== null && $job['payload_prepared_at'] === null) {
                // Remote/provider work is outside the persistence transaction. A durable final payload is never regenerated after uncertainty.
                $prepared = ($this->payloadFactory)($job, $settings, $this->clock->now());
                if (!is_array($prepared)) {
                    throw new \RuntimeException('Delivery payload factory must return an array.');
                }
                $this->jobs->preparePayload($job['job_id'], $owner, $prepared);
                $job = $this->jobs->get($job['job_id']) ?? throw new \RuntimeException('Prepared job disappeared.');
            }
            if ($job['module'] === 'daf' && $job['payload_prepared_at'] !== null && $this->jobs->repairPreparedDafPayload($job['job_id'], $owner)) {
                $job = $this->jobs->get($job['job_id']) ?? throw new \RuntimeException('Repaired Daf job disappeared.');
            }
            // Re-read revisions/time after eligibility work and immediately before the request.
            $settings = $this->settings->getGroup($job['group_id']);
            $stillEligible = $settings !== null && ($this->eligibility)($job, $settings, $this->clock->now());
            // Eligibility may itself await provider I/O; no yielding work follows the final cutoff/revision guard.
            $settings = $this->settings->getGroup($job['group_id']);
            if (!$stillEligible || $this->stopping || !$this->allowed($job, $settings) || !$this->jobs->owns($job['job_id'], $owner)) {
                $this->jobs->cancel($job['job_id'], $owner, 'delivery cutoff/revision/ownership changed');
                return ['status' => 'cancelled','job_id' => $job['job_id']];
            }
            // Native preflight (emoji catalog, forum topic, reusable media) can await.
            // Pass the fence down to the actual send boundary, never store it on a shared gateway.
            $guard = function () use ($job, $owner): void {
                $current = $this->settings->getGroup($job['group_id']);
                if ($this->stopping || !$this->allowed($job, $current) || !$this->jobs->owns($job['job_id'], $owner)) {
                    throw new DispatchRejectedException('delivery cutoff/revision/ownership changed before transport');
                }
                if ($this->dispatchEligibility !== null && !($this->dispatchEligibility)($job, $current, $this->clock->now())) {
                    throw new DispatchRejectedException('delivery evidence no longer permits transport');
                }
                if (!$this->jobs->guardDelivery($job, $current, $owner)) {
                    throw new DispatchRejectedException('delivery rate/window no longer permits transport');
                }
                $this->jobs->markDispatch($job['job_id'], $owner);
                // Local SQLite writes can consume wall-clock time even without a Fiber yield.
                // Recheck the religious/time evidence after persistence too; a rejected
                // reservation remains conservative evidence, never an unattempted job.
                $current = $this->settings->getGroup($job['group_id']);
                if (
                    $this->stopping || !$this->allowed($job, $current)
                    || ($this->dispatchEligibility !== null && !($this->dispatchEligibility)($job, $current, $this->clock->now()))
                ) {
                    throw new DispatchRejectedException('delivery boundary changed during dispatch persistence');
                }
            };
            if ($this->gateway instanceof \Shabbat\Telegram\GuardedTelegramGateway) {
                $response = $this->gateway->sendGuarded($job['group_id'], $job['payload'], $job['random_id'], $guard);
            } else {
                // Compatibility for synchronous in-process gateways without asynchronous preflight.
                $guard();
                $response = $this->gateway->send($job['group_id'], $job['payload'], $job['random_id']);
            }
            $this->jobs->sent($job['job_id'], $owner, $response);
            $result = ['status' => 'sent','job_id' => $job['job_id'],'response' => $response];
            if ($this->afterDelivery !== null) {
                try {
                    ($this->afterDelivery)($job, $response);
                } catch (\Amp\CancelledException $error) {
                    throw $error;
                } catch (\Throwable) {
                    // A confirmed send stays sent. Durable bookkeeping is replayed independently after restart.
                    $result['cleanup_pending'] = true;
                }
            }
            return $result;
        } catch (\Amp\CancelledException $error) {
            if ($this->jobs->owns($job['job_id'], $owner)) {
                $this->jobs->retry($job['job_id'], $owner, 'operation cancelled; worker stopped', 1);
            }
            throw $error;
        } catch (DispatchRejectedException $error) {
            if ($this->jobs->owns($job['job_id'], $owner)) {
                $this->jobs->cancel($job['job_id'], $owner, $error->getMessage());
            }
            return ['status' => 'cancelled', 'job_id' => $job['job_id'], 'dispatch_rejected' => true];
        } catch (DeliveryDeferredException $error) {
            if ($this->jobs->owns($job['job_id'], $owner)) {
                $this->jobs->defer($job['job_id'], $owner, $error->getMessage(), $error->retryAt);
            }
            return ['status' => 'deferred', 'job_id' => $job['job_id']];
        } catch (RetryableTelegramException $error) {
            $this->jobs->retry($job['job_id'], $owner, $error->getMessage(), $error->retryAfterSeconds);
            return ['status' => 'retry','job_id' => $job['job_id'],'error' => $error->getMessage()];
        } catch (\Throwable $error) {
            // An unknown transport error may follow successful acceptance. The next try reuses random_id.
            if ($this->jobs->owns($job['job_id'], $owner)) {
                $this->jobs->retry($job['job_id'], $owner, $error->getMessage(), min(300, 30 * (2 ** min(3, max(0, $job['attempts'] - $job['deferrals'] - 1)))));
            }
            return ['status' => 'retry','job_id' => $job['job_id'],'error' => $error->getMessage()];
        }
    }
    private function allowed(array $job, ?array $settings): bool
    {
        if ($settings === null || !$settings['active'] || $settings['revision'] !== $job['settings_revision'] || $this->clock->now()->getTimestamp() >= $job['expires_at']) {
            return false;
        }
        $field = match ($job['module']) {
            'zmanim'=>'zmanim_enabled','daf'=>'daf_enabled','tefillin'=>'tefillin_enabled','omer','omer_day'=>'omer_enabled',
            'times','shabbat_times','times_alert'=>'times_alert_enabled','close'=>'close_message_enabled','open'=>'open_message_enabled',
            default=>null
        };
        if ($field === null || !$settings[$field]) {
            return false;
        }
        if ($job['module'] === 'omer_day' && !$settings['omer_day_completion']) {
            return false;
        }
        if (in_array($job['module'], ['close','open'], true) && !in_array($settings['mode'], ['messages','lock_messages'], true)) {
            return false;
        }
        return true;
    }
}
