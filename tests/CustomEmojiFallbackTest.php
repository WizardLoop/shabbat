<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\CancelledException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\MadelineGateway;
use Shabbat\Runtime\Runner;
use Shabbat\UI\Lang;
use Shabbat\UI\Presentation;

final class CustomEmojiFallbackTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }

    private function rpc(string $name): \danog\MadelineProto\RPCErrorException
    {
        $error = (new \ReflectionClass(\danog\MadelineProto\RPCErrorException::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\danog\MadelineProto\RPCErrorException::class, 'rpc'))->setValue($error, $name);
        return $error;
    }

    public static function operations(): array
    {
        return ['private' => ['private','messages.sendMessage'], 'scheduled' => ['scheduled','messages.sendMessage'],
            'media' => ['media','messages.sendMedia'], 'edit' => ['edit','messages.editMessage'],
            'media edit' => ['media-edit','messages.editMessage'], 'inline' => ['inline','messages.setInlineBotResults']];
    }

    private function operation(MadelineGateway $gateway, string $kind): array
    {
        $payload = ['text' => '😀 ⭐ formatted',
            'entities' => [['_' => 'messageEntityBold','offset' => 0,'length' => 14],
                ['_' => 'messageEntityCustomEmoji','offset' => 3,'length' => 1,'document_id' => 42]],
            'buttons' => [[['text' => '⭐ Support','url' => 'https://example.org','style' => 'primary']]]];
        $original = $payload;
        if ($kind === 'private') {
            $gateway->sendPrivate('1', '<b>⭐ Support</b>', ['inline_keyboard' => $payload['buttons']]);
        } elseif (in_array($kind, ['edit','media-edit'], true)) {
            if ($kind === 'media-edit') {
                $payload['media'] = 'fixture-file-id';
            }
            $gateway->editPrivatePayload('1', 42, $payload);
        } elseif ($kind === 'inline') {
            $gateway->answerInline('456', [['_' => 'inputBotInlineResult','id' => 'i','type' => 'article',
                'send_message' => ['_' => 'inputBotInlineMessageText','message' => $payload['text'],'entities' => $payload['entities'],
                    'reply_markup' => MadelineGateway::normalizeMarkup(['inline_keyboard' => $payload['buttons']])]]]);
        } else {
            if ($kind === 'media') {
                $payload['media'] = 'fixture-file-id';
            }
            $gateway->send('-1001', $payload, '123');
        }
        return [$original,$payload];
    }

    #[DataProvider('operations')]
    public function testEntitlementRejectionRetriesOnceWithIdenticalTargetAndLatchesBasicMode(string $kind, string $method): void
    {
        $transport = new EmojiFixtureTransport();
        $transport->failures[$method] = [$this->rpc('PREMIUM_ACCOUNT_REQUIRED')];
        $gateway = new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives());
        [$original,$payload] = $this->operation($gateway, $kind);
        self::assertCount(2, $transport->calls);
        [$firstMethod,$first] = $transport->calls[0];
        [$secondMethod,$second] = $transport->calls[1];
        self::assertSame($method, $firstMethod);
        self::assertSame($firstMethod, $secondMethod);
        foreach (['peer','random_id','id','query_id','media'] as $identity) {
            self::assertSame($first[$identity] ?? null, $second[$identity] ?? null, $identity);
        }
        $firstBody = $kind === 'inline' ? $first['results'][0]['send_message'] : $first;
        $secondBody = $kind === 'inline' ? $second['results'][0]['send_message'] : $second;
        if ($kind !== 'private') {
            self::assertSame($firstBody['message'], $secondBody['message']);
            self::assertSame([['_' => 'messageEntityBold','offset' => 0,'length' => 14]], $secondBody['entities']);
        } else {
            self::assertStringNotContainsString('tg-emoji', $secondBody['message']);
            self::assertStringContainsString('<b>', $secondBody['message']);
        }
        $button = $secondBody['reply_markup']['rows'][0]['buttons'][0];
        self::assertArrayNotHasKey('icon', $button['style']);
        self::assertTrue($button['style']['bg_primary']);
        self::assertSame('https://example.org', $button['url']);
        self::assertSame($original['text'], $payload['text']);
        self::assertSame($original['entities'], $payload['entities']);
        $this->operation($gateway, $kind);
        self::assertCount(3, $transport->calls, 'The session must not repeat a known unsupported animation request.');
        $third = $transport->calls[2][1];
        $thirdBody = $kind === 'inline' ? $third['results'][0]['send_message'] : $third;
        self::assertArrayNotHasKey('icon', $thirdBody['reply_markup']['rows'][0]['buttons'][0]['style']);
    }

    public function testUnknownMarkupAndNetworkErrorsNeverRetryOrBecomeSilentSuccess(): void
    {
        foreach ([$this->rpc('REPLY_MARKUP_INVALID'), new \RuntimeException('Network failed'), new CancelledException()] as $error) {
            $transport = new EmojiFixtureTransport();
            $transport->failures['messages.sendMessage'] = [$error];
            try {
                $this->operation(new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives()), 'scheduled');
                self::fail('An unrelated failure was swallowed.');
            } catch (\Throwable $caught) {
                self::assertSame($error, $caught);
                self::assertCount(1, $transport->calls);
            }
        }
    }

    public function testFailedFallbackIsNotRetriedAgain(): void
    {
        $transport = new EmojiFixtureTransport();
        $second = $this->rpc('CHAT_WRITE_FORBIDDEN');
        $transport->failures['messages.sendMessage'] = [$this->rpc('PREMIUM_ACCOUNT_REQUIRED'), $second];
        try {
            $this->operation(new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives()), 'scheduled');
            self::fail('Second failure must surface.');
        } catch (\Throwable $caught) {
            self::assertSame($second, $caught);
            self::assertCount(2, $transport->calls);
        }
    }

    public function testBroadcastAlbumsKeepEveryItemIdentityAndRichTextThroughOneFallback(): void
    {
        $transport = new EmojiFixtureTransport();
        $transport->failures['messages.sendMultiMedia'] = [$this->rpc('PREMIUM_ACCOUNT_REQUIRED')];
        $gateway = new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives());
        $single = ['_' => 'inputSingleMedia','random_id' => 123,'media' => ['_' => 'inputMediaPhotoExternal','url' => 'https://example.org/photo.jpg'],
            'message' => '😀 ⭐ <literal>','entities' => [['_' => 'messageEntityBold','offset' => 0,'length' => 4],
                ['_' => 'messageEntityCustomEmoji','offset' => 3,'length' => 1,'document_id' => 42]]];
        $parameters = ['peer' => '-1001','silent' => true,'multi_media' => [$single, array_replace($single, ['random_id' => 456])]];
        $original = $parameters;
        $gateway->broadcastCall('sendMultiMedia', $parameters);
        self::assertCount(2, $transport->calls);
        self::assertSame($original, $parameters);
        foreach ($transport->calls[1][1]['multi_media'] as $index => $item) {
            self::assertSame($original['multi_media'][$index]['message'], $item['message']);
            self::assertSame($original['multi_media'][$index]['random_id'], $item['random_id']);
            self::assertSame($original['multi_media'][$index]['media'], $item['media']);
            self::assertSame([['_' => 'messageEntityBold','offset' => 0,'length' => 4]], $item['entities']);
        }
        self::assertTrue($transport->calls[1][1]['silent']);
    }

    public function testBroadcastPlainTextNeverBecomesHtmlAndUnsupportedMethodsFailBeforeRpc(): void
    {
        $transport = new EmojiFixtureTransport();
        $gateway = new MadelineGateway($transport, true, EmojiFixtureTransport::alternatives());
        $gateway->broadcastCall('sendMessage', ['peer' => '-1001','random_id' => 123,'message' => '⭐ <literal> & text','entities' => []]);
        self::assertSame('⭐ <literal> & text', $transport->calls[0][1]['message']);
        self::assertArrayNotHasKey('parse_mode', $transport->calls[0][1]);
        self::assertSame('messageEntityCustomEmoji', $transport->calls[0][1]['entities'][0]['_']);
        try {
            $gateway->broadcastCall('deleteMessages', ['id' => [1]]);
            self::fail('Unrelated RPCs must not use this message hook.');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, $transport->calls);
        }
    }
    public function testDocumentLookupIsOnceReadOnlyValidatesKnownIdsAndExactGreetingWinsOverAltMismatch(): void
    {
        $transport = new EmojiFixtureTransport();
        foreach ($transport->documents as &$document) {
            if ((string)$document['id'] === Presentation::ICONS['brand_coin']) {
                $document['attributes'][0]['alt'] = '⭐';
            }
        }
        unset($document);
        $gateway = new MadelineGateway($transport);
        $welcome = (new Lang())->text('he', 'welcome');
        $gateway->sendPrivate('1', $welcome);
        $gateway->sendPrivate('1', '⚙️ Settings');
        self::assertSame(['messages.getCustomEmojiDocuments','messages.sendMessage','messages.sendMessage'], array_column($transport->calls, 0));
        self::assertSame(array_map('intval', array_values(Presentation::ICONS)), $transport->calls[0][1]['document_id']);
        self::assertSame($welcome, $transport->calls[1][1]['message']);
        self::assertStringContainsString('5944760643524500583', $transport->calls[2][1]['message']);
    }

    public function testFailedCatalogUsesBasicPanelAndIntentionalCancellationPropagates(): void
    {
        $transport = new EmojiFixtureTransport();
        $transport->failures['messages.getCustomEmojiDocuments'] = [new \RuntimeException('Unavailable')];
        $gateway = new MadelineGateway($transport);
        $gateway->sendPrivate('1', '⚙️ Settings');
        $gateway->sendPrivate('1', '⚙️ Settings');
        self::assertCount(3, $transport->calls);
        self::assertSame('⚙️ Settings', $transport->calls[1][1]['message']);
        $cancelled = new CancelledException();
        $transport = new EmojiFixtureTransport();
        $transport->failures['messages.getCustomEmojiDocuments'] = [$cancelled];
        try {
            (new MadelineGateway($transport))->sendPrivate('1', '⚙️ Settings');
            self::fail('Intentional cancellation must propagate.');
        } catch (CancelledException $caught) {
            self::assertSame($cancelled, $caught);
            self::assertCount(1, $transport->calls);
        }
    }
}
