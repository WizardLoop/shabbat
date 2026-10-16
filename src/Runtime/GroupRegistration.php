<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Runtime;

/** Explicit settings entry can register a new group, but never reactivate a removed group. */
final class GroupRegistration
{
    public static function refresh(Application $app, string $user, string $group, callable $info): bool
    {
        if (!preg_match('/^-[1-9][0-9]*$/D', $group) || !$app->gateway->isGroupAdmin($user, $group)) {
            return false;
        }
        $fresh = $info($group);
        if (!is_array($fresh) || !in_array($fresh['type'] ?? '', ['chat','supergroup'], true)) {
            return false;
        }
        $title = $fresh['Chat']['title'] ?? $group;
        if (!is_string($title) || trim($title) === '') {
            $title = $group;
        }
        if ($app->settings->getGroup($group) === null) {
            $app->settings->ensureGroup($group, $title);
        } else {
            $app->settings->refreshGroupTitle($group, $title);
        }
        $app->subscribers->recordGroupAdmin($user, $group, true);
        return true;
    }
}
