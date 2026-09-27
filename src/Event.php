<?php

declare(strict_types=1);

namespace XInfra;

use DateTimeImmutable;
use Throwable;

/**
 * One log event. Empty fields are left out of the payload.
 *
 * @phpstan-type ExceptionData array{type: string|null, message: string, file: string|null, line: int|null, stacktrace: string|null}
 * @phpstan-type RequestData array{url?: string, method?: string, headers?: array<string, mixed>, data?: array<string, mixed>}
 * @phpstan-type UserData array{id?: string, name?: string}
 */
final class Event
{
    public DateTimeImmutable $timestamp;

    /** Name of the component that wrote the log. Used for grouping events without an exception. */
    public ?string $logger = null;

    /** Custom grouping key. Events with the same fingerprint end up in the same issue. */
    public ?string $fingerprint = null;

    /** @var array<array-key, mixed> */
    public array $context = [];

    /** @var array<string, string|int|float|bool> */
    public array $tags = [];

    /** @var ExceptionData|null */
    public ?array $exception = null;

    /** @var RequestData|null */
    public ?array $request = null;

    /** @var UserData|null */
    public ?array $user = null;

    public ?string $ip = null;

    public ?string $userAgent = null;

    /** Overrides the server name from the config. */
    public ?string $server = null;

    /**
     * @param array<array-key, mixed> $context
     */
    public function __construct(
        public Level $level,
        public string $message,
        array $context = [],
    ) {
        $this->context = $context;
        $this->timestamp = new DateTimeImmutable();
    }

    /**
     * An event for an exception. The message defaults to the exception message.
     *
     * @param array<array-key, mixed> $context
     */
    public static function fromThrowable(Throwable $exception, Level $level = Level::Error, ?string $message = null, array $context = []): self
    {
        $event = new self($level, $message ?? $exception->getMessage(), $context);
        $event->exception = self::exceptionData($exception);

        return $event;
    }

    /**
     * @return ExceptionData
     */
    public static function exceptionData(Throwable $exception): array
    {
        return [
            'type' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'stacktrace' => $exception->getTraceAsString(),
        ];
    }

    /**
     * Marks the place of a PHP error (warning, fatal error) that has no exception object.
     */
    public function setErrorLocation(string $file, int $line): self
    {
        $this->exception = [
            'type' => null,
            'message' => $this->message,
            'file' => $file,
            'line' => $line,
            'stacktrace' => null,
        ];

        return $this;
    }
}
