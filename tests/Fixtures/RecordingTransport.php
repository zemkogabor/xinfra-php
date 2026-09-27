<?php

declare(strict_types=1);

namespace XInfra\Tests\Fixtures;

use RuntimeException;
use XInfra\Transport\Transport;

final class RecordingTransport implements Transport
{
    /** @var list<array{url: string, key: string, body: array<string, mixed>, timeout: float}> */
    public array $requests = [];

    public bool $shouldFail = false;

    public function send(string $url, string $key, string $body, float $timeout): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->requests[] = ['url' => $url, 'key' => $key, 'body' => $decoded, 'timeout' => $timeout];

        if ($this->shouldFail) {
            throw new RuntimeException('Connection refused');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEvents(): array
    {
        $events = [];

        foreach ($this->requests as $request) {
            /** @var list<array<string, mixed>> $batch */
            $batch = $request['body']['events'];
            array_push($events, ...$batch);
        }

        return $events;
    }
}
