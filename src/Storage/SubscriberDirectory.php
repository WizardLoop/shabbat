<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Storage;

use PDO;
use Shabbat\Time\Clock;

/** Bounded, local directory. Metadata and group administration are observations, not authority. */
final class SubscriberDirectory
{
    public const SORTS = ['activity', 'joined', 'name', 'id'];
    public const FILTERS = ['all', 'users', 'groups', 'channels', 'bots'];
    public const AUDIENCES = ['users', 'groups', 'channels', 'all', 'groups_channels', 'authorized_channels', 'authorized_groups', 'authorized_groups_channels'];
    private const TYPES = ['unknown', 'user', 'bot', 'chat', 'supergroup', 'channel'];
    private const SOURCE = "WITH ids AS (SELECT peer_id AS id FROM known_broadcast_peers UNION SELECT user_id FROM users UNION SELECT group_id FROM groups),
 directory AS (SELECT ids.id, CASE WHEN k.peer_type IS NOT NULL AND k.peer_type!='unknown' THEN k.peer_type WHEN u.user_id IS NOT NULL THEN 'user' WHEN g.group_id IS NOT NULL THEN 'group' ELSE 'unknown' END AS type,
 COALESCE(NULLIF(k.title,''),NULLIF(g.title,''),NULLIF(trim(COALESCE(p.first_name,'')||' '||COALESCE(p.last_name,'')),''),ids.id) AS name,
 COALESCE(k.created_at,u.created_at,g.created_at,0) AS joined_at,
 COALESCE(p.last_activity_at,0) AS last_activity_at,COALESCE(p.activity_count,0) AS activity_count,
 p.username,COALESCE(p.private_chat_access,0) AS private_chat_access,COALESCE(p.broadcast_authorized,0) AS broadcast_authorized
 FROM ids LEFT JOIN known_broadcast_peers k ON k.peer_id=ids.id LEFT JOIN subscriber_profiles p ON p.peer_id=ids.id
 LEFT JOIN users u ON u.user_id=ids.id LEFT JOIN groups g ON g.group_id=ids.id) ";

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function observe(string $id, array $metadata = [], bool $activity = false, bool $opening = false, bool $privateChat = false): void
    {
        self::id($id);
        $allowed = ['type', 'title', 'username', 'first_name', 'last_name', 'language_code', 'bot', 'deleted', 'premium', 'contact'];
        if (array_diff(array_keys($metadata), $allowed) !== []) {
            throw new \InvalidArgumentException('Unsupported directory metadata.');
        }
        $type = $metadata['type'] ?? 'unknown';
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown peer type.');
        }
        foreach (['title' => 256, 'username' => 64, 'first_name' => 256, 'last_name' => 256, 'language_code' => 32] as $key => $max) {
            if (array_key_exists($key, $metadata) && $metadata[$key] !== null) {
                self::text($metadata[$key], $max);
            }
        }
        foreach (['bot', 'deleted', 'premium', 'contact'] as $key) {
            if (array_key_exists($key, $metadata) && !is_bool($metadata[$key]) && $metadata[$key] !== null) {
                throw new \InvalidArgumentException('Invalid observed boolean.');
            }
        }
        $now = $this->clock->now()->getTimestamp();
        $this->db->transaction(static function (PDO $pdo) use ($id, $metadata, $type, $activity, $opening, $privateChat, $now): void {
            $pdo->prepare("INSERT INTO known_broadcast_peers(peer_id,peer_type,title,created_at,updated_at) VALUES(?,?,?,?,?)
 ON CONFLICT(peer_id) DO UPDATE SET peer_type=CASE WHEN excluded.peer_type='unknown' THEN peer_type ELSE excluded.peer_type END,
 title=CASE WHEN excluded.title='' THEN title ELSE excluded.title END,updated_at=excluded.updated_at")
                ->execute([$id, $type, $metadata['title'] ?? '', $now, $now]);
            $fields = ['username', 'first_name', 'last_name', 'language_code', 'bot', 'deleted', 'premium', 'contact'];
            $values = [$id];
            foreach ($fields as $field) {
                $value = $metadata[$field] ?? null;
                $values[] = is_bool($value) ? (int) $value : $value;
            }
            array_push($values, $activity ? $now : null, $activity ? $now : null, (int) $activity, (int) $opening, $opening ? $now : null, $now, $privateChat ? 1 : null);
            $pdo->prepare("INSERT INTO subscriber_profiles(peer_id,username,first_name,last_name,language_code,is_bot,is_deleted,is_premium,is_contact,
 first_activity_at,last_activity_at,activity_count,start_count,last_start_at,metadata_at,private_chat_access) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
 ON CONFLICT(peer_id) DO UPDATE SET username=COALESCE(excluded.username,username),first_name=COALESCE(excluded.first_name,first_name),
 last_name=COALESCE(excluded.last_name,last_name),language_code=COALESCE(excluded.language_code,language_code),is_bot=COALESCE(excluded.is_bot,is_bot),
 is_deleted=COALESCE(excluded.is_deleted,is_deleted),is_premium=COALESCE(excluded.is_premium,is_premium),is_contact=COALESCE(excluded.is_contact,is_contact),
 first_activity_at=COALESCE(first_activity_at,excluded.first_activity_at),last_activity_at=COALESCE(excluded.last_activity_at,last_activity_at),
 activity_count=activity_count+excluded.activity_count,start_count=start_count+excluded.start_count,
 last_start_at=COALESCE(excluded.last_start_at,last_start_at),metadata_at=excluded.metadata_at,private_chat_access=COALESCE(excluded.private_chat_access,private_chat_access)")->execute($values);
        });
    }

    /** An actual incoming private update is the only new-chat proof of direct-message eligibility. */
    public function observePrivate(string $id, array $metadata = [], bool $activity = true, bool $opening = false): void
    {
        if (!in_array($metadata['type'] ?? 'unknown', ['unknown', 'user', 'bot'], true)) {
            throw new \InvalidArgumentException('Private observations require a private peer.');
        }
        $this->observe($id, $metadata, $activity, $opening, true);
    }
    /** Call only after a fresh Telegram administrator lookup; never use this cache for authorization. */
    public function recordGroupAdmin(string $user, string $group, bool $isAdmin, string $role = 'admin'): void
    {
        self::id($user);
        self::id($group);
        if (!in_array($role, ['owner', 'admin', 'member', 'left', 'unknown'], true)) {
            throw new \InvalidArgumentException('Unknown observed group role.');
        }
        $this->db->pdo()->prepare('INSERT INTO subscriber_group_admins(user_id,group_id,is_admin,role,checked_at) VALUES(?,?,?,?,?)
 ON CONFLICT(user_id,group_id) DO UPDATE SET is_admin=excluded.is_admin,role=excluded.role,checked_at=excluded.checked_at')
            ->execute([$user, $group, (int) $isAdmin, $role, $this->clock->now()->getTimestamp()]);
    }

    public function page(int $page = 0, int $size = 10, string $search = '', string $sort = 'activity', string $filter = 'all'): array
    {
        if ($page < 0 || $page > 999999 || $size < 1 || $size > 20 || !in_array($sort, self::SORTS, true) || !in_array($filter, self::FILTERS, true)) {
            throw new \InvalidArgumentException('Invalid directory page.');
        }
        self::text($search, 128);
        [$where, $parameters] = $this->where($filter, $search);
        $query = $this->db->pdo()->prepare(self::SOURCE . 'SELECT COUNT(*) FROM directory WHERE ' . $where);
        $query->execute($parameters);
        $total = (int) $query->fetchColumn();
        $pages = max(1, (int) ceil($total / $size));
        $page = min($page, $pages - 1);
        $order = match ($sort) {
            'joined' => 'joined_at DESC,id COLLATE BINARY',
            'name' => 'name COLLATE NOCASE,id COLLATE BINARY',
            'id' => 'id COLLATE BINARY',
            default => 'last_activity_at DESC,joined_at DESC,id COLLATE BINARY',
        };
        $query = $this->db->pdo()->prepare(self::SOURCE . 'SELECT * FROM directory WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?');
        foreach (array_values($parameters) as $index => $parameter) {
            $query->bindValue($index + 1, $parameter, PDO::PARAM_STR);
        }
        $query->bindValue(count($parameters) + 1, $size, PDO::PARAM_INT);
        $query->bindValue(count($parameters) + 2, $page * $size, PDO::PARAM_INT);
        $query->execute();
        return ['items' => $query->fetchAll(), 'page' => $page, 'pages' => $pages, 'total' => $total, 'search' => $search, 'sort' => $sort, 'filter' => $filter];
    }

    public function detail(string $id): ?array
    {
        self::id($id);
        $query = $this->db->pdo()->prepare(self::SOURCE . 'SELECT * FROM directory WHERE id=?');
        $query->execute([$id]);
        $item = $query->fetch();
        if ($item === false) {
            return null;
        }
        $profile = $this->db->pdo()->prepare('SELECT * FROM subscriber_profiles WHERE peer_id=?');
        $profile->execute([$id]);
        $item['profile'] = $profile->fetch() ?: [];
        $preferences = $this->db->pdo()->prepare('SELECT * FROM user_preferences WHERE user_id=?');
        $preferences->execute([$id]);
        $item['preferences'] = $preferences->fetch() ?: null;
        $group = $this->db->pdo()->prepare('SELECT s.*,g.title,g.active FROM group_settings s JOIN groups g USING(group_id) WHERE group_id=?');
        $group->execute([$id]);
        $item['group'] = $group->fetch() ?: null;
        $admins = $this->db->pdo()->prepare('SELECT a.*,g.title,g.active FROM subscriber_group_admins a JOIN groups g USING(group_id) WHERE a.user_id=? ORDER BY a.is_admin DESC,a.checked_at DESC,a.group_id LIMIT 51');
        $admins->execute([$id]);
        $item['group_roles'] = $admins->fetchAll();
        $item['group_roles_truncated'] = count($item['group_roles']) > 50;
        $item['group_roles'] = array_slice($item['group_roles'], 0, 50);
        $item['known_group_admin'] = count(array_filter($item['group_roles'], static fn(array $row): bool => (int) $row['is_admin'] === 1)) > 0;
        return $item;
    }

    /** Explicit local permission for the reference's authorized chat audiences; default denies. */
    public function setBroadcastAuthorized(string $id, bool $authorized): void
    {
        $this->db->transaction(fn(PDO $pdo) => $this->setBroadcastAuthorizedInTransaction($pdo, $id, $authorized));
    }

    /** Use only inside this database's already-open, freshly authorized confirmation transaction. */
    public function setBroadcastAuthorizedInTransaction(PDO $pdo, string $id, bool $authorized): void
    {
        if ($pdo !== $this->db->pdo()) {
            throw new \LogicException('Broadcast authorization requires the same database transaction.');
        }
        self::id($id);
        $item = $this->detail($id);
        if ($item === null || !in_array($item['type'], ['chat', 'supergroup', 'group', 'channel'], true)) {
            throw new \InvalidArgumentException('Only a known group or channel can be explicitly authorized.');
        }
        $now = $this->clock->now()->getTimestamp();
        $pdo->prepare("INSERT OR IGNORE INTO known_broadcast_peers(peer_id,peer_type,title,created_at,updated_at) VALUES(?,'unknown',?,?,?)")
            ->execute([$id, $item['name'], $now, $now]);
        $pdo->prepare('INSERT OR IGNORE INTO subscriber_profiles(peer_id,metadata_at) VALUES(?,?)')->execute([$id, $now]);
        $pdo->prepare('UPDATE subscriber_profiles SET broadcast_authorized=? WHERE peer_id=?')->execute([(int) $authorized, $id]);
    }

    public function targets(string $audience): array
    {
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw new \InvalidArgumentException('Unknown broadcast audience.');
        }
        $where = match ($audience) {
            'users' => "type='user' AND private_chat_access=1",
            'groups' => "type IN ('chat','supergroup','group')",
            'channels' => "type='channel'",
            'groups_channels' => "type IN ('chat','supergroup','group','channel')",
            'authorized_channels' => "type='channel' AND broadcast_authorized=1",
            'authorized_groups' => "type IN ('chat','supergroup','group') AND broadcast_authorized=1",
            'authorized_groups_channels' => "type IN ('chat','supergroup','group','channel') AND broadcast_authorized=1",
            default => "(type IN ('user','bot') AND private_chat_access=1) OR type IN ('chat','supergroup','group','channel')",
        };
        return $this->db->pdo()->query(self::SOURCE . 'SELECT id FROM directory WHERE ' . $where . ' ORDER BY id COLLATE BINARY')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Complete role observations are paged independently of the bounded profile summary. */
    public function roles(string $user, int $page = 0, int $size = 10): array
    {
        self::id($user);
        if ($page < 0 || $size < 1 || $size > 20) {
            throw new \InvalidArgumentException('Invalid role page.');
        }
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM subscriber_group_admins WHERE user_id=?');
        $count->execute([$user]);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $size));
        $page = min($page, $pages - 1);
        $query = $this->db->pdo()->prepare('SELECT a.*,g.title,g.active FROM subscriber_group_admins a JOIN groups g USING(group_id)
 WHERE a.user_id=? ORDER BY a.is_admin DESC,a.checked_at DESC,a.group_id LIMIT ? OFFSET ?');
        $query->bindValue(1, $user);
        $query->bindValue(2, $size, PDO::PARAM_INT);
        $query->bindValue(3, $page * $size, PDO::PARAM_INT);
        $query->execute();
        return ['items' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function stats(): array
    {
        $result = array_fill_keys(['users', 'bots', 'chats', 'supergroups', 'groups', 'channels', 'unknown', 'total', 'active_24h', 'active_7d', 'active_30d', 'starts', 'activity', 'registered_groups', 'active_groups', 'locking', 'locked', 'recovery', 'pending_jobs', 'failed_jobs'], 0);
        foreach ($this->db->pdo()->query(self::SOURCE . 'SELECT type,COUNT(*) AS amount FROM directory GROUP BY type')->fetchAll() as $row) {
            $key = match ($row['type']) {
                'user' => 'users', 'bot' => 'bots', 'chat' => 'chats', 'supergroup' => 'supergroups', 'group' => 'groups', 'channel' => 'channels', default => 'unknown'
            };
            $result[$key] = (int) $row['amount'];
            $result['total'] += (int) $row['amount'];
        }
        foreach (['active_24h' => 86400, 'active_7d' => 604800, 'active_30d' => 2592000] as $key => $seconds) {
            $query = $this->db->pdo()->prepare('SELECT COUNT(*) FROM subscriber_profiles WHERE last_activity_at>=?');
            $query->execute([$this->clock->now()->getTimestamp() - $seconds]);
            $result[$key] = (int) $query->fetchColumn();
        }
        $totals = $this->db->pdo()->query('SELECT COALESCE(SUM(start_count),0) AS starts,COALESCE(SUM(activity_count),0) AS activity FROM subscriber_profiles')->fetch();
        $result['starts'] = (int) $totals['starts'];
        $result['activity'] = (int) $totals['activity'];
        foreach (
            [
            'registered_groups' => 'SELECT COUNT(*) FROM group_settings',
            'active_groups' => 'SELECT COUNT(*) FROM groups WHERE active=1',
            'locking' => "SELECT COUNT(*) FROM groups g JOIN group_settings s USING(group_id) WHERE g.active=1 AND s.mode IN ('lock','lock_messages')",
            'locked' => "SELECT COUNT(*) FROM group_lock_state WHERE status='locked'",
            'recovery' => "SELECT COUNT(*) FROM group_lock_state WHERE status IN ('recovery_required','conflict','error')",
            'pending_jobs' => "SELECT COUNT(*) FROM scheduled_jobs WHERE status IN ('pending','leased')",
            'failed_jobs' => "SELECT COUNT(*) FROM scheduled_jobs WHERE status='failed'",
            ] as $key => $sql
        ) {
            $result[$key] = (int) $this->db->pdo()->query($sql)->fetchColumn();
        }
        return $result;
    }

    public function view(string $viewer): array
    {
        self::id($viewer);
        $query = $this->db->pdo()->prepare('SELECT search,sort,filter,page,prompt FROM subscriber_directory_views WHERE viewer_id=?');
        $query->execute([$viewer]);
        $row = $query->fetch();
        return $row === false ? ['search' => '', 'sort' => 'activity', 'filter' => 'all', 'page' => 0, 'prompt' => null] : $row;
    }

    public function saveView(string $viewer, array $changes): array
    {
        $view = array_replace($this->view($viewer), $changes);
        if (
            array_diff(array_keys($changes), ['search', 'sort', 'filter', 'page', 'prompt']) !== [] ||
            !is_string($view['search']) || !in_array($view['sort'], self::SORTS, true) || !in_array($view['filter'], self::FILTERS, true) ||
            !is_int($view['page']) || $view['page'] < 0 || $view['page'] > 999999 || !in_array($view['prompt'], [null, 'search', 'page'], true)
        ) {
            throw new \InvalidArgumentException('Invalid directory view.');
        }
        self::text($view['search'], 128);
        $this->db->pdo()->prepare('INSERT INTO subscriber_directory_views(viewer_id,search,sort,filter,page,prompt,updated_at) VALUES(?,?,?,?,?,?,?)
 ON CONFLICT(viewer_id) DO UPDATE SET search=excluded.search,sort=excluded.sort,filter=excluded.filter,page=excluded.page,prompt=excluded.prompt,updated_at=excluded.updated_at')
            ->execute([$viewer, $view['search'], $view['sort'], $view['filter'], $view['page'], $view['prompt'], $this->clock->now()->getTimestamp()]);
        return $view;
    }

    private function where(string $filter, string $search): array
    {
        $type = match ($filter) {
            'users' => "type='user'", 'bots' => "type='bot'", 'groups' => "type IN ('chat','supergroup','group')", 'channels' => "type='channel'", default => '1=1'
        };
        if ($search === '') {
            return [$type, []];
        }
        $needle = '%' . strtr(ltrim($search, '@'), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        return ["(" . $type . ") AND (name LIKE ? ESCAPE '\\' OR id LIKE ? ESCAPE '\\' OR username LIKE ? ESCAPE '\\')", [$needle, $needle, $needle]];
    }

    private static function id(string $id): void
    {
        if (preg_match('/^-?[1-9][0-9]{0,19}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid directory identifier.');
        }
    }

    private static function text(mixed $value, int $max): void
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max || preg_match('/[\\x00-\\x1f\\x7f]/u', $value)) {
            throw new \InvalidArgumentException('Invalid directory text.');
        }
    }
}
