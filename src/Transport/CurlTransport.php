<?php

declare(strict_types=1);

namespace XInfra\Transport;

use RuntimeException;

final class CurlTransport implements Transport
{
    public function send(string $url, string $key, string $body, float $timeout): void
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Could not initialize curl.');
        }

        $timeoutMs = (int) ceil($timeout * 1000);

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-XInfra-Key: ' . $key,
            ],
        ]);

        $result = curl_exec($handle);
        $error = curl_error($handle);

        if ($result === false) {
            throw new RuntimeException('Sending to xInfra failed: ' . $error);
        }
    }
}
