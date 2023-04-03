<?php

namespace Onion\Framework\Http\Listeners;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use Onion\Framework\Server\Events\MessageEvent;
use Onion\Framework\Http\Events\RequestEvent;
use Onion\Framework\Loop\Types\Operation;
use Psr\EventDispatcher\EventDispatcherInterface;

use function Onion\Framework\Http\build_request;
use function Onion\Framework\Loop\tick;

class HttpMessageListener
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = '';
        $connection = $event->connection;
        while (!$connection->eof() && $chunk = $connection->read(8192)) {
            $message .= $chunk;
            tick();
        }

        if (strlen($message) !== 0) {
            $request = build_request($message);
            /** @var RequestEvent $event */
            $event = $this->dispatcher->dispatch(
                new RequestEvent(
                    $request,
                    $connection,
                ),
            );

            if (!$connection->eof()) {
                $connection->wait(Operation::WRITE);
                $connection->write(Message::toString(
                    ($event->getResponse() ?? new Response(status: 204))
                    ->withAddedHeader(
                        'Connection',
                        $request->hasHeader('Connection') ? $request->getHeaderLine('Connection') : 'close'
                    )->withAddedHeader(
                        'Content-Length',
                        $event->getResponse()->getBody()->getSize()
                    )
                ));
            } else {
                $connection->close();
            }
        }
    }
}
