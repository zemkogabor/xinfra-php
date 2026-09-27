<?php

declare(strict_types=1);

namespace XInfra\Tests\Monolog;

use InvalidArgumentException;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\TestCase;
use XInfra\Client;
use XInfra\Level;
use XInfra\Monolog\XInfraHandler;
use XInfra\Tests\Fixtures\RecordingTransport;
use XInfra\Tests\Fixtures\TestConfig;

final class XInfraHandlerTest extends TestCase
{
    private RecordingTransport $transport;

    private Client $client;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->client = new Client(TestConfig::enabled(Level::Warning), $this->transport);
    }

    public function testRecordBecomesAnEvent(): void
    {
        $logger = new Logger('app', [new XInfraHandler($this->client)], [new PsrLogMessageProcessor()]);
        $logger->pushProcessor(static function (LogRecord $record): LogRecord {
            $record->extra['tags'] = ['tenant' => '12', 'invalid' => ['x']];

            return $record;
        });

        $logger->error('Import of {file} failed', ['file' => 'products.csv', 'rows' => 10]);
        $logger->close();

        $events = $this->transport->getEvents();
        self::assertCount(1, $events);
        self::assertSame('error', $events[0]['level']);
        self::assertSame('Import of products.csv failed', $events[0]['message']);
        self::assertSame(['file' => 'products.csv', 'rows' => 10], $events[0]['context']);
        self::assertSame(['tenant' => '12'], $events[0]['tags']);
        self::assertArrayNotHasKey('exception', $events[0]);
    }

    public function testExceptionInTheContext(): void
    {
        $logger = new Logger('app', [new XInfraHandler($this->client)]);
        $exception = new InvalidArgumentException('Bad input');

        $logger->critical('Request failed', ['exception' => $exception, 'orderId' => 5]);
        $logger->close();

        $event = $this->transport->getEvents()[0];
        self::assertSame(['orderId' => 5], $event['context']);
        self::assertIsArray($event['exception']);
        self::assertSame(InvalidArgumentException::class, $event['exception']['type']);
        self::assertSame('Bad input', $event['exception']['message']);
        self::assertSame($exception->getLine(), $event['exception']['line']);
    }

    public function testLevelDefaultsToTheClientMinimum(): void
    {
        $handler = new XInfraHandler($this->client);
        $logger = new Logger('app', [$handler]);

        $logger->info('Skipped');
        $logger->warning('Sent');
        $handler->flush();

        self::assertSame(['Sent'], array_column($this->transport->getEvents(), 'message'));
    }

    public function testRecordsAreBufferedUntilClose(): void
    {
        $logger = new Logger('app', [new XInfraHandler($this->client)]);

        $logger->error('One');
        $logger->error('Two');

        self::assertCount(0, $this->transport->requests);

        $logger->close();

        self::assertCount(1, $this->transport->requests);
        self::assertCount(2, $this->transport->getEvents());
    }
}
