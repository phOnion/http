<?php

use Onion\Framework\Http\Client;

use Nyholm\Psr7\Request;
use Onion\Framework\Loop\Scheduler\Select;

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\scheduler;


require_once __DIR__ . '/../vendor/autoload.php';

scheduler(new Select());
scheduler()->addErrorHandler(fn ($e) => var_dump("{$e->getMessage()} ({$e->getFile()}:{$e->getLine()})\n{$e->getTraceAsString()}"));
coroutine(function () {
    $client = new Client();
    $response = $client->sendRequest(new Request(
        'GET',
        'https://cloudflare.com/',
        ['accept' => ['text/html']]
    ));

    printf("Received %d bytes", $response->getBody()->getSize());
});
// scheduler()->start();
