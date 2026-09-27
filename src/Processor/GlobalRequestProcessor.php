<?php

declare(strict_types=1);

namespace XInfra\Processor;

use XInfra\Event;
use XInfra\EventProcessor;

/**
 * Adds the current HTTP request (URL, method, client IP, user agent) from $_SERVER.
 * Does nothing on the command line. Fields that are already set are kept.
 */
final class GlobalRequestProcessor implements EventProcessor
{
    /**
     * @param array<array-key, mixed>|null $server Defaults to $_SERVER at the time of the event
     * @param bool $trustForwardedHeaders Read X-Forwarded-For and X-Forwarded-Proto (set it when the app runs behind a proxy you control)
     */
    public function __construct(
        private readonly ?array $server = null,
        private readonly bool $trustForwardedHeaders = true,
    ) {}

    public function process(Event $event): Event
    {
        $server = $this->server ?? $_SERVER;

        if ($this->server === null && PHP_SAPI === 'cli') {
            return $event;
        }

        $uri = self::getString($server, 'REQUEST_URI');

        if ($uri === null) {
            return $event;
        }

        $event->request ??= [
            'url' => $this->buildUrl($server, $uri),
            'method' => self::getString($server, 'REQUEST_METHOD') ?? '',
        ];
        $event->ip ??= $this->getClientIp($server);
        $event->userAgent ??= self::getString($server, 'HTTP_USER_AGENT');

        return $event;
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private function buildUrl(array $server, string $uri): string
    {
        $host = self::getString($server, 'HTTP_HOST') ?? self::getString($server, 'SERVER_NAME');

        if ($host === null) {
            return $uri;
        }

        return $this->getScheme($server) . '://' . $host . $uri;
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private function getScheme(array $server): string
    {
        $forwardedProto = $this->trustForwardedHeaders ? self::getString($server, 'HTTP_X_FORWARDED_PROTO') : null;

        if ($forwardedProto !== null) {
            return strtolower(trim(explode(',', $forwardedProto)[0]));
        }

        $https = self::getString($server, 'HTTPS');

        return $https !== null && strtolower($https) !== 'off' ? 'https' : 'http';
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private function getClientIp(array $server): ?string
    {
        $forwardedFor = $this->trustForwardedHeaders ? self::getString($server, 'HTTP_X_FORWARDED_FOR') : null;

        if ($forwardedFor !== null) {
            // The first address is the original client
            $first = trim(explode(',', $forwardedFor)[0]);

            if ($first !== '') {
                return $first;
            }
        }

        return self::getString($server, 'REMOTE_ADDR');
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function getString(array $server, string $key): ?string
    {
        $value = $server[$key] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
