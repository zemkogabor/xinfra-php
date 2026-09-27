<?php

declare(strict_types=1);

namespace XInfra\Monolog;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level as MonologLevel;
use Monolog\LogRecord;
use Throwable;
use XInfra\Client;
use XInfra\Event;
use XInfra\Level;

/**
 * Monolog handler that passes log records to the xInfra client.
 *
 * A Throwable in the "exception" context key becomes the exception of the event.
 * Tags come from the "tags" key of the record's extra data, so processors can add them.
 */
final class XInfraHandler extends AbstractProcessingHandler
{
    /**
     * @param Level|MonologLevel|null $level Defaults to the minimum level of the client
     */
    public function __construct(
        private readonly Client $client,
        Level|MonologLevel|null $level = null,
        bool $bubble = true,
    ) {
        $level ??= $client->getConfig()->minLevel;

        parent::__construct($level instanceof MonologLevel ? $level : MonologLevel::fromName($level->value), $bubble);
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function flush(): void
    {
        $this->client->flush();
    }

    public function close(): void
    {
        $this->client->flush();
        parent::close();
    }

    public function reset(): void
    {
        $this->client->flush();
        parent::reset();
    }

    protected function write(LogRecord $record): void
    {
        $this->client->capture($this->buildEvent($record));
    }

    /**
     * Context objects, including exceptions anywhere in the context, become arrays.
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new NormalizerFormatter(DATE_ATOM);
    }

    private function buildEvent(LogRecord $record): Event
    {
        $formatted = $this->getFormatter()->format($record);
        $context = is_array($formatted) && is_array($formatted['context'] ?? null) ? $formatted['context'] : [];

        // The exception has its own field, no need to repeat it in the context
        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            unset($context['exception']);
        }

        $event = new Event(Level::from($record->level->toPsrLogLevel()), $record->message, $context);
        $event->timestamp = $record->datetime;
        $event->tags = self::getTags($record->extra['tags'] ?? null);

        if ($exception instanceof Throwable) {
            $event->exception = Event::exceptionData($exception);
        }

        return $event;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    private static function getTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        $result = [];

        foreach ($tags as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
