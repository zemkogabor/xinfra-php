<?php

declare(strict_types=1);

namespace XInfra\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XInfra\Client;
use XInfra\Config;
use XInfra\Event;
use XInfra\EventProcessor;
use XInfra\Level;
use XInfra\Tests\Fixtures\RecordingTransport;
use XInfra\Tests\Fixtures\TestConfig;

final class ClientTest extends TestCase
{
    private RecordingTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
    }

    public function testEventsAreBufferedUntilFlush(): void
    {
        $client = new Client(TestConfig::enabled(), $this->transport);

        $client->captureMessage('error', 'First');
        $client->captureMessage(Level::Warning, 'Second');

        self::assertCount(0, $this->transport->requests);
        self::assertCount(2, $client->getPendingEvents());

        $client->flush();

        self::assertCount(1, $this->transport->requests);
        self::assertSame('https://xinfra.example.com/api/ingest/events', $this->transport->requests[0]['url']);
        self::assertSame('test-key', $this->transport->requests[0]['key']);
        self::assertSame(['First', 'Second'], array_column($this->transport->getEvents(), 'message'));
        self::assertCount(0, $client->getPendingEvents());
    }

    public function testPayloadContainsConfigAndEventFields(): void
    {
        $client = new Client(TestConfig::enabled(), $this->transport);
        $event = new Event(Level::Error, 'Payment failed', ['orderId' => 42]);
        $event->tags = ['module' => 'billing', 'retry' => true];
        $event->user = ['id' => '7'];
        $event->logger = 'billing';

        $client->capture($event);
        $client->flush();

        $sent = $this->transport->getEvents()[0];
        self::assertSame('error', $sent['level']);
        self::assertSame('Payment failed', $sent['message']);
        self::assertSame('prod', $sent['environment']);
        self::assertSame('web-1', $sent['server']);
        self::assertSame('v1.2.3', $sent['release']);
        self::assertSame('billing', $sent['logger']);
        self::assertSame(['orderId' => 42], $sent['context']);
        self::assertSame(['module' => 'billing', 'retry' => 'true'], $sent['tags']);
        self::assertSame(['id' => '7'], $sent['user']);
        self::assertIsString($sent['timestamp']);
        self::assertArrayNotHasKey('exception', $sent);
        self::assertArrayNotHasKey('request', $sent);
    }

    public function testExceptionData(): void
    {
        $client = new Client(TestConfig::enabled(), $this->transport);
        $exception = new RuntimeException('Database is down');

        $client->captureException($exception);
        $client->flush();

        $sent = $this->transport->getEvents()[0];
        self::assertSame('error', $sent['level']);
        self::assertSame('Database is down', $sent['message']);
        self::assertSame([
            'type' => RuntimeException::class,
            'message' => 'Database is down',
            'file' => __FILE__,
            'line' => $exception->getLine(),
            'stacktrace' => $exception->getTraceAsString(),
        ], $sent['exception']);
    }

    public function testErrorWithoutExceptionKeepsThePlace(): void
    {
        $client = new Client(TestConfig::enabled(), $this->transport);

        $client->captureError('critical', 'Fatal error: Allowed memory size exhausted', '/app/index.php', 12);
        $client->flush();

        self::assertSame([
            'type' => null,
            'message' => 'Fatal error: Allowed memory size exhausted',
            'file' => '/app/index.php',
            'line' => 12,
            'stacktrace' => null,
        ], $this->transport->getEvents()[0]['exception']);
    }

    public function testEventsBelowTheMinimumLevelAreSkipped(): void
    {
        $client = new Client(TestConfig::enabled(Level::Warning), $this->transport);

        $client->captureMessage('info', 'Skipped');
        $client->captureMessage('notice', 'Skipped');
        $client->captureMessage('warning', 'Sent');

        self::assertCount(1, $client->getPendingEvents());
    }

    public function testDisabledClientDoesNothing(): void
    {
        $client = new Client(new Config(null, null, null), $this->transport);

        $client->captureMessage('emergency', 'Nothing happens');
        $client->flush();

        self::assertFalse($client->isEnabled());
        self::assertCount(0, $client->getPendingEvents());
        self::assertCount(0, $this->transport->requests);
    }

    public function testFullBatchIsSentRightAway(): void
    {
        $client = new Client(TestConfig::enabled(maxBatchSize: 3), $this->transport);

        for ($i = 1; $i <= 7; $i++) {
            $client->captureMessage('error', 'Event ' . $i);
        }

        self::assertCount(2, $this->transport->requests);
        self::assertCount(1, $client->getPendingEvents());

        $client->flush();

        self::assertCount(3, $this->transport->requests);
        self::assertCount(7, $this->transport->getEvents());
    }

    public function testSendingErrorsAreSwallowed(): void
    {
        $this->transport->shouldFail = true;
        $client = new Client(TestConfig::enabled(), $this->transport);

        $client->captureMessage('error', 'Lost');
        $client->flush();

        self::assertCount(1, $this->transport->requests);
        self::assertCount(0, $client->getPendingEvents());
    }

    public function testProcessorsCanChangeOrDropEvents(): void
    {
        $client = new Client(TestConfig::enabled(), $this->transport, [
            new class implements EventProcessor {
                public function process(Event $event): ?Event
                {
                    if ($event->message === 'Drop me') {
                        return null;
                    }

                    $event->tags['tenant'] = 5;

                    return $event;
                }
            },
        ]);

        $client->captureMessage('error', 'Drop me');
        $client->captureMessage('error', 'Keep me');
        $client->flush();

        $events = $this->transport->getEvents();
        self::assertCount(1, $events);
        self::assertSame(['tenant' => '5'], $events[0]['tags']);
    }

    public function testClearDropsPendingEvents(): void
    {
        $client = new Client(TestConfig::enabled(), $this->transport);

        $client->captureMessage('error', 'Dropped');
        $client->clear();
        $client->flush();

        self::assertCount(0, $this->transport->requests);
    }
}
