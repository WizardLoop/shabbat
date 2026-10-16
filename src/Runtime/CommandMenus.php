<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Runtime;

use Shabbat\UI\Lang;

final class CommandMenus
{
    private const LANGUAGES = ['he','en','ru','fr','es','pt-br'];
    private const PRIVATE_COMMANDS = ['start','shabbat','zmanim','daf','omer','groups','preferences','language','help','donate','stats','source'];
    private const GROUP_COMMANDS = ['settings'];
    private const OPERATOR_COMMANDS = ['admin','adminstats','subscribers','broadcast','managers','systemlinks','trackinglinks','testzmanim','closegroups','opengroups','setcommands','restart'];
    private \Closure $rpc;
    private \Closure $authorize;
    private ?\Closure $commandAllowed;
    public function __construct(private Lang $lang, callable $rpc, callable $authorize, ?callable $commandAllowed = null)
    {
        $this->rpc = \Closure::fromCallable($rpc);
        $this->authorize = \Closure::fromCallable($authorize);
        $this->commandAllowed = $commandAllowed === null ? null : \Closure::fromCallable($commandAllowed);
    }
    public function plan(array $operatorIds, string $language = 'all', string $scope = 'all'): array
    {
        if ($language !== 'all' && !in_array($language, self::LANGUAGES, true)) {
            throw new \InvalidArgumentException('Unsupported menu language.');
        }
        if (!in_array($scope, ['all','private','operators','groups','group_admins'], true)) {
            throw new \InvalidArgumentException('Unsupported command scope.');
        }
        $operators = [];
        foreach ($operatorIds as $id) {
            $id = (string)$id;
            if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id) || strlen($id) > strlen((string)PHP_INT_MAX) || (strlen($id) === strlen((string)PHP_INT_MAX) && strcmp($id, (string)PHP_INT_MAX) > 0)) {
                throw new \InvalidArgumentException('Operator menu peers must be real positive user IDs.');
            }$operators[$id] = $id;
        }
        $scopes = [];
        if (in_array($scope, ['all','private'], true)) {
            $scopes[] = ['scope' => ['_' => 'botCommandScopeUsers'],'ids' => self::PRIVATE_COMMANDS];
        }
        if (in_array($scope, ['all','groups'], true)) {
            $scopes[] = ['scope' => ['_' => 'botCommandScopeChats'],'ids' => self::GROUP_COMMANDS];
        }
        if (in_array($scope, ['all','group_admins'], true)) {
            $scopes[] = ['scope' => ['_' => 'botCommandScopeChatAdmins'],'ids' => self::GROUP_COMMANDS];
        }
        if (in_array($scope, ['all','operators'], true)) {
            foreach ($operators as $id) {
                $scopes[] = ['scope' => ['_' => 'botCommandScopePeer','peer' => $id],'ids' => array_merge(self::PRIVATE_COMMANDS, self::OPERATOR_COMMANDS)];
            }
        }
        $languages = $language === 'all' ? self::LANGUAGES : [$language];
        $plans = [];
        foreach ($scopes as $spec) {
            foreach ($languages as $locale) {
                $plans[] = $this->entry($spec, $locale, $locale === 'pt-br' ? 'pt' : $locale);
            }
            // Explicit Hebrew fallback for clients without a supported language. Updating one language does not rewrite this fallback.
            if ($language === 'all') {
                $plans[] = $this->entry($spec, 'he', '');
            }
        }
        return $plans;
    }
    private function entry(array $spec, string $locale, string $nativeCode): array
    {
        $commands = [];
        foreach ($spec['ids'] as $id) {
            if (
                $this->commandAllowed !== null && in_array($id, self::OPERATOR_COMMANDS, true)
                && !($this->commandAllowed)((string)($spec['scope']['peer'] ?? ''), $id)
            ) {
                continue;
            }
            $description = html_entity_decode($this->lang->text($locale, 'command_' . ($id === 'language' ? 'lang' : $id)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('/^[a-z0-9_]{1,32}$/D', $id) || mb_strlen($description) < 1 || mb_strlen($description) > 256) {
                throw new \RuntimeException('Invalid command description: ' . $locale . '/' . $id);
            }
            $commands[] = ['_' => 'botCommand','command' => $id,'description' => $description];
        }
        return ['locale' => $locale,'scope' => $spec['scope'],'lang_code' => $nativeCode,'commands' => $commands];
    }
    /** @phpstan-impure Operator authorization may change while menu RPCs await Telegram. */
    private function isAuthorized(string $operator): bool
    {
        return ($this->authorize)($operator);
    }
    public function apply(string $operator, array $operatorIds, string $language = 'all', string $scope = 'all', bool $preview = false): array
    {
        if (!$this->isAuthorized($operator)) {
            throw new \RuntimeException('Operator permission required.');
        }
        $plans = $this->plan($operatorIds, $language, $scope);
        if ($preview) {
            return ['preview' => true,'total' => count($plans),'applied' => 0,'failed' => 0,'plans' => $plans];
        }
        $applied = 0;
        $failures = [];
        $attempted = 0;
        foreach ($plans as $plan) {
            if (!$this->isAuthorized($operator)) {
                break;
            }$attempted++;
            try {
                $params = ['scope' => $plan['scope'],'lang_code' => $plan['lang_code'],'commands' => $plan['commands']];
                if (($this->rpc)('bots.setBotCommands', $params) !== true) {
                    throw new \RuntimeException('Telegram did not acknowledge the menu update.');
                }$applied++;
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $failures[] = ['scope' => $plan['scope']['_'],'locale' => $plan['locale'],'error' => get_class($error)];
            }
        }
        return ['preview' => false,'total' => count($plans),'attempted' => $attempted,'applied' => $applied,'failed' => count($failures),'skipped' => count($plans) - $attempted,'failures' => $failures];
    }
}
