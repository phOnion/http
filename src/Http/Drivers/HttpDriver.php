<?php
namespace Onion\Framework\Http\Drivers;

use function Onion\Framework\Http\build_request;
use Onion\Framework\Http\Events\RequestEvent;
use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Interfaces\AsyncResourceInterface;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Server\Drivers\DriverTrait;
use Onion\Framework\Server\Interfaces\ContextInterface;
use Onion\Framework\Server\Interfaces\DriverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class HttpDriver implements DriverInterface
{
    protected const SOCKET_FLAGS = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

    private $dispatcher;

    use DriverTrait;

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function getScheme(string $address): string
    {
        return 'tcp';
    }

    public function listen(string $address, ?int $port, ContextInterface ...$contexts): \Generator
    {
        $socket = $this->createSocket($address, $port, $contexts);

        while ($socket->isAlive()) {
            try {
                $connection = yield $socket->accept();

                yield Coroutine::create(function (ResourceInterface $connection, EventDispatcherInterface $dispatcher) {

                    try {
                        /** @var ConnectEvent $event */
                        while ($connection->isAlive()) {
                            yield $connection->wait();

                            $data = '';
                            while (($chunk = $connection->read(8192)) !== '') {
                                $data .= $chunk;
                                yield;
                            }

                            yield $connection->wait(AsyncResourceInterface::OPERATION_WRITE);
                            yield $dispatcher->dispatch(new RequestEvent(build_request($data), $connection));
                        }
                    } catch (\LogicException $ex) {
                        // Probably stream died mid event dispatching
                    }

                }, [$connection, $this->dispatcher]);

            } catch (\Throwable $ex) {
                // Accept failed, we ok
            }

            yield;
        }
    }
}
