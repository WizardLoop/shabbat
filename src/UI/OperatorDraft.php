<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

use Shabbat\Runtime\Application;
use Shabbat\Storage\AdminStateFiles;
use Shabbat\Storage\Database;
use Shabbat\Content\TemplateRenderer;

/** Operator drafts use reviewed SQLite-backed compatibility paths, scoped to one operator. */
final class OperatorDraft
{
    private const DELIVERY_KEYS = ['broadcast_operation.txt', 'selfdestructhours.txt', 'scheduledat.txt', 'button_label.txt'];
    private \Closure $authorize;
    public function __construct(private Application $app, callable $authorize)
    {
        $this->authorize = \Closure::fromCallable($authorize);
    }
    private function allowed(string $user, bool $mutate = false): void
    {
        if (!($this->authorize)($user, $mutate)) {
            throw new \RuntimeException('Operator permission required.');
        }
        $this->app->settings->getUser($user);
    }
    private function path(string $user, string $key): string
    {
        return 'data/' . $user . '/' . $key;
    }
    private function read(string $user, string $key, string $default = ''): string
    {
        if ($key === 'button_label.txt') {
            return $this->app->flows->latest($user, 'broadcast_button_label')['payload']['label'] ?? $default;
        }
        if (in_array($key, self::DELIVERY_KEYS, true)) {
            $query = $this->app->db->pdo()->prepare('SELECT value_text FROM operator_broadcast_options WHERE user_id=? AND state_key=?');
            $query->execute([$user, $key]);
            $value = $query->fetchColumn();
            return $value === false ? $default : (string) $value;
        }
        $path = $this->path($user, $key);
        return AdminStateFiles::exists($path) ? AdminStateFiles::read($path) : $default;
    }
    public function mode(string $user): string
    {
        $this->allowed($user);
        return $this->read($user, 'grs1.txt');
    }
    public function begin(string $user, string $mode): void
    {
        $this->allowed($user, true);
        if (!in_array($mode, ['broadcast1','addBUTTONS','broadcast_sd_custom','broadcast_schedule_custom','broadcast_button_label','broadcast_button_url'], true)) {
            throw new \InvalidArgumentException('Unknown editor mode.');
        }
        AdminStateFiles::write($this->path($user, 'grs1.txt'), $mode);
    }
    public function cancel(string $user, bool $clear = false): void
    {
        $this->allowed($user, true);
        foreach ($clear ? ['grs1.txt','txt.txt','ent.txt','media.txt','BUTTONS.txt','broadcast_operation.txt','selfdestructhours.txt','scheduledat.txt','button_label.txt'] : ['grs1.txt','button_label.txt'] as $key) {
            if (in_array($key, self::DELIVERY_KEYS, true)) {
                $this->option($user, $key, null);
            } else {
                AdminStateFiles::delete($this->path($user, $key));
            }
        }
    }
    public function payload(string $user): array
    {
        $this->allowed($user);
        $text = $this->read($user, 'txt.txt');
        $entities = json_decode($this->read($user, 'ent.txt', '[]'), true, 64, JSON_THROW_ON_ERROR);
        $media = $this->read($user, 'media.txt');
        // Preserve migrated global buttons once, then keep all operator changes private to this draft.
        if (!AdminStateFiles::exists($this->path($user, 'BUTTONS.txt'))) {
            $legacy = ($text !== '' || $media !== '') && AdminStateFiles::exists('data/BUTTONS.txt') ? AdminStateFiles::read('data/BUTTONS.txt') : '[]';
            AdminStateFiles::write($this->path($user, 'BUTTONS.txt'), $legacy);
        }
        $buttons = json_decode($this->read($user, 'BUTTONS.txt', '[]'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($entities) || !is_array($buttons)) {
            throw new \InvalidArgumentException('Invalid stored draft.');
        }
        $buttons = \Shabbat\Storage\TemplateRepository::buttonRows($buttons);
        TemplateRenderer::validateButtons($buttons);
        return ['text' => $text,'entities' => $entities,'media' => $media === '' ? null : ['bot_api_file_id' => $media],'buttons' => $buttons];
    }
    public function state(string $user): array
    {
        $payload = $this->payload($user);
        if (!AdminStateFiles::exists($this->path($user, 'broadcastsend.txt'))) {
            AdminStateFiles::write($this->path($user, 'broadcastsend.txt'), AdminStateFiles::exists('data/broadcastsend.txt') ? AdminStateFiles::read('data/broadcastsend.txt') : 'users');
        }
        if (!AdminStateFiles::exists($this->path($user, 'pinmessage.txt'))) {
            AdminStateFiles::write($this->path($user, 'pinmessage.txt'), AdminStateFiles::exists('data/pinmessage.txt') ? 'on' : 'off');
        }
        $audience = match ($this->read($user, 'broadcastsend.txt', 'users')) {
            'משתמשים','users'=>'users','ערוצים','channels'=>'channels','קבוצות','groups'=>'groups','כולם','all'=>'all','groups_channels'=>'groups_channels','authorized_channels'=>'authorized_channels','authorized_groups'=>'authorized_groups','authorized_groups_channels'=>'authorized_groups_channels',default=>throw new \InvalidArgumentException('Invalid audience.')
        };
        $operation = $this->read($user, 'broadcast_operation.txt', 'send');
        if (!in_array($operation, ['send', 'edit'], true)) {
            throw new \InvalidArgumentException('Unknown stored broadcast operation.');
        }
        $hours = $this->read($user, 'selfdestructhours.txt');
        $scheduled = $this->read($user, 'scheduledat.txt');
        if (
            ($hours !== '' && preg_match('/^(?:[1-9]|[1-3][0-9]|4[0-8])$/D', $hours) !== 1)
            || ($scheduled !== '' && preg_match('/^[1-9][0-9]{0,11}$/D', $scheduled) !== 1)
            || ($operation === 'edit' && ($hours !== '' || $scheduled !== ''))
        ) {
            throw new \InvalidArgumentException('Invalid stored broadcast delivery settings.');
        }
        return ['operation' => $operation, 'self_destruct_hours' => $hours === '' ? null : (int) $hours, 'scheduled_at' => $scheduled === '' ? null : (int) $scheduled, 'exists' => $payload['text'] !== '' || $payload['media'] !== null,'pin' => $this->read($user, 'pinmessage.txt') === 'on','audience' => $audience,'payload' => $payload];
    }
    public function capture(string $user, string $text, array $entities = [], ?string $media = null): bool
    {
        $this->allowed($user, true);
        if (str_starts_with(ltrim($text), '/')) {
            return false;
        }
        if ($this->mode($user) !== 'broadcast1') {
            return false;
        }
        if (!mb_check_encoding($text, 'UTF-8') || ($text === '' && ($media === null || $media === '')) || self::utf16($text) > ($media === null ? 4096 : 1024)) {
            throw new \InvalidArgumentException('Invalid draft text or caption.');
        }
        if ($media !== null && ($media === '' || strlen($media) > 4096)) {
            throw new \InvalidArgumentException('Invalid reusable media file identifier.');
        }
        $total = self::utf16($text);
        $boundaries = [0 => true];
        $position = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $position += self::utf16($character);
            $boundaries[$position] = true;
        }
        foreach ($entities as $entity) {
            if (!is_array($entity) || !is_int($entity['offset'] ?? null) || !is_int($entity['length'] ?? null) || $entity['offset'] < 0 || $entity['length'] < 1 || $entity['offset'] + $entity['length'] > $total || !isset($boundaries[$entity['offset']], $boundaries[$entity['offset'] + $entity['length']])) {
                throw new \InvalidArgumentException('Invalid draft entity.');
            }
        }
        $this->app->db->transaction(function (\PDO $pdo) use ($user, $text, $entities, $media): void {
            $this->write($pdo, $user, 'txt.txt', $text);
            $this->write($pdo, $user, 'ent.txt', Database::encode($entities));
            if ($media === null) {
                $pdo->prepare('DELETE FROM admin_state WHERE user_id=? AND state_key=?')->execute([$user,'media.txt']);
            } else {
                $this->write($pdo, $user, 'media.txt', $media);
            }
            // Content edits retain the actor's keyboard; removing buttons is an explicit editor action.
            $pdo->prepare('DELETE FROM admin_state WHERE user_id=? AND state_key=?')->execute([$user,'grs1.txt']);
        });
        return true;
    }
    public function buttons(string $user, string $input): bool
    {
        $this->allowed($user, true);
        if (str_starts_with(ltrim($input), '/') || $this->mode($user) !== 'addBUTTONS') {
            return false;
        }
        try {
            $buttons = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $buttons = $this->app->parseCustomButtons($input);
        }
        if (!is_array($buttons)) {
            throw new \InvalidArgumentException('Invalid button rows.');
        }
        $buttons = \Shabbat\Storage\TemplateRepository::buttonRows($buttons);
        TemplateRenderer::validateButtons($buttons);
        $this->app->db->transaction(function (\PDO $pdo) use ($user, $buttons): void {
            $this->write($pdo, $user, 'BUTTONS.txt', Database::encode($buttons));
            $pdo->prepare('DELETE FROM admin_state WHERE user_id=? AND state_key=?')->execute([$user,'grs1.txt']);
        });
        return true;
    }
    /** A guided link button editor preserves existing rows and never sends a broadcast. */
    public function buttonInput(string $user, string $text): ?string
    {
        $this->allowed($user, true);
        if (str_starts_with(ltrim($text), '/')) {
            return null;
        }
        $mode = $this->mode($user);
        if ($mode === 'broadcast_button_label') {
            $label = trim($text);
            if ($label === '' || !mb_check_encoding($label, 'UTF-8') || self::utf16($label) > 64 || preg_match('/[\x00-\x1f]/u', $label)) {
                throw new \InvalidArgumentException('Button label must be one line of 1 to 64 UTF-16 units.');
            }
            $this->option($user, 'button_label.txt', $label);
            $this->begin($user, 'broadcast_button_url');
            return 'url';
        }
        if ($mode !== 'broadcast_button_url') {
            return null;
        }
        $url = trim($text);
        $label = $this->read($user, 'button_label.txt');
        if (
            $label === '' || strlen($url) > 2048 || preg_match('/\s/u', $url)
            || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'tg'], true)
            || !is_string(parse_url($url, PHP_URL_HOST)) || parse_url($url, PHP_URL_HOST) === ''
        ) {
            throw new \InvalidArgumentException('Enter a complete HTTPS or Telegram link.');
        }
        $rows = $this->payload($user)['buttons'];
        $last = count($rows) - 1;
        $button = ['text' => $label, 'url' => $url];
        if ($last >= 0 && count($rows[$last]) < 2) {
            $rows[$last][] = $button;
        } else {
            $rows[] = [$button];
        }
        TemplateRenderer::validateButtons($rows);
        $this->app->db->transaction(function (\PDO $pdo) use ($user, $rows): void {
            $this->write($pdo, $user, 'BUTTONS.txt', Database::encode($rows));
            $pdo->prepare("DELETE FROM admin_state WHERE user_id=? AND state_key='grs1.txt'")->execute([$user]);
            $this->app->flows->revokeAction($user, 'broadcast_button_label');
        });
        return 'saved';
    }

    public function clearButtons(string $user): void
    {
        $this->allowed($user, true);
        AdminStateFiles::write($this->path($user, 'BUTTONS.txt'), '[]');
        $this->cancel($user);
        $this->option($user, 'button_label.txt', null);
    }

    public function setAudience(string $user, string $audience): void
    {
        $this->allowed($user, true);
        if (!in_array($audience, \Shabbat\Storage\SubscriberDirectory::AUDIENCES, true)) {
            throw new \InvalidArgumentException('Unknown audience.');
        }
        AdminStateFiles::write($this->path($user, 'broadcastsend.txt'), $audience);
    }
    public function setPin(string $user, bool $pin): void
    {
        $this->allowed($user, true);
        AdminStateFiles::write($this->path($user, 'pinmessage.txt'), $pin ? 'on' : 'off');
    }
    public function confirmation(string $user, array $targets): string
    {
        $this->allowed($user, true);
        $state = $this->state($user);
        if ($state['scheduled_at'] !== null && $state['scheduled_at'] <= $this->app->clock->now()->getTimestamp()) {
            throw new \InvalidArgumentException('Scheduled time elapsed; select a new time or immediate send.');
        }
        if (!$state['exists']) {
            throw new \InvalidArgumentException('Empty broadcast draft.');
        }
        if ($targets === []) {
            throw new \InvalidArgumentException('Empty broadcast audience.');
        }
        $targets = array_values(array_unique(array_map(static fn($id): string=>(string)$id, $targets)));
        foreach ($targets as $id) {
            if (!preg_match('/^-?[1-9][0-9]{0,19}$/D', $id)) {
                throw new \InvalidArgumentException('Invalid recipient ID.');
            }
        }
        $revision = $this->app->settings->getUser($user)['revision'];
        return $this->app->flows->create($user, 'user', null, $revision, 'operator_broadcast', 600, ['state' => $state,'digest' => hash('sha256', Database::encode($state)),'targets' => $targets]);
    }
    public function claim(string $user, string $token): array
    {
        $this->allowed($user, true);
        $state = $this->state($user);
        if ($state['scheduled_at'] !== null && $state['scheduled_at'] <= $this->app->clock->now()->getTimestamp()) {
            throw new \RuntimeException('Scheduled time elapsed before confirmation.');
        }
        $flow = $this->app->flows->peek($token, $user, 'operator_broadcast');
        if ($flow === null || !hash_equals($flow['payload']['digest'], hash('sha256', Database::encode($this->state($user))))) {
            throw new \RuntimeException('Draft confirmation is stale.');
        }
        $this->app->flows->confirm($token, $user, 'operator_broadcast', static fn(): bool=>false);
        return $flow['payload'];
    }
    public function setOperation(string $user, string $operation): void
    {
        $this->allowed($user, true);
        if (!in_array($operation, ['send', 'edit'], true)) {
            throw new \InvalidArgumentException('Unknown broadcast operation.');
        }
        $this->option($user, 'broadcast_operation.txt', $operation);
        if ($operation === 'edit') {
            $this->option($user, 'selfdestructhours.txt', null);
            $this->option($user, 'scheduledat.txt', null);
        }
    }

    public function setSelfDestructHours(string $user, ?int $hours): void
    {
        $this->allowed($user, true);
        if (($hours !== null && ($hours < 1 || $hours > 48)) || $this->state($user)['operation'] !== 'send') {
            throw new \InvalidArgumentException('Self-destruct requires a new send and 1 to 48 hours.');
        }
        if ($hours === null) {
            $this->option($user, 'selfdestructhours.txt', null);
        } else {
            $this->option($user, 'selfdestructhours.txt', (string) $hours);
        }
    }

    public function setScheduledAt(string $user, ?int $timestamp): void
    {
        $this->allowed($user, true);
        $now = $this->app->clock->now()->getTimestamp();
        if (($timestamp !== null && ($timestamp <= $now || $timestamp > $now + 31536000)) || $this->state($user)['operation'] !== 'send') {
            throw new \InvalidArgumentException('Schedule must be future, within one year, and apply to a new send.');
        }
        if ($timestamp === null) {
            $this->option($user, 'scheduledat.txt', null);
        } else {
            $this->option($user, 'scheduledat.txt', (string) $timestamp);
        }
    }

    /** Strict local wall-clock parsing rejects impossible or ambiguous DST times. */
    public function parseSchedule(string $user, string $input): ?int
    {
        $this->allowed($user);
        $input = trim($input);
        if (strtolower($input) === 'off') {
            return null;
        }
        $now = $this->app->clock->now()->getTimestamp();
        if (preg_match('/^[1-9][0-9]{0,5}$/D', $input)) {
            $timestamp = $now + ((int) $input * 60);
            if ($timestamp > $now + 31536000) {
                throw new \InvalidArgumentException('Schedule exceeds one year.');
            }
            return $timestamp;
        }
        $zone = new \DateTimeZone($this->app->settings->getUser($user)['timezone_id']);
        foreach (['Y-m-d H:i', 'd/m/Y H:i', 'd.m.Y H:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $input, $zone);
            $errors = \DateTimeImmutable::getLastErrors();
            if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format($format) !== $input) {
                continue;
            }
            $wall = $date->format('Y-m-d H:i');
            $candidates = [];
            foreach ($zone->getTransitions($date->getTimestamp() - 172800, $date->getTimestamp() + 172800) ?: [] as $transition) {
                $candidate = (new \DateTimeImmutable($wall, new \DateTimeZone('UTC')))->getTimestamp() - $transition['offset'];
                if ((new \DateTimeImmutable('@' . $candidate))->setTimezone($zone)->format('Y-m-d H:i') === $wall) {
                    $candidates[$candidate] = true;
                }
            }
            if (count($candidates) !== 1 || $date->getTimestamp() <= $now || $date->getTimestamp() > $now + 31536000) {
                throw new \InvalidArgumentException('Schedule is past, ambiguous or out of range.');
            }
            return $date->getTimestamp();
        }
        throw new \InvalidArgumentException('Invalid schedule date.');
    }

    public function controlInput(string $user, string $text): bool
    {
        if (str_starts_with(ltrim($text), '/')) {
            return false;
        }
        $mode = $this->mode($user);
        if (!in_array($mode, ['broadcast_sd_custom', 'broadcast_schedule_custom'], true)) {
            return false;
        }
        $this->allowed($user, true);
        if ($mode === 'broadcast_sd_custom') {
            $input = trim($text);
            if (strtolower($input) !== 'off' && !preg_match('/^(?:[1-9]|[1-3][0-9]|4[0-8])$/D', $input)) {
                throw new \InvalidArgumentException('Self-destruct hours must be 1 to 48.');
            }
            $this->setSelfDestructHours($user, strtolower($input) === 'off' ? null : (int) $input);
        } else {
            $this->setScheduledAt($user, $this->parseSchedule($user, $text));
        }
        $this->cancel($user);
        return true;
    }
    private function option(string $user, string $key, ?string $value): void
    {
        if (!in_array($key, self::DELIVERY_KEYS, true)) {
            throw new \LogicException('Unknown broadcast delivery option.');
        }
        if ($key === 'button_label.txt') {
            $this->app->flows->revokeAction($user, 'broadcast_button_label');
            if ($value !== null) {
                $this->app->flows->create($user, 'user', null, $this->app->settings->getUser($user)['revision'], 'broadcast_button_label', 900, ['label' => $value]);
            }
            return;
        }
        if ($value === null) {
            $this->app->db->pdo()->prepare('DELETE FROM operator_broadcast_options WHERE user_id=? AND state_key=?')->execute([$user, $key]);
            return;
        }
        $this->app->db->pdo()->prepare('INSERT INTO operator_broadcast_options(user_id,state_key,value_text,updated_at) VALUES(?,?,?,?)
 ON CONFLICT(user_id,state_key) DO UPDATE SET value_text=excluded.value_text,updated_at=excluded.updated_at')
            ->execute([$user, $key, $value, $this->app->clock->now()->getTimestamp()]);
    }

    private function write(\PDO $pdo, string $user, string $key, string $value): void
    {
        $now = $this->app->clock->now()->getTimestamp();
        $kind = in_array($key, ['ent.txt','BUTTONS.txt'], true) ? 'json' : ($key === 'media.txt' ? 'media' : 'text');
        $pdo->prepare('INSERT INTO admin_directories(user_id,created_at) VALUES(?,?) ON CONFLICT(user_id) DO NOTHING')->execute([$user,$now]);
        $pdo->prepare('INSERT INTO admin_state(user_id,state_key,value_kind,value_text,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(user_id,state_key) DO UPDATE SET value_kind=excluded.value_kind,value_text=excluded.value_text,updated_at=excluded.updated_at')->execute([$user,$key,$kind,$value,$now]);
    }
    private static function utf16(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
