<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Onion\Framework\Http\Drivers\HttpDriver;
use Onion\Framework\Server\Server;
use Psr\Http\Message\ServerRequestInterface;

use function Onion\Framework\Loop\{scheduler, coroutine};
use function Onion\Framework\Http\stringify_message;

require_once __DIR__ . '/../vendor/autoload.php';

scheduler()->addErrorHandler(fn ($e) => var_dump("{$e->getMessage()} ({$e->getFile()}:{$e->getLine()})\n{$e->getTraceAsString()}"));

$server = new Server();
$server->attach(
    new HttpDriver('0.0.0.0', 8080),
    function (ServerRequestInterface $request, \Onion\Framework\Loop\Interfaces\ResourceInterface $buffer) {
        $factory = new Psr17Factory();

        return $factory->createResponse(200)
            ->withBody($factory->createStream(stringify_message($request)));
    }
);

$server->start();
