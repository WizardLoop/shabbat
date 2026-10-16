<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Storage;

use DateTimeZone;
use PDO;
use Shabbat\Time\Clock;

final class SettingsRepository
{
    private const ARRAY_FIELDS = ['zmanim_days','daf_days','tefillin_days','tefillin_fixed_times','tefillin_sunset_offsets','lock_block_flags'];
    private const BOOL_FIELDS = ['israel','shabbat_enabled','holidays_enabled','times_alert_enabled','close_message_enabled','open_message_enabled',
        'zmanim_enabled','daf_enabled','tefillin_enabled','tefillin_chol_hamoed','omer_enabled','omer_day_completion','delete_holy_user_messages','delete_holy_bot_messages','tefillin_delete_previous','tefillin_ack_enabled'];
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }
    public function database(): Database
    {
        return $this->db;
    }
    /** @phpstan-impure Database state may change across an asynchronous operation. */
    public function getGroup(string $id): ?array
    {
        self::identifier($id);
        $query = $this->db->pdo()->prepare('SELECT s.*,g.title,g.active FROM group_settings s JOIN groups g USING(group_id) WHERE group_id=?');
        $query->execute([$id]);
        $row = $query->fetch();
        return $row === false ? null : self::decode($row);
    }
    public function listGroups(int $offset = 0, int $limit = 20, string $search = ''): array
    {
        if ($offset < 0 || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid page.');
        }
        $query = $this->db->pdo()->prepare("SELECT s.*,g.title,g.active FROM groups g JOIN group_settings s USING(group_id) WHERE g.active=1 AND (g.title LIKE :search ESCAPE '\\' OR g.group_id LIKE :search ESCAPE '\\') ORDER BY g.group_id LIMIT :lim OFFSET :off");
        $query->bindValue(':search', '%' . strtr($search, ['\\' => '\\\\','%' => '\\%','_' => '\\_']) . '%');
        $query->bindValue(':lim', $limit, PDO::PARAM_INT);
        $query->bindValue(':off', $offset, PDO::PARAM_INT);
        $query->execute();
        return array_map(self::decode(...), $query->fetchAll());
    }
    /** Stable keyset traversal for bounded scheduler batches, independent of UI pagination. */
    public function groupsAfter(?string $after, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid scheduler batch size.');
        }
        $query = $this->db->pdo()->prepare('SELECT s.*,g.title,g.active FROM groups g JOIN group_settings s USING(group_id) WHERE g.active=1 AND (:after IS NULL OR g.group_id > :after) ORDER BY g.group_id LIMIT :lim');
        $query->bindValue(':after', $after, $after === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $query->bindValue(':lim', $limit, PDO::PARAM_INT);
        $query->execute();
        return array_map(self::decode(...), $query->fetchAll());
    }
    /** Refresh only metadata after the caller has completed a fresh Telegram lookup. */
    public function refreshGroupTitle(string $id, string $title): void
    {
        self::identifier($id);
        if (
            !mb_check_encoding($title, 'UTF-8') || trim($title) === '' || strlen($title) > 1024
            || mb_strlen($title, 'UTF-8') > 256 || preg_match('/[\x00-\x1F\x7F]/u', $title)
        ) {
            throw new \InvalidArgumentException('Group title must be non-empty bounded UTF-8 without control characters.');
        }
        $now = $this->clock->now()->getTimestamp();
        $this->db->transaction(static function (PDO $pdo) use ($id, $title, $now): void {
            $known = $pdo->prepare('SELECT 1 FROM groups g JOIN group_settings s USING(group_id) WHERE g.group_id=?');
            $known->execute([$id]);
            if ($known->fetchColumn() === false) {
                throw new \InvalidArgumentException('Cannot refresh metadata for an unregistered group.');
            }
            $pdo->prepare('UPDATE groups SET title=?,updated_at=? WHERE group_id=?')->execute([$title, $now, $id]);
        });
    }
    public function ensureGroup(string $id, string $title = '', ?array $settings = null): array
    {
        self::identifier($id);
        $now = $this->clock->now()->getTimestamp();
        $this->db->transaction(function (PDO $pdo) use ($id, $title, $settings, $now): void {
            $pdo->prepare('INSERT OR IGNORE INTO groups(group_id,title,created_at,updated_at) VALUES(?,?,?,?)')->execute([$id,$title,$now,$now]);
            $query = $pdo->prepare('SELECT 1 FROM group_settings WHERE group_id=?');
            $query->execute([$id]);
            if ($query->fetchColumn() === false) {
                $pdo->prepare('INSERT INTO group_settings(group_id,updated_at) VALUES(?,?)')->execute([$id,$now]);
                if ($settings !== null && $settings !== []) {
                    $this->apply($pdo, 'group_settings', 'group_id', $id, 1, $settings, null, false);
                }
            }
        });
        return $this->getGroup($id) ?? throw new \LogicException('Missing newly created group.');
    }
    /** Explicit rejoin after the caller has freshly verified administrator authorization. */
    public function reactivateGroup(string $id, string $actorId, ?string $title = null): array
    {
        self::identifier($id);
        self::identifier($actorId);
        if (
            $title !== null && (
            !mb_check_encoding($title, 'UTF-8') || trim($title) === '' || strlen($title) > 1024
            || mb_strlen($title, 'UTF-8') > 256 || preg_match('/[\x00-\x1F\x7F]/u', $title)
            )
        ) {
            throw new \InvalidArgumentException('Group title must be non-empty bounded UTF-8 without control characters.');
        }
        return $this->db->transaction(function (PDO $pdo) use ($id, $actorId, $title): array {
            $group = $this->getGroup($id);
            if ($group === null) {
                throw new \InvalidArgumentException('Only an explicitly registered group can be reactivated.');
            }
            if ($group['active']) {
                return $group;
            }
            $revision = $group['revision'];
            $this->apply($pdo, 'group_settings', 'group_id', $id, $revision, [], $actorId);
            $now = $this->clock->now()->getTimestamp();
            $pdo->prepare('UPDATE groups SET active=1,title=COALESCE(?,title),updated_at=? WHERE group_id=? AND active=0')
                ->execute([$title, $now, $id]);
            $pdo->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,?,?,?,?)')
                ->execute([$actorId, $id, 'group.reactivate', Database::encode(['revision' => $revision + 1]), $now]);
            return $this->getGroup($id) ?? throw new \LogicException('Reactivated group disappeared.');
        });
    }
    public function updateGroup(string $id, int $expectedRevision, array $changes, string $actorId): array
    {
        return $this->db->transaction(fn(PDO $pdo): array => $this->updateGroupInTransaction($pdo, $id, $expectedRevision, $changes, $actorId));
    }
    /** For flow confirmations sharing this database's already-open transaction only. */
    public function updateGroupInTransaction(PDO $pdo, string $id, int $expectedRevision, array $changes, string $actorId): array
    {
        $this->apply($pdo, 'group_settings', 'group_id', $id, $expectedRevision, $changes, $actorId);
        return $this->getGroup($id) ?? throw new \LogicException('Group disappeared.');
    }
    public function getUser(string $id): array
    {
        self::identifier($id);
        $now = $this->clock->now()->getTimestamp();
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT OR IGNORE INTO users(user_id,created_at,updated_at) VALUES(?,?,?)')->execute([$id,$now,$now]);
        $pdo->prepare('INSERT OR IGNORE INTO user_preferences(user_id,updated_at) VALUES(?,?)')->execute([$id,$now]);
        $query = $pdo->prepare('SELECT * FROM user_preferences WHERE user_id=?');
        $query->execute([$id]);
        return self::decode($query->fetch());
    }
    public function updateUser(string $id, int $expectedRevision, array $changes): array
    {
        $this->getUser($id);
        return $this->db->transaction(fn(PDO $pdo): array => $this->updateUserInTransaction($pdo, $id, $expectedRevision, $changes));
    }
    public function updateUserInTransaction(PDO $pdo, string $id, int $expectedRevision, array $changes): array
    {
        $this->apply($pdo, 'user_preferences', 'user_id', $id, $expectedRevision, $changes, $id);
        $query = $pdo->prepare('SELECT * FROM user_preferences WHERE user_id=?');
        $query->execute([$id]);
        return self::decode($query->fetch());
    }
    public function deactivateGroup(string $id, string $actorId): void
    {
        $state = $this->db->pdo()->prepare("SELECT status FROM group_lock_state WHERE group_id=? AND status NOT IN ('restored','prelocked')");
        $state->execute([$id]);
        if ($state->fetchColumn() !== false) {
            throw new \RuntimeException('Restore or explicitly resolve group permissions before removing this group.');
        }
        $now = $this->clock->now()->getTimestamp();
        $this->db->transaction(function (PDO $pdo) use ($id, $actorId, $now): void {
            $pdo->prepare('UPDATE groups SET active=0,updated_at=? WHERE group_id=?')->execute([$now,$id]);
            $pdo->prepare("UPDATE scheduled_jobs SET status='cancelled',last_error='group removed' WHERE group_id=? AND status IN ('pending','leased') AND module NOT IN ('lock','restore')")->execute([$id]);
            $pdo->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,?,?,?,?)')->execute([$actorId,$id,'group.remove','{}',$now]);
        });
    }
    private function apply(PDO $pdo, string $table, string $key, string $id, int $revision, array $changes, ?string $actor, bool $increment = true): void
    {
        self::identifier($id);
        if ($revision < 1) {
            throw new \InvalidArgumentException('Invalid revision.');
        }
        $columns = array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
        $forbidden = [$key,'revision','updated_at','updated_by','imported_at'];
        $assignments = [];
        $values = [];
        foreach ($changes as $name => $value) {
            if (!is_string($name) || !in_array($name, $columns, true) || in_array($name, $forbidden, true)) {
                throw new \InvalidArgumentException('Unsupported setting: ' . (string)$name);
            }
            $value = $this->validate($name, $value);
            if (in_array($name, self::ARRAY_FIELDS, true)) {
                $value = Database::encode($value);
            }
            if (in_array($name, self::BOOL_FIELDS, true)) {
                $value = (int)$value;
            }
            $assignments[] = $name . '=?';
            $values[] = $value;
        }
        $current = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . $key . '=? AND revision=?');
        $current->execute([$id,$revision]);
        $existing = $current->fetch();
        if ($existing === false) {
            throw new StaleSettings('Settings changed. Open the current panel again.');
        }
        $candidate = array_replace($existing, $changes);
        foreach (['havdalah','omer_nightfall'] as $prefix) {
            if (!isset($candidate[$prefix . '_method'])) {
                continue;
            }
            $number = (float)$candidate[$prefix . '_value'];
            if ($candidate[$prefix . '_method'] === 'degrees' && !in_array($number, [8.5,7.083], true)) {
                throw new \InvalidArgumentException('Supported nightfall angles are 8.5 and 7.083 degrees.');
            }
            if ($candidate[$prefix . '_method'] === 'minutes' && floor($number) !== $number) {
                throw new \InvalidArgumentException('Fixed nightfall minutes must be a whole number.');
            }
        }
        $assignments[] = 'updated_at=?';
        $values[] = $this->clock->now()->getTimestamp();
        if ($increment) {
            $assignments[] = 'revision=revision+1';
        }
        if ($table === 'group_settings') {
            $assignments[] = 'updated_by=?';
            $values[] = $actor;
        }
        $query = $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(',', $assignments) . ' WHERE ' . $key . '=? AND revision=?');
        $query->execute([...$values,$id,$revision]);
        if ($query->rowCount() !== 1) {
            throw new StaleSettings('Settings changed. Open the current panel again.');
        }
        if ($increment) {
            $now = $this->clock->now()->getTimestamp();
            if ($table === 'group_settings') {
                $pdo->prepare("UPDATE scheduled_jobs SET status='cancelled',last_error='settings revision changed' WHERE group_id=? AND settings_revision<>? AND status IN ('pending','leased') AND module NOT IN ('lock','restore')")->execute([$id,$revision + 1]);
                $pdo->prepare('UPDATE conversation_states SET revoked_at=? WHERE group_id=? AND settings_revision<>? AND consumed_at IS NULL AND revoked_at IS NULL')->execute([$now,$id,$revision + 1]);
            } else {
                $pdo->prepare("UPDATE conversation_states SET revoked_at=? WHERE user_id=? AND scope='user' AND settings_revision<>? AND consumed_at IS NULL AND revoked_at IS NULL")->execute([$now,$id,$revision + 1]);
            }
            $pdo->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,?,?,?,?)')->execute([$actor,$table === 'group_settings' ? $id : null,'settings.update',Database::encode(['revision' => $revision + 1,'fields' => array_keys($changes)]),$now]);
        }
    }
    private function validate(string $name, mixed $value): mixed
    {
        if ($name === 'lock_block_flags') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException('Lock restrictions must be a list.');
            }
            return \Shabbat\Scheduling\Permissions::validateBlockFlags($value);
        }
        if (in_array($name, self::BOOL_FIELDS, true)) {
            if (!is_bool($value) && !in_array($value, [0,1], true)) {
                throw new \InvalidArgumentException('Expected boolean: ' . $name);
            }
            return (bool)$value;
        }
        if ($name === 'tefillin_ack_label') {
            if (
                $value !== null && (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8')
                || strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) > 96 || preg_match('/[\x00-\x1F\x7F]/u', $value))
            ) {
                throw new \InvalidArgumentException('Acknowledgement label must be bounded one-line UTF-8 or null for the localized default.');
            }
        }
        if ($name === 'timezone_id') {
            if (!is_string($value) || !in_array($value, DateTimeZone::listIdentifiers(), true)) {
                throw new \InvalidArgumentException('An IANA timezone is required.');
            }
        }
        if ($name === 'latitude' || $name === 'longitude') {
            $max = $name === 'latitude' ? 90 : 180;
            if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value) || abs($value) > $max) {
                throw new \InvalidArgumentException('Invalid coordinates.');
            }
        }
        $integers = ['candle_minutes' => [1,120],'close_before_candles_minutes' => [0,180],'open_after_havdalah_minutes' => [0,180],
            'times_alert_before_minutes' => [0,1440],'tefillin_interval_minutes' => [5,1440],'tefillin_max_per_day' => [1,24],
            'tefillin_min_spacing_minutes' => [5,1440],'tefillin_sunset_safety_minutes' => [1,120],
            'omer_after_nightfall_minutes' => [0,240],'omer_after_open_minutes' => [0,180],'target_topic_id' => [1,2147483647],'geoname_id' => [1,2147483647]];
        if (isset($integers[$name]) && $value !== null && (!is_int($value) || $value < $integers[$name][0] || $value > $integers[$name][1])) {
            throw new \InvalidArgumentException('Invalid integer setting: ' . $name);
        }
        if (in_array($name, ['havdalah_value','omer_nightfall_value'], true)) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value <= 0 || $value > 120) {
                throw new \InvalidArgumentException('Invalid time method value.');
            }
        }
        if (in_array($name, self::ARRAY_FIELDS, true)) {
            if (!is_array($value) || !array_is_list($value) || count($value) > 96) {
                throw new \InvalidArgumentException('Expected bounded list: ' . $name);
            }
            foreach ($value as $item) {
                if (str_ends_with($name, '_days') && (!is_int($item) || $item < 0 || $item > 6)) {
                    throw new \InvalidArgumentException('Invalid weekday.');
                }
                if ($name === 'tefillin_fixed_times') {
                    self::timeOfDay($item);
                }
                if ($name === 'tefillin_sunset_offsets' && (!is_int($item) || $item < 1 || $item > 360)) {
                    throw new \InvalidArgumentException('Invalid sunset offset.');
                }
            }
            return array_values(array_unique($value, SORT_REGULAR));
        }
        if (str_ends_with($name, '_time') || in_array($name, ['tefillin_window_start','tefillin_window_end','quiet_start','quiet_end'], true)) {
            self::timeOfDay($value);
        }
        $enums = ['language' => ['he','en','ru','fr','es','pt-br'],'mode' => ['off','messages','lock','lock_messages'],'havdalah_method' => ['degrees','minutes'],
            'omer_nightfall_method' => ['degrees','minutes'],'omer_mode' => ['fixed','nightfall'],'omer_format' => ['short','full'],
            'zmanim_format' => ['short','full'],'omer_nusach' => ['ashkenaz','sephard'],'omer_early_policy' => ['defer','skip'],
            'tefillin_tisha_bav_policy' => ['off','mincha'],'times_alert_mode' => ['local_time','before_close']];
        if (isset($enums[$name]) && !in_array($value, $enums[$name], true)) {
            throw new \InvalidArgumentException('Unsupported option: ' . $name);
        }
        if ($name === 'tefillin_suspended_date' && $value !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value, new DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException('Invalid suspension date.');
            }
        }
        if (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('Invalid scalar setting: ' . $name);
        }
        if (is_string($value) && strlen($value) > 16384) {
            throw new \InvalidArgumentException('Setting is too large.');
        }
        return $value;
    }
    private static function timeOfDay(mixed $value): void
    {
        if ($value !== null && (!is_string($value) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value))) {
            throw new \InvalidArgumentException('Use local HH:MM.');
        }
    }
    public static function identifier(string $id): void
    {
        if (!preg_match('/^-?[0-9]{1,20}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Telegram identifier.');
        }
    }
    private static function decode(array $row): array
    {
        foreach (self::ARRAY_FIELDS as $field) {
            if (isset($row[$field])) {
                $row[$field] = json_decode($row[$field], true, 32, JSON_THROW_ON_ERROR);
            }
        }
        foreach (self::BOOL_FIELDS as $field) {
            if (isset($row[$field])) {
                $row[$field] = (bool)$row[$field];
            }
        }
        foreach (['revision','target_topic_id','geoname_id'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (int)$row[$field];
            }
        }
        foreach (['latitude','longitude','havdalah_value','omer_nightfall_value'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (float)$row[$field];
            }
        }
        return $row;
    }
}
