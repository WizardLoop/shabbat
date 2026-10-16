<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

use Shabbat\Calendar\CalendarService;
use Shabbat\Location\GeoNames;
use Shabbat\Location\Place;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\Clock;
use Shabbat\Scheduling\Permissions;
use Shabbat\Scheduling\PermissionRecovery;

/** Settings writes require user-bound, revision-bound, one-use confirmations. */
final class BotController
{
    private Lang $lang;
    private \Closure $authorizeOperator;
    private array $inlineCancellation = [];
    private const MODULES = ['zmanim','daf','tefillin','omer'];
    private const FORM_FIELDS = [
        'shabbat' => ['candle_minutes','havdalah_method','havdalah_value','close_before_candles_minutes','open_after_havdalah_minutes','close_message_enabled','open_message_enabled','times_alert_enabled','times_alert_time','times_alert_mode','times_alert_before_minutes','quiet_start','quiet_end','target_topic_id','lock_block_flags','delete_holy_user_messages','delete_holy_bot_messages'],
        'zmanim' => ['time','days','format'], 'daf' => ['time','days','template'],
        'tefillin' => ['days','fixed_times','interval_minutes','window_start','window_end','sunset_offsets','max_per_day','min_spacing_minutes','chol_hamoed','tisha_bav_policy','sunset_safety_minutes','quiet_start','quiet_end','template','delete_previous','ack_enabled','ack_label'],
        'omer' => ['time','mode','after_nightfall_minutes','nightfall_method','nightfall_value','nusach','format','day_completion','early_policy','after_open_minutes'],
    ];
    public function __construct(private SettingsRepository $settings, private FlowTokenRepository $flows, private CalendarService $calendar, private UiGateway $gateway, private GeoNames $geo, private Clock $clock, ?Lang $lang = null, ?callable $authorizeOperator = null, private ?PermissionRecovery $permissionRecovery = null)
    {
        $this->lang = $lang ?? new Lang();
        $this->authorizeOperator = $authorizeOperator === null ? static fn(string $user): bool => false : \Closure::fromCallable($authorizeOperator);
    }
    /** Message: user_id,text,private,group_id?,message_id?,location?. */
    public function handleMessage(array $message): bool
    {
        $user = (string) ($message['user_id'] ?? '');
        if (!preg_match('/^[0-9]{1,20}$/D', $user)) {
            return false;
        }
        if (($message['private'] ?? false) && $this->wizardInput($user, $message)) {
            return true;
        }
        if (($message['private'] ?? false) && $this->locationInput($user, $message)) {
            return true;
        }
        if (isset($message['location']) && ($message['private'] ?? false)) {
            try {
                if (!($message['replied_bot'] ?? false) || !preg_match('/^GPS ([A-Za-z0-9_-]{24})$/m', (string) ($message['reply_text'] ?? ''), $gpsToken)) {
                    throw new \RuntimeException($this->t($user, 'gps_reply_required'));
                }
                $context = $this->context($user, $gpsToken[1], 'gps');
                $gps = $message['location'];
                $place = $this->coordinatePlace($user, $gps['latitude'] ?? $gps['lat'] ?? null, $gps['longitude'] ?? $gps['long'] ?? null, $context['_flow']['payload']['timezone_id'], 'GPS', 'telegram_gps');
                $this->changes($user, $context, $place, true);
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $this->sendError($user, $error);
            }
            return true;
        }
        $text = trim((string) ($message['text'] ?? ''));
        if (!preg_match('~^[/!.]([a-z]+)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$~is', $text, $match)) {
            return false;
        }
        $command = strtolower($match[1]);
        $arguments = trim($match[2] ?? '');
        if (!in_array($command, ['start','settings','groups','preferences','language','lang','shabat','shabbat','zmanim','daf','omer','help','city','coordinates','location','timezone','configure','viewdate'], true)) {
            return false;
        }
        try {
            if ($command === 'settings') {
                $group = isset($message['group_id']) ? (string) $message['group_id'] : $arguments;
                if ($group === '') {
                    $this->groupList($user);
                } else {
                    $this->panel($user, $this->group($user, $group));
                }
                return true;
            }
            if (in_array($command, ['language','lang'], true)) {
                $context = isset($message['group_id']) ? $this->group($user, (string) $message['group_id']) : $this->settings->getUser($user);
                $this->screen($user, $context, 'language');
                return true;
            }
            if (!($message['private'] ?? false)) {
                return false;
            }
            if ($command === 'start') {
                if (preg_match('/^g_(-?[0-9]+)$/D', $arguments, $g)) {
                    $this->panel($user, $this->group($user, $g[1]));
                } elseif (preg_match('/^loc_([A-Za-z0-9_-]{24})$/D', $arguments, $p)) {
                    $this->locationPreview($user, $p[1]);
                } else {
                    $this->start($user);
                }
            } elseif ($command === 'groups') {
                $this->groupList($user, 0, $arguments);
            } elseif ($command === 'preferences') {
                $this->panel($user, $this->settings->getUser($user));
            } elseif ($command === 'help') {
                $this->help($user);
            } elseif (in_array($command, ['shabat','shabbat','zmanim','daf','omer'], true)) {
                $this->view($user, $this->settings->getUser($user), in_array($command, ['shabat','shabbat'], true) ? 'shabbat' : $command, $arguments ?: null);
            } elseif ($command === 'city') {
                [$token, $query] = $this->twoArguments($arguments);
                $context = $this->context($user, $token, 'location');
                $this->cityResults($user, $context, $query);
            } elseif ($command === 'coordinates') {
                $parts = preg_split('/\s+/u', $arguments, 5);
                if (count($parts) < 4) {
                    throw new \InvalidArgumentException('Use /coordinates TOKEN latitude longitude IANA-zone name');
                }
                $context = $this->context($user, $parts[0], 'location');
                $place = $this->coordinatePlace($user, $parts[1], $parts[2], $parts[3], $parts[4] ?? 'Coordinates', 'coordinates');
                $this->changes($user, $context, $place, true);
            } elseif ($command === 'location') {
                [$token, $zone] = $this->twoArguments($arguments);
                $context = $this->context($user, $token, 'location');
                if ($zone !== 'auto' && !in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
                    throw new \InvalidArgumentException('An IANA timezone is required');
                }
                if ($zone === 'auto' && !$this->geo->configured()) {
                    throw new \RuntimeException($this->t($user, 'auto_timezone_missing'));
                }
                $gpsToken = $this->token($user, $context, 'gps', ['timezone_id' => $zone]);
                $this->send($user, $this->t($user, 'gps_prompt', ['title' => $this->title($user, $context), 'timezone' => $zone === 'auto' ? $this->t($user, 'timezone_auto') : $zone]) . "\nGPS " . $gpsToken);
            } elseif ($command === 'timezone') {
                [$token, $query] = $this->twoArguments($arguments);
                $context = $this->context($user, $token, 'location');
                $buttons = [];
                $zones = array_values(array_filter(\DateTimeZone::listIdentifiers(), static fn(string $zone): bool => stripos($zone, $query) !== false));
                foreach (array_slice($zones, 0, 25) as $zone) {
                    $choice = $this->token($user, $context, 'location_choice', ['timezone_id' => $zone]);
                    $buttons[] = [['text' => $zone, 'data' => 'sb:lp:' . $choice]];
                }
                $this->send($user, $this->t($user, 'timezone_warning'), $this->keyboard($buttons));
            } elseif ($command === 'configure') {
                [$token, $form] = $this->twoArguments($arguments);
                $context = $this->context($user, $token, 'edit');
                $changes = $this->parseForm($context['_flow']['payload']['module'], $form);
                if (!isset($context['group_id']) && array_diff(array_keys($changes), ['candle_minutes','havdalah_method','havdalah_value'])) {
                    throw new \InvalidArgumentException('Personal settings only accept candle and havdalah fields');
                }
                $this->changes($user, $context, $changes);
            } elseif ($command === 'viewdate') {
                [$token, $date] = $this->twoArguments($arguments);
                $context = $this->context($user, $token, 'date');
                $this->view($user, $context, $context['_flow']['payload']['module'], $this->date($date));
            }
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->sendError($user, $error);
        }
        return true;
    }
    /** Callback: user_id,data,query_id,message_id,private. */
    public function handleCallback(array $query): bool
    {
        $data = (string)($query['data'] ?? '');
        if (!str_starts_with($data, 'sb:')) {
            return false;
        }
        // Inline city-result selection opens a private confirmation; it is not private-panel navigation.
        if (($query['inline_selection'] ?? false) === true && str_starts_with($data, 'sb:lp:')) {
            return $this->dispatchCallback($query);
        }
        try {
            $target = CallbackUiGateway::fromQuery($this->gateway, $query);
        } catch (\InvalidArgumentException) {
            $user = (string)($query['user_id'] ?? '');
            $this->gateway->answerCallback((string)($query['query_id'] ?? ''), $this->t($user, 'callback_panel_unavailable'));
            return true;
        }
        $request = new self($this->settings, $this->flows, $this->calendar, $target, $this->geo, $this->clock, $this->lang, $this->authorizeOperator, $this->permissionRecovery);
        return $request->dispatchCallback($query);
    }
    private function dispatchCallback(array $query): bool
    {
        $data = (string) ($query['data'] ?? '');
        if (!str_starts_with($data, 'sb:')) {
            return false;
        }
        $user = (string) ($query['user_id'] ?? '');
        try {
            if (!($query['private'] ?? false)) {
                throw new \RuntimeException($this->t($user, 'not_private'));
            }
            $notification = '';
            $parts = explode(':', $data, 5);
            $action = $parts[1] ?? '';
            $token = $parts[2] ?? '';
            $value = $parts[3] ?? '';
            if (!in_array($action, ['wo','wi','wd','wc','save','cancel'], true)) {
                $this->flows->revokeAction($user, 'settings_wizard');
            }
            if (!in_array($action, ['pc', 'px'], true)) {
                $this->flows->revokeAction($user, 'permission_recovery_confirm');
            }
            if (in_array($action, ['pr', 'pa', 'pc', 'px'], true)) {
                if ($this->permissionRecovery === null) {
                    throw new \RuntimeException('Permission recovery is unavailable.');
                }
                $context = $this->context($user, $token, in_array($action, ['pc', 'px'], true) ? 'permission_recovery_confirm' : 'panel');
                $recovery = new PermissionRecoveryController($this->settings, $this->flows, $this->gateway, $this->permissionRecovery, $this->lang);
                match ($action) {
                    'pr' => $recovery->show($user, $context),
                    'pa' => $recovery->prepare($user, $context, $value),
                    'pc' => $recovery->confirm($user, $context, $token),
                    'px' => $recovery->cancel($user, $context, $token),
                };
            } elseif (in_array($action, ['wd','wc'], true)) {
                $context = $this->context($user, $token, 'settings_wizard');
                $this->wizardDays($user, $context, $action === 'wd' ? $this->integer($value, 0, 6) : null);
            } elseif ($action === 'start') {
                $this->start($user);
            } elseif ($action === 'groups') {
                $state = $this->flows->peek($token, $user, 'groups');
                $this->groupList($user, $state['payload']['offset'] ?? 0, $state['payload']['search'] ?? '');
            } elseif ($action === 'personal') {
                $this->panel($user, $this->settings->getUser($user));
            } elseif ($action === 'group') {
                $this->panel($user, $this->context($user, $token, 'panel'));
            } elseif ($action === 'help') {
                $this->help($user, $token);
            } elseif ($action === 'show') {
                $this->view($user, $this->settings->getUser($user), $token);
            } elseif ($action === 'lp') {
                $this->locationPreview($user, $token);
            } elseif ($action === 'lc') {
                $context = $this->context($user, $token, 'location_page');
                $payload = $context['_flow']['payload'];
                $this->cityResults($user, $context, $payload['query'], (int) $payload['offset']);
            } elseif ($action === 'save') {
                $context = $this->context($user, $token, 'save');
                $saved = $this->flows->confirm($token, $user, 'save', fn(string $group, string $actor): bool => $this->gateway->isGroupAdmin($actor, $group), $context['_flow']['payload']['changes']);
                $notification = $this->t($user, 'saved', ['title' => $this->title($user, $saved)]);
                $this->resumeSettings($user, $saved, $context['_flow']['payload']);
            } elseif ($action === 'cancel') {
                $context = $this->context($user, $token, 'save');
                $this->flows->revoke($token, $user, 'save');
                $this->resumeSettings($user, $context, $context['_flow']['payload']);
            } else {
                $context = $this->context($user, $token, 'panel');
                if (in_array($action, ['wf','wp','wo','wi'], true)) {
                    $module = $context['_flow']['payload']['wizard_module'] ?? $context['_flow']['payload']['return_wizard'] ?? '';
                    if ($action === 'wp') {
                        $this->wizardFields($user, $context, $module, $this->integer($value, 0, 100));
                    } elseif ($action === 'wf') {
                        $fields = SettingsWizard::fields($module, isset($context['group_id']));
                        $field = $fields[$this->integer($value, 0, count($fields) - 1)] ?? throw new \InvalidArgumentException('Unknown field.');
                        $this->wizardField($user, $context, $module, $field);
                    } else {
                        $field = $context['_flow']['payload']['wizard_field'] ?? '';
                        if ($action === 'wi') {
                            $this->wizardPrompt($user, $context, $module, $field);
                        } else {
                            $definition = SettingsWizard::definition($module, $field, isset($context['group_id']));
                            $options = $definition['options'] ?? [];
                            $index = $this->integer($value, 0, count($options) - 1);
                            $changes = SettingsWizard::changes($module, $field, $options[$index], $context, fn(string $m, string $form): array => $this->parseForm($m, $form));
                            $this->changes($user, $context, $changes);
                        }
                    }
                } elseif ($action === 'v') {
                    $this->screen($user, $context, $value);
                } elseif ($action === 's') {
                    $raw = $parts[4] ?? '';
                    $changes = match ($value) {
                        'l' => in_array($raw, Lang::CODES, true) ? ['language' => $raw] : throw new \InvalidArgumentException('Invalid language'),
                        'c' => ['candle_minutes' => $raw === 'city' ? null : $this->integer($raw, 1, 120)],
                        'h' => in_array($raw, ['8.5','42','50','72'], true) ? ['havdalah_method' => $raw === '8.5' ? 'degrees' : 'minutes', 'havdalah_value' => (float) $raw] : throw new \InvalidArgumentException('Invalid havdalah method'),
                        'i' => in_array($raw, ['0','1'], true) ? ['israel' => $raw === '1'] : throw new \InvalidArgumentException('Invalid calendar choice'),
                        'm' => in_array($raw, ['off','messages','lock','lock_messages'], true) ? ['mode' => $raw] : throw new \InvalidArgumentException('Invalid mode'),
                        's' => ['shabbat_enabled' => !($context['shabbat_enabled'] ?? true)], 'y' => ['holidays_enabled' => !($context['holidays_enabled'] ?? true)],
                        default => throw new \InvalidArgumentException('Unknown setting'),
                    };
                    $this->changes($user, $context, $changes);
                } elseif (in_array($action, ['flag','mask','toggle','at'], true)) {
                    if (!isset($context['group_id'])) {
                        throw new \RuntimeException('Group setting required');
                    }
                    if ($action === 'flag') {
                        if (!in_array($value, Permissions::BLOCK_FLAGS, true)) {
                            throw new \InvalidArgumentException('Unknown group restriction');
                        }
                        $mask = $context['lock_block_flags'] ?? Permissions::DEFAULT_BLOCK_FLAGS;
                        $mask = in_array($value, $mask, true) ? array_values(array_diff($mask, [$value])) : [...$mask, $value];
                        $changes = ['lock_block_flags' => Permissions::validateBlockFlags($mask)];
                    } elseif ($action === 'mask') {
                        $changes = ['lock_block_flags' => match ($value) {
                            'default' => Permissions::DEFAULT_BLOCK_FLAGS, 'all' => Permissions::BLOCK_FLAGS, 'none' => [],
                            default => throw new \InvalidArgumentException('Unknown restriction preset'),
                        }];
                    } elseif ($action === 'at') {
                        if (!preg_match('/^(?:[01][0-9]|2[0-3])[0-5][0-9]$/D', $value)) {
                            throw new \InvalidArgumentException('Invalid announcement time');
                        }
                        $changes = ['times_alert_time' => substr($value, 0, 2) . ':' . substr($value, 2), 'times_alert_mode' => 'local_time'];
                    } else {
                        $field = match ($value) {
                            'du' => 'delete_holy_user_messages', 'db' => 'delete_holy_bot_messages', 'tp' => 'tefillin_delete_previous', 'ta' => 'times_alert_enabled',
                            default => throw new \InvalidArgumentException('Unknown group-only toggle'),
                        };
                        $changes = [$field => !($context[$field] ?? false)];
                    }
                    $this->changes($user, $context, $changes);
                } elseif ($action === 'ap') {
                    if (!isset($context['group_id'])) {
                        throw new \RuntimeException('Group setting required');
                    }
                    $payload = (new \Shabbat\Content\ShabbatTimesFormatter($this->lang))->overview($context, $this->safeSnapshot($context));
                    $this->send($user, $payload['text'], $this->keyboard([[$this->button($user, 'back', 'sb:v:' . $token . ':announcement')]]));
                } elseif ($action === 't' && in_array($value, self::MODULES, true)) {
                    $this->changes($user, $context, [$value . '_enabled' => !($context[$value . '_enabled'] ?? false)]);
                } elseif ($action === 'e') {
                    $this->edit($user, $context, $value);
                } elseif ($action === 'r') {
                    $this->changes($user, $context, $this->moduleDefaults($value, isset($context['group_id'])));
                } elseif ($action === 'calendar') {
                    $this->shabbatView($user, $context, $this->integer($value, 0, 100));
                } elseif ($action === 'p') {
                    $this->view($user, $context, $value);
                } elseif ($action === 'f' && in_array($value, ['short','full'], true)) {
                    $context['zmanim_format'] = $value;
                    $this->view($user, $context, 'zmanim');
                } elseif ($action === 'date') {
                    $dateToken = $this->token($user, $context, 'date', ['module' => $value]);
                    $this->send($user, $this->t($user, 'date_prompt', ['token' => $dateToken]), $this->keyboard([[$this->button($user, 'back', 'sb:p:' . $token . ':' . $value)]]));
                } elseif ($action === 'day') {
                    $this->view($user, $context, $value, $parts[4] ?? null);
                } elseif ($action === 'loc') {
                    $this->locationScreen($user, $context);
                } elseif ($action === 'lookup') {
                    $this->locationPrompt($user, $context, $value);
                } elseif ($action === 'common') {
                    $this->changes($user, $context, Place::common()[$value] ?? throw new \InvalidArgumentException('Unknown curated place'), true);
                } elseif ($action === 'copy') {
                    if (!isset($context['group_id'])) {
                        throw new \RuntimeException('Group required');
                    }
                    $keys = ['location_name','country','geoname_id','latitude','longitude','timezone_id','location_source','israel','candle_minutes','havdalah_method','havdalah_value'];
                    $this->changes($user, $context, array_intersect_key($this->settings->getUser($user), array_flip($keys)), true);
                } elseif ($action === 'pause') {
                    $this->changes($user, $context, ['tefillin_suspended_date' => $this->localNow($context)->format('Y-m-d')]);
                } else {
                    throw new \InvalidArgumentException('Unsupported button');
                }
            }
            $this->gateway->answerCallback((string) ($query['query_id'] ?? ''), $notification);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->gateway->answerCallback((string) ($query['query_id'] ?? ''), $this->t($user, $error instanceof PanelEditException ? 'callback_panel_unavailable' : 'denied'));
        }
        return true;
    }
    /** Inline results never write settings or infer groups from peer_type. */
    public function handleInline(array $update): bool
    {
        $user = (string) ($update['user_id'] ?? '');
        $query = trim((string) ($update['query'] ?? ''));
        $id = (string) ($update['query_id'] ?? '');
        $offset = (string) ($update['offset'] ?? '');
        $results = [];
        $key = $user . ':' . (preg_match('/^loc\s+([A-Za-z0-9_-]{24})\s/u', $query, $flowMatch) ? $flowMatch[1] : 'public');
        ($this->inlineCancellation[$key] ?? null)?->cancel();
        $cancel = new \Amp\DeferredCancellation();
        $this->inlineCancellation[$key] = $cancel;
        try {
            if (preg_match('/^share\s+([A-Za-z0-9_-]{24})$/D', $query, $m)) {
                $context = $this->context($user, $m[1], 'share_snapshot');
                $payload = $context['_flow']['payload'];
                $snapshot = $payload['settings'];
                $text = $this->escape($this->title($user, $context)) . "\n" . $this->renderView($user, $snapshot, $payload['module'], $payload['date'] ?? null);
                $results[] = $this->article(hash('sha256', $m[1]), $this->t($user, $payload['module']) . ' - ' . $snapshot['location_name'], $snapshot['timezone_id'], $text);
                $this->gateway->answerInline($id, $results, ['cache_time' => 0, 'private' => true]);
            } elseif (preg_match('/^(tz|timezone)(?:\s+(.*))?$/uiD', $query, $m) || in_array($query, \DateTimeZone::listIdentifiers(), true)) {
                $context = null;
                $zoneQuery = isset($m[1]) ? trim($m[2] ?? '') : $query;
                if (isset($m[1]) && strtolower($m[1]) === 'timezone') {
                    $flow = $this->flows->latest($user, 'location_input');
                    if ($flow === null || ($flow['payload']['kind'] ?? '') !== 'timezone') {
                        throw new \RuntimeException('Reopen the private timezone search panel.');
                    }
                    $context = $this->context($user, $flow['payload']['token'], 'location_input');
                }
                $this->inlineTimezones($user, $id, $zoneQuery, $context);
            } elseif (preg_match('/^location(?:\s+(.*))?$/usD', $query, $m)) {
                $flow = $this->flows->latest($user, 'location_input');
                if ($flow === null || ($flow['payload']['kind'] ?? '') !== 'city') {
                    throw new \RuntimeException('Reopen the private location search panel.');
                }
                $context = $this->context($user, $flow['payload']['token'], 'location_input');
                $this->inlinePlaces($user, $id, trim($m[1] ?? ''), $offset, $cancel->getCancellation(), $context);
            } elseif (preg_match('/^loc\s+([A-Za-z0-9_-]{24})\s*(.*)$/us', $query, $m)) {
                $context = $this->context($user, $m[1], 'location');
                if (mb_strlen(trim($m[2])) < 2) {
                        $this->gateway->answerInline($id, [], ['cache_time' => 0, 'private' => true]);
                        return true;
                }
                        $search = $this->geo->search($m[2], $offset === '' ? 0 : $this->integer($offset, 0, 1000), $user, $cancel->getCancellation(), $this->userLanguage($user));
                foreach ($search['items'] as $place) {
                    $choice = $this->token($user, $context, 'location_choice', ['geoname_id' => $place['geoname_id'], 'query_key' => $search['query_key']]);
                    $markup = $this->keyboard([[$this->button($user, 'select_private', 'sb:lp:' . $choice)]]);
                    $markup['rows'][] = ['_' => 'keyboardButtonRow', 'buttons' => [['_' => 'keyboardButtonUrl', 'text' => $this->t($user, 'select_private'), 'url' => 'https://t.me/' . $this->gateway->botUsername() . '?start=loc_' . $choice]]];
                    $results[] = $this->article(substr(hash('sha256', $search['query_key'] . ':' . $m[1] . ':' . $place['geoname_id']), 0, 32), $this->placeTitle($place), $this->t($user, 'select_location'), $this->escape($this->placeTitle($place)), $markup);
                }
                        $this->gateway->answerInline($id, $results, ['cache_time' => 0, 'private' => true, 'next_offset' => (string) ($search['next_offset'] ?? '')]);
            } elseif (preg_match('/^loc\s+(.+)$/us', $query, $m)) {
                $search = $this->geo->search($m[1], $offset === '' ? 0 : $this->integer($offset, 0, 1000), $user, $cancel->getCancellation(), $this->userLanguage($user));
                foreach ($search['items'] as $place) {
                    $text = $this->escape($this->placeTitle($place)) . "\n" . $place['latitude'] . ', ' . $place['longitude'] . "\n" . $this->t($user, 'public_location') . "\n" . $this->t($user, 'location_attribution');
                    $results[] = $this->article(substr(hash('sha256', $search['query_key'] . ':' . $place['geoname_id']), 0, 32), $this->placeTitle($place), $this->t($user, 'public_location'), $text);
                }
                $this->gateway->answerInline($id, $results, ['cache_time' => 0, 'private' => true, 'next_offset' => (string) ($search['next_offset'] ?? '')]);
            } elseif (in_array(mb_strtolower($query), ['shabat','shabbat','שבת','zmanim','daf','omer'], true)) {
                $module = in_array(mb_strtolower($query), ['shabat','shabbat','שבת'], true) ? 'shabbat' : mb_strtolower($query);
                $context = $this->settings->getUser($user);
                $results[] = $this->article(hash('sha256', $module . ':' . $user . ':' . $context['revision'] . ':' . $this->localNow($context)->format('Y-m-d')), $this->t($user, $module) . ' - ' . $context['location_name'], $context['timezone_id'], $this->renderView($user, $context, $module));
                $this->gateway->answerInline($id, $results, ['cache_time' => 0, 'private' => true]);
            } elseif ($query !== '') {
                $this->inlinePlaces($user, $id, $query, $offset, $cancel->getCancellation());
            } else {
                $context = $this->settings->getUser($user);
                foreach (['shabbat','zmanim','daf','omer'] as $module) {
                    $results[] = $this->article(hash('sha256', $module . ':' . $user . ':' . $context['revision']), $this->t($user, $module) . ' - ' . $context['location_name'], $context['timezone_id'], $this->renderView($user, $context, $module));
                }
                $this->gateway->answerInline($id, $results, ['cache_time' => 0, 'private' => true]);
            }
        } catch (\Amp\CancelledException $error) {
            if (!$cancel->getCancellation()->isRequested()) {
                throw $error;
            } return true;
        } catch (\Throwable $error) {
            $results = [$this->article('location-unavailable', $this->t($user, 'unavailable'), '', $this->t($user, 'search_error', ['error' => $this->t($user, $this->geo->configured() ? 'unavailable' : 'geonames_missing')]))];
            $this->gateway->answerInline($id, $results, ['cache_time' => 0, 'private' => true]);
        } finally {
            if (($this->inlineCancellation[$key] ?? null) === $cancel) {
                unset($this->inlineCancellation[$key]);
            }
        }
        return true;
    }
    private function inlineTimezones(string $user, string $id, string $query, ?array $context): void
    {
        $results = [];
        if (mb_strlen($query) >= 2) {
            $zones = array_values(array_filter(\DateTimeZone::listIdentifiers(), static fn(string $zone): bool => stripos($zone, $query) !== false));
            foreach (array_slice($zones, 0, 25) as $zone) {
                $markup = [];
                if ($context !== null) {
                    $choice = $this->token($user, $context, 'location_choice', ['timezone_id' => $zone]);
                    $markup = $this->keyboard([[$this->button($user, 'select_private', 'sb:lp:' . $choice)]]);
                    $markup['rows'][] = ['_' => 'keyboardButtonRow', 'buttons' => [['_' => 'keyboardButtonUrl', 'text' => $this->t($user, 'select_private'), 'url' => 'https://t.me/' . $this->gateway->botUsername() . '?start=loc_' . $choice]]];
                }
                $local = $this->clock->now()->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i (P)');
                $text = '<b>' . $this->escape($zone) . '</b>' . "\n" . $local . "\n\n" . $this->t($user, 'timezone_warning');
                if ($context !== null) {
                    $text .= "\n" . $this->escape($this->title($user, $context));
                }
                $results[] = $this->article(hash('sha256', $user . ':' . $zone . ':' . ($context['group_id'] ?? 'public')), $zone, $local, $text, $markup);
            }
        }
        $this->gateway->answerInline($id, $results, ['cache_time' => 0, 'private' => true]);
    }
    private function inlinePlaces(string $user, string $id, string $query, string $offset, \Amp\Cancellation $cancellation, ?array $context = null): void
    {
        if (mb_strlen($query) < 2) {
            $this->gateway->answerInline($id, [], ['cache_time' => 0, 'private' => true]);
            return;
        }
        $search = $this->geo->search($query, $offset === '' ? 0 : $this->integer($offset, 0, 1000), $user, $cancellation, $this->userLanguage($user));
        $results = [];
        foreach ($search['items'] as $place) {
            if ($context !== null) {
                $choice = $this->token($user, $context, 'location_choice', ['geoname_id' => $place['geoname_id']]);
                $markup = $this->keyboard([[$this->button($user, 'select_private', 'sb:lp:' . $choice)]]);
                $markup['rows'][] = ['_' => 'keyboardButtonRow', 'buttons' => [['_' => 'keyboardButtonUrl', 'text' => $this->t($user, 'select_private'), 'url' => 'https://t.me/' . $this->gateway->botUsername() . '?start=loc_' . $choice]]];
                $description = $this->t($user, 'select_location') . ' · ' . $this->title($user, $context);
                $text = $this->escape($this->placeTitle($place)) . "\n" . $this->escape($this->title($user, $context));
            } else {
                $markup = [];
                $description = $this->t($user, 'public_location');
                $text = $this->escape($this->placeTitle($place)) . "\n" . $place['latitude'] . ', ' . $place['longitude'] . "\n" . $this->t($user, 'public_location') . "\n" . $this->t($user, 'location_attribution');
            }
            $results[] = $this->article(hash('sha256', $user . ':' . $search['query_key'] . ':' . ($context['group_id'] ?? 'user') . ':' . $place['geoname_id']), $this->placeTitle($place), $description, $text, $markup);
        }
        $this->gateway->answerInline($id, $results, ['cache_time' => 0, 'private' => true, 'next_offset' => (string)($search['next_offset'] ?? '')]);
    }
    public function start(string $user): void
    {
        $this->flows->revokeAction($user, 'location_input');
        $this->flows->revokeAction($user, 'settings_wizard');
        $rows = [];
        foreach (array_chunk(['shabbat','zmanim','daf','omer'], 2) as $modules) {
            $rows[] = array_map(fn(string $module): array => $this->button($user, $module, 'sb:show:' . $module), $modules);
        }
        $rows[] = [Presentation::button(['text' => $this->t($user, 'add'), 'url' => 'https://t.me/' . $this->gateway->botUsername() . '?startgroup=true'], true, 'add')];
        $rows[] = [$this->button($user, 'groups', 'sb:groups'), $this->button($user, 'preferences', 'sb:personal')];
        $rows[] = [$this->button($user, 'help', 'sb:help'), $this->button($user, 'donate', 'v3:donate')];
        $rows[] = [$this->button($user, 'source', 'v3:source'), $this->button($user, 'command_stats', 'ps:overview')];
        if (($this->authorizeOperator)($user)) {
            $rows[] = [$this->button($user, 'info_operators_title', 'sb:help:operators')];
        }
        $this->send($user, $this->t($user, 'welcome'), $this->keyboard($rows));
    }
    private function help(string $user, string $category = ''): void
    {
        $this->flows->revokeAction($user, 'location_input');
        $this->flows->revokeAction($user, 'settings_wizard');
        $categories = ['personal','calendar','groups','permissions','notifications','messages','privacy'];
        if (($this->authorizeOperator)($user)) {
            $categories[] = 'operators';
        }
        $rows = [];
        if ($category === '') {
            $text = $this->t($user, 'info_intro');
            foreach (array_chunk($categories, 2) as $chunk) {
                $rows[] = array_map(fn(string $name): array => $this->button($user, 'info_' . $name . '_title', 'sb:help:' . $name), $chunk);
            }
        } else {
            if (!in_array($category, $categories, true)) {
                throw new \RuntimeException('This information category is unavailable.');
            }
            $text = '<b>' . $this->escape($this->t($user, 'info_' . $category . '_title')) . '</b>' . "\n\n" . $this->t($user, 'info_' . $category . '_text');
            if ($category === 'personal') {
                $rows[] = [$this->button($user, 'preferences', 'sb:personal')];
            } elseif ($category === 'calendar') {
                $rows[] = [$this->button($user, 'shabbat', 'sb:show:shabbat'), $this->button($user, 'zmanim', 'sb:show:zmanim')];
                $rows[] = [$this->button($user, 'daf', 'sb:show:daf'), $this->button($user, 'omer', 'sb:show:omer')];
            } elseif (in_array($category, ['groups','permissions','notifications','messages'], true)) {
                $rows[] = [$this->button($user, 'groups', 'sb:groups')];
            } elseif ($category === 'privacy') {
                $rows[] = [$this->button($user, 'source', 'v3:source')];
                if (($this->authorizeOperator)($user)) {
                    $rows[] = [$this->button($user, 'info_operators_title', 'sb:help:operators')];
                }
            }
            $rows[] = [$this->button($user, 'back', 'sb:help')];
        }
        $rows[] = [$this->button($user, 'home', 'sb:start')];
        $this->send($user, $text, $this->keyboard($rows));
    }
    public function panel(string $user, array $context): void
    {
        $this->flows->revokeAction($user, 'location_input');
        $this->flows->revokeAction($user, 'settings_wizard');
        unset($context['_flow']);
        $token = $this->token($user, $context, 'panel');
        $rows = [];
        if (isset($context['group_id'])) {
            $this->group($user, (string) $context['group_id']);
            $rows[] = [$this->button($user, 'language', 'sb:v:' . $token . ':language'), $this->button($user, 'location', 'sb:v:' . $token . ':location')];
            $shabbat = $this->button($user, 'shabbat', 'sb:v:' . $token . ':shabbat');
            $shabbat['text'] .= ' · ' . $this->t($user, 'mode_' . $context['mode']);
            $rows[] = [$shabbat];
            foreach ([['zmanim', 'daf'], ['tefillin', 'omer']] as $screens) {
                $row = [];
                foreach ($screens as $screen) {
                    $button = $this->button($user, $screen, 'sb:v:' . $token . ':' . $screen);
                    $button['text'] .= ' · ' . $this->t($user, ($context[$screen . '_enabled'] ?? false) ? 'on' : 'off');
                    $row[] = $button;
                }
                $rows[] = $row;
            }
            $rows[] = [$this->button($user, 'moderation', 'sb:v:' . $token . ':moderation'),
                $this->button($user, 'messages', 'v3:templates:' . $context['group_id'])];
            $rows[] = [$this->button($user, 'schedule', 'sb:v:' . $token . ':schedule'),
                $this->button($user, 'status', 'sb:v:' . $token . ':status')];
            if ($this->permissionRecovery !== null) {
                $rows[] = [$this->button($user, 'permission_recovery', 'sb:pr:' . $token)];
            }
            $snapshot = $this->safeSnapshot($context);
            $next = isset($snapshot['next_close_at']) ? $this->time($snapshot['next_close_at'], $context) : $this->t($user, 'unavailable');
            $text = $this->t($user, 'group_panel', ['title' => $this->title($user, $context), 'location' => $context['location_name'], 'timezone' => $context['timezone_id'], 'mode' => $this->t($user, 'mode_' . $context['mode']), 'next' => $next]);
            if (isset($snapshot['error'])) {
                $text .= "\n" . $this->t($user, 'provider_unavailable');
            }
        } else {
            foreach (['language','location','candles','havdalah'] as $screen) {
                $rows[] = [$this->button($user, $screen, 'sb:v:' . $token . ':' . $screen)];
            }
            $text = $this->t($user, 'personal_panel', ['location' => $context['location_name'], 'timezone' => $context['timezone_id']]);
        }
        $rows[] = [$this->button($user, 'back', 'sb:start')];
        $this->send($user, $text, $this->keyboard($rows));
    }
    private function groupList(string $user, int $offset = 0, string $search = ''): void
    {
        $this->flows->revokeAction($user, 'location_input');
        $this->flows->revokeAction($user, 'settings_wizard');
        $rows = [];
        $groups = $this->settings->listGroups($offset, 20, $search);
        foreach ($groups as $group) {
            if (!$this->gateway->isGroupAdmin($user, (string) $group['group_id'])) {
                continue;
            }
            $token = $this->token($user, $group, 'panel');
            $rows[] = [['text' => (string) ($group['title'] ?: $group['group_id']), 'data' => 'sb:group:' . $token]];
        }
        $paging = [];
        if ($offset > 0) {
            $prev = $this->token($user, $this->settings->getUser($user), 'groups', ['offset' => max(0, $offset - 20), 'search' => $search]);
            $paging[] = $this->button($user, 'previous', 'sb:groups:' . $prev);
        }
        if (count($groups) === 20) {
            $next = $this->token($user, $this->settings->getUser($user), 'groups', ['offset' => $offset + 20, 'search' => $search]);
            $paging[] = $this->button($user, 'next', 'sb:groups:' . $next);
        }
        if ($paging) {
            $rows[] = $paging;
        } $rows[] = [$this->button($user, 'back', 'sb:start')];
        $this->send($user, $this->t($user, count($rows) > 1 ? 'settings_search' : 'no_groups'), $this->keyboard($rows));
    }
    private function screen(string $user, array $context, string $screen): void
    {
        unset($context['_flow']['payload']['return_wizard'], $context['_flow']['payload']['return_offset']);
        $context['_flow']['payload']['return_screen'] = $screen === 'shabbat_mode' ? 'shabbat' : $screen;
        $token = $this->token($user, $context, 'panel');
        $rows = [];
        $text = '';
        if ($screen === 'language') {
            $text = $this->t($user, 'language_choose');
            foreach (array_chunk(Lang::CODES, 2) as $languages) {
                $rows[] = array_map(static fn(string $code): array=>['text' => Lang::NATIVE_NAMES[$code],'data' => 'sb:s:' . $token . ':l:' . $code], $languages);
            }
        } elseif ($screen === 'location') {
            $this->locationScreen($user, $context);
            return;
        } elseif ($screen === 'candles') {
            $text = $this->t($user, 'candle_choose');
            foreach (array_chunk([10,15,18,20,22,30,40], 4) as $chunk) {
                $rows[] = array_map(fn(int $v): array => ['text' => (string) $v, 'data' => 'sb:s:' . $token . ':c:' . $v], $chunk);
            }
            $rows[] = [$this->button($user, 'city_custom', 'sb:s:' . $token . ':c:city')];
            $rows[] = [$this->button($user, 'edit', 'sb:e:' . $token . ':shabbat')];
        } elseif ($screen === 'havdalah') {
            $text = $this->t($user, 'havdalah_choose');
            $rows[] = [$this->button($user, 'degrees', 'sb:s:' . $token . ':h:8.5')];
            foreach ([42,50,72] as $v) {
                $rows[] = [['text' => $this->t($user, 'minutes', ['value' => $v]), 'data' => 'sb:s:' . $token . ':h:' . $v]];
            }
            $rows[] = [$this->button($user, 'edit', 'sb:e:' . $token . ':shabbat')];
        } elseif (in_array($screen, ['moderation','restrictions','announcement','botpermissions'], true)) {
            if (!isset($context['group_id'])) {
                throw new \RuntimeException('Group setting required');
            }
            if ($screen === 'botpermissions') {
                $capabilities = $this->capabilities($user, $context);
                $verified = ($capabilities['verified'] ?? false) === true;
                $text = $this->t($user, 'bot_permissions_intro');
                foreach (['bot_role_creator' => 'creator','bot_role_administrator' => 'is_admin'] as $label => $field) {
                    $text .= "\n" . $this->t($user, $label) . ': ' . $this->t($user, $verified ? (($capabilities[$field] ?? false) ? 'on' : 'off') : 'unavailable');
                }
                foreach (\Shabbat\Telegram\AdminRights::FLAGS as $flag) {
                    $text .= "\n" . $this->t($user, 'admin_right_' . $flag) . ': ' . $this->t($user, $verified ? (($capabilities['rights'][$flag] ?? false) ? 'on' : 'off') : 'unavailable');
                }
                $text .= "\n\n" . $this->t($user, 'moderation_delete_capability', ['state' => $this->t($user, $verified ? (($capabilities['can_delete_messages'] ?? false) ? 'on' : 'off') : 'unavailable')]);
            } elseif ($screen === 'restrictions') {
                $text = $this->t($user, 'moderation_intro') . "\n\n" . $this->t($user, 'moderation_visibility_warning');
                $mask = $context['lock_block_flags'] ?? Permissions::DEFAULT_BLOCK_FLAGS;
                foreach (Permissions::BLOCK_FLAGS as $flag) {
                    $selected = in_array($flag, $mask, true);
                    $rows[] = [['text' => ($selected ? '✅ ' : '⬜ ') . $this->t($user, 'permission_' . $flag), 'data' => 'sb:flag:' . $token . ':' . $flag, 'style' => ['_' => 'keyboardButtonStyle', ($selected ? 'bg_primary' : 'bg_danger') => true]]];
                }
                foreach (['default','none','all'] as $preset) {
                    $rows[] = [$this->button($user, 'moderation_' . $preset, 'sb:mask:' . $token . ':' . $preset)];
                }
            } elseif ($screen === 'announcement') {
                $text = $this->t($user, 'announcement_schedule_intro', ['timezone' => $context['timezone_id']]) . "\n\n" . $this->describe($user, array_intersect_key($context, array_flip(['times_alert_enabled','times_alert_time','times_alert_mode','times_alert_before_minutes'])));
                $rows[] = [$this->button($user, $context['times_alert_enabled'] ? 'on' : 'off', 'sb:toggle:' . $token . ':ta')];
                foreach (array_chunk(['10:00','11:00','12:00','12:30','13:00','13:30','14:00'], 3) as $chunk) {
                    $rows[] = array_map(static fn(string $hour): array => ['text' => $hour, 'data' => 'sb:at:' . $token . ':' . str_replace(':', '', $hour)], $chunk);
                }
                $wizard = $this->token($user, $context, 'panel', ['wizard_module' => 'shabbat']);
                array_push($rows, ...$this->wizardFieldButtons($user, $context, 'shabbat', $wizard, fields: SettingsWizard::sectionFields('announcement')));
                $rows[] = [$this->button($user, 'preview', 'sb:ap:' . $token), $this->button($user, 'messages', 'v3:templates:' . $context['group_id'] . ':times_alert')];
            } else {
                $text = $this->t($user, 'moderation_intro') . "\n\n" . $this->t($user, 'moderation_cleanup_hint') . "\n\n" . $this->t($user, 'moderation_bot_mode_hint');
                $rows[] = [$this->button($user, 'moderation_permissions', 'sb:v:' . $token . ':restrictions')];
                foreach (['du' => 'delete_holy_user_messages', 'db' => 'delete_holy_bot_messages'] as $short => $field) {
                    $rows[] = [['text' => $this->t($user, 'field_' . $field) . ' · ' . $this->t($user, ($context[$field] ?? false) ? 'on' : 'off'), 'data' => 'sb:toggle:' . $token . ':' . $short]];
                }
                $capabilities = $this->capabilities($user, $context);
                $text .= "\n\n" . $this->t($user, 'moderation_delete_capability', ['state' => ($capabilities['verified'] ?? false) ? $this->t($user, ($capabilities['can_delete_messages'] ?? false) ? 'on' : 'off') : $this->t($user, 'unavailable')]);
                $rows[] = [$this->button($user, 'bot_permissions', 'sb:v:' . $token . ':botpermissions')];
                $rows[] = [$this->button($user, 'moderation_default', 'sb:mask:' . $token . ':default')];
            }
        } elseif ($screen === 'shabbat_mode') {
            if (!isset($context['group_id'])) {
                throw new \RuntimeException('Group setting required');
            }
            $text = $this->t($user, 'settings_mode_intro', ['title' => $this->title($user, $context)]);
            foreach (array_chunk(['off', 'messages', 'lock', 'lock_messages'], 2) as $modes) {
                $row = [];
                foreach ($modes as $mode) {
                    $button = $this->button($user, 'mode_' . $mode, 'sb:s:' . $token . ':m:' . $mode);
                    $button['text'] = (($context['mode'] ?? 'off') === $mode ? '✅ ' : '') . $button['text'];
                    $row[] = $button;
                }
                $rows[] = $row;
            }
        } elseif (in_array($screen, ['shabbat_timing', 'shabbat_messages'], true)) {
            if (!isset($context['group_id'])) {
                throw new \RuntimeException('Group setting required');
            }
            $text = $this->t($user, 'wizard_title', ['module' => $this->t($user, 'settings_' . $screen), 'title' => $this->title($user, $context)])
                . "\n\n" . $this->t($user, 'settings_' . $screen . '_intro');
            $wizard = $this->token($user, $context, 'panel', ['wizard_module' => 'shabbat']);
            $rows = $this->wizardFieldButtons($user, $context, 'shabbat', $wizard, fields: SettingsWizard::sectionFields($screen));
            if ($screen === 'shabbat_messages') {
                $rows[] = [$this->button($user, 'template_kind_close', 'v3:templates:' . $context['group_id'] . ':close'),
                    $this->button($user, 'template_kind_open', 'v3:templates:' . $context['group_id'] . ':open')];
                $rows[] = [$this->button($user, 'announcement_schedule', 'sb:v:' . $token . ':announcement')];
            } else {
                $rows[] = [$this->button($user, 'preview', 'sb:p:' . $token . ':shabbat')];
            }
        } elseif ($screen === 'shabbat') {
            if (!isset($context['group_id'])) {
                throw new \RuntimeException('Group setting required');
            }
            $text = $this->t($user, 'settings_shabbat_summary', [
                'title' => $this->title($user, $context), 'mode' => $this->t($user, 'mode_' . $context['mode']),
                'location' => $context['location_name'], 'timezone' => $context['timezone_id'],
                'shabbat' => $this->t($user, ($context['shabbat_enabled'] ?? true) ? 'on' : 'off'),
                'holidays' => $this->t($user, ($context['holidays_enabled'] ?? true) ? 'on' : 'off'),
                'candles' => \Shabbat\Calendar\CalendarEngine::candleMinutes($context),
                'havdalah' => SettingsWizard::current('shabbat', 'havdalah_value', $context, $this->userLanguage($user)),
                'close' => $context['close_before_candles_minutes'], 'open' => $context['open_after_havdalah_minutes'],
            ]);
            $rows[] = [['text' => $this->t($user, 'settings_mode_button', ['mode' => $this->t($user, 'mode_' . $context['mode'])]),
                'data' => 'sb:v:' . $token . ':shabbat_mode', 'style' => ['_' => 'keyboardButtonStyle', ($context['mode'] === 'off' ? 'bg_danger' : 'bg_primary') => true]]];
            $saturday = $this->button($user, 'shabbat_enabled', 'sb:s:' . $token . ':s:1');
            $saturday['text'] .= ' · ' . $this->t($user, ($context['shabbat_enabled'] ?? true) ? 'on' : 'off');
            $holidays = $this->button($user, 'holidays_enabled', 'sb:s:' . $token . ':y:1');
            $holidays['text'] .= ' · ' . $this->t($user, ($context['holidays_enabled'] ?? true) ? 'on' : 'off');
            $rows[] = [$saturday, $holidays];
            $rows[] = [$this->button($user, 'settings_shabbat_timing', 'sb:v:' . $token . ':shabbat_timing'),
                $this->button($user, 'settings_shabbat_messages', 'sb:v:' . $token . ':shabbat_messages')];
            $rows[] = [$this->button($user, 'announcement_schedule', 'sb:v:' . $token . ':announcement'),
                $this->button($user, 'moderation', 'sb:v:' . $token . ':moderation')];
            $rows[] = [$this->button($user, 'preview', 'sb:p:' . $token . ':shabbat'),
                $this->button($user, 'wizard_more', 'sb:e:' . $token . ':shabbat')];
            $rows[] = [$this->button($user, 'reset', 'sb:r:' . $token . ':shabbat')];
        } elseif (in_array($screen, self::MODULES, true)) {
            if (!isset($context['group_id'])) {
                throw new \RuntimeException('Group module required');
            }
            $text = $this->t($user, 'module_panel', ['module' => $this->t($user, $screen), 'title' => $this->title($user, $context), 'settings' => $this->formSummary($user, $context, $screen)]);
            $rows[] = [$this->button($user, ($context[$screen . '_enabled'] ?? false) ? 'on' : 'off', 'sb:t:' . $token . ':' . $screen)];
            $wizardToken = $this->token($user, $context, 'panel', ['wizard_module' => $screen]);
            array_push($rows, ...$this->wizardFieldButtons($user, $context, $screen, $wizardToken));
            if (count(SettingsWizard::fields($screen)) > 8) {
                $rows[] = [$this->button($user, 'wizard_more', 'sb:wp:' . $wizardToken . ':8')];
            }
            $rows[] = [$this->button($user, 'preview', 'sb:p:' . $token . ':' . $screen),
                $this->button($user, 'messages', 'v3:templates:' . $context['group_id'] . ':' . $screen)];
            $rows[] = [$this->button($user, 'reset', 'sb:r:' . $token . ':' . $screen)];
            if ($screen === 'tefillin') {
                $rows[] = [$this->button($user, 'suspend', 'sb:pause:' . $token)];
                $rows[] = [['text' => $this->t($user, 'field_delete_previous') . ' · ' . $this->t($user, ($context['tefillin_delete_previous'] ?? false) ? 'on' : 'off'), 'data' => 'sb:toggle:' . $token . ':tp']];
            }
        } elseif ($screen === 'status' || $screen === 'schedule') {
            $snap = $this->safeSnapshot($context);
            if ($screen === 'status') {
                $text = $this->t($user, 'status_view', ['title' => $this->title($user, $context), 'utc' => $this->clock->now()->format('Y-m-d H:i:s T'), 'local' => $this->localNow($context)->format('Y-m-d H:i:s T'), 'coverage' => json_encode($snap['coverage'] ?? [], JSON_UNESCAPED_UNICODE), 'fetched' => isset($snap['fetched_at']) ? $this->time($snap['fetched_at'], $context) : '-', 'current' => $snap['current_event']['title'] ?? '-', 'next' => $snap['next_event']['title'] ?? '-', 'error' => $snap['error'] ?? '-']);
            } else {
                $q = $this->settings->database()->pdo()->prepare("SELECT module,run_at,status,last_error FROM scheduled_jobs WHERE group_id=? AND status IN ('pending','leased') ORDER BY run_at LIMIT 20");
                $q->execute([$context['group_id']]);
                $content = [];
                foreach ($q->fetchAll() as $job) {
                    $content[] = $this->time((int) $job['run_at'], $context) . ' · ' . $job['module'] . ' · ' . $job['status'] . ' · ' . ($job['last_error'] ?? '') . ' · Hebcal / revision ' . $context['revision'];
                }
                $text = $this->t($user, 'schedule_view', ['title' => $this->title($user, $context), 'content' => implode("\n", $content) ?: '-']);
            }
        } else {
            throw new \InvalidArgumentException('Unknown screen');
        }
        if ($this->permissionRecovery !== null && isset($context['group_id']) && in_array($screen, ['shabbat', 'moderation', 'restrictions', 'botpermissions', 'status'], true)) {
            $rows[] = [$this->button($user, 'permission_recovery', 'sb:pr:' . $token)];
        }
        $parent = match ($screen) {
            'shabbat_mode', 'shabbat_timing', 'shabbat_messages', 'announcement', 'moderation' => 'shabbat',
            'restrictions', 'botpermissions' => 'moderation',
            default => null,
        };
        $rows[] = [$this->button($user, 'back', $parent === null ? 'sb:group:' . $token : 'sb:v:' . $token . ':' . $parent)];
        $this->send($user, $text, $this->keyboard($rows));
    }
    private function cityResults(string $user, array $context, string $query, int $offset = 0): void
    {
        $search = $this->geo->search($query, $offset, $user, null, $this->userLanguage($user));
        $buttons = [];
        foreach ($search['items'] as $place) {
            $choice = $this->token($user, $context, 'location_choice', ['geoname_id' => $place['geoname_id']]);
            $buttons[] = [['text' => $this->placeTitle($place), 'data' => 'sb:lp:' . $choice]];
        }
        if ($search['next_offset'] !== null) {
            $next = $this->token($user, $context, 'location_page', ['query' => $query, 'offset' => $search['next_offset']]);
            $buttons[] = [$this->button($user, 'next', 'sb:lc:' . $next)];
        }
        $panel = $this->token($user, $context, 'panel');
        $buttons[] = [$this->button($user, 'back', 'sb:loc:' . $panel)];
        $this->send($user, $this->t($user, $search['items'] !== [] ? 'location_choose' : 'no_results'), $this->keyboard($buttons));
    }
    private function locationScreen(string $user, array $context): void
    {
        $panel = $this->token($user, $context, 'panel');
        $this->flows->revokeAction($user, 'location_input');
        $this->flows->revokeAction($user, 'settings_wizard');
        $rows = [];
        $text = $this->t($user, 'location_menu_intro', ['title' => $this->title($user, $context), 'location' => $context['location_name'], 'timezone' => $context['timezone_id']]);
        $rows[] = [$this->button($user, 'location_input_city_title', 'sb:lookup:' . $panel . ':city'), $this->button($user, 'location_input_gps_title', 'sb:lookup:' . $panel . ':gps')];
        $rows[] = [$this->button($user, 'location_input_coordinates_title', 'sb:lookup:' . $panel . ':coordinates'), $this->button($user, 'location_input_geoname_title', 'sb:lookup:' . $panel . ':geoname')];
        $rows[] = [$this->button($user, 'location_input_timezone_title', 'sb:lookup:' . $panel . ':timezone')];
        if (!$this->geo->configured()) {
            $text .= "\n\n" . $this->t($user, 'geonames_missing');
        }
        foreach (Place::common() as $key => $place) {
            $rows[] = [['text' => $place['location_name'], 'data' => 'sb:common:' . $panel . ':' . $key]];
        }
        $rows[] = [$this->button($user, 'calendar_israel', 'sb:s:' . $panel . ':i:1'), $this->button($user, 'calendar_diaspora', 'sb:s:' . $panel . ':i:0')];
        if (isset($context['group_id'])) {
            $rows[] = [$this->button($user, 'copy_personal', 'sb:copy:' . $panel)];
        }
        $rows[] = [$this->button($user, 'back', 'sb:group:' . $panel)];
        $this->send($user, $text, $this->keyboard($rows));
    }
    private function locationPrompt(string $user, array $context, string $kind): void
    {
        if (!in_array($kind, ['city','timezone','geoname','coordinates','gps'], true) || !$this->gateway instanceof CallbackUiGateway) {
            throw new \InvalidArgumentException('An active private location panel is required.');
        }
        $this->flows->revokeAction($user, 'location_input');
        $this->flows->revokeAction($user, 'settings_wizard');
        $payload = ['kind' => $kind, 'message_id' => $this->gateway->messageId()];
        $token = $this->token($user, $context, 'location_input', $payload);
        $this->flows->updatePayload($token, $user, 'location_input', $payload + ['token' => $token]);
        $text = '<b>' . $this->escape($this->t($user, 'location_input_' . $kind . '_title')) . '</b>' . "\n" . $this->escape($this->title($user, $context)) . "\n\n" . $this->t($user, 'location_input_' . $kind . '_prompt');
        $panel = $this->token($user, $context, 'panel');
        $rows = [];
        if (($kind === 'city' && $this->geo->configured()) || $kind === 'timezone') {
            $text .= "\n\n" . $this->t($user, $kind === 'city' ? 'location_inline_hint' : 'timezone_inline_hint', ['bot' => $this->gateway->botUsername()]);
            $rows[] = [['text' => $this->t($user, 'inline_search'), 'switch_inline' => $kind === 'city' ? 'location ' : 'timezone ', 'same_peer' => true]];
        }
        if (in_array($kind, ['city','geoname','gps'], true) && !$this->geo->configured()) {
            $text .= "\n\n" . $this->t($user, 'geonames_missing');
        }
        $rows[] = [$this->button($user, 'cancel', 'sb:loc:' . $panel)];
        $this->send($user, $text, $this->keyboard($rows));
    }
    private function locationInput(string $user, array $message): bool
    {
        $text = trim((string)($message['text'] ?? ''));
        if ($text !== '' && preg_match('~^[/!.][a-z]+(?:@|\s|$)~i', $text)) {
            return false;
        }
        $flow = $this->flows->latest($user, 'location_input');
        if ($flow === null) {
            return false;
        }
        $kind = $flow['payload']['kind'] ?? '';
        if (($text === '' && !isset($message['location'])) || (isset($message['location']) && $kind !== 'gps')) {
            return false;
        }
        $request = new self($this->settings, $this->flows, $this->calendar, new CallbackUiGateway($this->gateway, $user, (int)$flow['payload']['message_id']), $this->geo, $this->clock, $this->lang, $this->authorizeOperator, $this->permissionRecovery);
        try {
            $context = $this->context($user, $flow['payload']['token'], 'location_input');
            if ($kind === 'city') {
                $request->cityResults($user, $context, $text);
            } elseif ($kind === 'geoname') {
                $id = $this->integer($text, 1, 2147483647);
                $request->changes($user, $context, $this->geo->get($id, null, $this->userLanguage($user)), true);
            } elseif ($kind === 'coordinates') {
                if (!preg_match('/^(-?\d+(?:\.\d+)?)\s*[, ]\s*(-?\d+(?:\.\d+)?)(?:\s+([A-Za-z0-9_+\/-]+))?$/D', $text, $parts)) {
                    throw new \InvalidArgumentException('Coordinates expected.');
                }
                $place = $this->coordinatePlace($user, $parts[1], $parts[2], $parts[3] ?? $context['timezone_id'], $this->t($user, 'location_input_coordinates_title'), 'coordinates');
                $request->changes($user, $context, $place, true);
            } elseif ($kind === 'timezone') {
                $zones = array_values(array_filter(\DateTimeZone::listIdentifiers(), static fn(string $zone): bool => stripos($zone, $text) !== false));
                if ($zones === []) {
                    throw new \InvalidArgumentException('An IANA timezone is required.');
                }
                $rows = [];
                foreach (array_slice($zones, 0, 25) as $zone) {
                    $choice = $this->token($user, $context, 'location_choice', ['timezone_id' => $zone]);
                    $rows[] = [['text' => $zone, 'data' => 'sb:lp:' . $choice]];
                }
                $panel = $this->token($user, $context, 'panel');
                $rows[] = [$this->button($user, 'back', 'sb:loc:' . $panel)];
                $request->send($user, $this->t($user, 'timezone_warning'), $this->keyboard($rows));
            } elseif ($kind === 'gps') {
                if (!isset($message['location']) || ($message['replied_bot'] ?? false) !== true || ($message['reply_message_id'] ?? null) !== $flow['payload']['message_id']) {
                    throw new \InvalidArgumentException('Reply to the active bot location prompt.');
                }
                $gps = $message['location'];
                $place = $this->coordinatePlace($user, $gps['latitude'] ?? $gps['lat'] ?? null, $gps['longitude'] ?? $gps['long'] ?? null, $this->geo->configured() ? 'auto' : $context['timezone_id'], $this->t($user, 'location_input_gps_title'), 'telegram_gps');
                $request->changes($user, $context, $place, true);
            } else {
                return false;
            }
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (PanelEditException) {
            // Missing panels require an explicit new /settings command; never create fallback messages.
        } catch (\Throwable) {
            try {
                if (!isset($context)) {
                    $this->flows->revokeAction($user, 'location_input');
                    $this->flows->revokeAction($user, 'settings_wizard');
                    $request->send($user, $this->t($user, 'denied'), $this->keyboard([[$this->button($user, 'groups', 'sb:groups')], [$this->button($user, 'home', 'sb:start')]]));
                } else {
                    $panel = $this->token($user, $context, 'panel');
                    $request->send($user, $this->t($user, 'location_input_invalid'), $this->keyboard([[$this->button($user, 'back', 'sb:loc:' . $panel)]]));
                }
            } catch (PanelEditException) {
            }
        }
        return true;
    }
    private function coordinatePlace(string $user, mixed $latitude, mixed $longitude, string $zone, string $name, string $source): array
    {
        $place = Place::validate(['latitude' => $latitude, 'longitude' => $longitude, 'timezone_id' => $zone === 'auto' ? 'UTC' : $zone, 'location_name' => $name, 'location_source' => $source]);
        if ($zone === 'auto') {
            if (!$this->geo->configured()) {
                throw new \RuntimeException($this->t($user, 'auto_timezone_missing'));
            }
            $place = array_replace($place, $this->geo->timezone($place['latitude'], $place['longitude'], null, $this->userLanguage($user)));
        }
        return $place;
    }
    private function locationPreview(string $user, string $token): void
    {
        $context = $this->context($user, $token, 'location_choice');
        $payload = $context['_flow']['payload'];
        if (isset($payload['geoname_id'])) {
            $place = $this->geo->get((int) $payload['geoname_id'], null, $this->userLanguage($user));
        } elseif (isset($payload['timezone_id'])) {
            $place = ['timezone_id' => $payload['timezone_id']];
        } else {
            throw new \RuntimeException('Invalid server-side place selection');
        }
        $this->changes($user, $context, $place, true);
    }
    private function changes(string $user, array $context, array $changes, bool $location = false): void
    {
        $candidate = array_replace($context, $changes);
        $text = $this->t($user, 'changes_preview', ['title' => $this->title($user, $context), 'changes' => $this->describe($user, $changes)]);
        if ($location) {
            $zone = new \DateTimeZone($candidate['timezone_id']);
            $text = $this->t($user, 'location_preview', ['title' => $this->title($user, $context), 'location' => $candidate['location_name'], 'country' => $candidate['country'] ?? '', 'region' => $candidate['admin_region'] ?? '', 'latitude' => $candidate['latitude'], 'longitude' => $candidate['longitude'], 'geoname' => $candidate['geoname_id'] ?? '-', 'timezone' => $candidate['timezone_id'], 'offset' => $this->clock->now()->setTimezone($zone)->format('P'), 'warning' => isset($changes['timezone_id']) && count($changes) === 1 ? $this->t($user, 'timezone_warning') : '']);
            if (in_array($candidate['location_source'] ?? '', ['geonames','curated','default'], true)) {
                $text .= "\n" . $this->t($user, 'location_attribution');
            }
            $text .= "\n\n" . $this->renderView($user, $candidate, 'shabbat');
        }
        unset($changes['admin_region']);
        $token = $this->token($user, $context, 'save', ['changes' => $changes]);
        $this->send($user, $text, $this->keyboard([[$this->button($user, 'confirm', 'sb:save:' . $token)], [$this->button($user, 'cancel', 'sb:cancel:' . $token)]]));
    }
    /** Navigation is carried in server-owned flow metadata, never interpreted as a write. */
    private function settingsNavigation(array $context): array
    {
        return array_intersect_key($context['_flow']['payload'] ?? [], array_flip(['return_screen', 'return_wizard', 'return_offset']));
    }

    private function settingsReturnData(array $context, string $token, string $module): string
    {
        $navigation = $this->settingsNavigation($context);
        if (isset($navigation['return_wizard'])) {
            return 'sb:wp:' . $token . ':' . ($navigation['return_offset'] ?? 0);
        }
        return isset($navigation['return_screen']) ? 'sb:v:' . $token . ':' . $navigation['return_screen'] : 'sb:e:' . $token . ':' . $module;
    }

    private function resumeSettings(string $user, array $context, array $navigation): void
    {
        $this->flows->revokeAction($user, 'settings_wizard');
        unset($context['_flow']);
        $context['_flow']['payload'] = array_intersect_key($navigation, array_flip(['return_screen', 'return_wizard', 'return_offset']));
        if (isset($navigation['return_wizard'])) {
            $this->wizardFields($user, $context, $navigation['return_wizard'], (int)($navigation['return_offset'] ?? 0));
        } elseif (isset($navigation['return_screen'])) {
            $this->screen($user, $context, $navigation['return_screen']);
        } else {
            $this->panel($user, $context);
        }
    }

    private function edit(string $user, array $context, string $module): void
    {
        $this->wizardFields($user, $context, $module);
    }

    private function wizardFields(string $user, array $context, string $module, int $offset = 0): void
    {
        $fields = SettingsWizard::fields($module, isset($context['group_id']));
        $offset = max(0, min($offset, max(0, count($fields) - 1)));
        $token = $this->token($user, $context, 'panel', ['wizard_module' => $module, 'return_wizard' => $module, 'return_offset' => $offset]);
        $rows = $this->wizardFieldButtons($user, $context, $module, $token, $offset);
        $paging = [];
        if ($offset > 0) {
            $paging[] = $this->button($user, 'previous', 'sb:wp:' . $token . ':' . max(0, $offset - 8));
        }
        if ($offset + 8 < count($fields)) {
            $paging[] = $this->button($user, 'next', 'sb:wp:' . $token . ':' . ($offset + 8));
        }
        if ($paging !== []) {
            $rows[] = $paging;
        }
        $rows[] = [$this->button($user, 'back', isset($context['group_id']) ? 'sb:v:' . $token . ':' . $module : 'sb:group:' . $token)];
        $text = $this->t($user, 'wizard_title', ['module' => $this->t($user, $module), 'title' => $this->title($user, $context)])
            . "\n\n" . $this->t($user, 'wizard_choose_field') . "\n\n" . $this->formSummary($user, $context, $module);
        $this->send($user, $text, $this->keyboard($rows));
    }

    private function wizardFieldButtons(string $user, array $context, string $module, string $token, int $offset = 0, ?array $fields = null): array
    {
        $rows = [];
        $all = SettingsWizard::fields($module, isset($context['group_id']));
        $visible = $fields === null ? array_slice($all, $offset, 8, true) : array_intersect($all, $fields);
        foreach ($visible as $index => $field) {
            $value = SettingsWizard::current($module, $field, $context, $this->userLanguage($user));
            $rows[] = [['text' => mb_substr($this->t($user, 'field_' . $field) . ': ' . $value, 0, 110),
                'data' => 'sb:wf:' . $token . ':' . $index]];
        }
        return $rows;
    }

    private function wizardField(string $user, array $context, string $module, string $field): void
    {
        $definition = SettingsWizard::definition($module, $field, isset($context['group_id']));
        if ($field === 'template' && isset($context['group_id'])) {
            $rows = [[$this->button($user, 'messages', 'v3:templates:' . $context['group_id'] . ':' . $module)]];
            $token = $this->token($user, $context, 'panel');
            $rows[] = [$this->button($user, 'back', 'sb:v:' . $token . ':' . $module)];
            $this->send($user, $this->t($user, 'wizard_template'), $this->keyboard($rows));
            return;
        }
        if (($definition['kind'] ?? '') === 'flags') {
            $this->screen($user, $context, 'restrictions');
            return;
        }
        if (($definition['kind'] ?? '') === 'days') {
            $this->wizardPrompt($user, $context, $module, $field);
            return;
        }
        $token = $this->token($user, $context, 'panel', ['wizard_module' => $module, 'wizard_field' => $field]);
        $rows = [];
        foreach ($definition['options'] ?? [] as $index => $option) {
            $changes = SettingsWizard::changes($module, $field, $option, $context, fn(string $m, string $form): array => $this->parseForm($m, $form));
            $candidate = array_replace($context, $changes);
            $label = SettingsWizard::current($module, $field, $candidate, $this->userLanguage($user));
            $rows[] = [['text' => $label, 'data' => 'sb:wo:' . $token . ':' . $index,
                'style' => ['_' => 'keyboardButtonStyle'] + ($option === false ? ['bg_danger' => true] : [])]];
        }
        if ($rows !== []) {
            $rows = array_chunk(array_merge(...$rows), in_array($definition['kind'], ['bool', 'enum'], true) ? 2 : 3);
        }
        if (!in_array($definition['kind'] ?? '', ['bool','enum'], true)) {
            $rows[] = [$this->button($user, 'wizard_custom', 'sb:wi:' . $token)];
        }
        $rows[] = [$this->button($user, 'back', $this->settingsReturnData($context, $token, $module))];
        $this->send($user, $this->wizardQuestion($user, $context, $module, $field, $definition), $this->keyboard($rows));
    }

    private function wizardQuestion(string $user, array $context, string $module, string $field, array $definition): string
    {
        $kind = $definition['kind'] ?? 'text';
        $hint = in_array($kind, ['time','times','integer','float','minutes_list','text','days'], true)
            ? $this->t($user, 'wizard_hint_' . $kind) : $this->t($user, 'wizard_choose_option');
        return $this->t($user, 'wizard_input', ['field' => $this->t($user, 'field_' . $field),
            'current' => SettingsWizard::current($module, $field, $context, $this->userLanguage($user)),
            'hint' => $hint, 'title' => $this->title($user, $context)]);
    }

    private function wizardPrompt(string $user, array $context, string $module, string $field): void
    {
        if (!$this->gateway instanceof CallbackUiGateway) {
            throw new \InvalidArgumentException('An active private settings panel is required.');
        }
        $definition = SettingsWizard::definition($module, $field, isset($context['group_id']));
        $this->flows->revokeAction($user, 'settings_wizard');
        $navigation = $this->settingsNavigation($context);
        unset($context['_flow']);
        $context['_flow']['payload'] = $navigation;
        $name = $module === 'shabbat' || in_array($field, ['quiet_start','quiet_end'], true) ? $field : $module . '_' . $field;
        $payload = ['module' => $module, 'field' => $field, 'message_id' => $this->gateway->messageId(),
            'selected' => ($definition['kind'] ?? '') === 'days' ? ($context[$name] ?? []) : []] + $navigation;
        $token = $this->token($user, $context, 'settings_wizard', $payload);
        $this->flows->updatePayload($token, $user, 'settings_wizard', $payload + ['token' => $token]);
        if (($definition['kind'] ?? '') === 'days') {
            $this->wizardDays($user, $this->context($user, $token, 'settings_wizard'), -1);
            return;
        }
        $panel = $this->token($user, $context, 'panel');
        $this->send(
            $user,
            $this->wizardQuestion($user, $context, $module, $field, $definition),
            $this->keyboard([[$this->button($user, 'cancel', $this->settingsReturnData($context, $panel, $module))]])
        );
    }

    private function wizardDays(string $user, array $context, ?int $toggle): void
    {
        $payload = $context['_flow']['payload'];
        $token = $payload['token'];
        $selected = $payload['selected'];
        if ($toggle !== null && $toggle >= 0) {
            $selected = in_array($toggle, $selected, true) ? array_values(array_diff($selected, [$toggle])) : [...$selected, $toggle];
            sort($selected);
            $payload['selected'] = $selected;
            $this->flows->updatePayload($token, $user, 'settings_wizard', $payload);
        }
        if ($toggle === null) {
            $changes = SettingsWizard::changes($payload['module'], $payload['field'], $selected, $context, fn(string $m, string $form): array => $this->parseForm($m, $form));
            $this->changes($user, $context, $changes);
            return;
        }
        $rows = [];
        foreach (range(0, 6) as $day) {
            $rows[] = [['text' => (in_array($day, $selected, true) ? '✅ ' : '⬜ ') . $this->t($user, 'times_day_name_' . $day),
                'data' => 'sb:wd:' . $token . ':' . $day]];
        }
        $rows[] = [$this->button($user, 'wizard_done', 'sb:wc:' . $token)];
        $panel = $this->token($user, $context, 'panel');
        $rows[] = [$this->button($user, 'cancel', $this->settingsReturnData($context, $panel, $payload['module']))];
        $this->send($user, $this->wizardQuestion($user, $context, $payload['module'], $payload['field'], ['kind' => 'days']), $this->keyboard($rows));
    }

    private function wizardInput(string $user, array $message): bool
    {
        $text = trim((string)($message['text'] ?? ''));
        if ($text === '' || preg_match('~^[/!.][a-z]+(?:@|\s|$)~i', $text)) {
            return false;
        }
        $flow = $this->flows->latest($user, 'settings_wizard');
        if ($flow === null) {
            return false;
        }
        $request = new self(
            $this->settings,
            $this->flows,
            $this->calendar,
            new CallbackUiGateway($this->gateway, $user, (int)$flow['payload']['message_id']),
            $this->geo,
            $this->clock,
            $this->lang,
            $this->authorizeOperator
        );
        try {
            $context = $this->context($user, $flow['payload']['token'], 'settings_wizard');
            $module = $flow['payload']['module'];
            $field = $flow['payload']['field'];
            $definition = SettingsWizard::definition($module, $field, isset($context['group_id']));
            if (($definition['kind'] ?? '') === 'days') {
                throw new \InvalidArgumentException('Choose weekdays with the buttons.');
            }
            $changes = SettingsWizard::changes($module, $field, $text, $context, fn(string $m, string $form): array => $this->parseForm($m, $form));
            $request->changes($user, $context, $changes);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            try {
                $panel = isset($context) ? $this->token($user, $context, 'panel') : null;
                $rows = $panel === null ? [[$this->button($user, 'home', 'sb:start')]]
                    : [[$this->button($user, 'cancel', $this->settingsReturnData($context, $panel, $flow['payload']['module']))]];
                $request->send($user, $this->t($user, isset($context) ? 'wizard_invalid' : 'denied'), $this->keyboard($rows));
            } catch (PanelEditException) {
            }
        }
        return true;
    }
    public function parseForm(string $module, string $form): array
    {
        $allowed = self::FORM_FIELDS[$module] ?? throw new \InvalidArgumentException('Unsupported module');
        $changes = [];
        foreach (explode(';', $form) as $pair) {
            [$field, $value] = array_pad(explode('=', trim($pair), 2), 2, null);
            if (!in_array($field, $allowed, true) || $value === null || array_key_exists($module === 'shabbat' || in_array($field, ['quiet_start','quiet_end'], true) ? $field : $module . '_' . $field, $changes)) {
                throw new \InvalidArgumentException('Unsupported or repeated form field');
            }
            $name = $module === 'shabbat' || in_array($field, ['quiet_start','quiet_end'], true) ? $field : $module . '_' . $field;
            if ($field === 'lock_block_flags') {
                $value = Permissions::validateBlockFlags($value === '' ? [] : array_map('trim', explode(',', $value)));
            } elseif (in_array($field, ['days','fixed_times','sunset_offsets'], true)) {
                $value = $value === '' ? [] : array_map('trim', explode(',', $value));
                if ($field === 'fixed_times') {
                    foreach ($value as $time) {
                        $this->hours($time);
                    }
                } else {
                    $value = array_map(fn(string $v): int => $this->integer($v, $field === 'days' ? 0 : 1, $field === 'days' ? 6 : 360), $value);
                }
            } elseif (in_array($field, ['chol_hamoed','day_completion','times_alert_enabled','close_message_enabled','open_message_enabled','delete_previous','ack_enabled','delete_holy_user_messages','delete_holy_bot_messages'], true)) {
                if (!in_array($value, ['true','false'], true)) {
                    throw new \InvalidArgumentException('Use true or false');
                } $value = $value === 'true';
            } elseif (($field === 'candle_minutes' && $value === 'city') || ($field === 'interval_minutes' && $value === 'off') || (in_array($field, ['quiet_start','quiet_end','target_topic_id','lock_block_flags','delete_holy_user_messages','delete_holy_bot_messages'], true) && $value === 'off')) {
                $value = null;
            } elseif (str_ends_with($field, '_time') || in_array($field, ['time','window_start','window_end','quiet_start','quiet_end'], true)) {
                $this->hours($value);
            } elseif (in_array($field, ['havdalah_value','nightfall_value'], true)) {
                if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0 || (float) $value > 120) {
                    throw new \InvalidArgumentException('Invalid method value');
                } $value = (float) $value;
            } elseif (in_array($field, ['candle_minutes','close_before_candles_minutes','open_after_havdalah_minutes','times_alert_before_minutes','interval_minutes','max_per_day','min_spacing_minutes','sunset_safety_minutes','after_nightfall_minutes','after_open_minutes','target_topic_id','lock_block_flags','delete_holy_user_messages','delete_holy_bot_messages'], true)) {
                $value = $this->integer($value, 0, $field === 'target_topic_id' ? 2147483647 : 360);
            } elseif ($field === 'ack_label') {
                if (!mb_check_encoding($value, 'UTF-8') || strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) > 96 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                    throw new \InvalidArgumentException('The reminder confirmation label must be bounded plain text.');
                }
                $value = trim($value) === '' ? null : $value;
            } elseif ($field === 'template') {
                if (mb_strlen($value) > 4000) {
                    throw new \InvalidArgumentException('Template is too long');
                } preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches);
                foreach ($matches[1] as $placeholder) {
                    if (!in_array($placeholder, ['location','date','hdate','sunset','remaining','candles','havdalah','close','open','daf','omer','source'], true)) {
                        throw new \InvalidArgumentException('Unknown template placeholder');
                    }
                }
            } else {
                $enum = match ($field) {
                    'havdalah_method','nightfall_method' => ['degrees','minutes'], 'format' => ['short','full'], 'mode' => ['fixed','nightfall'], 'times_alert_mode' => ['local_time','before_close'], 'nusach' => ['ashkenaz','sephard'], 'early_policy' => ['defer','skip'], 'tisha_bav_policy' => ['off','mincha'], default => []
                };
                if (!in_array($value, $enum, true)) {
                    throw new \InvalidArgumentException('Unsupported form choice');
                }
            }
            $bounds = ['candle_minutes' => [1,120], 'close_before_candles_minutes' => [0,180], 'open_after_havdalah_minutes' => [0,180], 'times_alert_before_minutes' => [0,360], 'interval_minutes' => [5,360], 'max_per_day' => [1,24], 'min_spacing_minutes' => [5,360], 'sunset_safety_minutes' => [1,120], 'after_nightfall_minutes' => [0,240], 'after_open_minutes' => [0,180]];
            if ($value !== null && isset($bounds[$field]) && ($value < $bounds[$field][0] || $value > $bounds[$field][1])) {
                throw new \InvalidArgumentException('Value outside permitted range');
            }
            $changes[$name] = $value;
        }
        return $changes;
    }
    private function formSummary(string $user, array $context, string $module): string
    {
        $lines = [];
        foreach (SettingsWizard::fields($module, isset($context['group_id'])) as $field) {
            $lines[] = $this->t($user, 'field_' . $field) . ': ' . SettingsWizard::current($module, $field, $context, $this->userLanguage($user));
        }
        return implode("\n", $lines);
    }
    private function moduleDefaults(string $module, bool $group = true): array
    {
        if (!$group) {
            if ($module !== 'shabbat') {
                throw new \RuntimeException('Group module required');
            }
            return ['candle_minutes' => null,'havdalah_method' => 'degrees','havdalah_value' => 8.5];
        }
        return match ($module) {
            'shabbat' => ['mode' => 'lock_messages','shabbat_enabled' => true,'holidays_enabled' => true,'candle_minutes' => null,'havdalah_method' => 'degrees','havdalah_value' => 8.5,'close_before_candles_minutes' => 0,'open_after_havdalah_minutes' => 0,'lock_block_flags' => Permissions::DEFAULT_BLOCK_FLAGS,'delete_holy_user_messages' => false,'delete_holy_bot_messages' => false],
            'zmanim' => ['zmanim_enabled' => false,'zmanim_time' => '07:00','zmanim_days' => [0,1,2,3,4,5,6],'zmanim_format' => 'full'],
            'daf' => ['daf_enabled' => false,'daf_time' => '08:00','daf_days' => [0,1,2,3,4,5,6],'daf_template' => '{{daf}}'],
            'tefillin' => ['tefillin_enabled' => false,'tefillin_days' => [0,1,2,3,4,5],'tefillin_fixed_times' => ['09:00'],'tefillin_interval_minutes' => null,'tefillin_window_start' => '07:00','tefillin_window_end' => '19:00','tefillin_sunset_offsets' => [60,30,15],'tefillin_max_per_day' => 3,'tefillin_min_spacing_minutes' => 30,'tefillin_chol_hamoed' => false,'tefillin_tisha_bav_policy' => 'off','tefillin_sunset_safety_minutes' => 10,'tefillin_suspended_date' => null,'tefillin_delete_previous' => false,'tefillin_ack_enabled' => true,'tefillin_ack_label' => null],
            'omer' => ['omer_enabled' => false,'omer_time' => '21:00','omer_mode' => 'nightfall','omer_after_nightfall_minutes' => 10,'omer_nightfall_method' => 'degrees','omer_nightfall_value' => 8.5,'omer_nusach' => 'sephard','omer_format' => 'full','omer_day_completion' => false,'omer_early_policy' => 'defer','omer_after_open_minutes' => 10],
            default => throw new \InvalidArgumentException('Unsupported module'),
        };
    }
    private function view(string $user, array $context, string $module, ?string $date = null): void
    {
        if (!in_array($module, ['shabbat','zmanim','daf','omer','tefillin'], true)) {
            throw new \InvalidArgumentException('Unsupported view');
        }
        if ($module === 'shabbat') {
            $this->shabbatView($user, $context);
            return;
        }
        $text = $this->renderView($user, $context, $module, $date);
        $token = $this->token($user, $context, 'panel');
        $rows = [];
        if (in_array($module, ['zmanim','daf','omer'], true)) {
            $rows[] = [$this->button($user, 'today', 'sb:day:' . $token . ':' . $module . ':' . $this->localNow($context)->format('Y-m-d')), $this->button($user, 'tomorrow', 'sb:day:' . $token . ':' . $module . ':' . $this->localNow($context)->modify('+1 day')->format('Y-m-d'))];
            $rows[] = [$this->button($user, 'other_date', 'sb:date:' . $token . ':' . $module)];
        }
        if ($module === 'zmanim') {
            $rows[] = [$this->button($user, 'short', 'sb:f:' . $token . ':short'), $this->button($user, 'full', 'sb:f:' . $token . ':full')];
        }
        if ($module !== 'tefillin') {
            $shareQuery = $module;
            if (isset($context['group_id'])) {
                $snapshot = $context;
                unset($snapshot['_flow']);
                $shareToken = $this->flows->create($user, 'share', (string) $context['group_id'], (int) $context['revision'], 'share_snapshot', 600, ['settings' => $snapshot, 'module' => $module, 'date' => $date]);
                $shareQuery = 'share ' . $shareToken;
            }
            $rows[] = [['text' => $this->t($user, 'share'), 'switch_inline' => $shareQuery, 'same_peer' => false]];
        }
        $rows[] = [$this->button($user, 'back', isset($context['group_id']) ? 'sb:group:' . $token : 'sb:start')];
        $this->send($user, $text, $this->keyboard($rows));
    }
    private function shabbatView(string $user, array $context, int $offset = 0): void
    {
        $token = $this->token($user, $context, 'panel');
        try {
            $payload = (new \Shabbat\Content\ShabbatTimesFormatter($this->lang))->overview($context, $this->calendar->snapshot($context), $offset);
            $text = $payload['text'];
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            $payload = ['offset' => 0, 'total' => 0];
            $text = $this->t($user, 'provider_unavailable');
        }
        $rows = [];
        $shareQuery = 'shabat';
        if (isset($context['group_id'])) {
            $snapshot = $context;
            unset($snapshot['_flow']);
            $shareToken = $this->flows->create($user, 'share', (string)$context['group_id'], (int)$context['revision'], 'share_snapshot', 600, ['settings' => $snapshot, 'module' => 'shabbat', 'date' => null]);
            $shareQuery = 'share ' . $shareToken;
        }
        $rows[] = [['text' => $this->t($user, 'share'), 'switch_inline' => $shareQuery, 'same_peer' => false]];
        $rows[] = [$this->button($user, 'back', isset($context['group_id']) ? 'sb:group:' . $token : 'sb:start')];
        $this->send($user, $text, $this->keyboard($rows));
    }
    public function renderView(string $user, array $context, string $module, ?string $date = null, ?string $languageOverride = null): string
    {
        $translate = fn(string $key, array $values = []): string => $this->lang->text($languageOverride ?? ($context['language'] ?? 'he'), $key, $values);
        try {
            if ($module === 'shabbat') {
                $snap = $this->calendar->snapshot($context);
                $presentation = $context;
                $presentation['language'] = $languageOverride ?? ($context['language'] ?? 'he');
                return (new \Shabbat\Content\ShabbatTimesFormatter($this->lang))->overview($presentation, $snap)['text'];
            }
            $date = $date === null ? $this->localNow($context)->format('Y-m-d') : $this->date($date);
            $content = [];
            if ($module === 'zmanim') {
                $data = $this->calendar->zmanim($context, $date);
                foreach ([42,50,72] as $minutes) {
                    if (isset($data['times']['sunset'])) {
                        $data['times']['tzeit' . $minutes . 'min'] = $data['times']['sunset'] + $minutes * 60;
                    }
                }
                $keys = ($context['zmanim_format'] ?? 'full') === 'short' ? ['sunrise','sofZmanShma','chatzot','sunset','tzeit85deg'] : ['alotHaShachar','misheyakir','sunrise','sofZmanShma','sofZmanShmaMGA','sofZmanTfilla','sofZmanTfillaMGA','chatzot','minchaGedola','minchaKetana','plagHaMincha','sunset','tzeit85deg','tzeit42min','tzeit50min','tzeit72min','chatzotNight'];
                foreach ($keys as $key) {
                    $stamp = $data['times'][$key] ?? null;
                    $value = $translate('unavailable');
                    if (is_int($stamp)) {
                        $localTime = (new \DateTimeImmutable('@' . $stamp))->setTimezone(new \DateTimeZone($context['timezone_id']));
                        $value = $localTime->format($localTime->format('Y-m-d') === $date ? 'H:i' : 'Y-m-d H:i');
                    }
                    $content[] = $translate($key) . ': <b>' . $value . '</b>';
                }
            } elseif ($module === 'daf') {
                $data = $this->calendar->daf($context, $date);
                $title = ($languageOverride ?? $context['language']) === 'he' ? ($data['hebrew'] ?? $data['title']) : $data['title'];
                $content[] = '<b>📖 ' . $this->escape($title) . '</b>';
                if (is_string($data['link'] ?? null) && parse_url($data['link'], PHP_URL_SCHEME) === 'https') {
                    $content[] = '<a href="' . $this->escape($data['link']) . '">' . $this->escape($translate('daf_open_page')) . '</a>';
                }
            } elseif ($module === 'tefillin') {
                $data = $this->calendar->zmanim($context, $date);
                $state = $this->calendar->snapshot($context, new \DateTimeImmutable($date . ' 12:00', new \DateTimeZone($context['timezone_id'])));
                $rule = ['enabled' => true, 'weekdays' => array_map(static fn(int $d): int => $d === 0 ? 7 : $d, $context['tefillin_days']), 'times' => $context['tefillin_fixed_times'], 'interval_minutes' => $context['tefillin_interval_minutes'] ?? 0, 'window_start' => $context['tefillin_window_start'], 'window_end' => $context['tefillin_window_end'], 'before_sunset' => $context['tefillin_sunset_offsets'], 'max_per_day' => $context['tefillin_max_per_day'], 'min_gap_minutes' => $context['tefillin_min_spacing_minutes'], 'quiet_start' => $context['quiet_start'], 'quiet_end' => $context['quiet_end'], 'pause_date' => $context['tefillin_suspended_date'], 'chol_hamoed' => $context['tefillin_chol_hamoed'], 'tisha_bav' => $context['tefillin_tisha_bav_policy'], 'sunset_safety_minutes' => $context['tefillin_sunset_safety_minutes']];
                $jobs = (new \Shabbat\Notifications\ReminderPlanner())->tefillin($context, $rule, $date, $data['times'], $state);
                foreach ($jobs as $job) {
                    $content[] = $this->time($job['run_at'], $context) . ' · ' . $translate('window_end') . ': ' . $this->time($job['expires_at'], $context);
                }
                if ($content === []) {
                    $content[] = $translate('no_reminders');
                }
                $content[] = $translate('preview_only');
            } elseif ($module === 'omer') {
                $at = $date === $this->localNow($context)->format('Y-m-d') ? null : new \DateTimeImmutable($date . ' 23:00', new \DateTimeZone($context['timezone_id']));
                $data = $this->calendar->omer($context, $at);
                if (!$data['season']) {
                    return $translate('omer_inactive');
                } $date = $data['date'];
                $content[] = $this->escape((string)$data['count_text']);
                $content[] = '🌙 ' . $translate('omer_nightfall_label') . ': <b>' . $this->time($data['nightfall'], $context) . '</b>';
            } else {
                throw new \InvalidArgumentException('Unsupported calendar view');
            }
            $header = $translate('date_view', ['module' => $translate($module), 'date' => $date, 'location' => $context['location_name'], 'timezone' => $context['timezone_id'], 'hdate' => $data['hdate'] ?? '-', 'content' => '']);
            return $header . implode("\n", $content);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            return $translate('provider_unavailable');
        }
    }
    private function context(string $user, string $token, string $action): array
    {
        $flow = $this->flows->peek($token, $user, $action) ?? throw new \RuntimeException('Settings context expired or belongs to another user');
        if (isset($flow['payload']['source_wizard_token'])) {
            $parent = $this->flows->peek($flow['payload']['source_wizard_token'], $user, 'settings_wizard');
            if ($parent === null || $parent['scope'] !== $flow['scope'] || $parent['group_id'] !== $flow['group_id'] || $parent['revision'] !== $flow['revision']) {
                throw new \RuntimeException('The original settings question expired or was replaced.');
            }
        }
        if (isset($flow['payload']['source_input_token'])) {
            $parent = $this->flows->peek($flow['payload']['source_input_token'], $user, 'location_input');
            if ($parent === null || $parent['scope'] !== $flow['scope'] || $parent['group_id'] !== $flow['group_id'] || $parent['revision'] !== $flow['revision']) {
                throw new \RuntimeException('The original location prompt has expired or was replaced.');
            }
        }
        $context = in_array($flow['scope'], ['group','share'], true) ? $this->group($user, (string) $flow['group_id']) : $this->settings->getUser($user);
        if ((int) $context['revision'] !== (int) $flow['revision']) {
            throw new \RuntimeException('Settings changed; reopen the panel');
        } $context['_flow'] = $flow;
        return $context;
    }
    private function group(string $user, string $id): array
    {
        $context = $this->settings->getGroup($id) ?? throw new \RuntimeException('Unknown group. Existing DBgroups entries are imported automatically at startup.');
        if (!$this->gateway->isGroupAdmin($user, $id)) {
            throw new \RuntimeException('Current administrator permission is required');
        } return $context;
    }
    private function token(string $user, array $context, string $action, array $payload = []): string
    {
        $payload += $this->settingsNavigation($context);
        $wizardSource = $context['_flow']['payload']['source_wizard_token'] ?? (($context['_flow']['action'] ?? '') === 'settings_wizard' ? ($context['_flow']['payload']['token'] ?? null) : null);
        if ($wizardSource !== null && $action === 'save') {
            $parent = $this->flows->peek($wizardSource, $user, 'settings_wizard');
            if ($parent === null || (string)($parent['group_id'] ?? '') !== (string)($context['group_id'] ?? '') || $parent['revision'] !== (int)$context['revision']) {
                throw new \RuntimeException('The settings question expired or was replaced.');
            }
            $payload['source_wizard_token'] = $wizardSource;
        }
        $sourceInput = $context['_flow']['payload']['source_input_token'] ?? (($context['_flow']['action'] ?? '') === 'location_input' ? ($context['_flow']['payload']['token'] ?? null) : null);
        if ($sourceInput !== null && in_array($action, ['location_choice','location_page','save'], true)) {
            $parent = $this->flows->peek($sourceInput, $user, 'location_input');
            if ($parent === null || (string)($parent['group_id'] ?? '') !== (string)($context['group_id'] ?? '') || $parent['revision'] !== (int)$context['revision']) {
                throw new \RuntimeException('The location prompt has expired or was replaced.');
            }
            $payload['source_input_token'] = $sourceInput;
        }
        return $this->flows->create($user, isset($context['group_id']) ? 'group' : 'user', isset($context['group_id']) ? (string) $context['group_id'] : null, (int) $context['revision'], $action, 600, $payload);
    }
    private function capabilities(string $user, array $context): array
    {
        $capabilities = ['verified' => false];
        $diagnostic = $this->gateway instanceof CallbackUiGateway ? $this->gateway->underlying() : $this->gateway;
        if ($diagnostic instanceof \Shabbat\Telegram\MessageMaintenanceGateway) {
            try {
                $candidate = $diagnostic->botCapabilities((string)$context['group_id']);
                if (($candidate['verified'] ?? false) === true && ($candidate['group_id'] ?? null) === (string)$context['group_id']) {
                    $valid = true;
                    foreach (\Shabbat\Telegram\AdminRights::FLAGS as $flag) {
                        $valid = $valid && is_bool($candidate['rights'][$flag] ?? null);
                    }
                    foreach (['creator','is_admin','can_delete_messages'] as $field) {
                        $valid = $valid && is_bool($candidate[$field] ?? null);
                    }
                    if ($valid) {
                        $capabilities = $candidate;
                    }
                }
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                // A failed diagnostic never manufactures administrator rights.
            }
        }
        $this->group($user, (string)$context['group_id']);
        return $capabilities;
    }
    private function safeSnapshot(array $context): array
    {
        try {
            return $this->calendar->snapshot($context);
        } catch (\Amp\CancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
    private function userLanguage(string $user): string
    {
        return Lang::canonical($this->settings->getUser($user)['language'] ?? 'he');
    }
    private function t(string $user, string $key, array $values = []): string
    {
        return $this->lang->text($this->settings->getUser($user)['language'] ?? 'he', $key, $values);
    }
    private function title(string $user, array $context): string
    {
        return isset($context['group_id']) ? (string) ($context['title'] ?: $context['group_id']) : $this->t($user, 'preferences');
    }
    private function localNow(array $context): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone($context['timezone_id']));
    }
    private function time(?int $stamp, array $context): string
    {
        return $stamp === null ? '-' : (new \DateTimeImmutable('@' . $stamp))->setTimezone(new \DateTimeZone($context['timezone_id']))->format('Y-m-d H:i');
    }
    private function date(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Use a real YYYY-MM-DD date');
        } return $date;
    }
    private function hours(string $time): void
    {
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
            throw new \InvalidArgumentException('Use HH:mm');
        }
    }
    private function integer(string $value, int $min, int $max): int
    {
        if (!preg_match('/^[0-9]+$/D', $value) || (float) $value < $min || (float) $value > $max) {
            throw new \InvalidArgumentException('Value outside permitted range');
        } return (int) $value;
    }
    private function twoArguments(string $text): array
    {
        $parts = preg_split('/\s+/u', $text, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            throw new \InvalidArgumentException('A flow token and value are required');
        } return $parts;
    }
    private function describe(string $user, array $changes): string
    {
        $rows = [];
        foreach ($changes as $field => $value) {
            if (in_array($field, ['location_name','country','geoname_id','latitude','longitude','timezone_id','location_source','admin_region'], true)) {
                continue;
            }
            $base = preg_replace('/^(?:zmanim|daf|tefillin|omer)_/', '', $field);
            if ($field === 'lock_block_flags') {
                $label = $this->t($user, 'field_lock_block_flags');
                $shown = $value === [] ? $this->t($user, 'moderation_none') : implode(', ', array_map(fn(string $flag): string => $this->t($user, 'permission_' . $flag), $value));
            } elseif ($field === 'mode') {
                $label = $this->t($user, 'mode');
                $shown = $this->t($user, 'mode_' . $value);
            } elseif ($field === 'language') {
                $label = $this->t($user, 'field_language');
                $shown = Lang::NATIVE_NAMES[$value];
            } elseif ($field === 'israel') {
                $label = $this->t($user, 'calendar_israel');
                $shown = $this->t($user, $value ? 'on' : 'off');
            } elseif (in_array($field, ['shabbat_enabled','holidays_enabled'], true)) {
                $label = $this->t($user, $field);
                $shown = $this->t($user, $value ? 'on' : 'off');
            } elseif ($base === 'enabled') {
                $module = substr($field, 0, strpos($field, '_'));
                $label = $this->t($user, $module) . ' · ' . $this->t($user, 'field_enabled');
                $shown = $this->t($user, $value ? 'on' : 'off');
            } elseif ($field === 'tefillin_suspended_date') {
                $label = $this->t($user, 'tefillin');
                $shown = (string)$value;
            } else {
                $label = $this->t($user, 'field_' . $base);
                $module = preg_match('/^(zmanim|daf|tefillin|omer)_/', $field, $match) ? $match[1] : 'shabbat';
                $shown = SettingsWizard::valueLabel($module, $base, $value, $this->userLanguage($user));
                if (in_array($base, ['havdalah_method','nightfall_method'], true)) {
                    $shown = $this->t($user, 'method_' . $value);
                } elseif ($base === 'format' && in_array($value, ['short','full'], true)) {
                    $shown = $this->t($user, $value);
                }
            }
            $rows[] = $label . ': ' . $shown;
        }
        return implode("\n", $rows);
    }
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    private function placeTitle(array $place): string
    {
        return implode(', ', array_filter([$place['location_name'], $place['country'] ?? '', $place['admin_region'] ?? '']));
    }
    private function button(string $user, string $key, string $data): array
    {
        $color = match ($key) {
            'cancel','reset','off' => 'bg_danger',
            'confirm','on','add','preferences','groups','shabbat','edit' => 'bg_primary',
            default => null,
        };
        $style = ['_' => 'keyboardButtonStyle'];
        if ($color !== null) {
            $style[$color] = true;
        }
        return Presentation::button(['text' => $this->t($user, $key), 'data' => $data, 'style' => $style], true, $key);
    }
    private function keyboard(array $rows): array
    {
        $result = ['_' => 'replyInlineMarkup', 'rows' => []];
        foreach ($rows as $row) {
            $buttons = [];
            foreach ($row as $button) {
                if (isset($button['switch_inline'])) {
                    $buttons[] = ['_' => 'keyboardButtonSwitchInline', 'text' => $button['text'], 'query' => $button['switch_inline'], 'same_peer' => $button['same_peer'] ?? true];
                } elseif (isset($button['url'])) {
                    if (parse_url($button['url'], PHP_URL_SCHEME) !== 'https') {
                        throw new \InvalidArgumentException('HTTPS links required');
                    } $buttons[] = ['_' => 'keyboardButtonUrl', 'text' => $button['text'], 'url' => $button['url']];
                } else {
                    if (strlen($button['data']) > 64) {
                        throw new \LogicException('Callback exceeds Telegram limit');
                    } $buttons[] = ['_' => 'keyboardButtonCallback', 'text' => $button['text'], 'data' => $button['data']];
                }
                $styled = Presentation::button($button);
                $buttons[array_key_last($buttons)]['style'] = $styled['style'];
                if (($styled['_presentation_auto_icon'] ?? false) === true) {
                    $buttons[array_key_last($buttons)]['_presentation_auto_icon'] = true;
                }
            }
            if ($buttons) {
                $result['rows'][] = ['_' => 'keyboardButtonRow', 'buttons' => $buttons];
            }
        }
        return $result;
    }
    private function article(string $id, string $title, string $description, string $text, array $markup = []): array
    {
        $message = ['_' => 'inputBotInlineMessageText', 'message' => $text, 'parse_mode' => 'HTML'];
        if (($markup['rows'] ?? []) !== []) {
            $message['reply_markup'] = $markup;
        }
        return ['_' => 'inputBotInlineResult', 'id' => substr($id, 0, 64), 'type' => 'article', 'title' => $title, 'description' => $description, 'send_message' => $message];
    }
    private function send(string $user, string $text, array $markup = []): void
    {
        $this->gateway->sendPrivate($user, $text, $markup);
    }
    private function sendError(string $user, \Throwable $error): void
    {
        $key = $error instanceof \Shabbat\Location\LocationException ? ($this->geo->configured() ? 'unavailable' : 'geonames_missing') : ($error instanceof \InvalidArgumentException ? (str_contains($error->getMessage(), 'YYYY-MM-DD') ? 'date_invalid' : 'hours_invalid') : 'denied');
        $this->send($user, $this->t($user, $key));
    }
}
