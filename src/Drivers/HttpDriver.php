<?php
declare(strict_types=1);
namespace Onion\Framework\Http\Drivers;

use Closure;
use \Nyholm\Psr7\Factory\Psr17Factory;
use \Nyholm\Psr7Server\ServerRequestCreator;
use Onion\Framework\Loop\Interfaces\{TaskInterface, SchedulerInterface, ResourceInterface};
use \Onion\Framework\Loop\Types\{NetworkProtocol, NetworkAddress};
use \Onion\Framework\Server\Contexts\AggregateContext;
use Onion\Framework\Server\Interfaces\DriverInterface;
use Onion\Framework\Server\Interfaces\ContextInterface;

use function Onion\Framework\Loop\{signal, pipe};
use function Onion\Framework\Http\{build_request, stringify_message};

class HttpDriver implements DriverInterface
{

    private readonly ?ContextInterface $ctx;
    private readonly Psr17Factory $factory;
    private readonly ServerRequestCreator $creator;

    public function __construct(
        public readonly string $address,
        public readonly int $port = -1,
        ContextInterface ...$contexts,
    ) {
        $this->ctx = !empty($contexts) ? new AggregateContext($contexts) : null;
        $this->factory = new Psr17Factory();
        $this->creator = new ServerRequestCreator($this->factory, $this->factory, $this->factory, $this->factory);
    }

	public function listen(Closure $callback): void
    {
        signal(fn (Closure $resume, TaskInterface $task, SchedulerInterface $scheduler) => $resume(
            $scheduler->open($this->address, $this->port, function (ResourceInterface $connection) use ($callback) {
                $chunkingBody = new \Onion\Framework\Http\Resources\StreamWrappingBuffer(
                    new \Onion\Framework\Http\Resources\ChunkingBuffer(),
                );

                /** @var \Psr\Http\Message\ResponseInterface $response */
                $response = $callback(build_request($connection), $chunkingBody);

                $body = $response->getBody();
                if ($body === $chunkingBody) {
                    pipe(stringify_message(
                        $response->withAddedHeader('Transfer-Encoding', 'chunked')
                            ->withAddedHeader('Connection', 'close'),
                        false
                    ), $connection);

                    pipe($body, $connection);
                } else {
                    pipe(stringify_message(
                        $response->withAddedHeader('Content-Length', (string) $response->getBody()->getSize())
                            ->withAddedHeader('Connection', 'close'),
                    ), $connection);
                }
            }, NetworkProtocol::TCP, $this->ctx, match ($this->port) {
                -1 => NetworkAddress::LOCAL,
                default => NetworkAddress::NETWORK,
            }),
        ));
	}
}
