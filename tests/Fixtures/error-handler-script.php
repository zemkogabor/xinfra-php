<?php

declare(strict_types=1);

// Child process for ErrorHandlerTest: registers the handler, then fails in the way given in argv.

use XInfra\Client;
use XInfra\ErrorHandler;
use XInfra\Level;
use XInfra\Tests\Fixtures\FileTransport;
use XInfra\Tests\Fixtures\TestConfig;

require __DIR__ . '/../../vendor/autoload.php';

$client = new Client(TestConfig::enabled(Level::Debug), new FileTransport($argv[1]));
ErrorHandler::register($client);

switch ($argv[2]) {
    case 'exception':
        throw new RuntimeException('Something broke');
    case 'warning':
        trigger_error('Disk almost full', E_USER_WARNING);
        @trigger_error('Silenced', E_USER_WARNING);
        break;
    case 'fatal':
        ini_set('memory_limit', '8M');
        $data = str_repeat('x', 64 * 1024 * 1024);
        break;
}
