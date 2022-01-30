<?php

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Onion\Framework\Event\Dispatcher;
use Onion\Framework\Event\ListenerProviders\AggregateProvider;
use Onion\Framework\Event\ListenerProviders\SimpleProvider;
use Onion\Framework\Http\Events\RequestEvent;
use Onion\Framework\Http\Listeners\HttpMessageListener;
use Onion\Framework\Server\Drivers\NetworkDriver;
use Onion\Framework\Server\Events\MessageEvent;
use Onion\Framework\Server\Server;

use function Onion\Framework\Loop\scheduler;

require_once __DIR__ . '/../vendor/autoload.php';
$provider = new AggregateProvider;
$provider->addProvider(new SimpleProvider([
    MessageEvent::class => [
        function (MessageEvent $ev) use (&$dispatcher) {
            (new HttpMessageListener($dispatcher))($ev);
        }
    ],
    RequestEvent::class => [
        function (RequestEvent $event) {
            $event->setResponse(
                (new Response())->withBody(
                    Utils::streamFor(Message::toString($event->request)),
                ),
            );
        }
    ],
]));

$dispatcher = new Dispatcher($provider);

$server = new Server($dispatcher);
$server->attach(new NetworkDriver($dispatcher), 'tcp://0.0.0.0', 8080);

$server->start();
scheduler()->start();
