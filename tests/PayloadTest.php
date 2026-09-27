<?php

declare(strict_types=1);

namespace XInfra\Tests;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;
use XInfra\Event;
use XInfra\Level;
use XInfra\Payload;
use XInfra\Tests\Fixtures\TestConfig;

final class PayloadTest extends TestCase
{
    public function testContextIsMadeJsonSafe(): void
    {
        $event = new Event(Level::Error, 'Failed', [
            'date' => new DateTimeImmutable('2026-01-02 03:04:05.678+01:00'),
            'level' => Level::Error,
            'object' => new stdClass(),
            'exception' => new LogicException('Inner', 5),
            'nested' => ['a' => ['b' => 1]],
            'nan' => NAN,
            'inf' => -INF,
        ]);

        $context = Payload::fromEvent($event, TestConfig::enabled())['context'];

        self::assertIsArray($context);
        self::assertSame('2026-01-02T03:04:05.678+01:00', $context['date']);
        self::assertSame('error', $context['level']);
        self::assertSame(['class' => stdClass::class], $context['object']);
        self::assertIsArray($context['exception']);
        self::assertSame(LogicException::class, $context['exception']['class']);
        self::assertSame(['a' => ['b' => 1]], $context['nested']);
        self::assertSame('NAN', $context['nan']);
        self::assertSame('-INF', $context['inf']);
    }

    public function testDeepAndLargeContextIsCut(): void
    {
        $deep = ['value' => 1];

        for ($i = 0; $i < 12; $i++) {
            $deep = ['child' => $deep];
        }

        $event = new Event(Level::Error, 'Failed', ['deep' => $deep, 'many' => range(1, 300)]);
        $encoded = Payload::encode([Payload::fromEvent($event, TestConfig::enabled())]);

        self::assertStringContainsString('Over 8 levels, left out', $encoded);
        self::assertStringContainsString('Over 200 items, the rest is left out', $encoded);
    }

    public function testInvalidUtf8DoesNotBreakTheBatch(): void
    {
        $event = new Event(Level::Error, "Broken \xB1\x31 text");

        /** @var array{events: list<array{message: string}>} $decoded */
        $decoded = json_decode(Payload::encode([Payload::fromEvent($event, TestConfig::enabled())]), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringStartsWith('Broken', $decoded['events'][0]['message']);
    }
}
