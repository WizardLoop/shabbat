<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Donation presets/native invoice shape preserve WizardLoop's existing bot.php capability.
// Legacy copyright and MIT permission notices are retained by the project.
declare(strict_types=1);

namespace Shabbat\Runtime;

use Closure;
use PDO;
use Shabbat\Storage\Database;
use Shabbat\Time\Clock;
use Shabbat\UI\Lang;

final class Donations
{
    public const AMOUNTS = [5,25,100,150,250,400];
    private readonly Closure $rpc;
    private readonly Lang $lang;
    public function __construct(private readonly Database $db, private readonly Clock $clock, callable $rpc)
    {
        $this->rpc = Closure::fromCallable($rpc);
        $this->lang = new Lang();
    }
    /** Only invoice export; this never enables paid broadcasts or asks Telegram to debit a payer. */
    public function createOffers(string $user, string $language = 'he'): array
    {
        $offers = [];
        $errors = [];
        self::userId($user);
        $this->language($language);
        foreach (self::AMOUNTS as $amount) {
            try {
                $offers[] = $this->exportOffer($user, $amount, $language);
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                $errors[] = ['amount' => $amount, 'error' => $this->lang->text($language, 'donate_invoice_error')];
            }
        }
        $buttons = array_chunk(array_column($offers, 'button'), 3);
        $buttons[] = [[
            '_' => 'keyboardButtonCallback',
            'text' => $this->lang->text($language, 'donate_other'),
            'data' => 'v3:donate:custom',
            'style' => ['_' => 'keyboardButtonStyle', 'bg_primary' => true],
        ]];
        return [
            'text' => $this->lang->text($language, 'donate_prompt'),
            'offers' => $offers, 'buttons' => $buttons, 'errors' => $errors,
            'reply_markup' => ['_' => 'replyInlineMarkup', 'rows' => array_map(
                static fn(array $row): array => ['_' => 'keyboardButtonRow', 'buttons' => $row],
                $buttons,
            )],
        ];
    }

    public static function customAmount(mixed $amount): int
    {
        if (is_string($amount) && preg_match('/^[1-9][0-9]{0,4}$/D', $amount)) {
            $amount = (int)$amount;
        }
        if (!is_int($amount) || $amount < 1 || $amount > 10000) {
            throw new \InvalidArgumentException('A donation requires a whole number from 1 to 10000 Stars.');
        }
        return $amount;
    }

    public function createCustomOffer(string $user, mixed $amount, string $language = 'he'): array
    {
        self::userId($user);
        $this->language($language);
        return $this->exportOffer($user, self::customAmount($amount), $language);
    }

    private function language(string $language): void
    {
        if (!in_array($language, Lang::CODES, true)) {
            throw new \InvalidArgumentException('Unsupported donation language.');
        }
    }

    private function exportOffer(string $user, int $amount, string $language): array
    {
        $now = $this->clock->now()->getTimestamp();
        $this->db->pdo()->prepare('INSERT OR IGNORE INTO users(user_id,created_at,updated_at) VALUES(?,?,?)')->execute([$user, $now, $now]);
        $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $payload = 'shabbat:donate:' . $nonce;
        $this->db->pdo()->prepare('INSERT INTO payment_intents(user_id,payload,nonce,amount,language,created_at,expires_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$user, $payload, $nonce, $amount, $language, $now, $now + 3600]);
        $id = (int)$this->db->pdo()->lastInsertId();
        try {
            $invoice = [
                '_' => 'inputMediaInvoice',
                'title' => $this->lang->text($language, 'donate_title'),
                'description' => $this->lang->text($language, 'donate_description', ['amount' => $amount]),
                'invoice' => ['_' => 'invoice', 'currency' => 'XTR', 'prices' => [['_' => 'labeledPrice', 'label' => 'star', 'amount' => $amount]]],
                'payload' => $payload, 'provider_data' => ['_' => 'dataJSON', 'data' => '{}'],
            ];
            $response = ($this->rpc)('payments.exportInvoice', ['invoice_media' => $invoice]);
            $url = is_array($response) ? ($response['url'] ?? null) : null;
            if (
                !is_string($url) || strlen($url) > 2048 || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || !in_array(parse_url($url, PHP_URL_HOST), ['t.me', 'telegram.me'], true) || parse_url($url, PHP_URL_USER) !== null
                || parse_url($url, PHP_URL_PASS) !== null || preg_match('/[\x00\r\n]/', $url)
            ) {
                throw new \RuntimeException('Invoice export returned an invalid Telegram URL.');
            }
            $this->db->pdo()->prepare("UPDATE payment_intents SET status='exported',invoice_url=? WHERE intent_id=? AND status='created'")->execute([$url, $id]);
            return [
                'intent_id' => $id, 'amount' => $amount, 'payload' => $payload, 'url' => $url, 'expires_at' => $now + 3600,
                'button' => ['_' => 'keyboardButtonUrl', 'text' => '⭐️ ' . $amount, 'url' => $url, 'style' => ['_' => 'keyboardButtonStyle']],
            ];
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->db->pdo()->prepare("UPDATE payment_intents SET status='failed',last_error='invoice export failed' WHERE intent_id=?")->execute([$id]);
            throw $error;
        }
    }
    /** Read-only validation. Use answerPrecheckout() to reserve/approve an intent atomically. */
    public function validatePrecheckout(array $query): array
    {
        try {
            $user = self::userId($query['user_id'] ?? null);
            $payload = $query['payload'] ?? null;
            $amount = $query['total_amount'] ?? null;
            $currency = $query['currency'] ?? null;
            $queryId = self::queryId($query['query_id'] ?? null);
            if (!is_string($payload) || strlen($payload) > 128 || !is_int($amount) || $currency !== 'XTR') {
                return $this->reject('invalid_invoice', $user);
            }
            $intent = $this->intent($payload);
            if ($intent === null) {
                return $this->reject(str_starts_with($payload, 'donate|') ? 'legacy_invoice_expired' : 'unknown_invoice', $user);
            }
            if ($intent['user_id'] !== $user || (int)$intent['amount'] !== $amount || $intent['currency'] !== $currency) {
                return $this->reject('invoice_mismatch', $user);
            }
            if ((int)$intent['expires_at'] <= $this->clock->now()->getTimestamp()) {
                return $this->reject('invoice_expired', $user);
            }
            if (!in_array($intent['status'], ['exported','precheckout_approved'], true)) {
                return $this->reject('invoice_unavailable', $user);
            }
            if ($intent['precheckout_query_id'] !== null && $intent['precheckout_query_id'] !== $queryId) {
                return $this->reject('invoice_already_reserved', $user);
            }
            return ['ok' => true,'query_id' => $queryId,'intent_id' => (int)$intent['intent_id'],'user_id' => $user,'amount' => $amount,'currency' => 'XTR','language' => $intent['language']];
        } catch (\InvalidArgumentException) {
            return $this->reject('invalid_invoice');
        }
    }
    public function answerPrecheckout(array $query): array
    {
        $result = $this->validatePrecheckout($query);
        $queryId = self::queryId($query['query_id'] ?? null);
        if ($result['ok']) {
            $reserved = $this->db->transaction(function (PDO $pdo) use ($result, $queryId): bool {
                $now = $this->clock->now()->getTimestamp();
                $reserve = $pdo->prepare("UPDATE payment_intents SET status='precheckout_approved',precheckout_query_id=?,approved_at=? WHERE intent_id=? AND expires_at>? AND (status='exported' OR (status='precheckout_approved' AND precheckout_query_id=?))");
                $reserve->execute([$queryId,$now,$result['intent_id'],$now,$queryId]);
                return $reserve->rowCount() === 1;
            });
            if (!$reserved) {
                $result = $this->reject('invoice_unavailable', $result['user_id']);
            }
        }
        $parameters = ['query_id' => $queryId,'success' => $result['ok']];
        if (!$result['ok']) {
            $parameters['error'] = $result['error'];
        }
        $ack = ($this->rpc)('messages.setBotPrecheckoutResults', $parameters);
        if ($ack !== true) {
            throw new \RuntimeException('Telegram did not acknowledge the precheckout answer.');
        }
        return $result;
    }
    /** Accept only authenticated MTProto payment service updates, never ordinary text/callbacks. */
    public function recordPayment(array $update): array
    {
        $message = $update['message'] ?? $update;
        $action = $message['action'] ?? $update;
        if (isset($message['action']) && ($action['_'] ?? null) !== 'messageActionPaymentSentMe') {
            return ['status' => 'rejected','reason' => 'not_payment_service'];
        }
        try {
            $sender = $message['from_id'] ?? null;
            if (isset($message['action'])) {
                $user = self::userId(is_array($sender) ? (($sender['_'] ?? null) === 'peerUser' ? ($sender['user_id'] ?? null) : null) : $sender);
                if (isset($update['user_id']) && self::userId($update['user_id']) !== $user) {
                    return ['status' => 'rejected','reason' => 'sender_mismatch'];
                }
            } else {
                $user = self::userId($update['user_id'] ?? null);
            }
        } catch (\InvalidArgumentException) {
            return ['status' => 'rejected','reason' => 'invalid_sender'];
        }
        $payload = $action['payload'] ?? null;
        $amount = $action['total_amount'] ?? null;
        $currency = $action['currency'] ?? null;
        $charge = $action['charge']['id'] ?? $action['charge_uid'] ?? null;
        if (!is_string($payload) || !is_int($amount) || $currency !== 'XTR' || !is_string($charge) || strlen($charge) < 1 || strlen($charge) > 256 || preg_match('/[\x00\r\n]/', $charge)) {
            return ['status' => 'rejected','reason' => 'invalid_payment_fields'];
        }
        return $this->db->transaction(function (PDO $pdo) use ($user, $payload, $amount, $currency, $charge, $message): array {
            $intent = $this->intent($payload);
            if ($intent === null || $intent['user_id'] !== $user || (int)$intent['amount'] !== $amount || $intent['currency'] !== $currency) {
                return ['status' => 'rejected','reason' => str_starts_with($payload, 'donate|') ? 'legacy_unverifiable' : 'intent_mismatch'];
            }
            $existing = $pdo->prepare('SELECT intent_id,user_id,amount,currency FROM donations WHERE charge_uid=?');
            $existing->execute([$charge]);
            $donation = $existing->fetch();
            if ($donation !== false) {
                return ['status' => (int)$donation['intent_id'] === (int)$intent['intent_id'] && $donation['user_id'] === $user && (int)$donation['amount'] === $amount && $donation['currency'] === $currency ? 'duplicate' : 'rejected'];
            }
            if ($intent['status'] !== 'precheckout_approved') {
                return ['status' => 'rejected','reason' => 'payment_not_approved_or_already_recorded'];
            }
            $now = $this->clock->now()->getTimestamp();
            $serviceId = is_int($message['id'] ?? null) ? $message['id'] : null;
            $pdo->prepare('INSERT INTO donations(intent_id,user_id,currency,amount,charge_uid,received_at,service_message_id) VALUES(?,?,?,?,?,?,?)')
                ->execute([$intent['intent_id'],$user,$currency,$amount,$charge,$now,$serviceId]);
            $pdo->prepare("UPDATE payment_intents SET status='paid',paid_at=? WHERE intent_id=?")->execute([$now,$intent['intent_id']]);
            return ['status' => 'recorded','user_id' => $user,'amount' => $amount,'currency' => $currency,'language' => $intent['language'],'service_message_id' => $serviceId];
        });
    }
    public function stats(): array
    {
        $row = $this->db->pdo()->query('SELECT COUNT(*) AS donations,COALESCE(SUM(amount),0) AS stars FROM donations')->fetch();
        return ['donations' => (int)$row['donations'],'stars' => (int)$row['stars']];
    }
    private function intent(string $payload): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM payment_intents WHERE payload=?');
        $query->execute([$payload]);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }
    private function reject(string $reason, ?string $user = null): array
    {
        $language = 'he';
        if ($user !== null) {
            $query = $this->db->pdo()->prepare('SELECT language FROM user_preferences WHERE user_id=?');
            $query->execute([$user]);
            $language = $query->fetchColumn() ?: 'he';
        }
        return ['ok' => false,'reason' => $reason,'error' => $this->lang->text($language, 'donate_checkout_error')];
    }
    private static function userId(mixed $id): string
    {
        if (!is_int($id) && !is_string($id)) {
            throw new \InvalidArgumentException('Invalid payment user.');
        }
        $id = (string)$id;
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid payment user.');
        }
        return $id;
    }
    private static function queryId(mixed $id): string
    {
        if (!is_int($id) && !is_string($id)) {
            throw new \InvalidArgumentException('Invalid precheckout query.');
        }
        $id = (string)$id;
        if (!preg_match('/^-?[0-9]{1,19}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid precheckout query.');
        }
        return $id;
    }
}
