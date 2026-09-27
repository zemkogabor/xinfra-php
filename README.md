# xinfra-php

PHP client for **xInfra**, a log collection and monitoring app. It sends log events and
errors to the xInfra ingest API.

- No dependencies besides the `curl` and `json` extensions
- Events are buffered and sent in batches, at the latest when the PHP process ends
- Sending never breaks your app: the timeout is short and every error is swallowed
- Optional global error handlers for apps without a framework
- Optional [Monolog](https://github.com/Seldaek/monolog) handler

Using Laravel? Use [xinfra-laravel](https://github.com/zemkogabor/xinfra-laravel), it is built on this package.

## Requirements

- PHP 8.3 or newer
- `ext-curl`, `ext-json`
- `monolog/monolog` 3.x, only for the Monolog handler

## Installation

```bash
composer require zemkogabor/xinfra-php
```

## Configuration

`Client::fromEnvironment()` reads these environment variables:

| Variable | Required | Description |
| --- | --- | --- |
| `XINFRA_INGEST_URL` | yes | The ingest endpoint, e.g. `https://xinfra.example.com/api/ingest/events` |
| `XINFRA_INGEST_KEY` | yes | The key of the log client |
| `XINFRA_ENVIRONMENT` | yes | Environment name, e.g. `prod`. It must exist on the project in xInfra. |
| `XINFRA_LOG_LEVEL` | no | Minimum level to send. Default: `warning` |
| `XINFRA_RELEASE` | no | Version of your app, e.g. a git tag |

If the URL or the key is empty, sending is turned off and the client does nothing.
This is handy in development and tests. If they are set, the environment is required too.

You can also create the config in code:

```php
use XInfra\Client;
use XInfra\Config;
use XInfra\Level;
use XInfra\Processor\GlobalRequestProcessor;

$client = new Client(
    new Config(
        url: 'https://xinfra.example.com/api/ingest/events',
        key: 'your-key',
        environment: 'prod',
        minLevel: Level::Warning,
        release: 'v1.4.0',
        server: null,        // default: the hostname
        maxBatchSize: 50,    // events per request, at most 100
        timeout: 2.0,        // seconds, for connecting and for the whole request
    ),
    processors: [new GlobalRequestProcessor()],
);
```

## Usage

### Plain PHP

```php
use XInfra\Client;
use XInfra\ErrorHandler;

$client = Client::fromEnvironment();

// Uncaught exceptions, PHP warnings/notices and fatal errors
ErrorHandler::register($client);

// Your own events
$client->captureMessage('warning', 'Stock sync was slow', ['seconds' => 42]);
$client->captureException($exception);
$client->captureException($exception, 'critical', 'Payment failed', ['orderId' => 42]);
```

`ErrorHandler` keeps the normal PHP behaviour: handlers registered before it are still called,
and without them PHP prints and logs errors as usual. Errors silenced with `@` are not sent.

If your app already has its own error handler, call the client from it instead:

```php
set_exception_handler(function (Throwable $e) use ($client): void {
    $client->captureException($e, 'error', 'Uncaught ' . $e::class . ': ' . $e->getMessage());
    // ... your own handling
});

set_error_handler(function (int $level, string $message, string $file, int $line) use ($client): bool {
    $client->captureError('warning', 'Warning: ' . $message, $file, $line);
    // ... your own handling
    return false;
});
```

### Monolog

```php
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use XInfra\Client;
use XInfra\Monolog\XInfraHandler;

$logger = new Logger('app', [new XInfraHandler(Client::fromEnvironment())], [new PsrLogMessageProcessor()]);

$logger->error('Import of {file} failed', ['file' => 'products.csv', 'exception' => $e]);
```

- A `Throwable` in the `exception` context key is sent as the exception of the event.
- Tags come from `$record->extra['tags']`, so you can add them with a processor:

```php
$logger->pushProcessor(function (LogRecord $record): LogRecord {
    $record->extra['tags'] = ['tenant' => (string) $tenantId];

    return $record;
});
```

### Long running processes

Buffered events are sent when a batch is full or when the process ends. A queue worker or a
daemon may run for days, so call `flush()` after each job, otherwise rare errors wait in memory:

```php
$client->flush();
```

### Processors

Processors can add data to every event, or drop an event by returning `null`:

```php
use XInfra\Event;
use XInfra\EventProcessor;

final class TenantProcessor implements EventProcessor
{
    public function process(Event $event): ?Event
    {
        $event->tags['tenant'] = (string) current_tenant_id();

        return $event;
    }
}

$client->addProcessor(new TenantProcessor());
```

`GlobalRequestProcessor` adds the URL, method, client IP and user agent of the current HTTP
request from `$_SERVER`. It reads `X-Forwarded-For` and `X-Forwarded-Proto` by default. If your
app is not behind a proxy, turn this off with `new GlobalRequestProcessor(trustForwardedHeaders: false)`.

## Event fields

| Field | Description |
| --- | --- |
| `level`, `message` | PSR-3 level and the message |
| `environment`, `release`, `server` | From the config |
| `timestamp` | Time of the event |
| `logger` | Name of the component. Events without an exception are grouped by logger and message. |
| `fingerprint` | Custom grouping key |
| `context` | Any extra data |
| `tags` | Short key-value pairs for filtering |
| `exception` | Type, message, file, line and stack trace |
| `request`, `ip`, `userAgent`, `user` | The HTTP request and the user |

Sensitive data (passwords, tokens) is masked and long fields are cut on the server side.

## Development

```bash
composer install
composer check   # code style, static analysis, tests
```

## License

MIT
