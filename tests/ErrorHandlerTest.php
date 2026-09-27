<?php

declare(strict_types=1);

namespace XInfra\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The handlers change global PHP state, so they run in a child process.
 */
final class ErrorHandlerTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        $this->outputPath = (string) tempnam(sys_get_temp_dir(), 'xinfra');
    }

    protected function tearDown(): void
    {
        @unlink($this->outputPath);
    }

    public function testUncaughtExceptionIsSentOnceAndPhpStillFails(): void
    {
        $exitCode = $this->runScript('exception');

        $events = $this->readEvents();
        self::assertSame(255, $exitCode);
        self::assertCount(1, $events);
        self::assertSame('critical', $events[0]['level']);
        self::assertSame('Uncaught RuntimeException: Something broke', $events[0]['message']);
        self::assertIsArray($events[0]['exception']);
        self::assertSame('RuntimeException', $events[0]['exception']['type']);
    }

    public function testWarningIsSentAndSilencedErrorsAreSkipped(): void
    {
        $exitCode = $this->runScript('warning');

        $events = $this->readEvents();
        self::assertSame(0, $exitCode);
        self::assertCount(1, $events);
        self::assertSame('warning', $events[0]['level']);
        self::assertSame('User warning: Disk almost full', $events[0]['message']);
        self::assertIsArray($events[0]['exception']);
        self::assertNull($events[0]['exception']['type']);
        self::assertIsString($events[0]['exception']['file']);
        self::assertStringEndsWith('error-handler-script.php', $events[0]['exception']['file']);
    }

    public function testFatalErrorIsSent(): void
    {
        $exitCode = $this->runScript('fatal');

        $events = $this->readEvents();
        self::assertSame(255, $exitCode);
        self::assertCount(1, $events);
        self::assertSame('critical', $events[0]['level']);
        self::assertIsString($events[0]['message']);
        self::assertStringStartsWith('Fatal error: Allowed memory size', $events[0]['message']);
    }

    private function runScript(string $case): int
    {
        $command = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', __DIR__ . '/Fixtures/error-handler-script.php', $this->outputPath, $case];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return proc_close($process);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEvents(): array
    {
        $events = [];
        $lines = file($this->outputPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        foreach ($lines as $line) {
            /** @var array{events: list<array<string, mixed>>} $body */
            $body = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            array_push($events, ...$body['events']);
        }

        return $events;
    }
}
