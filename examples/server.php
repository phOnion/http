<?php
use function GuzzleHttp\Psr7\str;
use function GuzzleHttp\Psr7\stream_for;
use GuzzleHttp\Psr7\Response;
use Onion\Framework\Event\Dispatcher;
use Onion\Framework\Event\ListenerProviders\AggregateProvider;
use Onion\Framework\Event\ListenerProviders\SimpleProvider;
use Onion\Framework\Http\Drivers\HttpDriver;
use Onion\Framework\Http\Events\RequestEvent;
use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Server\Server;

require_once __DIR__ . '/../vendor/autoload.php';
$provider = new AggregateProvider;
$provider->addProvider(new SimpleProvider([
    RequestEvent::class => [
        function (RequestEvent $event) {
            $data = "Received: \n" . str($event->getRequest());
            $response = new Response(200, [
                'Content-type' => 'text/plain'
            ], stream_for($data));
            $event->getConnection()->write(str($response));
            $event->getConnection()->close();
        }
    ],
]));

$dispatcher = new Dispatcher($provider);
$driver = new HttpDriver($dispatcher);

$server = new Server($dispatcher);
$server->attach($driver, '0.0.0.0', 8080);

$scheduler = new Scheduler;
$scheduler->add($server->start());

$scheduler->start();
