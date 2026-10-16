<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Amp;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\Donations;
use Shabbat\Storage\Database;
use Shabbat\Time\FrozenClock;

final class DonationsTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private Donations $donations;
    private array $calls = [];
    private array $offers;
    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-13T09:00:00Z'));
        $this->donations = new Donations($this->db, $this->clock, function (string $method, array $args): mixed {
            $this->db->transaction(static fn(): null=>null); // Proves RPC is outside an application transaction.
            $this->calls[] = [$method,$args];
            return $method === 'payments.exportInvoice' ? ['_' => 'payments.exportedInvoice','url' => 'https://t.me/invoice-fixture-' . $args['invoice_media']['invoice']['prices'][0]['amount']] : true;
        });
        $this->offers = $this->donations->createOffers('17', 'en')['offers'];
    }
    private function query(int $index = 0, string $id = '123'): array
    {
        return ['query_id' => $id,'user_id' => 17,'currency' => 'XTR','total_amount' => $this->offers[$index]['amount'],'payload' => $this->offers[$index]['payload']];
    }
    private function payment(int $index = 0, string $charge = 'fixture-charge'): array
    {
        return ['message' => ['id' => 1234,'from_id' => ['_' => 'peerUser','user_id' => 17],
            'action' => ['_' => 'messageActionPaymentSentMe','payload' => $this->offers[$index]['payload'],'currency' => 'XTR','total_amount' => $this->offers[$index]['amount'],'charge' => ['id' => $charge]]]];
    }
    public function testOffersPreserveSixPresetsAndUseNativeStarsInvoicesWithNeutralChoices(): void
    {
        self::assertSame([5,25,100,150,250,400], array_column($this->offers, 'amount'));
        self::assertCount(6, array_unique(array_column($this->offers, 'payload')));
        foreach ($this->calls as [$method,$args]) {
            self::assertSame('payments.exportInvoice', $method);
            $invoice = $args['invoice_media'];
            self::assertSame('XTR', $invoice['invoice']['currency']);
            self::assertSame(['_' => 'dataJSON','data' => '{}'], $invoice['provider_data']);
            self::assertArrayNotHasKey('provider', $invoice);
            self::assertArrayNotHasKey('email_requested', $invoice['invoice']);
        }
        foreach ($this->offers as $offer) {
            self::assertSame('keyboardButtonUrl', $offer['button']['_']);
            self::assertSame(['_' => 'keyboardButtonStyle'], $offer['button']['style']);
        }
        self::assertSame(6, (int)$this->db->pdo()->query("SELECT COUNT(*) FROM payment_intents WHERE status='exported'")->fetchColumn());
    }
    public function testPrecheckoutValidatesPayerAmountCurrencyPayloadAndExpiry(): void
    {
        self::assertTrue($this->donations->validatePrecheckout($this->query())['ok']);
        foreach ([['user_id' => 18],['total_amount' => 6],['total_amount' => '5'],['currency' => 'USD'],['payload' => 'untrusted'],['query_id' => 'bad']] as $changes) {
            self::assertFalse($this->donations->validatePrecheckout(array_replace($this->query(), $changes))['ok']);
        }
        $this->clock->set(new DateTimeImmutable('2026-10-13T10:00:00Z'));
        self::assertSame('invoice_expired', $this->donations->validatePrecheckout($this->query())['reason']);
    }
    public function testPrecheckoutApprovalReservesOneQueryAndRejectsPaidOrConcurrentQueries(): void
    {
        self::assertTrue($this->donations->answerPrecheckout($this->query())['ok']);
        self::assertTrue($this->donations->answerPrecheckout($this->query())['ok']);
        self::assertFalse($this->donations->answerPrecheckout($this->query(0, '124'))['ok']);
        $last = end($this->calls);
        self::assertSame('messages.setBotPrecheckoutResults', $last[0]);
        self::assertFalse($last[1]['success']);
        self::assertArrayHasKey('error', $last[1]);
        $this->donations->recordPayment($this->payment());
        self::assertFalse($this->donations->validatePrecheckout($this->query())['ok']);
    }
    public function testPaymentRequiresApprovalThenRecordsOnceAcrossRestartAndLateDelivery(): void
    {
        self::assertSame('rejected', $this->donations->recordPayment($this->payment())['status']);
        $this->donations->answerPrecheckout($this->query());
        $this->clock->set(new DateTimeImmutable('2026-10-13T11:00:00Z')); // Accepted before expiry; service update can arrive later.
        $received = $this->donations->recordPayment($this->payment());
        self::assertSame('recorded', $received['status']);
        self::assertSame(5, $received['amount']);
        self::assertArrayNotHasKey('charge_uid', $received);
        self::assertArrayNotHasKey('payload', $received);
        $restarted = new Donations($this->db, $this->clock, static function (): never {
            throw new RuntimeException('Recording a payment must not make an RPC.');
        });
        self::assertSame('duplicate', $restarted->recordPayment($this->payment())['status']);
        self::assertSame(['donations' => 1,'stars' => 5], $restarted->stats());
    }
    public function testNativeIntegerSenderAndPrivateBotPeerRecordOnceWithoutRpc(): void
    {
        $this->donations->answerPrecheckout($this->query());
        $payment = $this->payment();
        $payment['_'] = 'updateNewMessage';
        $payment['message']['_'] = 'messageService';
        $payment['message']['from_id'] = 17;
        $payment['message']['peer_id'] = 987654321; // Incoming private peer can be the bot itself.
        $service = new Donations($this->db, $this->clock, static function (): never {
            throw new RuntimeException('Payment recording must not make an RPC.');
        });
        $recorded = $service->recordPayment($payment);
        self::assertSame('recorded', $recorded['status']);
        self::assertSame('17', $recorded['user_id']);
        self::assertSame(1234, $recorded['service_message_id']);
        self::assertSame('duplicate', $service->recordPayment($payment)['status']);
        self::assertSame(['donations' => 1, 'stars' => 5], $service->stats());
    }

    public function testNativeSenderBindingRejectsWrongOrInvalidIdsWithoutUsingPrivatePeerAsPayer(): void
    {
        $this->donations->answerPrecheckout($this->query());
        foreach ([18, -17, 0, true, null, ['_' => 'peerChannel', 'channel_id' => 17]] as $sender) {
            $payment = $this->payment();
            $payment['message']['from_id'] = $sender;
            $payment['message']['peer_id'] = 17;
            self::assertSame('rejected', $this->donations->recordPayment($payment)['status']);
        }
        $forged = $this->payment();
        $forged['message']['from_id'] = 18;
        $forged['user_id'] = 17;
        self::assertSame('sender_mismatch', $this->donations->recordPayment($forged)['reason']);
        $native = $this->payment();
        $native['message']['from_id'] = 17;
        $native['message']['peer_id'] = ['_' => 'peerUser', 'user_id' => 987654321];
        self::assertSame('recorded', $this->donations->recordPayment($native['message'])['status']);
        self::assertSame(['donations' => 1, 'stars' => 5], $this->donations->stats());
    }

    public function testWrongSenderOrCurrencyCannotUseAValidPayload(): void
    {
        $this->donations->answerPrecheckout($this->query());
        $wrong = $this->payment();
        $wrong['message']['from_id']['user_id'] = 18;
        self::assertSame('rejected', $this->donations->recordPayment($wrong)['status']);
        $wrong = $this->payment();
        $wrong['message']['action']['currency'] = 'USD';
        self::assertSame('rejected', $this->donations->recordPayment($wrong)['status']);
        $wrong = $this->payment();
        $wrong['message']['from_id'] = ['_' => 'peerChannel','channel_id' => 17];
        self::assertSame('rejected', $this->donations->recordPayment($wrong)['status']);
        self::assertSame(['donations' => 0,'stars' => 0], $this->donations->stats());
    }
    public function testExplicitUserCannotOverrideTheAuthenticatedRawSender(): void
    {
        $this->donations->answerPrecheckout($this->query());
        $forged = $this->payment();
        $forged['message']['from_id']['user_id'] = 18;
        $forged['user_id'] = 17;
        self::assertSame('rejected', $this->donations->recordPayment($forged)['status']);
        self::assertSame(['donations' => 0,'stars' => 0], $this->donations->stats());
    }
    public function testUnacknowledgedPrecheckoutRpcDoesNotReportSuccess(): void
    {
        $service = new Donations($this->db, $this->clock, static fn(): bool=>false);
        $this->expectException(RuntimeException::class);
        $service->answerPrecheckout($this->query());
    }
    public function testChargeUniquenessPreventsConflictingIntentReplays(): void
    {
        $this->donations->answerPrecheckout($this->query());
        $this->donations->recordPayment($this->payment());
        $this->donations->answerPrecheckout($this->query(1, '124'));
        self::assertSame('rejected', $this->donations->recordPayment($this->payment(1))['status']);
        self::assertSame(['donations' => 1,'stars' => 5], $this->donations->stats());
    }
    public function testLegacyPendingPayloadGetsExplicitFreshInvoiceRequest(): void
    {
        $legacy = $this->query();
        $legacy['payload'] = 'donate|17|5';
        $reply = $this->donations->answerPrecheckout($legacy);
        self::assertFalse($reply['ok']);
        self::assertSame('legacy_invoice_expired', $reply['reason']);
        self::assertStringContainsString('/donate', $reply['error']);
        $payment = $this->payment();
        $payment['message']['action']['payload'] = 'donate|17|5';
        self::assertSame('legacy_unverifiable', $this->donations->recordPayment($payment)['reason']);
    }
    public function testFailedDonationWriteRollsBackPaymentStatusAndCanBeRetried(): void
    {
        $this->donations->answerPrecheckout($this->query());
        $this->db->pdo()->exec("CREATE TRIGGER reject_donation BEFORE INSERT ON donations BEGIN SELECT RAISE(FAIL,'simulated disk error'); END");
        try {
            $this->donations->recordPayment($this->payment());
            self::fail('Database failure was ignored.');
        } catch (PDOException) {
        }
        self::assertSame('precheckout_approved', $this->db->pdo()->query('SELECT status FROM payment_intents ORDER BY intent_id LIMIT 1')->fetchColumn());
        self::assertSame(['donations' => 0,'stars' => 0], $this->donations->stats());
        $this->db->pdo()->exec('DROP TRIGGER reject_donation');
        self::assertSame('recorded', $this->donations->recordPayment($this->payment())['status']);
    }
    public function testInvalidExportUrlsAreNotOfferedAndDoNotDiscardOtherDonorRecords(): void
    {
        $invalid = new Donations($this->db, $this->clock, static fn(): array=>['url' => 'https://evil.invalid/invoice']);
        $result = $invalid->createOffers('18');
        self::assertSame([], $result['offers']);
        self::assertCount(6, $result['errors']);
        self::assertSame(6, (int)$this->db->pdo()->query("SELECT COUNT(*) FROM payment_intents WHERE user_id='17' AND status='exported'")->fetchColumn());
    }
}
