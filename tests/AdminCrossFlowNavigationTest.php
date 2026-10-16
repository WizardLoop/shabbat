<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\Cancellation;
use PHPUnit\Framework\TestCase;
use Shabbat\Calendar\{CalendarService, HebcalProvider};
use Shabbat\Http\JsonClient;
use Shabbat\Location\GeoNames;
use Shabbat\Runtime\Application;
use Shabbat\Storage\{AdminStateFiles, Database};
use Shabbat\Time\FrozenClock;

final class AdminCrossFlowNavigationTest extends TestCase
{
    private Application $app;
    private GuidedUiGateway $gateway;
    private AdminNavigationFixture $bridge;
    private bool $owner = true;

    protected function setUp(): void
    {
        $db = Database::open(':memory:');
        $db->migrate();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-15T12:00:00Z'));
        $this->gateway = new GuidedUiGateway();
        $http = new class implements JsonClient {
            public function get(string $url, array $query = [], ?Cancellation $cancellation = null): array
            {
                throw new \RuntimeException('No navigation HTTP.');
            }
        };
        $this->app = new Application(
            $this->gateway,
            $clock,
            $db,
            new CalendarService(new HebcalProvider($http), $clock),
            new GeoNames($http, $clock, ''),
            rpc:static fn(string $method): mixed => $method === 'payments.exportInvoice' ? ['url' => 'https://t.me/invoice-fixture'] : true,
            configuredOwners:fn(): array => $this->owner ? ['1'] : []
        );
        $this->app->settings->ensureGroup('-1001', 'Fixture group');
        $this->bridge = new AdminNavigationFixture($this->app);
    }

    public function testPublicStatisticsAreEditableWithoutManagerRoleAndDoNotLeakGroupIdentity(): void
    {
        $this->owner = false;
        self::assertTrue($this->bridge->callback('ps:overview', user: 2));
        self::assertTrue($this->bridge->callback('ps:modules', user: 2));
        self::assertSame([], $this->gateway->sent);
        self::assertCount(2, $this->gateway->edited);
        foreach ($this->gateway->edited as $edit) {
            self::assertSame(200, $edit['id']);
            self::assertStringNotContainsString('Fixture group', $edit['text']);
            self::assertStringNotContainsString('-1001', $edit['text']);
        }
        self::assertTrue($this->bridge->callback('ps:modules', user: 2, chat: -1001));
        self::assertCount(2, $this->gateway->edited, 'Group callbacks cannot edit a private statistics panel.');
    }

    private function competingInputs(): void
    {
        $draft = $this->bridge->draft();
        $draft->begin('1', 'broadcast1');
        $draft->capture('1', 'Keep this draft');
        $draft->begin('1', 'broadcast1');
        $this->app->subscribers->saveView('1', ['prompt' => 'search']);
        $this->bridge->adminPrompt();
    }

    public function testDonationEarlyReturnClearsAllOtherActorInputsButKeepsDraftContent(): void
    {
        $this->competingInputs();
        $revision = $this->app->settings->getUser('1')['revision'];
        $this->app->flows->create('1', 'user', null, $revision, 'settings_wizard', 600, ['panel_id' => 200]);
        self::assertTrue($this->bridge->callback('v3:donate:custom'));
        self::assertSame('', $this->bridge->draft()->mode('1'));
        self::assertSame('Keep this draft', $this->bridge->draft()->payload('1')['text']);
        self::assertNull($this->app->subscribers->view('1')['prompt']);
        self::assertSame(0, (int)$this->app->db->pdo()->query("SELECT COUNT(*) FROM admin_panel_flows WHERE actor_id='1'")->fetchColumn());
        self::assertNull($this->app->flows->latest('1', 'settings_wizard'));
        self::assertNotNull($this->app->flows->latest('1', 'donation_input'));
        self::assertTrue($this->bridge->message('5'));
        self::assertFalse($this->bridge->message('No accidental broadcast capture'));
        self::assertSame([], $this->gateway->sent);
        foreach ($this->gateway->edited as $edit) {
            self::assertSame(200, $edit['id']);
        }
    }

    public function testBroadcastQuestionKeepsOnlyItsOwnInputAndEditsOriginalPanel(): void
    {
        $this->competingInputs();
        self::assertTrue($this->bridge->callback('bc:sd:custom'));
        self::assertSame('broadcast_sd_custom', $this->bridge->draft()->mode('1'));
        self::assertNull($this->app->subscribers->view('1')['prompt']);
        self::assertSame(0, (int)$this->app->db->pdo()->query("SELECT COUNT(*) FROM admin_panel_flows WHERE actor_id='1'")->fetchColumn());
        self::assertTrue($this->bridge->message('12'));
        self::assertSame(12, $this->bridge->draft()->state('1')['self_destruct_hours']);
        self::assertSame('Keep this draft', $this->bridge->draft()->payload('1')['text']);
        self::assertSame([], $this->gateway->sent);
        self::assertSame(200, end($this->gateway->edited)['id']);
    }

    public function testSubscriberQuestionCancelsBroadcastAndAdminCapture(): void
    {
        $this->competingInputs();
        self::assertTrue($this->bridge->callback('sd:search'));
        self::assertSame('', $this->bridge->draft()->mode('1'));
        self::assertSame('search', $this->app->subscribers->view('1')['prompt']);
        self::assertTrue($this->bridge->message('Alice'));
        self::assertSame('Alice', $this->app->subscribers->view('1')['search']);
        self::assertSame('Keep this draft', $this->bridge->draft()->payload('1')['text']);
        self::assertSame([], $this->gateway->sent);
    }

    public function testTypedOperatorDestinationCancelsOldInputBeforeReturningTheNewMenu(): void
    {
        $this->competingInputs();
        self::assertTrue($this->bridge->message('/subscribers'));
        self::assertSame('', $this->bridge->draft()->mode('1'));
        self::assertNull($this->app->subscribers->view('1')['prompt']);
        self::assertFalse($this->bridge->message('An ordinary message'));
        self::assertCount(1, $this->gateway->sent, 'An explicit command opens one menu; later ordinary text does not send another.');
    }

    public function testForeignGroupCallbackDoesNotCancelPrivateInputAndActorsRemainIsolated(): void
    {
        $this->competingInputs();
        self::assertFalse($this->bridge->callback('sb:start', chat:-1001, frontend:false));
        self::assertSame('broadcast1', $this->bridge->draft()->mode('1'));
        self::assertSame('search', $this->app->subscribers->view('1')['prompt']);
        $this->app->settings->getUser('2');
        AdminStateFiles::write('data/2/grs1.txt', 'broadcast1');
        $this->app->subscribers->saveView('2', ['prompt' => 'search']);
        self::assertFalse($this->bridge->callback('sb:start', user:2, frontend:false));
        self::assertFalse(AdminStateFiles::exists('data/2/grs1.txt'));
        self::assertNull($this->app->subscribers->view('2')['prompt']);
        self::assertSame('broadcast1', $this->bridge->draft()->mode('1'));
        self::assertSame('search', $this->app->subscribers->view('1')['prompt']);
    }

    public function testRevokedOperatorCanStillClearOnlyTheirOldPendingInput(): void
    {
        $this->competingInputs();
        $this->owner = false;
        self::assertFalse($this->bridge->callback('sb:start', frontend:false));
        self::assertFalse(AdminStateFiles::exists('data/1/grs1.txt'));
        self::assertNull($this->app->subscribers->view('1')['prompt']);
        $this->owner = true;
        self::assertSame('', $this->bridge->draft()->mode('1'));
        self::assertSame('Keep this draft', $this->bridge->draft()->payload('1')['text']);
    }

    public function testWizardConfirmationSurvivesAdminBridgeAndAppliesItsParentBoundSave(): void
    {
        $g = $this->app->settings->getGroup('-1001');
        $parent = $this->app->flows->create('1', 'group', '-1001', $g['revision'], 'settings_wizard', 600, ['module' => 'tefillin','field' => 'max_per_day','panel_id' => 200]);
        $save = $this->app->flows->create('1', 'group', '-1001', $g['revision'], 'save', 600, ['changes' => ['tefillin_max_per_day' => 4],'source_wizard_token' => $parent]);
        self::assertTrue($this->bridge->callback('sb:save:' . $save));
        self::assertSame(4, $this->app->settings->getGroup('-1001')['tefillin_max_per_day']);
        self::assertSame(2, $this->app->settings->getGroup('-1001')['revision']);
        self::assertNull($this->app->flows->peek($save, '1', 'save'));
        self::assertSame([], $this->gateway->sent);
        self::assertSame(200, end($this->gateway->edited)['id']);
    }
}
