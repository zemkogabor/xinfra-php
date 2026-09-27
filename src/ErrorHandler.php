<?php

declare(strict_types=1);

namespace XInfra;

use Throwable;

/**
 * Optional global handlers for apps without a framework: uncaught exceptions,
 * PHP errors (warnings, notices, deprecations) and fatal errors.
 *
 * The normal PHP behaviour stays the same: handlers set before are still called,
 * and without them PHP prints and logs the errors as usual.
 */
final class ErrorHandler
{
    private const int FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /** @var (callable(Throwable): void)|null */
    private $previousExceptionHandler;

    /** @var (callable(int, string, string, int): mixed)|null */
    private $previousErrorHandler;

    private bool $hasUncaughtException = false;

    private function __construct(private readonly Client $client) {}

    public static function register(Client $client): self
    {
        $handler = new self($client);

        $handler->previousExceptionHandler = set_exception_handler($handler->handleException(...));
        $handler->previousErrorHandler = set_error_handler($handler->handleError(...));
        register_shutdown_function($handler->handleShutdown(...));

        return $handler;
    }

    public function handleException(Throwable $exception): void
    {
        $this->client->captureException($exception, Level::Critical, 'Uncaught ' . $exception::class . ': ' . $exception->getMessage());
        $this->hasUncaughtException = true;

        if ($this->previousExceptionHandler !== null) {
            ($this->previousExceptionHandler)($exception);

            return;
        }

        // Without an earlier handler PHP should report it as usual (output, log, exit code 255)
        throw $exception;
    }

    public function handleError(int $level, string $message, string $file, int $line): bool
    {
        // Errors silenced with @ or turned off in error_reporting are skipped
        if ((error_reporting() & $level) !== 0) {
            $this->client->captureError(self::getLevel($level), self::getLabel($level) . ': ' . $message, $file, $line);
        }

        if ($this->previousErrorHandler !== null) {
            // Only false means "not handled", any other return value counts as handled
            return ($this->previousErrorHandler)($level, $message, $file, $line) !== false;
        }

        // False lets PHP handle the error as usual
        return false;
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && ($error['type'] & self::FATAL_ERRORS) !== 0 && !$this->isRethrownException($error['message'])) {
            $this->client->captureError(Level::Critical, 'Fatal error: ' . $error['message'], $error['file'], $error['line']);
        }

        $this->client->flush();
    }

    /**
     * The exception thrown again in handleException ends as a fatal error, it was already sent.
     */
    private function isRethrownException(string $message): bool
    {
        return $this->hasUncaughtException && str_starts_with($message, 'Uncaught ');
    }

    private static function getLevel(int $level): Level
    {
        return match ($level) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => Level::Warning,
            E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => Level::Notice,
            default => Level::Error,
        };
    }

    private static function getLabel(int $level): string
    {
        return match ($level) {
            E_WARNING => 'Warning',
            E_NOTICE => 'Notice',
            E_USER_ERROR => 'User error',
            E_USER_WARNING => 'User warning',
            E_USER_NOTICE => 'User notice',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User deprecated',
            E_RECOVERABLE_ERROR => 'Recoverable error',
            default => 'Error (' . $level . ')',
        };
    }
}
