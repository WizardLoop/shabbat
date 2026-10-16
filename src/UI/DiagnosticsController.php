<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

use Amp\CancelledException;
use Shabbat\Runtime\Application;
use Shabbat\Runtime\DiagnosticReport;
use Shabbat\Storage\DiagnosticsRepository;

/** A private operator panel. Navigation uses one cached snapshot and edits one message. */
final class DiagnosticsController
{
    private const FILTERS = ['all', 'problems', 'summary', 'calendar', 'permissions', 'queue', 'groups'];
    private const PAGE_SIZE = 4;
    private readonly DiagnosticsRepository $repository;
    private readonly \Closure $capture;
    private readonly Lang $lang;
    private array $languages = [];

    public function __construct(private readonly Application $app, ?callable $capture = null)
    {
        $this->repository = new DiagnosticsRepository($app->db, $app->clock);
        $this->lang = new Lang();
        $this->capture = \Closure::fromCallable($capture ?? (new DiagnosticReport($app))->capture(...));
    }

    public function open(string $user, ?string $group = null): void
    {
        $this->allow($user);
        if (!$this->app->gateway instanceof DiagnosticsGateway) {
            throw new \RuntimeException('The diagnostics transport is unavailable.');
        }
        if ($group !== null && (!preg_match('/^-[1-9][0-9]{0,19}$/D', $group) || $this->app->settings->getGroup($group) === null)) {
            throw new \InvalidArgumentException('Unknown diagnostic group.');
        }
        $token = $this->repository->begin($user, $group);
        try {
            $this->repository->saveReport($user, $token, ($this->capture)($user, $group), $token);
            $this->allow($user);
            $row = $this->required($user, $token);
            $frame = $this->frame($user, $row, $token);
            // Repeated /testzmanim also refreshes the existing acknowledged panel.
            if (is_int($row['message_id']) && $row['message_id'] > 0) {
                $this->app->gateway->editPrivate($user, $row['message_id'], $frame['text'], $frame['markup']);
            } else {
                $id = $this->app->gateway->sendDiagnosticPanel($user, $frame['text'], $frame['markup']);
                $this->repository->update($user, $token, ['message_id' => $id], $token);
            }
        } finally {
            $this->repository->release($user, $token, $token);
        }
    }

    public function cancelInput(string $user): void
    {
        $this->repository->cancelInput($user);
    }

    public function callback(array $event): bool
    {
        $data = $event['data'] ?? '';
        if (!is_string($data) || !str_starts_with($data, 'dz:')) {
            return false;
        }
        $user = (string)($event['user_id'] ?? '');
        $query = (string)($event['query_id'] ?? '');
        $token = null;
        $claimed = false;
        try {
            $this->allow($user);
            if (
                ($event['private'] ?? false) !== true || ($event['inline_selection'] ?? false)
                || (string)($event['chat_id'] ?? '') !== $user
                || !preg_match('/^dz:([a-f0-9]{24}):(list|prev|next|page|search|filters|filter|clear|refresh|export|group)(?::([a-z0-9]{1,12}))?$/D', $data, $match)
            ) {
                throw new \RuntimeException('A bound private diagnostic panel is required.');
            }
            $token = $match[1];
            $row = $this->required($user, $token);
            if ((int)$row['message_id'] < 1 || (int)$row['message_id'] !== ($event['message_id'] ?? 0)) {
                throw new \RuntimeException('Diagnostic panel mismatch.');
            }
            $claimed = $this->repository->claim($user, $token);
            if (!$claimed) {
                $this->app->gateway->answerCallback($query, $this->t($user, 'busy'));
                return true;
            }
            $action = $match[2];
            $value = $match[3] ?? null;
            if ($value !== null && !in_array($action, ['filter', 'group'], true)) {
                throw new \InvalidArgumentException('Unexpected diagnostic callback argument.');
            }
            $this->repository->update($user, $token, ['prompt' => null], $claimed);
            if ($action === 'export') {
                if (!$this->app->gateway instanceof DiagnosticsGateway || !$this->repository->claimExport($user, $token, $claimed, $query)) {
                    $this->app->gateway->answerCallback($query, $this->t($user, 'busy'));
                    return true;
                }
                $this->allow($user);
                $json = json_encode($row['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $this->app->gateway->sendDiagnosticDocument($user, $json, $this->t($user, 'export_caption'), (string)random_int(1, PHP_INT_MAX));
                $this->app->gateway->answerCallback($query, $this->t($user, 'export_sent'));
                return true;
            }
            if ($action === 'refresh' || $action === 'group') {
                $group = $row['group_id'];
                if ($action === 'group') {
                    $record = ctype_digit((string)$value) ? ($row['report']['records'][(int)$value] ?? null) : null;
                    $group = is_array($record) && ($record['category'] ?? '') === 'groups' ? ($record['data']['group_id'] ?? null) : null;
                    if (!is_string($group)) {
                        throw new \InvalidArgumentException('Unknown diagnostic group selection.');
                    }
                }
                // Release the old claim, then atomically rotate its token before new I/O.
                $this->repository->release($user, $token, $claimed);
                $claimed = false;
                $this->open($user, $group);
                $this->app->gateway->answerCallback($query, '');
                return true;
            }
            if ($action === 'page' || $action === 'search') {
                $this->repository->update($user, $token, ['prompt' => $action], $claimed);
                $frame = $this->prompt($user, $token, $action);
            } elseif ($action === 'filters') {
                $rows = [];
                foreach (self::FILTERS as $filter) {
                    $rows[] = [$this->button($user, $token, $filter, 'filter:' . $filter, $filter === $row['category'] ? 'bg_primary' : null)];
                }
                $rows[] = [$this->button($user, $token, 'cancel', 'list')];
                $frame = $this->markup('<b>' . $this->t($user, 'filters') . '</b>', $rows);
            } else {
                $changes = match ($action) {
                    'prev' => ['page' => max(0, (int)$row['page'] - 1)],
                    'next' => ['page' => (int)$row['page'] + 1],
                    'filter' => in_array($value, self::FILTERS, true) ? ['category' => $value, 'page' => 0] : throw new \InvalidArgumentException('Invalid filter.'),
                    'clear' => ['category' => 'all', 'search' => '', 'page' => 0],
                    default => [],
                };
                $this->repository->update($user, $token, $changes, $claimed);
                $frame = $this->frame($user, $this->required($user, $token), $claimed);
            }
            $this->allow($user);
            $this->edit($user, $row, $frame, $claimed);
            $this->app->gateway->answerCallback($query, '');
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Shabbat\Storage\DiagnosticThrottled) {
            $this->app->gateway->answerCallback($query, $this->t($user, 'busy'));
        } catch (\Throwable) {
            $this->app->gateway->answerCallback($query, $this->t($user, 'unavailable'));
        } finally {
            if ($claimed && $token !== null) {
                $this->repository->release($user, $token, $claimed);
            }
        }
        return true;
    }

    public function input(array $event): bool
    {
        $user = (string)($event['user_id'] ?? '');
        $text = trim((string)($event['text'] ?? ''));
        if (($event['private'] ?? false) !== true || str_starts_with($text, '/')) {
            return false;
        }
        $row = $this->repository->get($user);
        if ($row === null || !in_array($row['prompt'], ['search', 'page'], true)) {
            return false;
        }
        $claimed = false;
        try {
            $this->allow($user);
            $claimed = $this->repository->claim($user, $row['token']);
            if (!$claimed) {
                return true;
            }
            $changes = ['prompt' => null, 'page' => 0];
            if ($row['prompt'] === 'search') {
                if (mb_strlen($text) > 80) {
                    $frame = $this->prompt($user, $row['token'], 'search', true);
                    $this->edit($user, $row, $frame, $claimed);
                    return true;
                }
                $changes['search'] = $text;
            } else {
                if (!preg_match('/^[1-9][0-9]{0,2}$/D', $text) || (int)$text > $this->pages($row)) {
                    $frame = $this->prompt($user, $row['token'], 'page', true);
                    $this->edit($user, $row, $frame, $claimed);
                    return true;
                }
                $changes['page'] = (int)$text - 1;
            }
            $this->repository->update($user, $row['token'], $changes, $claimed);
            $frame = $this->frame($user, $this->required($user, $row['token']), $claimed);
            $this->allow($user);
            $this->edit($user, $row, $frame, $claimed);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            if (is_string($claimed)) {
                try {
                    $this->repository->update($user, $row['token'], ['prompt' => null], $claimed);
                } catch (\RuntimeException) {
                    // A later interaction owns the panel; do not erase its prompt.
                }
            }
            // Deleted/expired panels and revoked roles must never produce replacement messages.
        } finally {
            if ($claimed) {
                $this->repository->release($user, $row['token'], $claimed);
            }
        }
        return true;
    }

    private function edit(string $user, array $row, array $frame, string $owner): void
    {
        $this->allow($user);
        $this->repository->assertOwnership($user, $row['token'], $owner);
        $this->app->gateway->editPrivate($user, (int)$row['message_id'], $frame['text'], $frame['markup']);
    }

    private function frame(string $user, array $row, string $owner): array
    {
        $records = $this->filtered($row);
        $pages = max(1, (int)ceil(count($records) / self::PAGE_SIZE));
        $page = min(max(0, (int)$row['page']), $pages - 1);
        $this->repository->update($user, $row['token'], ['page' => $page], $owner);
        $zone = $row['report']['timezone'];
        $created = (new \DateTimeImmutable('@' . $row['created_at']))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i');
        $text = '<b>' . $this->t($user, 'title') . "</b>\n" . $this->t($user, 'read_only') . "\n"
            . $this->t($user, 'context') . ': <code>' . self::html((string)$row['report']['context']) . "</code>\n"
            . self::html($created . ' / ' . $zone) . "\n"
            . $this->t($user, 'filters') . ': ' . $this->t($user, $row['category']);
        if ($row['search'] !== '') {
            $text .= "\n🔎 " . self::html($row['search']);
        }
        $buttons = [];
        foreach (array_slice($records, $page * self::PAGE_SIZE, self::PAGE_SIZE, true) as $index => $record) {
            $icon = match ($record['status']) {
                'error' => '❌', 'warning' => '⚠️', default => '•'
            };
            $text .= "\n\n" . $icon . ' <b>' . $this->t($user, $record['label']) . "</b>\n<code>" . self::html(mb_substr($this->recordValue($user, $record), 0, 210)) . '</code>';
            if (isset($record['data']['group_id'])) {
                $buttons[] = [['_' => 'keyboardButtonCallback', 'text' => '👥 ' . mb_substr($record['value'], 0, 44), 'data' => 'dz:' . $row['token'] . ':group:' . $index]];
            }
        }
        if ($records === []) {
            $text .= "\n\n" . $this->t($user, 'empty');
        }
        $token = $row['token'];
        $buttons[] = [$this->button($user, $token, 'previous', 'prev'), ['_' => 'keyboardButtonCallback', 'text' => ($page + 1) . ' / ' . $pages, 'data' => 'dz:' . $token . ':page'], $this->button($user, $token, 'next', 'next')];
        $buttons[] = [$this->button($user, $token, 'search', 'search', 'bg_primary'), $this->button($user, $token, 'filters', 'filters')];
        $buttons[] = [$this->button($user, $token, 'refresh', 'refresh'), $this->button($user, $token, 'clear', 'clear')];
        $buttons[] = [$this->button($user, $token, 'export', 'export', 'bg_primary')];
        $buttons[] = [['_' => 'keyboardButtonCallback', 'text' => $this->t($user, 'back'), 'data' => 'חזרהמנהל']];
        return $this->markup($text, $buttons);
    }

    private function prompt(string $user, string $token, string $kind, bool $invalid = false): array
    {
        return $this->markup(($invalid ? $this->t($user, 'invalid_' . $kind) . "\n" : '') . $this->t($user, $kind . '_prompt', $kind === 'page' ? ['pages' => $this->pages($this->required($user, $token))] : []), [[$this->button($user, $token, 'cancel', 'list', 'bg_danger')]]);
    }

    private function filtered(array $row): array
    {
        $search = mb_strtolower($row['search']);
        return array_filter($row['report']['records'], function (array $record) use ($row, $search): bool {
            return ($row['category'] === 'all' || ($row['category'] === 'problems' ? $record['status'] !== 'ok' : $record['category'] === $row['category']))
                && ($search === '' || str_contains(mb_strtolower($record['value'] . ' ' . $this->recordValue($row['viewer_id'], $record) . ' ' . $this->t($row['viewer_id'], $record['label'])), $search));
        });
    }

    private function recordValue(string $user, array $record): string
    {
        if (isset($record['data']['snapshot_provenance'])) {
            return html_entity_decode($this->t($user, $record['data']['snapshot_provenance'] === 'verified_native_v1' ? 'snapshot_verified' : 'snapshot_unverified'), ENT_QUOTES, 'UTF-8');
        }
        $fields = $record['data']['different_fields'] ?? null;
        if (isset($record['data']['rights'])) {
            $fields = array_keys(array_filter($record['data']['rights'], static fn(mixed $value): bool => $value === true));
        }
        if (is_array($fields)) {
            $language = $this->app->settings->getUser($user)['language'];
            return $fields === [] ? '-' : implode(', ', array_map(fn(string $field): string => html_entity_decode($this->lang->text($language, 'permission_' . $field), ENT_QUOTES, 'UTF-8'), $fields));
        }
        return $record['value'];
    }
    private function pages(array $row): int
    {
        return max(1, (int)ceil(count($this->filtered($row)) / self::PAGE_SIZE));
    }

    private function required(string $user, string $token): array
    {
        return $this->repository->get($user, $token) ?? throw new \RuntimeException('Diagnostic panel expired.');
    }

    private function allow(string $user): void
    {
        $this->app->adminAccess->require($user, 'statistics');
    }

    private function t(string $user, string $key, array $values = []): string
    {
        $this->languages[$user] ??= $this->app->settings->getUser($user)['language'];
        return $this->lang->text($this->languages[$user], 'diagnostics_' . $key, $values);
    }

    private function button(string $user, string $token, string $label, string $action, ?string $style = null): array
    {
        $button = ['_' => 'keyboardButtonCallback', 'text' => $this->t($user, $label), 'data' => 'dz:' . $token . ':' . $action];
        if ($style !== null) {
            $button['style'] = ['_' => 'keyboardButtonStyle', $style => true];
        }
        return $button;
    }

    private function markup(string $text, array $rows): array
    {
        return ['text' => $text, 'markup' => ['_' => 'replyInlineMarkup', 'rows' => array_map(static fn(array $buttons): array => ['_' => 'keyboardButtonRow', 'buttons' => $buttons], $rows)]];
    }

    private static function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
