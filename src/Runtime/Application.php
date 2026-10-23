<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Runtime;

use Shabbat\Storage\{Database,SettingsRepository,FlowTokenRepository,TemplateRepository,LegacyImporter};
use Shabbat\Scheduling\{JobRepository,OutboxWorker,LockCoordinator,MessageCleanup};
use Shabbat\Time\{Clock,SystemClock,LocalTime};
use Shabbat\Http\{AmpJsonClient,SharedJsonClient,SqliteJsonClient};
use Shabbat\Calendar\{CalendarService,HebcalProvider};
use Shabbat\Location\GeoNames;
use Shabbat\UI\BotController;
use Shabbat\UI\Lang;
use Shabbat\Notifications\ReminderPlanner;
use Shabbat\Content\{DafFormatter,OmerText,TemplateRenderer,ShabbatTimesFormatter};

final class Application
{
    public readonly Database $db;
    public readonly SettingsRepository $settings;
    public readonly FlowTokenRepository $flows;
    public readonly TemplateRepository $templates;
    public readonly CalendarService $calendar;
    public readonly BotController $ui;
    public readonly LockCoordinator $locks;
    public readonly \Shabbat\Scheduling\PermissionRecovery $permissionRecovery;
    public readonly ManualGroups $manualGroups;
    public readonly ?Donations $donations;
    public readonly ?\Shabbat\UI\DonationController $donationUi;
    public readonly \Shabbat\Storage\AdminAccessRepository $adminAccess;
    public readonly BroadcastJobs $broadcastJobs;
    public readonly \Shabbat\Scheduling\TefillinAcknowledgements $tefillinAcknowledgements;
    public readonly \Shabbat\Storage\SubscriberDirectory $subscribers;
    private JobRepository $jobs;
    private OutboxWorker $worker;
    private ReminderPlanner $planner;
    public readonly \Shabbat\Storage\DeletionRepository $deletions;
    public readonly MessageCleanup $cleanup;
    private array $recoveryWindows = [];
    private array $permissionWindows = [];
    private bool $ticking = false;
    private bool $notifying = false;
    private bool $stopped = false;
    private ?string $planningCursor = null;
    private array $dispatchWindows = [];
    private int $lastMaintenance = 0;
    private ?int $permissionHeartbeat = null;
    private ?int $notificationHeartbeat = null;
    private array $cycleMetrics = [];
    /** @var \Amp\DeferredFuture<null>|null */
    private ?\Amp\DeferredFuture $tickCompletion = null;
    private string $owner;
    private ?\Closure $buttonParser;
    public function __construct(public readonly \Shabbat\Telegram\TelegramGateway&\Shabbat\UI\UiGateway $gateway, public readonly Clock $clock = new SystemClock(), ?Database $database = null, ?CalendarService $calendar = null, ?GeoNames $geo = null, ?callable $buttonParser = null, ?callable $rpc = null, ?callable $authorizeOperator = null, ?callable $configuredOwners = null)
    {
        $this->buttonParser = $buttonParser === null ? null : \Closure::fromCallable($buttonParser);
        $path = RuntimePaths::resolve(dirname(__DIR__, 2))->databasePath;
        if ($database === null && !is_file($path)) {
            throw new \RuntimeException('Create the application schema with bin/migrate.php before starting the bot.');
        }
        $this->db = $database ?? Database::open($path);
        $this->db->assertCurrentSchema();
        $this->adminAccess = new \Shabbat\Storage\AdminAccessRepository($this->db, $clock, $configuredOwners ?? static fn(): array => []);
        $this->subscribers = new \Shabbat\Storage\SubscriberDirectory($this->db, $clock);
        $this->broadcastJobs = new BroadcastJobs($this->db, $clock, $this->adminAccess);
        $operatorAccess = $configuredOwners !== null ? $this->adminAccess->isAdmin(...) : ($authorizeOperator ?? static fn(string $user): bool => false);
        $groupOperations = $configuredOwners !== null ? fn(string $user): bool => $this->adminAccess->canMutate($user, 'group_operations') : $operatorAccess;
        \Shabbat\Storage\AdminStateFiles::configure($this->db, $clock, dirname(__DIR__, 2));
        $this->settings = new SettingsRepository($this->db, $clock);
        $this->flows = new FlowTokenRepository($this->db, $clock);
        $this->templates = new TemplateRepository($this->db, $clock);
        $this->tefillinAcknowledgements = new \Shabbat\Scheduling\TefillinAcknowledgements($this->db, $this->settings, $clock, $gateway instanceof \Shabbat\Telegram\MessageMaintenanceGateway ? $gateway->selfUserId() : null);
        // Additive registration only; rich settings/snapshots need the explicit maintenance importer.
        if ($database === null) {
            (new LegacyImporter($this->db, $clock))->importGroupListAutomatically(getenv('LEGACY_DATA_PATH') ?: dirname(__DIR__, 2));
        }
        $http = new SharedJsonClient(new SqliteJsonClient(new \Shabbat\Http\ProviderCircuitClient(new AmpJsonClient(), $clock), $this->db, $clock), $clock);
        $this->calendar = $calendar ?? new CalendarService(new HebcalProvider($http), $clock);
        $this->locks = new LockCoordinator($this->db, $clock, $gateway, $this->freshPermissionDesire(...), $this->permissionDesireStillCurrent(...));
        $this->permissionRecovery = new \Shabbat\Scheduling\PermissionRecovery(
            $this->db,
            $clock,
            $gateway,
            $this->locks,
            $gateway->isGroupAdmin(...),
            $this->permissionRecoveryAllowed(...),
            $this->permissionRecoveryStillAllowed(...)
        );
        $this->ui = new BotController($this->settings, $this->flows, $this->calendar, $gateway, $geo ?? new GeoNames($http, $clock, getenv('GEONAMES_USERNAME') ?: '', getenv('GEONAMES_ENDPOINT') ?: 'https://secure.geonames.org'), $clock, authorizeOperator: $operatorAccess, permissionRecovery: $this->permissionRecovery);
        $this->manualGroups = new ManualGroups($this->db, $this->settings, $this->locks, $clock, $groupOperations);
        $this->donations = $rpc === null ? null : new Donations($this->db, $clock, $rpc);
        $this->donationUi = $this->donations === null ? null : new \Shabbat\UI\DonationController($this->db, $this->settings, $this->flows, $clock, $this->donations, $gateway);
        $this->jobs = new JobRepository($this->db, $clock);
        $this->deletions = new \Shabbat\Storage\DeletionRepository($this->db, $clock);
        $this->cleanup = new MessageCleanup($this->deletions, $this->settings, $gateway, $this->calendar, $clock, Runner::isStopping(...));
        $this->worker = new OutboxWorker($this->jobs, $this->settings, $gateway, $clock, $this->eligible(...), $this->preparePayload(...), $this->recordDelivered(...), $this->dispatchEligible(...));
        $this->planner = new ReminderPlanner();
        $this->owner = bin2hex(random_bytes(16));
    }
    public function parseCustomButtons(string $input): array
    {
        if ($this->buttonParser === null) {
            throw new \InvalidArgumentException('Use a JSON array of button rows in this installation.');
        }
        $markup = ($this->buttonParser)($input);
        $rows = [];
        if (!is_array($markup) || ($markup['_'] ?? null) !== 'replyInlineMarkup') {
            throw new \InvalidArgumentException('Invalid button text.');
        }
        foreach ($markup['rows'] ?? [] as $row) {
            $buttons = [];foreach ($row['buttons'] ?? [] as $button) {
                $normalized = ['text' => $button['text']];
                if (isset($button['url'])) {
                    $normalized['url'] = $button['url'];
                } elseif (isset($button['data'])) {
                    $normalized['callback_data'] = $button['data'];
                } elseif (isset($button['copy_text'])) {
                    $normalized['copy_text'] = $button['copy_text'];
                } else {
                    // Current typed and other recognized native actions retain all fields.
                    $normalized = $button;
                }
                if (isset($button['style'])) {
                    $normalized['style'] = $button['style'];
                }$buttons[] = $normalized;
            }if ($buttons !== []) {
                $rows[] = $buttons;
            }
        }
        if ($rows === []) {
            throw new \InvalidArgumentException('No valid buttons found.');
        }TemplateRenderer::validateButtons($rows);
        return $rows;
    }
    /** Wait for in-flight permission work before preparing an operator broadcast. */
    public function prioritizePermissions(?\Amp\Cancellation $cancellation = null): bool
    {
        $cancellation?->throwIfRequested();
        while ($this->tickCompletion !== null) {
            if (Runner::isStopping()) {
                return false;
            }
            $this->tickCompletion->getFuture()->await($cancellation);
        }
        if (Runner::isStopping()) {
            return false;
        }
        $cancellation?->throwIfRequested();
        $this->permissionTick();
        return !Runner::isStopping() && !$this->stopped;
    }

    /** Compatibility entry point. Production runs the two independent cron callbacks. */
    public function tick(): void
    {
        $this->permissionTick();
        $this->notificationTick();
    }

    public function permissionTick(): void
    {
        if ($this->ticking || $this->isStopping()) {
            return;
        }
        $this->ticking = true;
        $started = hrtime(true);
        $count = 0;
        $completion = new \Amp\DeferredFuture();
        $completion->getFuture()->ignore();
        $this->tickCompletion = $completion;
        try {
            $cursor = null;
            $page = [];
            $offset = 0;
            $exhausted = false;
            $halted = new \Amp\DeferredCancellation();
            // Claim one peer synchronously. This shared cursor never yields, so each
            // peer is claimed once while four independent workers keep their slots busy.
            $claim = function () use (&$cursor, &$page, &$offset, &$exhausted, $halted, &$count): ?array {
                if ($halted->isCancelled() || $this->isStopping()) {
                    return null;
                }
                if ($offset >= count($page)) {
                    if ($exhausted) {
                        return null;
                    }
                    $page = $this->settings->groupsAfter($cursor, 100);
                    $offset = 0;
                    $exhausted = count($page) < 100;
                    if ($page === []) {
                        return null;
                    }
                }
                $group = $page[$offset++];
                $cursor = $group['group_id'];
                $count++;
                return $group;
            };
            $futures = [];
            for ($worker = 0; $worker < 4; $worker++) {
                $futures[] = \Amp\async(function () use ($claim, $halted): void {
                    try {
                        while (($group = $claim()) !== null) {
                            $this->reconcileGroup($group);
                            \Amp\delay(0); // Cooperate even when every cached operation completed synchronously.
                        }
                    } catch (\Throwable $error) {
                        $halted->cancel($error);
                        throw $error;
                    }
                });
            }
            // Join every worker even on cancellation. A blocked peer consumes only
            // its own slot; other slots continue across page boundaries.
            [$errors] = \Amp\Future\awaitAll($futures);
            if ($errors !== []) {
                throw reset($errors);
            }
            if ($this->isStopping()) {
                return;
            }
            $this->permissionHeartbeat = $this->clock->now()->getTimestamp();
            $this->cycleMetrics['permissions'] = ['groups' => $count, 'duration_ms' => (int) ((hrtime(true) - $started) / 1000000)];
            $this->writeHeartbeat();
        } catch (\Throwable $error) {
            $completion->error($error);
            throw $error;
        } finally {
            $this->ticking = false;
            $this->tickCompletion = null;
            if (!$completion->isComplete()) {
                $completion->complete();
            }
        }
    }

    private function reconcileGroup(array $group): void
    {
        if ($this->isStopping()) {
            return;
        }
        try {
            $now = $this->clock->now();
            $manual = in_array($group['manual_override_mode'], ['lock', 'open'], true)
                && $group['manual_override_until'] > $now->getTimestamp();
            $state = $manual ? null : $this->calendar->snapshotNow($group);
            $now = $this->clock->now();
            $event = $state['lock_event'] ?? $state['current_event'] ?? $state['next_event'] ?? null;
            $eventKey = $manual ? 'manual:' . $group['revision'] . ':' . $group['manual_override_until'] : ($event['event_key'] ?? 'ordinary');
            $previousLock = $this->locks->state($group['group_id']);
            $result = $this->locks->reconcile($group['group_id'], $eventKey, ManualGroups::desired($group, $state['should_group_be_locked'] ?? false, $now->getTimestamp()));
            if ($result['changed']) {
                $kind = $result['status'] === 'locked' ? 'close' : 'open';
                $stamp = $this->clock->now()->getTimestamp();
                // Rendering may fetch data. Never delay the next permission change for it.
                $this->jobs->enqueue($group['group_id'], $kind, $kind . ':' . ($kind === 'open' ? ($previousLock['event_key'] ?? 'recovery') : ($event['event_key'] ?? $now->format('Y-m-d'))), $stamp, $stamp + 3600, $group['revision'], ['text' => ''], 1);
            }
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->recordError($group['group_id'], 'scheduler', $error);
        }
    }

    public function notificationTick(): void
    {
        if ($this->notifying || $this->isStopping()) {
            return;
        }
        $this->notifying = true;
        $started = hrtime(true);
        $planned = 0;
        $processed = 0;
        try {
            $groups = $this->settings->groupsAfter($this->planningCursor, 20);
            if ($groups === [] && $this->planningCursor !== null) {
                $this->planningCursor = null;
                $groups = $this->settings->groupsAfter(null, 20);
            }
            foreach ($groups as $group) {
                if ($this->isStopping() || ($planned > 0 && hrtime(true) - $started >= 2000000000)) {
                    break;
                }
                $this->planningCursor = $group['group_id'];
                $planned++;
                if (!$this->needsPlanning($group)) {
                    continue;
                }
                try {
                    $state = $this->calendar->snapshotNow($group);
                    $this->plan($group, $state, new \DateTimeImmutable('@' . $state['evaluated_at']));
                } catch (\Amp\CancelledException $error) {
                    throw $error;
                } catch (\Throwable $error) {
                    $this->recordError($group['group_id'], 'planning', $error);
                }
            }
            $this->replayDeliveryBookkeeping();
            $deliveryStarted = hrtime(true);
            for ($count = 0; $count < 20 && !$this->stopped && !Runner::isStopping(); $count++) {
                if ($count > 0 && hrtime(true) - $deliveryStarted >= 2000000000) {
                    break;
                }
                if ($this->worker->runOnce($this->owner) === null) {
                    break;
                }
                $processed++;
            }
            for ($count = 0; $count < 20 && !$this->stopped && !Runner::isStopping(); $count++) {
                if ($count > 0 && hrtime(true) - $deliveryStarted >= 3000000000) {
                    break;
                }
                if ($this->cleanup->runOnce($this->owner) === null) {
                    break;
                }
            }
            $now = $this->clock->now()->getTimestamp();
            if ($now < $this->lastMaintenance || $now - $this->lastMaintenance >= 60) {
                $this->jobs->maintain(90, 200);
                $this->lastMaintenance = $now;
            }
            $this->notificationHeartbeat = $now;
            $this->cycleMetrics['notifications'] = ['groups_planned' => $planned, 'jobs_processed' => $processed, 'duration_ms' => (int) ((hrtime(true) - $started) / 1000000)];
            $this->writeHeartbeat();
        } finally {
            $this->notifying = false;
        }
    }

    private function needsPlanning(array $group): bool
    {
        return $group['zmanim_enabled'] || $group['daf_enabled'] || $group['tefillin_enabled']
            || $group['omer_enabled'] || $group['times_alert_enabled']
            || ($group['mode'] === 'messages' && ($group['close_message_enabled'] || $group['open_message_enabled']));
    }

    private function writeHeartbeat(): void
    {
        $heartbeat = RuntimePaths::resolve(dirname(__DIR__, 2))->heartbeatPath;
        $data = ['timestamp' => $this->permissionHeartbeat, 'permission_timestamp' => $this->permissionHeartbeat,
            'notification_timestamp' => $this->notificationHeartbeat, 'cycles' => $this->cycleMetrics,
            'version' => Version::current(), 'commit' => Version::commit()];
        $temporary = $heartbeat . '.' . $this->owner . '.tmp';
        if (file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($temporary, $heartbeat)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new \RuntimeException('Cannot persist scheduler heartbeat.');
        }
    }
    private function plan(array $g, array $state, \DateTimeImmutable $now): void
    {
        $zone = new \DateTimeZone($g['timezone_id']);
        $local = $now->setTimezone($zone);
        foreach (($g['zmanim_enabled'] || $g['daf_enabled'] || $g['tefillin_enabled'] || $g['omer_enabled']) ? [-1,0,1] : [] as $offset) {
            $date = $local->modify('+' . $offset . ' day')->format('Y-m-d');
            $sun = $this->calendar->zmanim($g, $date)['times'];
            $midday = new \DateTimeImmutable($date . ' 12:00', $zone);
            $dayState = $this->calendar->snapshot($g, $midday);
            if ($offset >= 0) {
                foreach (['zmanim','daf'] as $kind) {
                    $rule = ['enabled' => $g[$kind . '_enabled'],'time' => $g[$kind . '_time'],'weekdays' => $this->weekdays($g[$kind . '_days']),'quiet_start' => $g['quiet_start'],'quiet_end' => $g['quiet_end']];
                    foreach ($this->planner->daily($g, $rule, $date, $kind, $dayState) as $job) {
                        $this->enqueue($g, $job);
                    }
                }
                $rule = ['enabled' => $g['tefillin_enabled'],'weekdays' => $this->weekdays($g['tefillin_days']),'times' => $g['tefillin_fixed_times'],'interval_minutes' => $g['tefillin_interval_minutes'] ?? 0,'window_start' => $g['tefillin_window_start'],'window_end' => $g['tefillin_window_end'],'before_sunset' => $g['tefillin_sunset_offsets'],'max_per_day' => $g['tefillin_max_per_day'],'min_gap_minutes' => $g['tefillin_min_spacing_minutes'],'quiet_start' => $g['quiet_start'],'quiet_end' => $g['quiet_end'],'pause_date' => $g['tefillin_suspended_date'],'chol_hamoed' => $g['tefillin_chol_hamoed'],'tisha_bav' => $g['tefillin_tisha_bav_policy'],'sunset_safety_minutes' => $g['tefillin_sunset_safety_minutes']];
                $tefillin = $this->planner->tefillin($g, $rule, $date, $sun, $dayState);
            // At most one still-valid late reminder after a restart; never replay an entire missed series.
                $past = array_values(array_filter($tefillin, fn(array $j): bool=>$j['run_at'] < $now->getTimestamp()));
                $lastPast = $past === [] ? null : end($past)['event_key'];
                foreach ($tefillin as $job) {
                    if ($job['run_at'] < $now->getTimestamp() && $job['event_key'] !== $lastPast) {
                                continue;
                    }$this->enqueue($g, $job);
                }
            }
            $omerRule = ['enabled' => $g['omer_enabled'],'schedule' => $g['omer_mode'] === 'fixed' ? 'fixed' : 'relative','time' => $g['omer_time'],'after_tzeit_minutes' => $g['omer_after_nightfall_minutes'],'tzeit_method' => $g['omer_nightfall_method'],'tzeit_value' => $g['omer_nightfall_value'],'early_policy' => $g['omer_early_policy'] === 'skip' ? 'skip' : 'delay','after_open_minutes' => $g['omer_after_open_minutes'],'quiet_start' => $g['quiet_start'],'quiet_end' => $g['quiet_end']];
            foreach ($this->planner->omer($g, $omerRule, $date, $sun, $dayState) as $job) {
                $this->enqueue($g, $job);
            }
        }
        if ($g['omer_enabled'] && $g['omer_day_completion']) {
            $o = $this->calendar->omer($g, $now);
            $date = $local->format('Y-m-d');
            $at = LocalTime::resolve($date, '10:00', $g['timezone_id']);
            if ($at !== null && $o['season']) {
                $sun = $this->calendar->zmanim($g, $date)['times'];
                if (isset($sun['sunset']) && $at < $sun['sunset']) {
                    $this->enqueue($g, ['kind' => 'omer_day','run_at' => $at,'expires_at' => $sun['sunset'],'event_key' => 'omer-day:' . $date]);
                }
            }
        }
        if ($g['times_alert_enabled']) {
            foreach ($state['operational_events'] as $event) {
                if ($event['close_at'] < $now->getTimestamp()) {
                    continue;
                }$date = (new \DateTimeImmutable('@' . $event['close_at']))->setTimezone($zone)->format('Y-m-d');
                $at = ($g['times_alert_mode'] ?? 'local_time') === 'before_close' ? $event['close_at'] - (int)$g['times_alert_before_minutes'] * 60 : LocalTime::resolve($date, $g['times_alert_time'], $g['timezone_id']);
                if ($at !== null && $at < $event['close_at']) {
                    $this->enqueue($g, ['kind' => 'times_alert','run_at' => $at,'expires_at' => $event['close_at'],'event_key' => 'times:' . $event['event_key']]);
                }break;
            }
        }
        // Messages-only mode still announces transitions; it does not claim permission changes.
        if ($g['mode'] === 'messages') {
            foreach ($state['operational_events'] as $event) {
                foreach (['close' => 'close_at','open' => 'open_at'] as $kind => $field) {
                    $this->enqueue($g, ['kind' => $kind,'run_at' => $event[$field],'expires_at' => $event[$field] + 3600,'event_key' => $kind . ':' . $event['event_key']]);
                }
            }
        }
    }
    private function enqueue(array $g, array $job): void
    {
        if ($job['expires_at'] <= $this->clock->now()->getTimestamp()) {
            return;
        }
        $payload = ['text' => ''];
        $this->jobs->enqueue($g['group_id'], $job['kind'], $job['event_key'], $job['run_at'], $job['expires_at'], $g['revision'], $payload, in_array($job['kind'], ['close', 'open'], true) ? 1 : ($job['kind'] === 'tefillin' ? 50 : 100));
    }
    public function payload(array $g, string $kind, \DateTimeImmutable $instant, ?array $templateOverride = null): array
    {
        $zone = new \DateTimeZone($g['timezone_id']);
        $date = $instant->setTimezone($zone)->format('Y-m-d');
        $lang = new Lang();
        $t = fn(string $key, array $values = []): string=>$lang->text($g['language'], $key, $values);
        $text = match ($kind) {
            'close'=>$t('group_close_notice'),'open'=>$t('group_open_notice'),default=>''
        };
        if ($kind === 'zmanim') {
            $text = $this->ui->renderView('0', $g, 'zmanim', $date);
        }
        $dafPlain = '';
        if ($kind === 'daf') {
            // Provider failures must reach the worker so a scheduled message can retry.
            $d = $this->calendar->daf($g, $date);
            $formatter = new DafFormatter($lang);
            $dafPlain = $formatter->plain($g, $d, $date);
            $text = $formatter->html($g, $d, $date);
        }
        if ($kind === 'tefillin') {
            $sun = $this->calendar->zmanim($g, $date)['times'];
            $sunset = isset($sun['sunset']) ? (new \DateTimeImmutable('@' . $sun['sunset']))->setTimezone($zone)->format('H:i') : '-';
            $text = $t('tefillin_reminder', ['location' => $g['location_name'],'sunset' => $sunset]);
        }
        if (in_array($kind, ['omer','omer_day'], true)) {
            $o = $this->calendar->omer($g, $instant);
            if ($o['season']) {
                $nightfall = $o['nightfall'] === null ? '-' : (new \DateTimeImmutable('@' . $o['nightfall']))->setTimezone($zone)->format('d/m/Y H:i');
                $content = OmerText::render($o['day'], $g['omer_nusach'], $g['omer_format'] === 'full', $kind === 'omer_day', $g['language']) . "\n" . $t('omer_nightfall_label') . ': ' . $nightfall;
                $text = $t('date_view', ['module' => $t('omer'),'date' => $o['date'],'location' => $g['location_name'],'timezone' => $g['timezone_id'],'hdate' => $o['hdate'] ?? '-','content' => $content]);
                // The shared frame escapes dynamic values; the bundled traditional text contains no HTML markup.
            } else {
                $text = $t('omer_inactive');
            }
        }
        if ($kind === 'times_alert') {
            $text = $this->formatShabbat($g, $this->calendar->snapshot($g, $instant));
        }
        $templateKind = match ($kind) {
            'omer_day'=>'omer',default=>$kind
        };
        $template = $kind === 'omer_day' ? null : ($templateOverride ?? $this->templates->get($g['group_id'], $templateKind));
        $payload = ['text' => $text,'topic_id' => $g['target_topic_id']];
        $payload['buttons_mode'] = $template['buttons_mode'] ?? 'inherit';
        if ($template !== null) {
            // Editing only a keyboard preserves the generated, current calendar content.
            if ($template['text'] !== '' || $template['media'] !== null) {
                $payload = array_intersect_key($template, array_flip(['text','entities','media'])) + $payload;
            }
            if ($payload['buttons_mode'] === 'custom') {
                $payload['buttons'] = $template['buttons'];
            }
        }
        if ($payload['buttons_mode'] === 'inherit') {
            $payload['buttons'] = \Shabbat\Content\GroupMessageButtons::defaults($kind, $g['language']);
        }
        $inline = $g[$kind . '_template'] ?? '';
        if ($inline !== '' && $template === null) {
            $payload['text'] = $inline;
        }
        if ($kind === 'daf' && $payload['text'] === '{{daf}}' && !array_key_exists('entities', $payload)) {
            // The built-in default selects the card; authored native entities remain plain text.
            $payload['text'] = $text;
        }
        if (str_contains($payload['text'], '{{')) {
            $z = $this->calendar->zmanim($g, $date);
            $sun = $z['times'];
            $state = $this->calendar->snapshot($g, $instant);
            $event = $state['current_event'] ?? $state['next_event'];
            $format = fn(?int $at): string=>$at === null ? '-' : (new \DateTimeImmutable('@' . $at))->setTimezone($zone)->format('H:i');
            $values = ['location' => $g['location_name'],'date' => $date,'hdate' => $z['hdate'] ?? '-','source' => 'Hebcal','daf' => $kind === 'daf' ? $dafPlain : $text,'omer' => $text,'sunset' => $format($sun['sunset'] ?? null),'remaining' => isset($sun['sunset']) ? (string)max(0, (int)ceil(($sun['sunset'] - $instant->getTimestamp()) / 60)) : '-','candles' => $format($event['candles_at'] ?? null),'havdalah' => $format($event['havdalah_at'] ?? null),'close' => $format($event['close_at'] ?? null),'open' => $format($event['open_at'] ?? null)];
            $payload = (new TemplateRenderer())->renderPayload($payload, $values);
        }
        return $payload;
    }
    /** Keyboard-only edits do not replace generated calendar text. */
    public function groupMessageButtons(array $group, string $kind): array
    {
        $template = $this->templates->get($group['group_id'], $kind);
        return match ($template['buttons_mode'] ?? 'inherit') {
            'inherit' => \Shabbat\Content\GroupMessageButtons::defaults($kind, $group['language']),
            'custom' => $template['buttons'],
            default => [],
        };
    }
    private function preparePayload(array $job, array $g, \DateTimeImmutable $now): array
    {
        // A delayed announcement still names its scheduled sequence, not the next unrelated holiday.
        $instant = $job['module'] === 'times_alert' ? new \DateTimeImmutable('@' . $job['run_at']) : $now;
        $payload = $this->payload($g, $job['module'], $instant);
        return $this->tefillinAcknowledgements->preparePayload($job, $g, $instant, $payload);
    }
    public function incomingGroupMessage(string $group, int $messageId, int $sentAt, string $sender, bool $outgoing = false): bool
    {
        try {
            $queued = $this->cleanup->receive($group, $messageId, $sentAt, $sender, $outgoing);
            if ($queued) {
                $this->cleanup->runOnce($this->owner);
            }
            return $queued;
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->recordError($group, 'cleanup', $error);
            return false;
        }
    }
    private function recordDelivered(array $job, array $response): void
    {
        if (!$this->gateway instanceof \Shabbat\Telegram\MessageMaintenanceGateway) {
            $this->jobs->markDeliveryProcessed($job['job_id']);
            return;
        }
        $settings = $this->settings->getGroup($job['group_id']);
        $ids = $job['module'] === 'tefillin' ? $this->gateway->extractSentMessageIds($response, $job['random_id'], $job['group_id']) : [];
        if ($job['module'] === 'tefillin' && $ids === []) {
            // Preserve the prior pointer when Telegram did not supply verifiable sent message IDs.
            $this->jobs->markDeliveryProcessed($job['job_id']);
            $this->recordError($job['group_id'], 'delivery_cleanup', new \RuntimeException('Sent message identity unavailable; previous reminder preserved.'));
            return;
        }
        if ($job['module'] === 'tefillin') {
            $this->tefillinAcknowledgements->registerDelivery($job['job_id'], $ids);
        }
        $this->deletions->recordSentJobDelivery($job['job_id'], $ids, (bool)($settings['tefillin_delete_previous'] ?? false));
    }
    private function replayDeliveryBookkeeping(): void
    {
        foreach ($this->jobs->pendingDeliveryBookkeeping(20) as $job) {
            if (Runner::isStopping()) {
                return;
            }
            try {
                $this->recordDelivered($job, $job['response']);
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $this->recordError($job['group_id'], 'delivery_cleanup', $error);
            }
        }
    }
    /** Recovery never bypasses a religious/operational interval, including an operator-open override. */
    private function permissionRecoveryAllowed(string $group): bool
    {
        unset($this->recoveryWindows[$group]);
        for ($pass = 0; $pass < 2; $pass++) {
            if (Runner::isStopping()) {
                throw new \Amp\CancelledException(new \RuntimeException('Permission recovery stopped during shutdown.'));
            }
            $settings = $this->settings->getGroup($group) ?? throw new \RuntimeException('Group disappeared before recovery.');
            $instant = $this->clock->now();
            if ($settings['manual_override_mode'] === 'lock' && $settings['manual_override_until'] > $instant->getTimestamp()) {
                return false;
            }
            $state = $this->calendar->snapshotNow($settings);
            $instant = new \DateTimeImmutable('@' . $state['evaluated_at']);
            $latest = $this->settings->getGroup($group) ?? throw new \RuntimeException('Group disappeared during recovery.');
            if ($latest['revision'] !== $settings['revision'] || $this->clock->now()->getTimestamp() !== $instant->getTimestamp()) {
                continue;
            }
            if (Runner::isStopping()) {
                throw new \Amp\CancelledException(new \RuntimeException('Permission recovery stopped during shutdown.'));
            }
            if ($state['quiet_now'] !== false || $state['should_group_be_locked'] !== false) {
                return false;
            }
            $stamp = $instant->getTimestamp();
            $coverageEnd = new \DateTimeImmutable($state['coverage']['end'] . ' 00:00:00', new \DateTimeZone($settings['timezone_id']));
            $until = min($stamp + 120, $coverageEnd->getTimestamp());
            foreach (['events' => 'start_at', 'operational_events' => 'close_at'] as $collection => $boundary) {
                foreach ($state[$collection] as $event) {
                    if ($event[$boundary] > $stamp) {
                        $until = min($until, $event[$boundary]);
                    }
                }
            }
            if ($until <= $stamp) {
                return false;
            }
            if (count($this->recoveryWindows) >= 256) {
                unset($this->recoveryWindows[array_key_first($this->recoveryWindows)]);
            }
            $this->recoveryWindows[$group] = ['settings' => hash('sha256', Database::encode($latest)), 'checked_at' => $stamp, 'until' => $until];
            return true;
        }
        throw new \RuntimeException('Recovery conditions changed; refresh the panel.');
    }
    /** Synchronous fence after the final Telegram read; never fetches or suspends. */
    private function permissionRecoveryStillAllowed(string $group): bool
    {
        if (Runner::isStopping()) {
            throw new \Amp\CancelledException(new \RuntimeException('Permission recovery stopped during shutdown.'));
        }
        $window = $this->recoveryWindows[$group] ?? null;
        $now = $this->clock->now()->getTimestamp();
        if ($window === null || $now < $window['checked_at'] || $now >= $window['until']) {
            return false;
        }
        $settings = $this->settings->getGroup($group);
        return $settings !== null && hash_equals($window['settings'], hash('sha256', Database::encode($settings)))
            && !($settings['manual_override_mode'] === 'lock' && $settings['manual_override_until'] > $now);
    }
    private function freshPermissionDesire(string $group): bool
    {
        unset($this->permissionWindows[$group]);
        for ($pass = 0; $pass < 2; $pass++) {
            if (Runner::isStopping()) {
                throw new \Amp\CancelledException(new \RuntimeException('Permission mutation stopped during shutdown.'));
            }
            $settings = $this->settings->getGroup($group) ?? throw new \RuntimeException('Group disappeared before permission mutation.');
            $instant = $this->clock->now();
            // An active operator override must not wait for the calendar provider.
            if (
                in_array($settings['manual_override_mode'], ['lock', 'open'], true)
                && $settings['manual_override_until'] > $instant->getTimestamp()
            ) {
                $desired = $settings['active'] && $settings['manual_override_mode'] === 'lock';
                $this->rememberPermissionDesire($group, $settings, $desired, $instant->getTimestamp(), $settings['manual_override_until']);
                return $desired;
            }
            $state = $this->calendar->snapshotNow($settings);
            $instant = new \DateTimeImmutable('@' . $state['evaluated_at']);
            $latest = $this->settings->getGroup($group) ?? throw new \RuntimeException('Group disappeared during permission calculation.');
            $current = $this->clock->now();
            if ($latest['revision'] !== $settings['revision'] || $current->getTimestamp() !== $instant->getTimestamp()) {
                continue;
            }
            if (Runner::isStopping()) {
                throw new \Amp\CancelledException(new \RuntimeException('Permission mutation stopped during shutdown.'));
            }
            $stamp = $current->getTimestamp();
            $coverageEnd = new \DateTimeImmutable($state['coverage']['end'] . ' 00:00:00', new \DateTimeZone($settings['timezone_id']));
            $until = $coverageEnd->getTimestamp();
            foreach ($state['operational_events'] as $event) {
                foreach (['close_at','open_at'] as $boundary) {
                    if ($event[$boundary] > $stamp) {
                        $until = min($until, $event[$boundary]);
                    }
                }
            }
            $desired = $latest['active'] && ManualGroups::desired($latest, $state['should_group_be_locked'], $stamp);
            $this->rememberPermissionDesire($group, $latest, $desired, $stamp, $until);
            return $desired;
        }
        throw new \RuntimeException('Permission desire changed during provider work; defer to a fresh cycle.');
    }

    private function rememberPermissionDesire(string $group, array $settings, bool $desired, int $checkedAt, int $until): void
    {
        if (count($this->permissionWindows) >= 256) {
            unset($this->permissionWindows[array_key_first($this->permissionWindows)]);
        }
        $this->permissionWindows[$group] = ['settings' => hash('sha256', Database::encode($settings)),
            'desired' => $desired,'checked_at' => $checkedAt,'until' => min($checkedAt + 120, $until)];
    }
    /** Synchronous local fence between the final Telegram read and the mutation. */
    private function permissionDesireStillCurrent(string $group, bool $expected): bool
    {
        if (Runner::isStopping()) {
            throw new \Amp\CancelledException(new \RuntimeException('Permission mutation stopped during shutdown.'));
        }
        $window = $this->permissionWindows[$group] ?? null;
        $now = $this->clock->now()->getTimestamp();
        if ($window === null || $now < $window['checked_at'] || $now >= $window['until'] || $window['desired'] !== $expected) {
            return false;
        }
        $settings = $this->settings->getGroup($group);
        return $settings !== null && hash_equals($window['settings'], hash('sha256', Database::encode($settings)));
    }

    private function eligible(array $job, array $g, \DateTimeImmutable $now): bool
    {
        unset($this->dispatchWindows[$job['job_id']]);
        if ($this->isStopping()) {
            return false;
        }
        $state = $this->calendar->snapshotNow($g);
        $stamp = $state['evaluated_at'];
        $now = new \DateTimeImmutable('@' . $stamp);
        $kind = $job['module'];
        $transition = in_array($kind, ['close', 'open'], true);
        if (!$transition) {
            $lock = $this->locks->state($g['group_id']);
            if ($lock !== null && $lock['snapshot_json'] !== null && !in_array($lock['status'], ['restored', 'prelocked'], true)) {
                throw new \Shabbat\Scheduling\DeliveryDeferredException('Waiting for restoration of bot-owned permissions.', max($stamp + 60, (int) ($lock['retry_at'] ?? 0)));
            }
            if ($kind === 'omer' && $lock !== null && $lock['status'] === 'restored' && $lock['restored_at'] !== null) {
                $afterOpen = (int) $lock['restored_at'] + $g['omer_after_open_minutes'] * 60;
                if ($stamp < $afterOpen) {
                    throw new \Shabbat\Scheduling\DeliveryDeferredException('Waiting for configured Omer gap after actual restoration.', $afterOpen);
                }
            }
        }
        $manualClosed = ($g['manual_override_mode'] ?? 'none') === 'lock' && ($g['manual_override_until'] ?? 0) > $stamp;
        $allowed = match ($kind) {
            'close' => $state['quiet_now'] || $manualClosed,
            default => !$state['quiet_now'] && !$manualClosed,
        };
        if (!$allowed) {
            return false;
        }
        $zone = $g['timezone_id'];
        $quietStart = $g['quiet_start'] ?? null;
        $quietEnd = $g['quiet_end'] ?? null;
        if (!$transition && \Shabbat\Scheduling\QuietHours::contains($stamp, $zone, $quietStart, $quietEnd)) {
            $next = \Shabbat\Scheduling\QuietHours::nextBoundary($stamp, $zone, $quietStart, $quietEnd);
            throw new \Shabbat\Scheduling\DeliveryDeferredException('Waiting for local quiet hours to end.', $next ?? $stamp + 3600);
        }
        $until = min((int) $job['expires_at'], $stamp + 120);
        $local = $now->setTimezone(new \DateTimeZone($zone));
        $until = min($until, $local->modify('tomorrow')->setTime(0, 0)->getTimestamp());
        if (($g['manual_override_until'] ?? 0) > $stamp) {
            $until = min($until, $g['manual_override_until']);
        }
        foreach (['events' => ['start_at', 'end_at'], 'operational_events' => ['close_at', 'open_at']] as $collection => $fields) {
            foreach ($state[$collection] as $event) {
                foreach ($fields as $field) {
                    if ($event[$field] > $stamp) {
                        $until = min($until, $event[$field]);
                    }
                }
            }
        }
        if (!$transition) {
            $quietBoundary = \Shabbat\Scheduling\QuietHours::nextBoundary($stamp, $zone, $quietStart, $quietEnd);
            $until = min($until, $quietBoundary ?? $until);
        }
        if ($kind === 'omer' || $kind === 'omer_day') {
            $omer = $this->calendar->omer($g, $now);
            if ($kind === 'omer_day') {
                if (!$g['omer_day_completion'] || !$omer['day_completion']) {
                    return false;
                }
                $until = min($until, $omer['sunset']);
            } else {
                if (!$omer['eligible'] || $omer['expires_at'] === null) {
                    return false;
                }
                $until = min($until, $omer['expires_at']);
            }
        }
        if ($kind === 'tefillin') {
            $sun = $this->calendar->zmanim($g, $local->format('Y-m-d'))['times'];
            $windowStart = LocalTime::resolve($local->format('Y-m-d'), $g['tefillin_window_start'], $zone);
            $windowEnd = LocalTime::resolve($local->format('Y-m-d'), $g['tefillin_window_end'], $zone);
            if (
                !isset($sun['sunset'], $sun['misheyakir']) || $windowStart === null || $windowEnd === null
                || $stamp < max($sun['misheyakir'], $windowStart)
                || !in_array((int) $local->format('w'), $g['tefillin_days'], true)
                || $g['tefillin_suspended_date'] === $local->format('Y-m-d')
                || ($state['is_chol_hamoed'] && !$g['tefillin_chol_hamoed'])
            ) {
                return false;
            }
            if ($state['is_tisha_bav'] && ($g['tefillin_tisha_bav_policy'] !== 'mincha' || !isset($sun['minchaGedola']) || $stamp < $sun['minchaGedola'])) {
                return false;
            }
            $until = min($until, $sun['sunset'] - $g['tefillin_sunset_safety_minutes'] * 60, $windowEnd);
        }
        if (count($this->dispatchWindows) >= 512) {
            unset($this->dispatchWindows[array_key_first($this->dispatchWindows)]);
        }
        $this->dispatchWindows[$job['job_id']] = ['settings' => hash('sha256', Database::encode($g)), 'checked_at' => $stamp, 'until' => $until];
        // Additional provider work above may have crossed a boundary. Do not trust the
        // captured timestamp again, even before the gateway performs its own preflight.
        return $this->dispatchEligible($job, $g, $this->clock->now());
    }

    /** Synchronous, repeatable evidence check immediately before the transport call. */
    private function dispatchEligible(array $job, array $settings, \DateTimeImmutable $now): bool
    {
        $window = $this->dispatchWindows[$job['job_id']] ?? null;
        $stamp = $now->getTimestamp();
        if ($this->isStopping() || $window === null || $stamp < $window['checked_at'] || $stamp >= $window['until']) {
            return false;
        }
        if (!hash_equals($window['settings'], hash('sha256', Database::encode($settings)))) {
            return false;
        }
        if (!in_array($job['module'], ['close', 'open'], true)) {
            if (\Shabbat\Scheduling\QuietHours::contains($stamp, $settings['timezone_id'], $settings['quiet_start'] ?? null, $settings['quiet_end'] ?? null)) {
                return false;
            }
            $lock = $this->locks->state($settings['group_id']);
            if ($lock !== null && $lock['snapshot_json'] !== null && !in_array($lock['status'], ['restored', 'prelocked'], true)) {
                return false;
            }            if ($job['module'] === 'omer' && $lock !== null && $lock['status'] === 'restored' && $lock['restored_at'] !== null) {
                $afterOpen = (int) $lock['restored_at'] + $settings['omer_after_open_minutes'] * 60;
                if ($stamp < $afterOpen) {
                    throw new \Shabbat\Scheduling\DeliveryDeferredException('Waiting for updated actual restoration gap.', $afterOpen);
                }
            }
        }
        return true;
    }
    public function formatShabbat(array $g, array $s): string
    {
        return (new ShabbatTimesFormatter())->overview($g, $s)['text'];
    }
    public function formatZmanim(array $g, array $z): string
    {
        return $this->ui->renderView('0', $g, 'zmanim', $z['date']);
    }
    private function weekdays(array $days): array
    {
        return array_map(fn(int $day): int=>$day === 0 ? 7 : $day, $days);
    }
    private function recordError(string $group, string $action, \Throwable $error): void
    {
        $latest = $this->db->pdo()->prepare('SELECT created_at FROM audit_log WHERE group_id=? AND action=? ORDER BY created_at DESC LIMIT 1');
        $latest->execute([$group,$action . '.error']);
        if (($latest->fetchColumn() ?: 0) > $this->clock->now()->getTimestamp() - 300) {
            return;
        }
        $this->db->pdo()->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(NULL,?,?,?,?)')->execute([$group,$action . '.error',json_encode(['type' => get_class($error)], JSON_THROW_ON_ERROR),$this->clock->now()->getTimestamp()]);
    }
    /** @phpstan-impure Shutdown state can change while another Amp operation is suspended. */
    private function isStopping(): bool
    {
        return $this->stopped || Runner::isStopping();
    }
    public function stop(): void
    {
        $this->stopped = true;
        $this->broadcastJobs->stop();
        $this->worker->stop();
        $this->cleanup->stop();
    }
}
