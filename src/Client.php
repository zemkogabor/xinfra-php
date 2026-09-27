<?php

declare(strict_types=1);

namespace XInfra;

use Throwable;
use XInfra\Processor\GlobalRequestProcessor;
use XInfra\Transport\CurlTransport;
use XInfra\Transport\Transport;

/**
 * Collects events and sends them to the ingest API in batches.
 *
 * Events are buffered and sent when the batch is full, when flush() is called,
 * or at the end of the PHP process. Sending never throws and never breaks the app:
 * the timeout is short and every error is swallowed.
 */
final class Client
{
    private readonly Transport $transport;

    /** @var list<EventProcessor> */
    private array $processors;

    /** @var list<Event> */
    private array $buffer = [];

    private bool $isFlushing = false;

    private bool $isShutdownFlushRegistered = false;

    /**
     * @param list<EventProcessor> $processors
     */
    public function __construct(
        private readonly Config $config,
        ?Transport $transport = null,
        array $processors = [],
    ) {
        $this->transport = $transport ?? new CurlTransport();
        $this->processors = $processors;
    }

    /**
     * A client configured from the XINFRA_* environment variables,
     * with request data collected from the PHP superglobals.
     */
    public static function fromEnvironment(): self
    {
        return new self(Config::fromEnvironment(), null, [new GlobalRequestProcessor()]);
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function addProcessor(EventProcessor $processor): void
    {
        $this->processors[] = $processor;
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function captureMessage(Level|string $level, string $message, array $context = []): void
    {
        $this->capture(new Event(Level::fromName($level), $message, $context));
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function captureException(Throwable $exception, Level|string $level = Level::Error, ?string $message = null, array $context = []): void
    {
        $this->capture(Event::fromThrowable($exception, Level::fromName($level), $message, $context));
    }

    /**
     * A PHP error (warning, notice, fatal error) that has no exception object, only a place.
     *
     * @param array<array-key, mixed> $context
     */
    public function captureError(Level|string $level, string $message, string $file, int $line, array $context = []): void
    {
        $event = new Event(Level::fromName($level), $message, $context);
        $event->setErrorLocation($file, $line);
        $this->capture($event);
    }

    public function capture(Event $event): void
    {
        if (!$this->config->isEnabled() || !$event->level->isAtLeast($this->config->minLevel)) {
            return;
        }

        foreach ($this->processors as $processor) {
            $processed = $processor->process($event);

            if ($processed === null) {
                return;
            }

            $event = $processed;
        }

        $this->buffer[] = $event;
        $this->registerShutdownFlush();

        // An error while sending may log again, that event waits for the next flush
        if (!$this->isFlushing && count($this->buffer) >= $this->config->maxBatchSize) {
            $this->flush();
        }
    }

    /**
     * Sends every buffered event. Long running processes (queue workers, daemons)
     * should call this after each unit of work.
     */
    public function flush(): void
    {
        if ($this->isFlushing || count($this->buffer) === 0) {
            return;
        }

        $this->isFlushing = true;

        try {
            while (count($this->buffer) > 0) {
                $batch = array_splice($this->buffer, 0, $this->config->maxBatchSize);
                $this->send($batch);
            }
        } finally {
            $this->isFlushing = false;
        }
    }

    /**
     * Events waiting to be sent.
     *
     * @return list<Event>
     */
    public function getPendingEvents(): array
    {
        return $this->buffer;
    }

    /**
     * Drops the buffered events without sending them.
     */
    public function clear(): void
    {
        $this->buffer = [];
    }

    /**
     * @param list<Event> $events
     */
    private function send(array $events): void
    {
        $url = $this->config->url;
        $key = $this->config->key;

        if ($url === null || $key === null) {
            return;
        }

        try {
            $payloads = array_map(fn(Event $event): array => Payload::fromEvent($event, $this->config), $events);
            $this->transport->send($url, $key, Payload::encode($payloads), $this->config->timeout);
        } catch (Throwable) {
            // Logging must never break the app. It can not log its own error either, that would loop.
        }
    }

    /**
     * Sends the rest of the buffer at the end of the process. It is registered on the first
     * event, so shutdown functions registered earlier (e.g. a fatal error handler) can still add events.
     */
    private function registerShutdownFlush(): void
    {
        if ($this->isShutdownFlushRegistered) {
            return;
        }

        $this->isShutdownFlushRegistered = true;
        register_shutdown_function(function (): void {
            $this->flush();
        });
    }
}
