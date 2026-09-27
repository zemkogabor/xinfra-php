<?php

declare(strict_types=1);

namespace XInfra;

use BackedEnum;
use DateTimeInterface;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Turns events into the JSON body of the ingest API.
 * Context values are made JSON safe here, the server does the truncation and masking.
 */
final class Payload
{
    public const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.vP';

    private const int MAX_DEPTH = 8;

    private const int MAX_ITEMS = 200;

    /**
     * @return array<string, mixed>
     */
    public static function fromEvent(Event $event, Config $config): array
    {
        $payload = [
            'level' => $event->level->value,
            'message' => $event->message,
            'environment' => $config->environment,
            'timestamp' => $event->timestamp->format(self::TIMESTAMP_FORMAT),
            'server' => $event->server ?? $config->server,
            'release' => $config->release,
            'logger' => $event->logger,
            'fingerprint' => $event->fingerprint,
            'context' => count($event->context) > 0 ? self::normalize($event->context, 0) : null,
            'tags' => count($event->tags) > 0 ? self::normalizeTags($event->tags) : null,
            'exception' => $event->exception,
            'request' => $event->request !== null ? self::normalize($event->request, 0) : null,
            'user' => $event->user,
            'ip' => $event->ip,
            'userAgent' => $event->userAgent,
        ];

        return array_filter($payload, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Invalid UTF-8 and values that can not be encoded never break the whole batch.
     *
     * @param list<array<string, mixed>> $events
     */
    public static function encode(array $events): string
    {
        return (string) json_encode(
            ['events' => $events],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function normalize(array $data, int $depth): array
    {
        $result = [];
        $count = 0;

        foreach ($data as $key => $value) {
            if ($count >= self::MAX_ITEMS) {
                $result['...'] = 'Over ' . self::MAX_ITEMS . ' items, the rest is left out';
                break;
            }

            $result[$key] = self::normalizeValue($value, $depth + 1);
            $count++;
        }

        return $result;
    }

    private static function normalizeValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_scalar($value)) {
            return is_float($value) && !is_finite($value) ? self::nonFiniteToString($value) : $value;
        }

        if ($depth > self::MAX_DEPTH) {
            return 'Over ' . self::MAX_DEPTH . ' levels, left out';
        }

        if (is_array($value)) {
            return self::normalize($value, $depth);
        }

        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile() . ':' . $value->getLine(),
            ];
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(self::TIMESTAMP_FORMAT);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof JsonSerializable) {
            return self::normalizeValue($value->jsonSerialize(), $depth);
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_object($value)) {
            return ['class' => $value::class];
        }

        return get_debug_type($value);
    }

    /**
     * JSON has no NAN or INF. Casting them to string warns since PHP 8.5.
     */
    private static function nonFiniteToString(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }

        return $value > 0 ? 'INF' : '-INF';
    }

    /**
     * @param array<string, string|int|float|bool> $tags
     * @return array<string, string>
     */
    private static function normalizeTags(array $tags): array
    {
        $result = [];

        foreach ($tags as $key => $value) {
            $result[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $result;
    }
}
