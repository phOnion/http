<?php

use GuzzleHttp\Psr7\Request;
use Onion\Framework\Http\Client;

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\scheduler;

require_once __DIR__ . '/../vendor/autoload.php';

coroutine(function () {
    $client = new Client();
    $response = $client->sendRequest(new Request(
        'GET',
        'https://cloudflare.com/',
        ['accept' => ['text/html']]
    ));

    printf("Received %d bytes", $response->getBody()->getSize());
});
scheduler()->start();
