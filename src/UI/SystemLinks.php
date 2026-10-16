<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

use Shabbat\Storage\AdminAccessRepository;

/** Public links navigate; privileged links always require a current bot capability. */
final class SystemLinks
{
    public const DEFINITIONS = [
        'home' => ['action' => 'start', 'label' => 'home'],
        'preferences' => ['action' => 'preferences', 'label' => 'preferences'],
        'language' => ['action' => 'language', 'label' => 'language'],
        'groups' => ['action' => 'groups', 'label' => 'groups'],
        'shabbat' => ['action' => 'shabbat', 'label' => 'shabbat'],
        'zmanim' => ['action' => 'zmanim', 'label' => 'zmanim'],
        'daf' => ['action' => 'daf', 'label' => 'daf'],
        'omer' => ['action' => 'omer', 'label' => 'omer'],
        'help' => ['action' => 'help', 'label' => 'help'],
        'support' => ['action' => 'donate', 'label' => 'support'],
        'source' => ['action' => 'source', 'label' => 'source'],
        'license' => ['action' => 'license', 'label' => 'license'],
        'version' => ['action' => 'version', 'label' => 'version'],
        'admin' => ['action' => 'admin', 'label' => 'panel', 'permission' => 'panel'],
        'admin_stats' => ['action' => 'statistics', 'label' => 'statistics', 'permission' => 'statistics'],
        'admin_subscribers' => ['action' => 'subscribers', 'label' => 'subscribers', 'permission' => 'subscribers'],
        'admin_broadcast' => ['action' => 'broadcast', 'label' => 'broadcast', 'permission' => 'broadcast'],
        'admin_managers' => ['action' => 'managers', 'label' => 'managers', 'permission' => 'manage_admins'],
        'admin_links' => ['action' => 'system_links', 'label' => 'system_links', 'permission' => 'system_links'],
        'admin_tracking' => ['action' => 'tracking_links', 'label' => 'tracking_links', 'permission' => 'tracking_links'],
        'admin_commands' => ['action' => 'commands', 'label' => 'commands', 'permission' => 'commands'],
    ];
    public static function url(string $username, string $parameter): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]{5,32}$/D', $username) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $parameter)) {
            throw new \InvalidArgumentException('Invalid bot deep link.');
        }
        return 'https://t.me/' . $username . '?start=' . rawurlencode($parameter);
    }
    public static function isReserved(string $parameter): bool
    {
        $parameter = strtolower($parameter);
        return isset(self::DEFINITIONS[$parameter]) || in_array($parameter, ['start','settings','donate','shabat','tefillin','adminpanel'], true)
            || preg_match('/^(?:g_|loc_|sb_|tpl_|v3_|adm:|trk:)/D', $parameter) === 1;
    }
    public static function resolve(string $parameter, string $user, bool $private, AdminAccessRepository $access): ?string
    {
        if (!$private) {
            return null;
        }
        $definition = self::DEFINITIONS[strtolower($parameter)] ?? null;
        if ($definition === null) {
            return null;
        }
        if (isset($definition['permission'])) {
            if ($definition['permission'] === 'panel') {
                if (!$access->isAdmin($user)) {
                    throw new \RuntimeException('Bot administrator access required.');
                }
            } else {
                $access->require($user, $definition['permission']);
            }
        }
        return $definition['action'];
    }
}
