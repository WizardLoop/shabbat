<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Storage;

use PDO;
use Shabbat\Time\Clock;
use Shabbat\UI\SystemLinks;

/** Aggregate start analytics; raw visitor IDs and individual click histories are never stored. */
final class TrackingLinkRepository
{
    public function __construct(private Database $db, private Clock $clock, private AdminAccessRepository $access)
    {
    }
    public static function normalize(string $param): string
    {
        $param = strtolower(trim($param));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9_-]{0,62}[a-z0-9])?$/D', $param) || SystemLinks::isReserved($param)) {
            throw new \InvalidArgumentException('Choose an unused 1-64 character English start parameter.');
        }
        return $param;
    }
    public function create(string $actor, string $param, string $label = '', string $destination = 'home'): array
    {
        $param = self::normalize($param);
        if (!isset(SystemLinks::DEFINITIONS[$destination]) || isset(SystemLinks::DEFINITIONS[$destination]['permission']) || mb_strlen($label) > 80 || preg_match('/[\x00-\x1f]/u', $label)) {
            throw new \InvalidArgumentException('Invalid tracking label or public destination.');
        }
        $id = $this->db->transaction(function (PDO $pdo) use ($actor, $param, $label, $destination): int {
            $this->access->require($actor, 'tracking_links', true);
            if ((int) $pdo->query('SELECT COUNT(*) FROM tracking_links')->fetchColumn() >= 10000) {
                throw new \RuntimeException('Tracking link capacity reached.');
            }
            $pdo->prepare('INSERT INTO tracking_links(param,label,destination,created_by,created_at) VALUES(?,?,?,?,?)')->execute([$param, $label === '' ? $param : $label, $destination, $actor, $this->clock->now()->getTimestamp()]);
            return (int) $pdo->lastInsertId();
        });
        return $this->get($actor, $id) ?? throw new \RuntimeException('Tracking link save failed.');
    }
    public function page(string $actor, int $page = 0): array
    {
        $this->access->require($actor, 'tracking_links');
        $total = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM tracking_links')->fetchColumn();
        $pages = max(1, (int) ceil($total / 5));
        $page = max(0, min($page, $pages - 1));
        $items = $this->db->pdo()->query('SELECT * FROM tracking_links ORDER BY id DESC LIMIT 5 OFFSET ' . ($page * 5))->fetchAll();
        return compact('items', 'total', 'page', 'pages');
    }
    public function get(string $actor, int $id): ?array
    {
        $this->access->require($actor, 'tracking_links');
        $q = $this->db->pdo()->prepare('SELECT * FROM tracking_links WHERE id=?');
        $q->execute([$id]);
        $row = $q->fetch();
        if ($row === false) {
            return null;
        }
        $q = $this->db->pdo()->prepare('SELECT day,clicks FROM tracking_link_days WHERE tracker_id=? AND day>=? ORDER BY day DESC');
        $q->execute([$id, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('-6 days')->format('Y-m-d')]);
        $row['days'] = $q->fetchAll();
        $row['repeat_clicks'] = max(0, (int) $row['total_clicks'] - (int) $row['unique_users']);
        return $row;
    }
    /** Only a private /start with a positive Telegram message ID counts; redelivery is deduplicated. */
    public function recordStart(string $param, string $user, int $messageId, bool $private = true): ?string
    {
        if (!$private || !preg_match('/^[1-9][0-9]{0,18}$/D', $user) || $messageId < 1) {
            return null;
        }
        try {
            $param = self::normalize($param);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $q = $this->db->pdo()->prepare('SELECT id FROM tracking_links WHERE param=?');
        $q->execute([$param]);
        if ($q->fetchColumn() === false) {
            return null;
        }
        return $this->db->transaction(function (PDO $pdo) use ($param, $user, $messageId): ?string {
            $q = $pdo->prepare('SELECT * FROM tracking_links WHERE param=?');
            $q->execute([$param]);
            $tracker = $q->fetch();
            if ($tracker === false) {
                return null;
            }
            $salt = $pdo->query("SELECT meta_value FROM admin_meta WHERE meta_key='tracking_salt'")->fetchColumn();
            if ($salt === false) {
                $salt = bin2hex(random_bytes(32));
                $pdo->prepare("INSERT INTO admin_meta VALUES('tracking_salt',?)")->execute([$salt]);
            }
            if (!is_string($salt) || !preg_match('/^[a-f0-9]{64}$/D', $salt)) {
                throw new \RuntimeException('Tracking privacy key is invalid.');
            }
            $hash = hash_hmac('sha256', $user, $salt);
            $q = $pdo->prepare('SELECT last_message_id FROM tracking_link_users WHERE tracker_id=? AND user_hash=?');
            $q->execute([$tracker['id'], $hash]);
            $last = $q->fetchColumn();
            if ($last !== false && (int) $last >= $messageId) {
                return (string) $tracker['destination'];
            }
            $now = $this->clock->now()->getTimestamp();
            $pdo->prepare('INSERT INTO tracking_link_users VALUES(?,?,?) ON CONFLICT(tracker_id,user_hash) DO UPDATE SET last_message_id=excluded.last_message_id')->execute([$tracker['id'], $hash, $messageId]);
            $pdo->prepare('UPDATE tracking_links SET total_clicks=total_clicks+1,unique_users=unique_users+?,first_click_at=COALESCE(first_click_at,?),last_click_at=? WHERE id=?')->execute([$last === false ? 1 : 0, $now, $now, $tracker['id']]);
            $day = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
            $pdo->prepare('INSERT INTO tracking_link_days VALUES(?,?,1) ON CONFLICT(tracker_id,day) DO UPDATE SET clicks=clicks+1')->execute([$tracker['id'], $day]);
            $pdo->prepare('DELETE FROM tracking_link_days WHERE tracker_id=? AND day<?')->execute([$tracker['id'], $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('-90 days')->format('Y-m-d')]);
            return (string) $tracker['destination'];
        });
    }
    public function reset(string $actor, int $id, int $revision): void
    {
        $this->mutate($actor, $id, $revision, false);
    }
    public function delete(string $actor, int $id, int $revision): void
    {
        $this->mutate($actor, $id, $revision, true);
    }
    private function mutate(string $actor, int $id, int $revision, bool $delete): void
    {
        $this->db->transaction(function (PDO $pdo) use ($actor, $id, $revision, $delete): void {
            $this->access->require($actor, 'tracking_links', true);
            $q = $pdo->prepare('SELECT revision FROM tracking_links WHERE id=?');
            $q->execute([$id]);
            if ((int) $q->fetchColumn() !== $revision || $revision < 1) {
                throw new StaleSettings('Tracking link changed; reopen the details.');
            }
            if ($delete) {
                $pdo->prepare('DELETE FROM tracking_links WHERE id=?')->execute([$id]);
            } else {
                $pdo->prepare('DELETE FROM tracking_link_users WHERE tracker_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM tracking_link_days WHERE tracker_id=?')->execute([$id]);
                $pdo->prepare('UPDATE tracking_links SET revision=revision+1,total_clicks=0,unique_users=0,first_click_at=NULL,last_click_at=NULL WHERE id=?')->execute([$id]);
            }
            $pdo->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,NULL,?,?,?)')->execute([$actor, 'operator.tracking.' . ($delete ? 'delete' : 'reset'), Database::encode(['id' => $id]), $this->clock->now()->getTimestamp()]);
        });
    }
}
