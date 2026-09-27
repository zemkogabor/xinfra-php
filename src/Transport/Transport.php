<?php

declare(strict_types=1);

namespace XInfra\Transport;

/**
 * Sends an encoded batch to the ingest API. It may throw, the client catches every error.
 */
interface Transport
{
    public function send(string $url, string $key, string $body, float $timeout): void;
}
