<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-only
namespace Shabbat\Storage;

use PDO;
use Shabbat\Time\Clock;

final class TemplateRepository
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }
    public function get(string $group, string $kind): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM message_templates WHERE group_id=? AND kind=?');
        $query->execute([$group,$kind]);
        $row = $query->fetch();
        return $row === false ? null : self::decode($row);
    }
    public function save(string $group, string $kind, array $content, string $actor, ?int $expectedRevision = null): array
    {
        return $this->db->transaction(fn(PDO $pdo): array=>$this->saveInTransaction($pdo, $group, $kind, $content, $actor, $expectedRevision));
    }
    public function saveInTransaction(PDO $pdo, string $group, string $kind, array $content, string $actor, ?int $expectedRevision = null): array
    {
        if ($pdo !== $this->db->pdo()) {
            throw new \LogicException('Template mutation must use its repository database.');
        }
        if (!in_array($kind, ['open','close','times_alert','zmanim','daf','tefillin','omer'], true)) {
            throw new \InvalidArgumentException('Unsupported template kind.');
        }
        $text = $content['text'] ?? '';
        if (!is_string($text) || mb_strlen($text) > 4096) {
            throw new \InvalidArgumentException('Template text exceeds Telegram limits.');
        }
        foreach (['entities','buttons'] as $field) {
            if (isset($content[$field]) && !is_array($content[$field])) {
                throw new \InvalidArgumentException('Invalid template metadata.');
            }
        }
        if (isset($content['media']) && !is_array($content['media'])) {
            throw new \InvalidArgumentException('Media must be a validated metadata object.');
        }
        $mutation = function (PDO $pdo) use ($group, $kind, $content, $actor, $expectedRevision, $text): void {
            $now = $this->clock->now()->getTimestamp();
            $current = $this->get($group, $kind);
            $mode = $content['buttons_mode'] ?? ((!empty($content['buttons'])) ? 'custom' : ($current['buttons_mode'] ?? 'inherit'));
            if (!in_array($mode, ['inherit', 'custom', 'none'], true) || ($mode !== 'custom' && !empty($content['buttons']))) {
                throw new \InvalidArgumentException('Invalid template button inheritance mode.');
            }
            if ($current === null) {
                if ($expectedRevision !== null && $expectedRevision !== 0) {
                    throw new StaleSettings('Template changed.');
                }
                $pdo->prepare('INSERT INTO message_templates(group_id,kind,text,entities_json,media_json,buttons_json,updated_at,buttons_mode) VALUES(?,?,?,?,?,?,?,?)')
                    ->execute([$group,$kind,$text,Database::encode($content['entities'] ?? []),isset($content['media']) ? Database::encode($content['media']) : null,Database::encode($content['buttons'] ?? []),$now,$mode]);
            } else {
                if ($expectedRevision !== null && $expectedRevision !== $current['revision']) {
                    throw new StaleSettings('Template changed.');
                }
                $query = $pdo->prepare('UPDATE message_templates SET text=?,entities_json=?,media_json=?,buttons_json=?,buttons_mode=?,revision=revision+1,updated_at=? WHERE group_id=? AND kind=? AND revision=?');
                $query->execute([$text,Database::encode($content['entities'] ?? []),isset($content['media']) ? Database::encode($content['media']) : null,Database::encode($content['buttons'] ?? []),$mode,$now,$group,$kind,$current['revision']]);
                if ($query->rowCount() !== 1) {
                    throw new StaleSettings('Template changed.');
                }
            }
            $pdo->prepare('INSERT INTO audit_log(actor_id,group_id,action,details_json,created_at) VALUES(?,?,?,?,?)')->execute([$actor,$group,'template.update',Database::encode(['kind' => $kind]),$now]);
        };
        $mutation($pdo);
        return $this->get($group, $kind) ?? throw new \LogicException('Template save failed.');
    }
    public static function decode(array $row): array
    {
        $row['entities'] = json_decode($row['entities_json'], true, 64, JSON_THROW_ON_ERROR);
        $row['buttons'] = self::buttonRows(json_decode($row['buttons_json'], true, 64, JSON_THROW_ON_ERROR));
        $row['media'] = $row['media_json'] === null ? null : json_decode($row['media_json'], true, 64, JSON_THROW_ON_ERROR);
        $row['buttons_mode'] ??= $row['buttons'] === [] ? 'inherit' : 'custom';
        $row['revision'] = (int)$row['revision'];
        unset($row['entities_json'], $row['buttons_json'], $row['media_json']);
        return $row;
    }

    /** Normalize only recognized full keyboard wrappers; every button field remains intact. */
    public static function buttonRows(array $buttons): array
    {
        if (array_is_list($buttons)) {
            return $buttons;
        }
        if (array_key_exists('inline_keyboard', $buttons)) {
            if (!is_array($buttons['inline_keyboard']) || !array_is_list($buttons['inline_keyboard'])) {
                throw new \InvalidArgumentException('Malformed legacy inline keyboard wrapper.');
            }
            return $buttons['inline_keyboard'];
        }
        if (($buttons['_'] ?? '') === 'replyInlineMarkup') {
            if (!is_array($buttons['rows'] ?? null) || !array_is_list($buttons['rows'])) {
                throw new \InvalidArgumentException('Malformed legacy native keyboard wrapper.');
            }
            $rows = [];
            foreach ($buttons['rows'] as $row) {
                if (!is_array($row) || !is_array($row['buttons'] ?? null) || !array_is_list($row['buttons'])) {
                    throw new \InvalidArgumentException('Malformed legacy native keyboard row.');
                }
                $rows[] = $row['buttons'];
            }
            return $rows;
        }
        return $buttons;
    }
}
