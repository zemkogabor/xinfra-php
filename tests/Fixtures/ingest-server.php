<?php

declare(strict_types=1);

// Router for the built-in PHP server used by CurlTransportTest. Stores what it receives.

$outputPath = (string) getenv('XINFRA_TEST_OUTPUT');

if (($_SERVER['REQUEST_URI'] ?? '') === '/slow') {
    sleep(3);
}

file_put_contents($outputPath, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'uri' => $_SERVER['REQUEST_URI'] ?? null,
    'key' => $_SERVER['HTTP_X_XINFRA_KEY'] ?? null,
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
    'body' => file_get_contents('php://input'),
]));

header('Content-Type: application/json');
echo '{"accepted":1,"dropped":0,"reason":null}';
