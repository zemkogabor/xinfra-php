<?php

declare(strict_types=1);

namespace XInfra\Tests\Fixtures;

use XInfra\Config;
use XInfra\Level;

final class TestConfig
{
    public static function enabled(Level $minLevel = Level::Debug, int $maxBatchSize = 50): Config
    {
        return new Config(
            url: 'https://xinfra.example.com/api/ingest/events',
            key: 'test-key',
            environment: 'prod',
            minLevel: $minLevel,
            release: 'v1.2.3',
            server: 'web-1',
            maxBatchSize: $maxBatchSize,
        );
    }
}
