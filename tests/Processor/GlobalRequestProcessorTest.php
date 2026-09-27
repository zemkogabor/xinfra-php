<?php

declare(strict_types=1);

namespace XInfra\Tests\Processor;

use PHPUnit\Framework\TestCase;
use XInfra\Event;
use XInfra\Level;
use XInfra\Processor\GlobalRequestProcessor;

final class GlobalRequestProcessorTest extends TestCase
{
    public function testRequestDataBehindAProxy(): void
    {
        $processor = new GlobalRequestProcessor([
            'REQUEST_URI' => '/checkout?step=2',
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'shop.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 10.0.0.1',
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);

        $event = $processor->process(new Event(Level::Error, 'Failed'));

        self::assertSame(['url' => 'https://shop.example.com/checkout?step=2', 'method' => 'POST'], $event->request);
        self::assertSame('203.0.113.5', $event->ip);
        self::assertSame('Mozilla/5.0', $event->userAgent);
    }

    public function testForwardedHeadersCanBeIgnored(): void
    {
        $processor = new GlobalRequestProcessor([
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'shop.example.com',
            'HTTPS' => 'on',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
            'REMOTE_ADDR' => '198.51.100.7',
        ], trustForwardedHeaders: false);

        $event = $processor->process(new Event(Level::Error, 'Failed'));

        self::assertSame(['url' => 'https://shop.example.com/', 'method' => 'GET'], $event->request);
        self::assertSame('198.51.100.7', $event->ip);
    }

    public function testExistingFieldsAreKept(): void
    {
        $processor = new GlobalRequestProcessor(['REQUEST_URI' => '/', 'REMOTE_ADDR' => '198.51.100.7']);
        $event = new Event(Level::Error, 'Failed');
        $event->ip = '192.0.2.1';

        self::assertSame('192.0.2.1', $processor->process($event)->ip);
    }

    public function testNothingWithoutARequest(): void
    {
        $event = (new GlobalRequestProcessor([]))->process(new Event(Level::Error, 'Failed'));

        self::assertNull($event->request);
        self::assertNull($event->ip);
    }
}
