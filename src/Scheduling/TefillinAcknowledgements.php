<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Scheduling;

use Amp\CancelledException;
use PDO;
use Shabbat\Storage\{Database, SettingsRepository, StaleSettings};
use Shabbat\Telegram\{RetryableTelegramException, SentMessageIds};
use Shabbat\Time\Clock;

/** Durable per-message acknowledgements. No DB transaction includes a remote edit. */
final class TefillinAcknowledgements
{
    private static array $editing = [];
    private string $databaseIdentity;
    public const ICON = 5944970388252402183;
    public function __construct(private Database $db, private SettingsRepository $settings, private Clock $clock, private ?string $selfBotId = null)
    {
        $main = $this->db->pdo()->query('PRAGMA database_list')->fetch();
        $this->databaseIdentity = ($main['file'] ?? '') !== '' ? (string)$main['file'] : 'memory:' . spl_object_id($db->pdo());
    }
    public function preparePayload(array $job, array $group, \DateTimeImmutable $instant, array $payload): array
    {
        $mode = $payload['buttons_mode'] ?? (empty($payload['buttons']) && empty($payload['reply_markup']) ? 'inherit' : 'custom');
        if (($job['module'] ?? '') !== 'tefillin' || !($group['tefillin_ack_enabled'] ?? true) || $mode !== 'inherit') {
            return $payload;
        }
        $jobId = $job['job_id'] ?? null;
        $groupId = $group['group_id'] ?? null;
        if (!is_int($jobId) || $jobId < 1 || !is_string($groupId)) {
            throw new \InvalidArgumentException('Acknowledgements require a persisted tefillin job.');
        }
        self::group($groupId);
        $round = $this->db->transaction(function (PDO $pdo) use ($jobId, $groupId, $group, $instant, $payload): array {
            $q = $pdo->prepare('SELECT * FROM scheduled_jobs WHERE job_id=?');
            $q->execute([$jobId]);
            $stored = $q->fetch();
            if (
                $stored === false || $stored['group_id'] !== $groupId || $stored['module'] !== 'tefillin'
                || !in_array($stored['status'], ['pending', 'leased'], true) || (int)$stored['settings_revision'] !== $group['revision']
            ) {
                throw new StaleSettings('Acknowledgement job identity or revision changed.');
            }
            $current = $this->settings->getGroup($groupId);
            if ($current === null || !$current['active'] || !$current['tefillin_ack_enabled'] || $current['revision'] !== $group['revision']) {
                throw new StaleSettings('Acknowledgement group settings changed.');
            }
            $q = $pdo->prepare('SELECT * FROM tefillin_ack_rounds WHERE job_id=?');
            $q->execute([$jobId]);
            $round = $q->fetch();
            if ($round !== false && (int)$round['settings_revision'] === $group['revision']) {
                return $round;
            }
            if ($round !== false) {
                $bound = $pdo->prepare('SELECT 1 FROM tefillin_ack_messages WHERE round_token=?');
                $bound->execute([$round['round_token']]);
                if ($bound->fetchColumn() !== false) {
                    throw new \RuntimeException('A delivered acknowledgement round cannot change settings identity.');
                }
                $pdo->prepare('DELETE FROM tefillin_ack_rounds WHERE round_token=?')->execute([$round['round_token']]);
            }
            $token = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $label = $group['tefillin_ack_label'] ?? TefillinAckText::label($group['language']);
            $markup = $this->appendButton($payload, $token, $label);
            $pdo->prepare('INSERT INTO tefillin_ack_rounds VALUES(?,?,?,?,?,?,?,?)')->execute([$token, $jobId, $groupId, $label, $group['language'], $group['revision'], Database::encode($markup), $instant->getTimestamp()]);
            return ['round_token' => $token, 'markup_json' => Database::encode($markup)];
        });
        unset($payload['buttons']);
        $payload['reply_markup'] = json_decode($round['markup_json'], true, 64, JSON_THROW_ON_ERROR);
        $payload['_tefillin_ack_token'] = $round['round_token'];
        return $payload;
    }
    /** Only identifiers present in the durable successful response can become clickable rounds. */
    public function registerDelivery(int $jobId, array $verifiedIds): void
    {
        $ids = self::messageIds($verifiedIds);
        $this->db->transaction(function (PDO $pdo) use ($jobId, $ids): void {
            $q = $pdo->prepare("SELECT j.*,d.response_json,r.round_token,r.group_id AS ack_group FROM scheduled_jobs j JOIN delivery_log d ON d.job_id=j.job_id AND d.attempt=j.attempts AND d.outcome='sent' JOIN tefillin_ack_rounds r ON r.job_id=j.job_id WHERE j.job_id=? AND j.status='sent' AND j.module='tefillin'");
            $q->execute([$jobId]);
            $job = $q->fetch();
            if ($job === false) {
                $q = $pdo->prepare('SELECT 1 FROM tefillin_ack_rounds WHERE job_id=?');
                $q->execute([$jobId]);
                if ($q->fetchColumn() === false) {
                    return; // Explicitly removed/custom buttons create no acknowledgement round.
                }
                throw new \RuntimeException('Acknowledgements require a durable successful send.');
            }
            $payload = json_decode($job['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            $response = json_decode($job['response_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!isset($payload['_tefillin_ack_token'])) {
                return;
            }
            if (
                ($payload['_tefillin_ack_token'] ?? null) !== $job['round_token'] || $job['group_id'] !== $job['ack_group']
                || !is_array($response) || array_diff($ids, SentMessageIds::extract($response, $job['random_id'], $job['group_id'])) !== []
            ) {
                throw new \RuntimeException('Acknowledgement message identity is not verified by the confirmed response.');
            }
            foreach ($ids as $messageId) {
                $q = $pdo->prepare('SELECT round_token FROM tefillin_ack_messages WHERE group_id=? AND message_id=?');
                $q->execute([$job['group_id'], $messageId]);
                $old = $q->fetchColumn();
                if ($old !== false && $old !== $job['round_token']) {
                    throw new \RuntimeException('A delivered message is already bound to another acknowledgement round.');
                }
                $pdo->prepare('INSERT OR IGNORE INTO tefillin_ack_messages(group_id,message_id,round_token,updated_at) VALUES(?,?,?,?)')
                    ->execute([$job['group_id'], $messageId, $job['round_token'], $this->now()]);
            }
        });
    }
    public function toggle(string $user, string $group, int $message, string $token, string $callbackId, bool $bot = false, bool $anonymous = false, bool $inline = false): array
    {
        self::group($group);
        self::messageIds([$message]);
        self::token($token);
        if (
            !preg_match('/^[1-9][0-9]{0,18}$/D', $user) || $user === $this->selfBotId || $bot || $anonymous || $inline
            || $callbackId === '' || strlen($callbackId) > 128 || preg_match('/[^\x21-\x7e]/', $callbackId)
        ) {
            throw new \InvalidArgumentException('A real identified group member and native callback are required.');
        }
        return $this->db->transaction(function (PDO $pdo) use ($user, $group, $message, $token, $callbackId): array {
            $settings = $this->settings->getGroup($group);
            if ($settings === null || !$settings['active'] || !$settings['tefillin_ack_enabled'] || !$settings['tefillin_enabled']) {
                throw new \RuntimeException('Tefillin acknowledgements are disabled for this group.');
            }
            $bound = $this->snapshot($group, $message, $user);
            if ($bound === null || !hash_equals($bound['token'], $token)) {
                throw new \RuntimeException('This acknowledgement belongs to another group or message.');
            }
            $hash = hash('sha256', $callbackId);
            $q = $pdo->prepare('SELECT * FROM tefillin_ack_callbacks WHERE callback_hash=?');
            $q->execute([$hash]);
            $previous = $q->fetch();
            if ($previous !== false) {
                if ($previous['round_token'] !== $token || (int)$previous['message_id'] !== $message || $previous['user_id'] !== $user) {
                    throw new \RuntimeException('Acknowledgement callback identity was reused in another context.');
                }
                return $bound + ['duplicate' => true];
            }
            if ($bound['selected']) {
                $pdo->prepare('DELETE FROM tefillin_ack_votes WHERE round_token=? AND message_id=? AND user_id=?')->execute([$token, $message, $user]);
            } else {
                $pdo->prepare('INSERT INTO tefillin_ack_votes VALUES(?,?,?,?)')->execute([$token, $message, $user, $this->now()]);
            }
            $pdo->prepare('INSERT INTO tefillin_ack_callbacks VALUES(?,?,?,?,?)')->execute([$hash, $token, $message, $user, $this->now()]);
            $pdo->prepare('UPDATE tefillin_ack_messages SET revision=revision+1,retry_at=CASE WHEN retry_at>? THEN retry_at ELSE NULL END,edit_attempts=CASE WHEN retry_at>? THEN edit_attempts ELSE 0 END,last_error_code=CASE WHEN retry_at>? THEN last_error_code ELSE NULL END,updated_at=? WHERE group_id=? AND message_id=?')
                ->execute([$this->now(), $this->now(), $this->now(), $this->now(), $group, $message]);
            return ($this->snapshot($group, $message, $user) ?? throw new \LogicException('Acknowledgement disappeared.')) + ['duplicate' => false];
        });
    }
    /** Current state is always read from SQLite, including during an in-flight markup edit. */
    public function snapshot(string $group, int $message, ?string $user = null): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT m.*,r.label,r.language,r.markup_json,(SELECT COUNT(*) FROM tefillin_ack_votes v WHERE v.round_token=m.round_token AND v.message_id=m.message_id) AS ack_count,EXISTS(SELECT 1 FROM tefillin_ack_votes v WHERE v.round_token=m.round_token AND v.message_id=m.message_id AND v.user_id=?) AS actor_selected FROM tefillin_ack_messages m JOIN tefillin_ack_rounds r USING(round_token) WHERE m.group_id=? AND m.message_id=?');
        $q->execute([$user, $group, $message]);
        $row = $q->fetch();
        if ($row === false) {
            return null;
        }
        $count = (int)$row['ack_count'];
        $markup = $this->withCount(json_decode($row['markup_json'], true, 64, JSON_THROW_ON_ERROR), $row['round_token'], $row['label'], $count);
        return ['group_id' => $group, 'message_id' => $message, 'token' => $row['round_token'], 'language' => $row['language'],
            'count' => $count, 'selected' => (bool)$row['actor_selected'], 'revision' => (int)$row['revision'],
            'rendered_revision' => (int)$row['rendered_revision'], 'retry_at' => $row['retry_at'] === null ? null : (int)$row['retry_at'],
            'edit_attempts' => (int)$row['edit_attempts'], 'markup' => $markup];
    }
    /** edit(group, messageId, markup) performs a markup-only RPC and returns only after confirmation. */
    public function refresh(string $group, int $message, callable $edit): array
    {
        self::group($group);
        self::messageIds([$message]);
        $key = $this->databaseIdentity . ':' . $group . ':' . $message;
        if (isset(self::$editing[$key])) {
            return ['status' => 'coalesced'];
        }
        self::$editing[$key] = true;
        try {
            for ($pass = 0; $pass < 3; $pass++) {
                $s = $this->snapshot($group, $message);
                $g = $this->settings->getGroup($group);
                if ($s === null || $g === null || !$g['active'] || !$g['tefillin_ack_enabled'] || !$g['tefillin_enabled']) {
                    return ['status' => 'inactive'];
                }
                if ($s['revision'] <= $s['rendered_revision']) {
                    return ['status' => 'current'];
                }
                if (($s['retry_at'] !== null && $s['retry_at'] > $this->now()) || $s['edit_attempts'] >= 5) {
                    return ['status' => 'deferred'];
                }
                try {
                    $edit($group, $message, $s['markup']);
                } catch (CancelledException $error) {
                    throw $error;
                } catch (RetryableTelegramException $error) {
                    $this->defer($group, $message, max(1, $error->retryAfterSeconds), 'retryable');
                    return ['status' => 'retry'];
                } catch (\Throwable $error) {
                    $this->defer($group, $message, min(900, 30 * (2 ** min(4, $s['edit_attempts']))), get_class($error));
                    return ['status' => 'retry'];
                }
                $this->db->pdo()->prepare('UPDATE tefillin_ack_messages SET rendered_revision=MAX(rendered_revision,?),retry_at=NULL,edit_attempts=0,last_error_code=NULL WHERE group_id=? AND message_id=?')
                    ->execute([$s['revision'], $group, $message]);
            }
            return ['status' => 'pending'];
        } finally {
            unset(self::$editing[$key]);
        }
    }
    public function flushPending(callable $edit, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid acknowledgement edit batch size.');
        }
        $q = $this->db->pdo()->prepare('SELECT m.group_id,m.message_id FROM tefillin_ack_messages m JOIN groups g USING(group_id) JOIN group_settings s USING(group_id) WHERE m.rendered_revision<m.revision AND m.edit_attempts<5 AND (m.retry_at IS NULL OR m.retry_at<=?) AND g.active=1 AND s.tefillin_enabled=1 AND s.tefillin_ack_enabled=1 ORDER BY m.updated_at,m.message_id LIMIT ?');
        $q->bindValue(1, $this->now(), PDO::PARAM_INT);
        $q->bindValue(2, $limit, PDO::PARAM_INT);
        $q->execute();
        $results = [];
        foreach ($q->fetchAll() as $row) {
            $results[] = $this->refresh($row['group_id'], (int)$row['message_id'], $edit);
        }
        return $results;
    }
    private function defer(string $group, int $message, int $seconds, string $code): void
    {
        $until = $seconds > PHP_INT_MAX - $this->now() ? PHP_INT_MAX : $this->now() + $seconds;
        $this->db->pdo()->prepare('UPDATE tefillin_ack_messages SET retry_at=?,edit_attempts=edit_attempts+1,last_error_code=? WHERE group_id=? AND message_id=?')
            ->execute([$until, mb_substr($code, 0, 128), $group, $message]);
    }
    private function appendButton(array $payload, string $token, string $label): array
    {
        if (!mb_check_encoding($label, 'UTF-8') || trim($label) === '' || strlen(mb_convert_encoding($label, 'UTF-16LE', 'UTF-8')) > 96 || preg_match('/[\x00-\x1F\x7F]/u', $label)) {
            throw new \InvalidArgumentException('Invalid tefillin acknowledgement button label.');
        }
        $button = ['_' => 'keyboardButtonCallback', 'text' => $label . ' (0)', '_presentation_fallback_text' => '🔔 ' . $label . ' (0)', 'data' => 'tf:' . $token,
            'style' => ['_' => 'keyboardButtonStyle', 'bg_primary' => true, 'icon' => self::ICON]];
        $markup = $payload['reply_markup'] ?? ['inline_keyboard' => $payload['buttons'] ?? []];
        if (($markup['_'] ?? '') === 'replyInlineMarkup' && is_array($markup['rows'] ?? null)) {
            $markup['rows'][] = ['_' => 'keyboardButtonRow', 'buttons' => [$button]];
        } elseif (is_array($markup['inline_keyboard'] ?? null)) {
            $markup['inline_keyboard'][] = [$button];
        } else {
            throw new \InvalidArgumentException('Unsupported reminder keyboard format.');
        }
        return $markup;
    }
    private function withCount(array $markup, string $token, string $label, int $count): array
    {
        $found = 0;
        if (($markup['_'] ?? '') === 'replyInlineMarkup') {
            foreach ($markup['rows'] as &$row) {
                foreach ($row['buttons'] as &$button) {
                    if (($button['data'] ?? null) === 'tf:' . $token) {
                        $button['text'] = $label . ' (' . $count . ')';
                        $button['_presentation_fallback_text'] = '🔔 ' . $label . ' (' . $count . ')';
                        $found++;
                    }
                }
                unset($button);
            }
            unset($row);
        } else {
            foreach ($markup['inline_keyboard'] as &$row) {
                foreach ($row as &$button) {
                    if (($button['data'] ?? $button['callback_data'] ?? null) === 'tf:' . $token) {
                        $button['text'] = $label . ' (' . $count . ')';
                        $button['_presentation_fallback_text'] = '🔔 ' . $label . ' (' . $count . ')';
                        $found++;
                    }
                }
                unset($button);
            }
            unset($row);
        }
        if ($found !== 1) {
            throw new \RuntimeException('Reminder acknowledgement keyboard identity is invalid.');
        }
        return $markup;
    }
    private static function group(string $group): void
    {
        if (!preg_match('/^-[1-9][0-9]{0,18}$/D', $group)) {
            throw new \InvalidArgumentException('A native group identifier is required.');
        }
    }
    private static function token(string $token): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{24}$/D', $token)) {
            throw new \InvalidArgumentException('Invalid tefillin acknowledgement token.');
        }
    }
    private static function messageIds(array $ids): array
    {
        if (!array_is_list($ids) || $ids === [] || count($ids) > 100) {
            throw new \InvalidArgumentException('Verified message identifiers are required.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || $id > 2147483647) {
                throw new \InvalidArgumentException('Invalid reminder message identifier.');
            }
        }
        return array_values(array_unique($ids));
    }
    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
