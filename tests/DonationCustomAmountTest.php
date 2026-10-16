<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\Donations;
use Shabbat\Storage\{Database, FlowTokenRepository, SettingsRepository};
use Shabbat\Time\FrozenClock;
use Shabbat\UI\{DonationController, Lang, PanelEditException};

final class DonationCustomAmountTest extends TestCase
{
    private Database $db;
    private FrozenClock $clock;
    private SettingsRepository $settings;
    private FlowTokenRepository $flows;
    private GuidedUiGateway $gateway;
    private Donations $donations;
    private DonationController $ui;
    private array $calls = [];
    private ?\Closure $duringExport = null;

    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
        $this->db->migrate();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z'));
        $this->settings = new SettingsRepository($this->db, $this->clock);
        $this->flows = new FlowTokenRepository($this->db, $this->clock);
        $this->gateway = new GuidedUiGateway();
        $this->donations = new Donations($this->db, $this->clock, function (string $method, array $args): mixed {
            $this->db->transaction(static fn(): null => null);
            $this->calls[] = compact('method', 'args');
            if ($method === 'payments.exportInvoice') {
                if ($this->duringExport !== null) {
                    $hook = $this->duringExport;
                    $this->duringExport = null;
                    $hook();
                }
                return ['url' => 'https://t.me/invoice-fixture-' . count($this->calls)];
            }
            return true;
        });
        $this->ui = $this->controller();
    }

    private function controller(): DonationController
    {
        return new DonationController($this->db, $this->settings, $this->flows, $this->clock, $this->donations, $this->gateway);
    }

    private function click(string $data = 'v3:donate:custom', int $panel = 123, string $user = '1'): bool
    {
        return $this->ui->callback(['user_id' => $user,'private' => true,'chat_id' => $user,'message_id' => $panel,'data' => $data,'query_id' => 'query-fixture']);
    }

    private function message(string $text, string $user = '1', bool $private = true): bool
    {
        return $this->ui->message(['user_id' => $user,'private' => $private,'text' => $text]);
    }

    public function testCustomBoundsAndInvalidInputsProduceNoInvoicesOrRecords(): void
    {
        foreach ([1,'1',10000,'10000'] as $value) {
            self::assertSame((int)$value, Donations::customAmount($value));
        }
        foreach ([0,-1,10001,1.5,1.0,true,null,[], '0','10001','1.5','1e3',' 1','01','1\n'] as $value) {
            try {
                $this->donations->createCustomOffer('1', $value);
                self::fail('Invalid custom Stars accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->calls);
                self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM payment_intents')->fetchColumn());
            }
        }
    }

    public function testPresetMenuHasOtherAndCustomInvoicesRetainNativeShapeInSixLanguages(): void
    {
        $lang = new Lang();
        foreach (Lang::CODES as $language) {
            $user = $this->settings->getUser('1');
            $this->settings->updateUser('1', $user['revision'], ['language' => $language]);
            $menu = $this->donations->createOffers('1', $language);
            self::assertSame(Donations::AMOUNTS, array_column($menu['offers'], 'amount'));
            self::assertSame('v3:donate:custom', $menu['reply_markup']['rows'][2]['buttons'][0]['data']);
            self::assertSame($lang->text($language, 'donate_other'), $menu['reply_markup']['rows'][2]['buttons'][0]['text']);
            foreach ([1,10000] as $amount) {
                $offer = $this->donations->createCustomOffer('1', $amount, $language);
                self::assertSame($amount, $offer['amount']);
                $last = end($this->calls);
                self::assertSame('payments.exportInvoice', $last['method']);
                $media = $last['args']['invoice_media'];
                self::assertSame('XTR', $media['invoice']['currency']);
                self::assertSame($amount, $media['invoice']['prices'][0]['amount']);
                self::assertSame($lang->text($language, 'donate_title'), $media['title']);
                self::assertSame($offer['payload'], $media['payload']);
            }
        }
    }

    public function testPromptAndValidationEditExactlyTheOriginalPrivatePanelWithoutFlooding(): void
    {
        self::assertTrue($this->click());
        self::assertNotNull($this->flows->latest('1', 'donation_input'));
        foreach (['0','10001','12.5'] as $input) {
            self::assertTrue($this->message($input));
        }
        self::assertSame([], $this->calls);
        self::assertSame([], $this->gateway->sent);
        self::assertCount(4, $this->gateway->edited);
        foreach ($this->gateway->edited as $edit) {
            self::assertSame(123, $edit['id']);
        }
        self::assertTrue($this->message('10000'));
        self::assertCount(1, $this->calls);
        self::assertSame(10000, $this->calls[0]['args']['invoice_media']['invoice']['prices'][0]['amount']);
        self::assertSame(123, $this->gateway->edited[4]['id']);
    }

    public function testDurablePromptSurvivesControllerRestartButDuplicateTextCannotExportAgain(): void
    {
        $this->click();
        $this->ui = $this->controller();
        $this->duringExport = function (): void {
            self::assertFalse($this->message('5'));
        };
        self::assertTrue($this->message('1'));
        self::assertFalse($this->message('1'));
        $this->ui = $this->controller();
        self::assertFalse($this->message('1'));
        self::assertCount(1, $this->calls);
        self::assertSame(1, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM payment_intents')->fetchColumn());
        self::assertSame([], $this->gateway->sent);
    }

    public function testNavigationAndReplacedPromptDuringExportNeverOverwriteTheNewPanel(): void
    {
        $this->click(panel: 123);
        $before = count($this->gateway->edited);
        $this->duringExport = function (): void {
            $this->ui->cancel('1');
        };
        self::assertTrue($this->message('5'));
        self::assertCount($before, $this->gateway->edited);
        $this->click(panel: 124);
        $this->duringExport = function (): void {
            $this->click(panel: 125);
        };
        self::assertTrue($this->message('6'));
        self::assertSame(125, end($this->gateway->edited)['id']);
        self::assertSame(125, $this->flows->latest('1', 'donation_input')['payload']['panel_id']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testCommandsCancelPromptExpiryAndForeignMessagesCannotExport(): void
    {
        $this->click();
        self::assertFalse($this->message('5', '2'));
        self::assertFalse($this->message('5', '1', false));
        self::assertFalse($this->message('/start'));
        self::assertFalse($this->message('5'));
        $this->click();
        $this->clock->set($this->clock->now()->modify('+11 minutes'));
        self::assertFalse($this->message('5'));
        self::assertSame([], $this->calls);
    }

    public function testForwardedOrNonPrivateCallbackIsRejectedBeforePromptMutation(): void
    {
        foreach ([['private' => false],['chat_id' => '2']] as $change) {
            try {
                $this->ui->callback(array_replace(['user_id' => '1','private' => true,'chat_id' => '1','message_id' => 123,'data' => 'v3:donate:custom','query_id' => 'fixture'], $change));
                self::fail('Foreign donation panel accepted.');
            } catch (\InvalidArgumentException) {
                self::assertNull($this->flows->latest('1', 'donation_input'));
                self::assertSame([], $this->calls);
            }
        }
    }

    public function testCustomPrecheckoutBindsPayerAndPaymentRecordsOnceAcrossRestart(): void
    {
        $offer = $this->donations->createCustomOffer('1', 10000, 'en');
        $query = ['query_id' => '123','user_id' => '1','currency' => 'XTR','total_amount' => 10000,'payload' => $offer['payload']];
        self::assertFalse($this->donations->validatePrecheckout(array_replace($query, ['user_id' => '2']))['ok']);
        self::assertFalse($this->donations->validatePrecheckout(array_replace($query, ['total_amount' => 9999]))['ok']);
        self::assertTrue($this->donations->answerPrecheckout($query)['ok']);
        $payment = ['message' => ['id' => 55,'from_id' => ['_' => 'peerUser','user_id' => 1],'action' => ['_' => 'messageActionPaymentSentMe','payload' => $offer['payload'],'currency' => 'XTR','total_amount' => 10000,'charge' => ['id' => 'custom-fixture-charge']]]];
        self::assertSame('recorded', $this->donations->recordPayment($payment)['status']);
        $restarted = new Donations($this->db, $this->clock, static fn(): never => throw new \RuntimeException('No recording RPC.'));
        self::assertSame('duplicate', $restarted->recordPayment($payment)['status']);
        self::assertSame(['donations' => 1,'stars' => 10000], $restarted->stats());
    }

    public function testActualAsyncInvoiceExportCannotRepaintAfterNavigationCancellation(): void
    {
        $this->click();
        $before = count($this->gateway->edited);
        $entered = new \Amp\DeferredFuture();
        $release = new \Amp\DeferredFuture();
        $this->duringExport = static function () use ($entered, $release): void {
            $entered->complete();
            $release->getFuture()->await();
        };
        $pending = \Amp\async(fn(): bool => $this->message('25'));
        $entered->getFuture()->await();
        $this->ui->cancel('1');
        $release->complete();
        self::assertTrue($pending->await());
        self::assertCount($before, $this->gateway->edited);
        self::assertSame([], $this->gateway->sent);
        self::assertCount(1, $this->calls);
    }

    public function testDeletedPanelCannotFallBackToSendingAnInvoiceAsANewMessage(): void
    {
        $this->click();
        $this->gateway->editFails = true;
        try {
            $this->message('5');
            self::fail('Deleted panel reported a successful display.');
        } catch (PanelEditException) {
            self::assertSame([], $this->gateway->sent);
        }
        self::assertCount(1, $this->calls);
    }
}
