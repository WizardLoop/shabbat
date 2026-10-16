<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use danog\MadelineProto\Namespace\Blacklist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\MadelineGateway;

/** The installed SDK declarations and TL schema are the contract, not our fakes. */
final class NativeRpcContractTest extends TestCase
{
    public static function nativeCalls(): array
    {
        return [
            ['channels.getParticipant', ['channel', 'participant']],
            ['channels.deleteMessages', ['channel', 'id']],
            ['messages.deleteMessages', ['revoke', 'id']],
            ['messages.editChatDefaultBannedRights', ['peer', 'banned_rights']],
            ['messages.getForumTopicsByID', ['peer', 'topics']],
            ['messages.sendMedia', ['peer', 'media', 'message', 'random_id', 'entities', 'reply_markup', 'reply_to', 'parse_mode']],
            ['messages.sendMessage', ['peer', 'message', 'random_id', 'entities', 'reply_markup', 'reply_to', 'parse_mode', 'no_webpage']],
            ['messages.editMessage', ['peer', 'id', 'message', 'parse_mode', 'reply_markup', 'no_webpage']],
            ['messages.setBotCallbackAnswer', ['query_id', 'message', 'alert', 'cache_time']],
            ['messages.setInlineBotResults', ['query_id', 'results', 'cache_time', 'private', 'next_offset', 'switch_pm']],
            ['payments.exportInvoice', ['invoice_media']],
            ['messages.setBotPrecheckoutResults', ['query_id', 'success', 'error']],
            ['bots.setBotCommands', ['scope', 'lang_code', 'commands']],
        ];
    }

    #[DataProvider('nativeCalls')]
    public function testReachableRpcIsAvailableAndItsArgumentsMatchLockedSchema(string $rpc, array $arguments): void
    {
        self::assertArrayNotHasKey($rpc, Blacklist::BLACKLIST);
        [$namespace, $method] = explode('.', $rpc, 2);
        $reflection = new \ReflectionMethod('danog\\MadelineProto\\Namespace\\' . ucfirst($namespace), $method);
        self::assertTrue($reflection->isPublic());
        $declared = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $reflection->getParameters());
        $schema = self::schemaEntry($rpc);
        foreach ($arguments as $argument) {
            // SDK array-call mode also supports TL fields such as random_id,
            // intentionally omitted from the generated named-argument interface.
            self::assertTrue(in_array($argument, $declared, true) || preg_match('/\b' . preg_quote($argument, '/') . ':/', $schema) === 1, $rpc . ':' . $argument);
        }
        self::assertContains('floodWaitLimit', $declared);
    }

    public function testEmittedGatewayParametersMatchTheActualSdk(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['channels.getParticipant'] = ['participant' => ['_' => 'channelParticipantAdmin']];
        $transport->responses['messages.getForumTopicsByID'] = ['topics' => [['id' => 7, 'closed' => false]]];
        $gateway = new MadelineGateway($transport);
        $gateway->isGroupAdmin('7', '-100123');
        $gateway->setPermissions('-100123', ['_' => 'chatBannedRights', 'until_date' => 0]);
        $gateway->send('-100123', ['text' => 'fixture', 'topic_id' => 7], '42');
        $gateway->send('-100123', ['text' => 'fixture', 'media' => 'fixture-file-id'], '43');
        $gateway->sendPrivate('7', 'fixture');
        $gateway->editPrivate('7', 1, 'fixture');
        $gateway->answerCallback('123', 'fixture');
        $gateway->answerInline('124', [], ['next_offset' => '']);
        foreach ($transport->calls as [$rpc, $arguments]) {
            self::assertArrayNotHasKey($rpc, Blacklist::BLACKLIST);
            [$namespace, $method] = explode('.', $rpc, 2);
            $reflection = new \ReflectionMethod('danog\\MadelineProto\\Namespace\\' . ucfirst($namespace), $method);
            $declared = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $reflection->getParameters());
            foreach (array_keys($arguments) as $argument) {
                self::assertTrue(in_array($argument, $declared, true) || preg_match('/\b' . preg_quote($argument, '/') . ':/', self::schemaEntry($rpc)) === 1, $rpc . ':' . $argument);
            }
        }
        self::assertCount(9, $transport->calls);
    }

    public function testInvoiceAndMenuConstructorsExistInTheNativeSchema(): void
    {
        $expected = [
            'inputMediaInvoice' => ['title', 'description', 'invoice', 'payload', 'provider_data'],
            'invoice' => ['currency', 'prices'],
            'labeledPrice' => ['label', 'amount'],
            'dataJSON' => ['data'],
            'botCommandScopeUsers' => [],
            'botCommandScopeChats' => [],
            'botCommandScopeChatAdmins' => [],
            'botCommandScopePeer' => ['peer'],
            'botCommand' => ['command', 'description'],
        ];
        foreach ($expected as $constructor => $fields) {
            $schema = self::schemaEntry($constructor);
            foreach ($fields as $field) {
                self::assertMatchesRegularExpression('/\b' . preg_quote($field, '/') . ':/', $schema);
            }
        }
    }

    private static function schemaEntry(string $name): string
    {
        $schema = file_get_contents(dirname(__DIR__) . '/vendor/danog/madelineproto/src/TL_telegram_v225.tl');
        self::assertIsString($schema);
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . '#[^\r\n]+/m', $schema, $match), 'Missing native TL entry: ' . $name);
        return $match[0];
    }
}
