<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\UI;

use Shabbat\Runtime\Donations;
use Shabbat\Storage\Database;
use Shabbat\Storage\FlowTokenRepository;
use Shabbat\Storage\SettingsRepository;
use Shabbat\Time\Clock;

/** An explicit private prompt, durable across restart, never an unsolicited invoice. */
final class DonationController
{
    private const ACTION = 'donation_input';

    public function __construct(
        private Database $db,
        private SettingsRepository $settings,
        private FlowTokenRepository $flows,
        private Clock $clock,
        private Donations $donations,
        private UiGateway $gateway,
        private Lang $lang = new Lang(),
    ) {
    }

    public function menu(string $user, ?UiGateway $target = null): void
    {
        $this->cancel($user);
        $offers = $this->donations->createOffers($user, $this->settings->getUser($user)['language']);
        $markup = $offers['reply_markup'];
        $markup['rows'][] = ['_' => 'keyboardButtonRow', 'buttons' => [$this->back($user, 'sb:start')]];
        ($target ?? $this->gateway)->sendPrivate($user, $offers['text'], $markup);
    }

    public function callback(array $query): bool
    {
        $data = $query['data'] ?? '';
        if (!in_array($data, ['v3:donate', 'v3:donate:custom', 'v3:donate:cancel'], true)) {
            return false;
        }
        $target = CallbackUiGateway::fromQuery($this->gateway, $query);
        $user = (string)$query['user_id'];
        $this->cancel($user);
        if ($data === 'v3:donate:custom') {
            $this->flows->revokeAction($user, 'location_input');
            $settings = $this->settings->getUser($user);
            $this->flows->create($user, 'user', null, $settings['revision'], self::ACTION, 600, [
                'panel_id' => $target->messageId(), 'nonce' => bin2hex(random_bytes(16)),
            ]);
            $target->sendPrivate($user, $this->text($user, 'donate_custom_prompt'), $this->promptMarkup($user));
        } else {
            $this->menu($user, $target);
        }
        $target->answerCallback((string)$query['query_id'], '');
        return true;
    }

    public function message(array $message): bool
    {
        if (($message['private'] ?? false) !== true) {
            return false;
        }
        $user = (string)($message['user_id'] ?? '');
        $text = trim((string)($message['text'] ?? ''));
        if (str_starts_with($text, '/')) {
            $this->cancel($user);
            return false;
        }
        $flow = $this->flows->latest($user, self::ACTION);
        if ($flow === null) {
            return false;
        }
        $panel = $flow['payload']['panel_id'] ?? null;
        if (!is_int($panel) || $panel < 1) {
            $this->cancel($user);
            return true;
        }
        $target = new CallbackUiGateway($this->gateway, $user, $panel);
        try {
            $amount = Donations::customAmount($text);
        } catch (\InvalidArgumentException) {
            $target->sendPrivate($user, $this->text($user, 'donate_custom_invalid') . "\n\n" . $this->text($user, 'donate_custom_prompt'), $this->promptMarkup($user));
            return true;
        }
        // Claim exactly this prompt before awaiting Telegram; parallel text updates cannot export twice.
        $claimed = $this->db->transaction(function () use ($user, $flow): bool {
            $latest = $this->flows->latest($user, self::ACTION);
            if ($latest === null || $latest['payload'] !== $flow['payload']) {
                return false;
            }
            $now = $this->clock->now()->getTimestamp();
            $query = $this->db->pdo()->prepare('UPDATE conversation_states SET consumed_at=? WHERE user_id=? AND action=? AND payload_json=? AND scope=? AND group_id IS NULL AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>?');
            $query->execute([$now, $user, self::ACTION, Database::encode($flow['payload']), 'user', $now]);
            return $query->rowCount() === 1;
        });
        if (!$claimed) {
            return true;
        }
        try {
            $offer = $this->donations->createCustomOffer($user, $amount, $this->settings->getUser($user)['language']);
            if (!$this->current($user, $flow)) {
                return true;
            }
            $markup = ['_' => 'replyInlineMarkup', 'rows' => [
                ['_' => 'keyboardButtonRow', 'buttons' => [$offer['button']]],
                ['_' => 'keyboardButtonRow', 'buttons' => [$this->back($user, 'v3:donate')]],
            ]];
            $target->sendPrivate($user, $this->text($user, 'donate_custom_ready', ['amount' => $amount]), $markup);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (PanelEditException $error) {
            throw $error;
        } catch (\Throwable) {
            if (!$this->current($user, $flow)) {
                return true;
            }
            $target->sendPrivate($user, $this->text($user, 'donate_invoice_error'), [
                '_' => 'replyInlineMarkup', 'rows' => [['_' => 'keyboardButtonRow', 'buttons' => [$this->back($user, 'v3:donate')]]],
            ]);
        }
        return true;
    }

    public function cancel(string $user): void
    {
        $this->db->pdo()->prepare('UPDATE conversation_states SET revoked_at=? WHERE user_id=? AND action=? AND revoked_at IS NULL')
            ->execute([$this->clock->now()->getTimestamp(), $user, self::ACTION]);
    }

    private function current(string $user, array $flow): bool
    {
        $query = $this->db->pdo()->prepare('SELECT 1 FROM conversation_states WHERE user_id=? AND action=? AND payload_json=? AND consumed_at IS NOT NULL AND revoked_at IS NULL AND expires_at>?');
        $query->execute([$user, self::ACTION, Database::encode($flow['payload']), $this->clock->now()->getTimestamp()]);
        return $query->fetchColumn() !== false;
    }
    private function promptMarkup(string $user): array
    {
        return ['_' => 'replyInlineMarkup', 'rows' => [
            ['_' => 'keyboardButtonRow', 'buttons' => [[
                '_' => 'keyboardButtonCallback', 'text' => $this->text($user, 'donate_custom_cancel'),
                'data' => 'v3:donate:cancel', 'style' => ['_' => 'keyboardButtonStyle', 'bg_danger' => true],
            ]]],
        ]];
    }

    private function back(string $user, string $data): array
    {
        return [
            '_' => 'keyboardButtonCallback', 'text' => $this->text($user, 'back'), 'data' => $data,
            'style' => ['_' => 'keyboardButtonStyle'],
        ];
    }

    private function text(string $user, string $key, array $values = []): string
    {
        return $this->lang->text($this->settings->getUser($user)['language'], $key, $values);
    }
}
