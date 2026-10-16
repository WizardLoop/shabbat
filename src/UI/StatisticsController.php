<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

use Shabbat\Storage\Database;
use Shabbat\Time\Clock;

/** Public aggregate statistics. No subscriber identities, settings or diagnostic errors leave this view. */
final class StatisticsController
{
    public function __construct(private readonly Database $db, private readonly Clock $clock, private readonly Lang $lang)
    {
    }

    public function frame(string $language, string $tab = 'overview', string $timezone = 'Asia/Jerusalem'): array
    {
        if (!in_array($tab, ['overview', 'modules'], true)) {
            throw new \InvalidArgumentException('Unknown statistics tab.');
        }
        $t = static fn(string $key): string => AdminDirectoryText::text($language, $key);
        $counts = $this->counts();
        $lines = ['<b>' . $t('stats_overview') . '</b>', ''];
        if ($tab === 'overview') {
            $lines[] = '<b>' . $t('stats_groups') . '</b>';
            foreach (['known' => 'registered_groups', 'active' => 'active_groups', 'locking' => 'locking', 'locked' => 'locked'] as $key => $label) {
                $lines[] = $t($label) . ': <b>' . number_format($counts[$key]) . '</b>';
            }
            $lines[] = '';
            foreach (['off', 'messages', 'lock', 'lock_messages'] as $mode) {
                $lines[] = $t('mode_' . $mode) . ': <b>' . number_format($counts[$mode]) . '</b>';
            }
            $lines[] = "\n<i>" . $t('stats_public_note') . '</i>';
        } else {
            $lines[] = '<b>' . $t('stats_modules') . '</b>';
            foreach (['times_alert' => 'shabbat', 'zmanim' => 'zmanim', 'daf' => 'daf', 'tefillin' => 'tefillin', 'omer' => 'omer'] as $key => $label) {
                $lines[] = $this->lang->text($language, $label) . ': <b>' . number_format($counts[$key]) . '</b>';
            }
            $lines[] = "\n<i>" . $t('stats_public_note') . '</i>';
        }
        $lines[] = "\n" . $t('stats_refresh_at') . ': <code>'
            . $this->clock->now()->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d H:i T') . '</code>';
        $buttons = [];
        foreach (['overview' => 'back_summary', 'modules' => 'stats_modules'] as $destination => $label) {
            $buttons[] = ['_' => 'keyboardButtonCallback', 'text' => $t($label), 'data' => 'ps:' . $destination,
                'style' => ['_' => 'keyboardButtonStyle'] + ($tab === $destination ? ['bg_primary' => true] : [])];
        }
        return ['text' => implode("\n", $lines), 'markup' => ['_' => 'replyInlineMarkup', 'rows' => [
            ['_' => 'keyboardButtonRow', 'buttons' => $buttons],
            ['_' => 'keyboardButtonRow', 'buttons' => [
                ['_' => 'keyboardButtonCallback', 'text' => $t('refresh'), 'data' => 'ps:' . $tab],
                ['_' => 'keyboardButtonCallback', 'text' => $this->lang->text($language, 'back'), 'data' => 'sb:start'],
            ]],
        ]]];
    }

    public function counts(): array
    {
        $row = $this->db->pdo()->query("SELECT COUNT(*) known,
            COALESCE(SUM(g.active),0) active,
            COALESCE(SUM(g.active=1 AND s.mode IN ('lock','lock_messages')),0) locking,
            COALESCE(SUM(g.active=1 AND s.mode='off'),0) off,
            COALESCE(SUM(g.active=1 AND s.mode='messages'),0) messages,
            COALESCE(SUM(g.active=1 AND s.mode='lock'),0) lock,
            COALESCE(SUM(g.active=1 AND s.mode='lock_messages'),0) lock_messages,
            COALESCE(SUM(g.active=1 AND s.times_alert_enabled=1 AND s.mode IN ('messages','lock_messages')),0) times_alert,
            COALESCE(SUM(g.active=1 AND s.zmanim_enabled=1),0) zmanim,
            COALESCE(SUM(g.active=1 AND s.daf_enabled=1),0) daf,
            COALESCE(SUM(g.active=1 AND s.tefillin_enabled=1),0) tefillin,
            COALESCE(SUM(g.active=1 AND s.omer_enabled=1),0) omer,
            (SELECT COUNT(*) FROM group_lock_state WHERE status='locked') locked
            FROM groups g JOIN group_settings s USING(group_id)")->fetch();
        return array_map(static fn(mixed $value): int => (int)$value, $row);
    }
}
