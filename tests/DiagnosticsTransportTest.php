<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\ByteStream\ReadableStream;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\MadelineGateway;

final class DiagnosticsTransportTest extends TestCase
{
    public function testJsonIsUploadedAsBoundedInMemoryDocumentWithNoFilePath(): void
    {
        $transport = new GatewayFixtureTransport();
        $gateway = new MadelineGateway($transport, false);
        $json = json_encode(['schema' => 'shabbat-diagnostics-1', 'read_only' => true, 'records' => []], JSON_THROW_ON_ERROR);
        $gateway->sendDiagnosticDocument('2', $json, 'Diagnostic snapshot', '12345');
        self::assertCount(1, $transport->calls);
        [$method, $payload] = $transport->calls[0];
        self::assertSame('messages.sendMedia', $method);
        self::assertSame(12345, $payload['random_id']);
        self::assertSame('2', $payload['peer']);
        self::assertSame(0, $payload['floodWaitLimit']);
        self::assertSame('inputMediaUploadedDocument', $payload['media']['_']);
        self::assertSame('application/json', $payload['media']['mime_type']);
        self::assertTrue($payload['media']['force_file']);
        self::assertInstanceOf(ReadableStream::class, $payload['media']['file']);
        self::assertSame($json, $payload['media']['file']->read());
        self::assertNull($payload['media']['file']->read());
        self::assertSame('shabbat-diagnostics.json', $payload['media']['attributes'][0]['file_name']);
        self::assertArrayNotHasKey('parse_mode', $payload);
    }

    public function testAcknowledgedPanelIdentityUsesNativeSendResponse(): void
    {
        $transport = new GatewayFixtureTransport();
        $transport->responses['messages.sendMessage'] = ['_' => 'updateShortSentMessage', 'id' => 500];
        $gateway = new MadelineGateway($transport, false);
        self::assertSame(500, $gateway->sendDiagnosticPanel('2', '<b>Test</b>', ['inline_keyboard' => [[['text' => 'Next', 'callback_data' => 'dz:012345678901234567890123:next']]]]));
        self::assertSame('keyboardButtonCallback', $transport->calls[0][1]['reply_markup']['rows'][0]['buttons'][0]['_']);
        $transport->responses['messages.sendMessage'] = ['_' => 'updates', 'updates' => []];
        $this->expectException(\RuntimeException::class);
        $gateway->sendDiagnosticPanel('2', 'Test', []);
    }

    public function testInvalidExportsNeverReachTransport(): void
    {
        $transport = new GatewayFixtureTransport();
        $gateway = new MadelineGateway($transport, false);
        foreach (
            [
            ['-1001', '{}', 'ok', '1'],
            ['2', '/etc/passwd', 'ok', '1'],
            ['2', '{}', 'ok', '1'],
            ['2', str_repeat('x', 1048577), 'ok', '1'],
            ] as $args
        ) {
            try {
                $gateway->sendDiagnosticDocument(...$args);
                self::fail('Invalid export must be rejected.');
            } catch (\InvalidArgumentException | \JsonException) {
                self::assertSame([], $transport->calls);
            }
        }
    }

    public function testPinnedSerializerAcceptsReadableStreamsBeforeFilePathFallback(): void
    {
        // Verify the exact locked SDK path that the mock transport cannot execute.
        $base = dirname(__DIR__) . '/vendor/danog/madelineproto/src/';
        $tl = file_get_contents($base . 'TL/TL.php');
        $logic = file_get_contents($base . 'MTProtoTools/FilesLogic.php');
        self::assertStringContainsString('$value = $this->API->upload($value, cancellation:', $tl);
        self::assertStringContainsString('if ($file instanceof ReadableStream || \is_resource($file))', $logic);
        self::assertStringContainsString('return $this->uploadFromStream($file, 0,', $logic);
    }
}
