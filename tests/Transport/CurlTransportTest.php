<?php

declare(strict_types=1);

namespace XInfra\Tests\Transport;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XInfra\Transport\CurlTransport;

final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private string $outputPath;

    private int $port;

    protected function setUp(): void
    {
        $this->outputPath = (string) tempnam(sys_get_temp_dir(), 'xinfra');
        $this->port = $this->findFreePort();
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/../Fixtures/ingest-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['XINFRA_TEST_OUTPUT' => $this->outputPath],
        );
        self::assertIsResource($server);
        $this->server = $server;
        $this->waitForServer();
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        @unlink($this->outputPath);
    }

    public function testPostsTheBodyWithTheKey(): void
    {
        (new CurlTransport())->send('http://127.0.0.1:' . $this->port . '/api/ingest/events', 'secret', '{"events":[]}', 2.0);

        $received = json_decode((string) file_get_contents($this->outputPath), true);
        self::assertSame([
            'method' => 'POST',
            'uri' => '/api/ingest/events',
            'key' => 'secret',
            'contentType' => 'application/json',
            'body' => '{"events":[]}',
        ], $received);
    }

    public function testTimeout(): void
    {
        $start = microtime(true);

        try {
            (new CurlTransport())->send('http://127.0.0.1:' . $this->port . '/slow', 'secret', '{"events":[]}', 0.5);
            self::fail('The request should time out.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Sending to xInfra failed', $exception->getMessage());
        }

        self::assertLessThan(2.0, microtime(true) - $start);
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitForServer(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', $this->port);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        self::fail('The test server did not start.');
    }
}
