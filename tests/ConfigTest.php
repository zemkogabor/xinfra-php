<?php

declare(strict_types=1);

namespace XInfra\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;
use XInfra\Config;
use XInfra\Level;

final class ConfigTest extends TestCase
{
    public function testEmptyUrlOrKeyTurnsSendingOff(): void
    {
        self::assertFalse((new Config('', 'key', null))->isEnabled());
        self::assertFalse((new Config('https://xinfra.example.com', '  ', null))->isEnabled());
        self::assertTrue((new Config('https://xinfra.example.com', 'key', 'prod'))->isEnabled());
    }

    public function testEnvironmentIsRequiredWhenEnabled(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config('https://xinfra.example.com', 'key', '');
    }

    public function testServerDefaultsToTheHostname(): void
    {
        self::assertSame(gethostname(), (new Config(null, null, null))->server);
        self::assertSame('web-1', (new Config(null, null, null, server: 'web-1'))->server);
    }

    public function testBatchSizeMustFitTheApiLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(null, null, null, maxBatchSize: 101);
    }

    #[BackupGlobals(true)]
    public function testFromEnvironment(): void
    {
        $_ENV['XINFRA_INGEST_URL'] = 'https://xinfra.example.com/api/ingest/events';
        $_ENV['XINFRA_INGEST_KEY'] = 'secret';
        $_ENV['XINFRA_ENVIRONMENT'] = 'prod';
        $_ENV['XINFRA_LOG_LEVEL'] = 'ERROR';
        $_ENV['XINFRA_RELEASE'] = 'v2';

        $config = Config::fromEnvironment();

        self::assertTrue($config->isEnabled());
        self::assertSame('https://xinfra.example.com/api/ingest/events', $config->url);
        self::assertSame('secret', $config->key);
        self::assertSame('prod', $config->environment);
        self::assertSame(Level::Error, $config->minLevel);
        self::assertSame('v2', $config->release);
    }

    #[BackupGlobals(true)]
    public function testFromEnvironmentDefaults(): void
    {
        foreach (['XINFRA_INGEST_URL', 'XINFRA_INGEST_KEY', 'XINFRA_ENVIRONMENT', 'XINFRA_LOG_LEVEL', 'XINFRA_RELEASE'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        $config = Config::fromEnvironment();

        self::assertFalse($config->isEnabled());
        self::assertSame(Level::Warning, $config->minLevel);
        self::assertNull($config->release);
    }

    #[BackupGlobals(true)]
    public function testUnknownLevelIsAnError(): void
    {
        $_ENV['XINFRA_LOG_LEVEL'] = 'loud';

        $this->expectException(InvalidArgumentException::class);

        Config::fromEnvironment();
    }
}
