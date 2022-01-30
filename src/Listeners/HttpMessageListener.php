<?php

namespace Onion\Framework\Http\Listeners;

use GuzzleHttp\Psr7\Message;
use Onion\Framework\Server\Events\MessageEvent;
use Onion\Framework\Http\Events\RequestEvent;
use Onion\Framework\Loop\Types\Operation;
use Psr\EventDispatcher\EventDispatcherInterface;

use function Onion\Framework\Loop\tick;
use function Onion\Framework\Http\build_request;

class HttpMessageListener
{
    public function __construct(private readonly EventDispatcherInterface $dispatcher)
    {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = '';
        $connection = $event->connection;
        while (!$connection->eof() && $chunk = $connection->read(1024)) {
            $message .= $chunk;
            tick();
        }

        if (strlen($message) !== 0) {
            /** @var RequestEvent $event */
            $event = $this->dispatcher->dispatch(
                new RequestEvent(
                    build_request($message),
                    $connection,
                ),
            );

            $connection->wait(Operation::WRITE);
            $connection->write(Message::toString($event->getResponse()));
            $connection->close();
        }
    }
}
