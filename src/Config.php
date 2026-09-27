<?php

declare(strict_types=1);

namespace XInfra;

use InvalidArgumentException;

/**
 * Client settings. Sending is turned off when the URL or the key is empty,
 * so the same code can run in development and tests without network calls.
 */
final class Config
{
    /** The ingest API accepts at most this many events in one request. */
    public const int MAX_BATCH_SIZE_LIMIT = 100;

    public readonly ?string $url;

    public readonly ?string $key;

    public readonly ?string $environment;

    public readonly ?string $release;

    public readonly ?string $server;

    public function __construct(
        ?string $url,
        ?string $key,
        ?string $environment,
        public readonly Level $minLevel = Level::Warning,
        ?string $release = null,
        ?string $server = null,
        public readonly int $maxBatchSize = 50,
        public readonly float $timeout = 2.0,
    ) {
        $this->url = self::nullIfEmpty($url);
        $this->key = self::nullIfEmpty($key);
        $this->environment = self::nullIfEmpty($environment);
        $this->release = self::nullIfEmpty($release);
        $this->server = self::nullIfEmpty($server) ?? self::hostname();

        if ($maxBatchSize < 1 || $maxBatchSize > self::MAX_BATCH_SIZE_LIMIT) {
            throw new InvalidArgumentException('maxBatchSize must be between 1 and ' . self::MAX_BATCH_SIZE_LIMIT . '.');
        }

        if ($timeout <= 0) {
            throw new InvalidArgumentException('timeout must be greater than zero.');
        }

        // The server drops events without an environment, so this would lose every event silently
        if ($this->isEnabled() && $this->environment === null) {
            throw new InvalidArgumentException('The environment is required when sending is enabled.');
        }
    }

    /**
     * Reads the XINFRA_* environment variables.
     */
    public static function fromEnvironment(): self
    {
        $level = self::env('XINFRA_LOG_LEVEL');

        return new self(
            url: self::env('XINFRA_INGEST_URL'),
            key: self::env('XINFRA_INGEST_KEY'),
            environment: self::env('XINFRA_ENVIRONMENT'),
            minLevel: $level !== null ? Level::fromName($level) : Level::Warning,
            release: self::env('XINFRA_RELEASE'),
        );
    }

    public function isEnabled(): bool
    {
        return $this->url !== null && $this->key !== null;
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? self::nullIfEmpty($value) : null;
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private static function hostname(): ?string
    {
        $hostname = gethostname();

        return $hostname !== false && $hostname !== '' ? $hostname : null;
    }
}
