<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Storage;

use Shabbat\Scheduling\Permissions;
use Shabbat\Time\Clock;

final class LegacyImporter
{
    private readonly SettingsRepository $settings;
    private array $fileExists = [];
    private array $fileContents = [];
    private array $directoryEntries = [];
    private array $verificationTargets = [];
    private string $sourceBase = '';
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
        $this->settings = new SettingsRepository($db, $clock);
    }
    /** Startup bridge: only add missing list members; never rewrite preferences or migrate schema. */
    public function importGroupListAutomatically(string $legacyRoot): array
    {
        $this->resetReadState();
        [$data,$this->sourceBase] = $this->paths($legacyRoot);
        $report = ['added' => [],'existing' => [],'warnings' => []];
        foreach ($this->groupIds($data, $report['warnings']) as $id) {
            if ($this->settings->getGroup($id) !== null) {
                $report['existing'][] = $id;
                continue;
            }
            $this->settings->ensureGroup($id, '', $this->legacyDefaults($data, $id));
            $this->db->pdo()->prepare('UPDATE group_settings SET imported_at=? WHERE group_id=? AND updated_by IS NULL')->execute([$this->clock->now()->getTimestamp(),$id]);
            $report['added'][] = $id;
        }
        return $report;
    }
    /** Reads only whitelisted legacy names. Does not delete legacy data or invoke Telegram. */
    public function run(string $legacyRoot, bool $dryRun = true, ?array $expectedReport = null): array
    {
        $this->resetReadState();
        [, $this->sourceBase] = $this->paths($legacyRoot);
        // Complete every normalization and source read before the first application write.
        $report = $this->import($legacyRoot, true);
        $report['report_version'] = 1;
        $report['source_sha256'] = $this->sourceFingerprint();
        $report['destination_sha256'] = $this->destinationFingerprint();
        $report['plan_sha256'] = hash('sha256', Database::encode($report));
        if ($expectedReport !== null) {
            foreach (['source_sha256','destination_sha256','plan_sha256'] as $key) {
                if (
                    ($expectedReport['report_version'] ?? null) !== 1 || ($expectedReport['dry_run'] ?? null) !== true
                    || !is_string($expectedReport[$key] ?? null) || !hash_equals($report[$key], $expectedReport[$key])
                ) {
                    throw new \RuntimeException('Reviewed legacy report no longer matches source or destination; run and review --dry-run again.');
                }
            }
        }
        if ($dryRun) {
            $report['verification'] = ['status' => 'not_applied','checked_records' => 0];
            return $report;
        }
        // Reuse the reviewed in-memory source snapshot. No Telegram work or source writes occur.
        $this->import($legacyRoot, false);
        $report['dry_run'] = false;
        $report['verification'] = $this->verifyTargets();
        return $report;
    }
    private function import(string $legacyRoot, bool $dryRun): array
    {
        [$data,$base] = $this->paths($legacyRoot);
        $report = ['dry_run' => $dryRun,'groups' => [],'users' => [],'templates' => [],'recovery' => [],'warnings' => [],
            'admin_state' => [],'known_peers' => [],'duplicates' => [],'orphan_group_directories' => [],
            'counts' => ['groups' => 0,'users' => 0,'templates' => 0,'snapshots' => 0,'admin_state' => 0,'known_peers' => 0]];
        $groups = $this->groupIds($data, $report['warnings'], $report['duplicates']);
        $report['group_list_present'] = $this->hasFile($data . '/DBgroups.txt');
        if (!$report['group_list_present']) {
            $report['warnings'][] = 'DBgroups.txt is missing; no group subscriptions will be inferred from directories.';
        }
        foreach ($this->directories($data) as $dir) {
            $id = basename($dir);
            if (str_starts_with($id, '-') && $this->validPeerId($id) && !in_array($id, $groups, true)) {
                $report['orphan_group_directories'][] = $id;
            }
        }
        if ($report['orphan_group_directories'] !== []) {
            $report['warnings'][] = 'Group directories absent from DBgroups.txt are retained for review and are not subscribed automatically.';
        }
        $globalStates = [];
        foreach (['state_open.txt','close_lock.txt','open_lock.txt','alert_lock.txt','close_lock2.txt','open_lock2.txt'] as $name) {
            foreach (array_unique([$base . '/' . $name,$data . '/' . $name]) as $path) {
                if ($this->hasFile($path)) {
                    $globalStates[$name] = $this->read($path);
                    if (!$dryRun) {
                        $this->recordSource($path, ['kind' => 'legacy_global_state','requires_review' => true]);
                    }
                }
            }
        }
        if ($globalStates !== []) {
            $report['warnings'][] = 'Old global dates are recorded for recovery only; they do not set the current calendar week.';
        }
        $report['warnings'][] = 'Legacy Jerusalem requested b=18 but Hebcal city rules use forty minutes; imported candle_minutes=null preserves city behavior. Closure remains ten minutes before candles. Old opening checks omitted the ten-minute closure offset.';
        foreach ($groups as $id) {
            $existing = $this->settings->getGroup($id);
            $defaults = $this->legacyDefaults($data, $id);
            $differences = [];
            foreach ($defaults as $key => $value) {
                if ($existing !== null && $existing[$key] !== $value) {
                    $differences[] = $key;
                }
            }
            $report['groups'][] = ['group_id' => $id,'action' => $existing === null ? 'insert' : 'preserve_existing',
                'settings_revision' => $existing['revision'] ?? 1,'source_settings' => $defaults,'different_settings' => $differences];
            $this->trackTarget('groups', ['group_id' => $id], ['group_id' => $id]);
            $this->trackTarget('group_settings', ['group_id' => $id], ['group_id' => $id] + $defaults);
            $report['counts']['groups']++;
            if (!$dryRun && $existing === null) {
                $this->settings->ensureGroup($id, '', $this->legacyDefaults($data, $id));
                $this->db->pdo()->prepare('UPDATE group_settings SET imported_at=? WHERE group_id=?')->execute([$this->clock->now()->getTimestamp(),$id]);
            }
            foreach (['open' => 'MsgOpener','close' => 'MsgCloser'] as $kind => $prefix) {
                $template = $this->template($data . '/' . $id, $prefix, $report['warnings']);
                if ($template === null) {
                    continue;
                }
                $values = $this->templateValues($template);
                $this->trackTarget('message_templates', ['group_id' => $id,'kind' => $kind], $values);
                $query = $this->db->pdo()->prepare('SELECT * FROM message_templates WHERE group_id=? AND kind=?');
                $query->execute([$id,$kind]);
                $stored = $query->fetch();
                $exists = $stored !== false;
                $report['templates'][] = ['group_id' => $id,'kind' => $kind,'action' => $exists ? 'preserve_existing' : 'insert']
                    + $this->valueComparison($values, $stored);
                $report['counts']['templates']++;
                if (!$dryRun && !$exists) {
                    $this->db->pdo()->prepare('INSERT INTO message_templates(group_id,kind,text,entities_json,media_json,buttons_json,updated_at,imported_at,buttons_mode) VALUES(?,?,?,?,?,?,?,?,?)')
                        ->execute([$id,$kind,$template['text'],Database::encode($template['entities']),$template['media'] === null ? null : Database::encode($template['media']),Database::encode($template['buttons']),$this->clock->now()->getTimestamp(),$this->clock->now()->getTimestamp(),$template['buttons'] === [] ? 'inherit' : 'custom']);
                }
            }
            $snapshotFile = $data . '/' . $id . '/chatb1.json';
            if ($this->hasFile($snapshotFile)) {
                $report['counts']['snapshots']++;
                $error = null;
                $snapshot = null;
                try {
                    $decoded = json_decode($this->read($snapshotFile), true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($decoded)) {
                        throw new \RuntimeException('Snapshot is not an object.');
                    }
                    Permissions::validate($decoded);
                    $snapshot = $decoded;
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                    $report['warnings'][] = $id . ': malformed original permissions: ' . $error;
                }
                $report['recovery'][] = ['group_id' => $id,'snapshot_valid' => $error === null,'action' => 'requires_current_permission_and_calendar_check'];
                $serialized = $snapshot === null ? null : Database::encode($snapshot);
                $this->trackTarget('group_lock_state', ['group_id' => $id], ['status' => 'recovery_required',
                    'snapshot_json' => $serialized,'snapshot_provenance' => 'legacy_unverified']);
                $this->read($snapshotFile);
                if (!$dryRun) {
                    $applied = $snapshot === null ? null : Permissions::locked($snapshot);
                    // The old source also set these flags. Retain them in the expected legacy lock.
                    if ($applied !== null) {
                        $applied['edit_rank'] = true;
                        $applied['send_reactions'] = true;
                    }
                    $this->db->pdo()->prepare("INSERT OR IGNORE INTO group_lock_state(group_id,event_key,status,snapshot_json,applied_json,snapshot_sha256,prepared_at,last_error) VALUES(?,?,'recovery_required',?,?,?,?,?)")
                        ->execute([$id,'legacy:' . hash('sha256', Database::encode($globalStates)),$serialized,$applied === null ? null : Database::encode($applied),$serialized === null ? null : hash('sha256', $serialized),$this->clock->now()->getTimestamp(),$error]);
                    $this->recordSource($snapshotFile, ['group_id' => $id,'snapshot_valid' => $error === null]);
                }
            } elseif ($globalStates !== []) {
                $report['recovery'][] = ['group_id' => $id,'snapshot_valid' => false,'action' => 'manual_review_without_permission_guesses'];
                $this->trackTarget('group_lock_state', ['group_id' => $id], ['status' => 'recovery_required',
                    'snapshot_provenance' => 'legacy_unverified']);
                if (!$dryRun) {
                    $this->db->pdo()->prepare("INSERT OR IGNORE INTO group_lock_state(group_id,event_key,status,prepared_at,last_error) VALUES(?,'legacy:missing-snapshot','recovery_required',?,'Global legacy state exists without original permissions; administrator review required')")
                        ->execute([$id,$this->clock->now()->getTimestamp()]);
                }
            }
            foreach (['alertshabat.txt','alertshabat2.txt','msgclosermotan.txt','msgclosermotan2.txt','MsgOpener.txt','MsgOpener2.txt','MsgOpenerMedia.txt','MsgOpenerButtons.txt','MsgCloser.txt','MsgCloser2.txt','MsgCloserMedia.txt','MsgCloserButtons.txt'] as $name) {
                $path = $data . '/' . $id . '/' . $name;
                if ($this->hasFile($path)) {
                    $this->read($path);
                    if (!$dryRun) {
                        $this->recordSource($path, ['group_id' => $id]);
                    }
                }
            }
        }
        $knownUsers = [];
        $idsFile = $base . '/ids.txt';
        if ($this->hasFile($idsFile)) {
            $knownPeers = [];
            foreach (preg_split('/\R/', $this->read($idsFile)) ?: [] as $line) {
                $peer = trim($line);
                if ($peer === '') {
                    continue;
                }
                if (!$this->validPeerId($peer)) {
                    $report['warnings'][] = 'Skipped unsupported dialog identifier in ids.txt.';
                    continue;
                }
                if (isset($knownPeers[$peer])) {
                    $report['duplicates'][] = ['source' => 'ids.txt','identifier' => $peer,'action' => 'deduplicated'];
                }
                $knownPeers[$peer] = true;
                if ($this->validUserId($peer)) {
                    $knownUsers[$peer] = true;
                }
            }
            foreach (array_keys($knownPeers) as $peer) {
                $peer = (string)$peer;
                $this->trackTarget('known_broadcast_peers', ['peer_id' => $peer], ['peer_id' => $peer,'peer_type' => 'unknown']);
                $query = $this->db->pdo()->prepare('SELECT 1 FROM known_broadcast_peers WHERE peer_id=?');
                $query->execute([$peer]);
                $report['known_peers'][] = ['peer_id' => $peer,'action' => $query->fetchColumn() === false ? 'insert_unknown' : 'preserve_existing','authority' => 'none'];
                $report['counts']['known_peers']++;
                if (!$dryRun) {
                    $now = $this->clock->now()->getTimestamp();
                    $this->db->pdo()->prepare("INSERT OR IGNORE INTO known_broadcast_peers(peer_id,peer_type,created_at,updated_at,imported_at) VALUES(?,'unknown',?,?,?)")->execute([$peer,$now,$now,$now]);
                }
            }
            foreach (array_keys($knownUsers) as $user) {
                $user = (string)$user;
                $userExists = $this->trackUser($user);
                $report['users'][] = ['user_id' => $user,'action' => $userExists ? 'preserve_existing' : 'insert_known_user_without_admin_authority','authority' => 'none'];
                $report['counts']['users']++;
                if (!$dryRun) {
                    $this->settings->getUser($user);
                }
            }
        }
        foreach (['ids.txt','idsnew.txt'] as $name) {
            $this->importAdminState($base . '/' . $name, '0', $name, $dryRun, $report);
        }
        foreach (['BUTTONS.txt','broadcastsend.txt','pinmessage.txt','startline_cat.txt','endline_cat.txt','page_var.txt'] as $name) {
            $this->importAdminState($data . '/' . $name, '0', $name, $dryRun, $report);
        }
        // Numeric directories outside the subscribed group list contain personal/private drafts.
        foreach ($this->directories($data) as $dir) {
            $user = basename($dir);
            if (!$this->validUserId($user) || in_array($user, $groups, true)) {
                continue;
            }
            $selectedFile = $dir . '/groupid.txt';
            $hasDraft = $this->hasFile($selectedFile);
            foreach (['grs1.txt','messagetodelete.txt','txt.txt','ent.txt','media.txt','BUTTONS.txt','broadcastsend.txt','pinmessage.txt','page_var.txt'] as $name) {
                $hasDraft = $hasDraft || $this->hasFile($dir . '/' . $name);
            }
            if (!$hasDraft) {
                continue;
            }
            if (!isset($knownUsers[$user])) {
                $this->trackUser($user);
                $report['users'][] = ['user_id' => $user,'action' => 'preserve_personal_drafts_without_admin_authority','authority' => 'none'];
                $report['counts']['users']++;
                $knownUsers[$user] = true;
            }
            foreach (['grs1.txt','messagetodelete.txt','txt.txt','ent.txt','media.txt','BUTTONS.txt','broadcastsend.txt','pinmessage.txt','page_var.txt'] as $name) {
                $this->importAdminState($dir . '/' . $name, $user, $name, $dryRun, $report);
            }
            $draft = $this->userDraft($dir, $report['warnings']);
            if ($draft !== null) {
                $values = $this->templateValues($draft);
                $stored = $this->trackTarget('message_templates', ['user_id' => $user,'kind' => 'broadcast'], $values);
                $report['templates'][] = ['user_id' => $user,'kind' => 'broadcast','action' => $stored === false ? 'insert' : 'preserve_existing']
                    + $this->valueComparison($values, $stored);
                $report['counts']['templates']++;
            }
            if (!$dryRun) {
                $this->settings->getUser($user);
                if ($draft !== null) {
                    $this->db->pdo()->prepare("INSERT OR IGNORE INTO message_templates(user_id,kind,text,entities_json,media_json,buttons_json,updated_at,imported_at,buttons_mode) VALUES(?,'broadcast',?,?,?,?,?,?,?)")
                        ->execute([$user,$draft['text'],Database::encode($draft['entities']),$draft['media'] === null ? null : Database::encode($draft['media']),Database::encode($draft['buttons']),$this->clock->now()->getTimestamp(),$this->clock->now()->getTimestamp(),$draft['buttons'] === [] ? 'inherit' : 'custom']);
                }
            }
            foreach (['groupid.txt','grs1.txt','VAR-MENU','txt.txt','ent.txt','media.txt'] as $name) {
                if ($this->hasFile($dir . '/' . $name)) {
                    $this->read($dir . '/' . $name);
                    if (!$dryRun) {
                        $this->recordSource($dir . '/' . $name, ['user_id' => $user,'authority' => 'none','requires_new_authenticated_flow' => true]);
                    }
                }
            }
        }
        if (!$dryRun && $this->hasFile($data . '/DBgroups.txt')) {
            $this->recordSource($data . '/DBgroups.txt', ['groups' => $groups]);
        }
        return $report;
    }
    private function validPeerId(string $id): bool
    {
        if (!preg_match('/^-?[1-9][0-9]{0,18}$/D', $id)) {
            return false;
        }
        $negative = $id[0] === '-';
        $digits = $negative ? substr($id, 1) : $id;
        $max = $negative ? substr((string)PHP_INT_MIN, 1) : (string)PHP_INT_MAX;
        return strlen($digits) < strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) <= 0);
    }
    private function validUserId(string $id): bool
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            return false;
        }
        $max = (string)PHP_INT_MAX;
        return strlen($id) < strlen($max) || (strlen($id) === strlen($max) && strcmp($id, $max) <= 0);
    }
    private function importAdminState(string $path, string $user, string $key, bool $dryRun, array &$report): void
    {
        if (!$this->hasFile($path)) {
            return;
        }
        $contents = $this->read($path);
        if (strlen($contents) > 1048576) {
            $report['warnings'][] = $key . ': exceeds SQLite draft limit; retained in legacy source for explicit review.';
            return;
        }
        $this->trackTarget('admin_directories', ['user_id' => $user], ['user_id' => $user]);
        $stored = $this->trackTarget('admin_state', ['user_id' => $user,'state_key' => $key], ['value_text' => $contents]);
        $query = $this->db->pdo()->prepare('SELECT 1 FROM admin_state WHERE user_id=? AND state_key=?');
        $query->execute([$user,$key]);
        $exists = $query->fetchColumn() !== false;
        $report['admin_state'][] = ['user_id' => $user,'key' => $key,'action' => $exists ? 'preserve_existing' : 'insert']
            + $this->valueComparison(['value_text' => $contents], $stored);
        $report['counts']['admin_state']++;
        if ($dryRun) {
            return;
        }
        $kind = match ($key) {
            'ent.txt','BUTTONS.txt'=>'json','media.txt'=>'media','ids.txt','idsnew.txt'=>'ids',default=>'text'
        };
        $now = $this->clock->now()->getTimestamp();
        $this->db->transaction(static function (\PDO $pdo) use ($user, $key, $kind, $contents, $now): void {
            $pdo->prepare('INSERT OR IGNORE INTO admin_directories(user_id,created_at) VALUES(?,?)')->execute([$user,$now]);
            $pdo->prepare('INSERT OR IGNORE INTO admin_state(user_id,state_key,value_kind,value_text,updated_at) VALUES(?,?,?,?,?)')->execute([$user,$key,$kind,$contents,$now]);
        });
        $this->recordSource($path, ['user_id' => $user,'kind' => 'admin_draft','authority' => 'none','requires_new_authenticated_flow' => true]);
    }
    private function legacyDefaults(string $data, string $id): array
    {
        $messages = $this->hasFile($data . '/' . $id . '/alertshabat2.txt');
        return ['location_name' => 'Jerusalem','country' => 'IL','geoname_id' => 281184,'latitude' => 31.76904,'longitude' => 35.21633,
            'timezone_id' => 'Asia/Jerusalem','location_source' => 'legacy','israel' => true,'language' => 'he','candle_minutes' => null,
            'havdalah_method' => 'degrees','havdalah_value' => 8.5,'close_before_candles_minutes' => 10,'open_after_havdalah_minutes' => 0,
            'mode' => $messages ? 'lock_messages' : 'lock','shabbat_enabled' => true,'holidays_enabled' => true,
            'times_alert_enabled' => $this->hasFile($data . '/' . $id . '/alertshabat.txt'),'times_alert_time' => '13:30',
            'close_message_enabled' => $messages,'open_message_enabled' => $messages,'zmanim_enabled' => false,'daf_enabled' => false,'tefillin_enabled' => false,'omer_enabled' => false];
    }
    private function paths(string $root): array
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Legacy source directory does not exist.');
        }
        $data = is_dir($resolved . '/data') ? $resolved . '/data' : $resolved;
        $base = basename($data) === 'data' ? dirname($data) : $resolved;
        return [$data,$base];
    }
    private function groupIds(string $data, array &$warnings, array &$duplicates = []): array
    {
        $file = $data . '/DBgroups.txt';
        if (!$this->hasFile($file)) {
            return [];
        }
        $ids = [];
        foreach (preg_split('/\R/', $this->read($file)) ?: [] as $line) {
            $id = trim($line);
            if ($id === '') {
                continue;
            }
            if (!$this->validPeerId($id)) {
                $warnings[] = 'Skipped invalid group identifier in DBgroups.txt: ' . substr($id, 0, 40);
                continue;
            }
            if (isset($ids[$id])) {
                $duplicates[] = ['source' => 'DBgroups.txt','identifier' => $id,'action' => 'deduplicated'];
            }
            $ids[$id] = $id;
        }
        return array_values($ids);
    }
    private function template(string $dir, string $prefix, array &$warnings): ?array
    {
        $textFile = $dir . '/' . $prefix . '.txt';
        $entityFile = $dir . '/' . $prefix . '2.txt';
        $mediaFile = $dir . '/' . $prefix . 'Media.txt';
        $buttonFile = $dir . '/' . $prefix . 'Buttons.txt';
        if (!$this->hasFile($textFile) && !$this->hasFile($mediaFile) && !$this->hasFile($buttonFile)) {
            return null;
        }
        return ['text' => $this->hasFile($textFile) ? $this->read($textFile) : '','entities' => $this->jsonArray($entityFile, $warnings),
            'media' => $this->hasFile($mediaFile) ? ['bot_api_file_id' => trim($this->read($mediaFile))] : null,
            'buttons' => $this->hasFile($buttonFile) ? $this->buttons($this->read($buttonFile), $warnings) : []];
    }
    private function userDraft(string $dir, array &$warnings): ?array
    {
        if (!$this->hasFile($dir . '/txt.txt') && !$this->hasFile($dir . '/media.txt')) {
            return null;
        }
        $buttons = $this->hasFile($dir . '/BUTTONS.txt') ? $dir . '/BUTTONS.txt' : dirname($dir) . '/BUTTONS.txt';
        return ['text' => $this->hasFile($dir . '/txt.txt') ? $this->read($dir . '/txt.txt') : '','entities' => $this->jsonArray($dir . '/ent.txt', $warnings),
            'media' => $this->hasFile($dir . '/media.txt') ? ['bot_api_file_id' => trim($this->read($dir . '/media.txt'))] : null,'buttons' => TemplateRepository::buttonRows($this->jsonArray($buttons, $warnings))];
    }
    private function jsonArray(string $path, array &$warnings): array
    {
        if (!$this->hasFile($path)) {
            return [];
        }
        try {
            $data = json_decode($this->read($path), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException('Expected JSON array.');
            }
            return $data;
        } catch (\Throwable $error) {
            $warnings[] = basename($path) . ': malformed JSON retained in legacy source, omitted from normalized template: ' . $error->getMessage();
            return [];
        }
    }
    private function buttons(string $text, array &$warnings): array
    {
        // Some older operators stored the later JSON format; support both without executing content.
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return TemplateRepository::buttonRows($decoded);
        }
        $rows = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = [];
            foreach (explode('&&', $line) as $part) {
                $pieces = array_map('trim', explode(' - ', trim($part)));
                if (count($pieces) < 2 || trim($pieces[0]) === '') {
                    $warnings[] = 'Malformed legacy button omitted; source text retained.';
                    continue;
                }
                [$label,$action] = $pieces;
                $button = ['text' => trim($label)];
                $style = [];
                foreach (array_slice($pieces, 2) as $option) {
                    if (in_array($option, ['style:bg_success','style:bg_primary','style:bg_danger'], true)) {
                        $style[substr($option, 6)] = true;
                    }
                    if (str_starts_with($option, 'icon:') && preg_match('/^[0-9]{1,20}$/D', substr($option, 5))) {
                        $style['icon'] = (int)substr($option, 5);
                    }
                }
                if ($style !== []) {
                    $button['style'] = ['_' => 'keyboardButtonStyle'] + $style;
                }
                if (preg_match('#^https?://#i', $action)) {
                    $button['url'] = $action;
                } elseif (str_starts_with($action, 'data:')) {
                    $button['callback_data'] = substr($action, 5);
                } elseif (str_starts_with($action, 'copy:')) {
                    $button['copy_text'] = ['_' => 'copyTextButton','text' => substr($action, 5)];
                } elseif (str_starts_with($action, 'alert:') || str_starts_with($action, 'popup:')) {
                    [$type,$value] = explode(':', $action, 2);
                    $button['callback_data'] = $type . ':' . base64_encode(mb_substr($value, 0, 180));
                } else {
                    $warnings[] = 'Unsupported legacy button action omitted; source text retained.';
                    continue;
                }
                $row[] = $button;
            }
            if ($row !== []) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
    private function read(string $path): string
    {
        if (isset($this->fileContents[$path])) {
            return $this->fileContents[$path];
        }
        for ($entry = $path; $entry !== $this->sourceBase && strlen($entry) > strlen($this->sourceBase); $entry = dirname($entry)) {
            if (is_link($entry)) {
                throw new \RuntimeException('Symlinked legacy sources require explicit operator review.');
            }
        }
        if (is_link($path)) {
            throw new \RuntimeException('Symlinked legacy sources require explicit operator review.');
        }
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === false || $size > 2 * 1024 * 1024) {
            throw new \RuntimeException('Legacy file is too large or unreadable: ' . basename($path));
        }
        $text = file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException('Cannot read legacy file: ' . basename($path));
        }
        if (strlen($text) > 2 * 1024 * 1024) {
            throw new \RuntimeException('Legacy file is too large or unreadable: ' . basename($path));
        }
        $this->fileContents[$path] = $text;
        return $text;
    }
    private function recordSource(string $path, array $details): void
    {
        $query = $this->db->pdo()->prepare('INSERT OR IGNORE INTO legacy_imports(source_path,sha256,imported_at,details_json) VALUES(?,?,?,?)');
        $query->execute([$path,hash('sha256', $this->read($path)),$this->clock->now()->getTimestamp(),Database::encode($details)]);
    }
    private function resetReadState(): void
    {
        $this->fileExists = [];
        $this->fileContents = [];
        $this->directoryEntries = [];
        $this->verificationTargets = [];
    }
    private function hasFile(string $path): bool
    {
        return $this->fileExists[$path] ??= is_file($path);
    }
    private function directories(string $data): array
    {
        return $this->directoryEntries[$data] ??= glob($data . '/*', GLOB_ONLYDIR) ?: [];
    }
    private function sourceFingerprint(): string
    {
        $files = [];
        foreach ($this->fileExists as $path => $exists) {
            $relative = str_replace('\\', '/', substr($path, strlen($this->sourceBase) + 1));
            $files[$relative] = ['exists' => $exists,'sha256' => isset($this->fileContents[$path]) ? hash('sha256', $this->fileContents[$path]) : null];
        }
        foreach ($this->fileContents as $path => $contents) {
            $relative = str_replace('\\', '/', substr($path, strlen($this->sourceBase) + 1));
            $files[$relative] = ['exists' => true,'sha256' => hash('sha256', $contents)];
        }
        ksort($files, SORT_STRING);
        return hash('sha256', Database::encode($files));
    }
    private function templateValues(array $template): array
    {
        return ['text' => $template['text'],'entities_json' => Database::encode($template['entities']),
            'media_json' => $template['media'] === null ? null : Database::encode($template['media']),
            'buttons_json' => Database::encode($template['buttons']),'buttons_mode' => $template['buttons'] === [] ? 'inherit' : 'custom'];
    }
    private function valueComparison(array $values, array|false $stored): array
    {
        $sourceHash = hash('sha256', Database::encode($values));
        $storedHash = null;
        // Normalize column order before comparing JSON hashes.
        if ($stored !== false) {
            $ordered = [];
            foreach ($values as $key => $value) {
                $ordered[$key] = $stored[$key];
            }
            $storedHash = hash('sha256', Database::encode($ordered));
        }
        return ['source_value_sha256' => $sourceHash,'destination_value_sha256' => $storedHash,
            'matches_source' => $storedHash === null ? null : hash_equals($sourceHash, $storedHash)];
    }
    private function targetRow(string $table, array $where): array|false
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', array_map(static fn(string $key): string => $key . '=?', array_keys($where))));
        $query->execute(array_values($where));
        return $query->fetch();
    }
    private function trackTarget(string $table, array $where, array $expected): array|false
    {
        $key = $table . ':' . Database::encode($where);
        if (!isset($this->verificationTargets[$key])) {
            $this->verificationTargets[$key] = ['table' => $table,'where' => $where,'expected' => $expected,'before' => $this->targetRow($table, $where)];
        }
        return $this->verificationTargets[$key]['before'];
    }
    private function trackUser(string $id): bool
    {
        $before = $this->trackTarget('users', ['user_id' => $id], ['user_id' => $id]);
        $this->trackTarget('user_preferences', ['user_id' => $id], ['user_id' => $id]);
        return $before !== false;
    }
    private function destinationFingerprint(): string
    {
        $records = [];
        foreach ($this->verificationTargets as $key => $target) {
            $records[$key] = $target['before'];
        }
        ksort($records, SORT_STRING);
        return hash('sha256', Database::encode($records));
    }
    private function verifyTargets(): array
    {
        $preserved = 0;
        $inserted = 0;
        foreach ($this->verificationTargets as $target) {
            $after = $this->targetRow($target['table'], $target['where']);
            if ($after === false) {
                throw new \RuntimeException('Legacy import verification failed: missing record in ' . $target['table'] . '. Keep the bot stopped and review the pre-import backup.');
            }
            if ($target['before'] !== false) {
                if ($after !== $target['before']) {
                    throw new \RuntimeException('Legacy import verification failed: an existing record changed in ' . $target['table'] . '. Keep the bot stopped and review the pre-import backup.');
                }
                $preserved++;
                continue;
            }
            foreach ($target['expected'] as $key => $value) {
                $actual = $after[$key] ?? null;
                $matches = $value === null ? $actual === null : $actual !== null && (string)$actual === (string)(is_bool($value) ? (int)$value : $value);
                if (!$matches) {
                    throw new \RuntimeException('Legacy import verification failed: inserted value differs in ' . $target['table'] . '.' . $key . '. Keep the bot stopped and review the pre-import backup.');
                }
            }
            $inserted++;
        }
        $integrity = $this->db->integrity();
        if ($integrity['integrity_check'] !== ['ok'] || $integrity['foreign_key_check'] !== []) {
            throw new \RuntimeException('Legacy import database integrity verification failed. Keep the bot stopped and review the pre-import backup.');
        }
        return ['status' => 'passed','checked_records' => count($this->verificationTargets),
            'inserted_records' => $inserted,'preserved_records' => $preserved,'integrity_check' => 'ok','foreign_key_check' => []];
    }
}
