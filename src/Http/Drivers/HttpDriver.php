<?php
namespace Onion\Framework\Http\Drivers;

use function Onion\Framework\Http\build_request;
use Onion\Framework\Http\Events\RequestEvent;
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
        $socket->unblock();
        while ($socket->isAlive()) {
            yield $socket->wait();
            try {
                $connection = $socket->accept();
            } catch (\InvalidArgumentException $ex) {
                // Accept failed, we ok
                continue;
            }

            $connection->wait();
            $data = '';
            while ($connection->isAlive()) {
                $chunk = $connection->read(8192);
                if (!$chunk) {
                    break;
                }

                $data .= $chunk;
            }

            $request = build_request($data);
            yield $this->dispatcher->dispatch(
                new RequestEvent($request, $connection)
            );
        }
    }
}
