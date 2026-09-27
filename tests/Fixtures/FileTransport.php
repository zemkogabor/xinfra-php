<?php

declare(strict_types=1);

namespace XInfra\Tests\Fixtures;

use XInfra\Transport\Transport;

/**
 * Appends every batch to a file, one JSON body per line. Used by the child process tests.
 */
final class FileTransport implements Transport
{
    public function __construct(private readonly string $path) {}

    public function send(string $url, string $key, string $body, float $timeout): void
    {
        file_put_contents($this->path, $body . "\n", FILE_APPEND);
    }
}
