<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace Shabbat\Tests;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use danog\MadelineProto\API;
use danog\MadelineProto\Connection;
use danog\MadelineProto\DataCenter;
use danog\MadelineProto\DataCenterConnection;
use danog\MadelineProto\Logger;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\Stream\ConnectionContext;
use danog\MadelineProto\Stream\ContextIterator;
use danog\MadelineProto\Stream\MTProtoBufferInterface;
use PHPUnit\Framework\TestCase;
use Shabbat\Runtime\Runner;
use Shabbat\Runtime\SingleInstance;

/** Real SDK unreference/disconnect/finalize; an in-memory transport and flush callback replace network/session I/O. */
final class SdkShutdownBarrierTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Runner::settings();
    }

    public function testDisconnectedTransportCannotBeReusedAfterFinalizationReleasesWriter(): void
    {
        $previousLogger = Logger::$default;
        $destructors = new \ReflectionProperty(API::class, 'destructors');
        $previousDestructors = $destructors->getValue();
        $path = tempnam(sys_get_temp_dir(), 'shabbat-sdk-stop-');
        self::assertIsString($path);
        $lock = SingleInstance::acquire($path);
        $events = [];
        $api = (new \ReflectionClass(MTProto::class))->newInstanceWithoutConstructor();
        $api->logger = new Logger((new \danog\MadelineProto\Settings\Logger())
            ->setType(Logger::CALLABLE_LOGGER)->setExtra(static function (): void {
            }));
        $shared = (new \ReflectionClass(DataCenterConnection::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($shared, 'API'))->setValue($shared, $api);
        (new \ReflectionProperty($shared, 'datacenter'))->setValue($shared, 1);
        (new \ReflectionProperty($shared, 'ctx'))->setValue($shared, new ContextIterator([new ConnectionContext()]));
        $socket = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        $socket->API = $api;
        (new \ReflectionProperty($socket, 'shared'))->setValue($socket, $shared);
        $transport = new class ($events) implements MTProtoBufferInterface {
            public int $disconnects = 0;
            public int $writes = 0;
            public function __construct(public array &$events)
            {
            }
            public function disconnect(): void
            {
                $this->disconnects++;
                $this->events[] = 'transport_disconnected';
            }
            public function write(string $bytes): void
            {
                $this->writes++;
            }
        };
        $socket->stream = $transport;
        (new \ReflectionProperty($shared, 'connections'))->setValue($shared, [$socket]);
        (new \ReflectionProperty($shared, 'availableConnections'))->setValue($shared, [100]);
        $datacenter = (new \ReflectionClass(DataCenter::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($datacenter, 'list'))->setValue($datacenter, [$shared]);
        $api->datacenter = $datacenter;
        try {
            self::assertSame($socket, $shared->waitGetConnection());
            $events[] = 'shutdown_requested';
            $future = \Amp\async(static function () use ($api, $path, &$events): void {
                $api->unreference();
                $events[] = 'unreferenced';
                // Model a delayed session flush while exercising the real SDK finalizer.
                \Amp\delay(0.005);
                try {
                    SingleInstance::acquire($path);
                    self::fail('Writer ownership escaped before SDK flush completion.');
                } catch (\RuntimeException) {
                    $events[] = 'writer_still_owned';
                }
                $events[] = 'flush_completed';
            });
            $destructors->setValue(null, [314160 => $future]);
            Runner::finalizeSession($lock, static function () use (&$events): void {
                API::finalize();
                $events[] = 'finalize_completed';
            });
            $nextWriter = SingleInstance::acquire($path);
            $events[] = 'next_writer_acquired';
            $nextWriter->release();
            self::assertSame(['shutdown_requested', 'transport_disconnected', 'unreferenced',
                'writer_still_owned', 'flush_completed', 'finalize_completed', 'next_writer_acquired'], $events);
            self::assertSame(1, $transport->disconnects);
            self::assertNull($socket->stream);
            self::assertSame(0, $shared->hasConnection());
            $late = \Amp\async(static function () use ($shared): void {
                $connection = $shared->waitGetConnection();
                $connection->stream->write('late fixture request');
            });
            $keepAlive = \Revolt\EventLoop::repeat(1.0, static function (): void {
            });
            try {
                $late->await(new TimeoutCancellation(0.01));
                self::fail('A disconnected datacenter supplied a stale socket.');
            } catch (CancelledException) {
                self::assertSame(0, $transport->writes);
                self::assertFalse($late->isComplete());
            } finally {
                \Revolt\EventLoop::cancel($keepAlive);
                // Settle the fixture's pending wait without opening any connection.
                $pending = (new \ReflectionProperty($shared, 'connectionsDeferred'))->getValue($shared);
                $pending->error(new CancelledException());
                try {
                    $late->await();
                } catch (CancelledException) {
                    self::assertTrue($late->isComplete());
                }
            }
            self::assertSame(0, $transport->writes);
            self::assertSame(1, $transport->disconnects);
        } finally {
            $destructors->setValue(null, $previousDestructors);
            $lock->release();
            unlink($path);
            $api->unreference();
            Logger::$default = $previousLogger;
        }
    }
}
