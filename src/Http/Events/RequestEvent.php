<?php
namespace Onion\Framework\Http\Events;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Psr\Http\Message\ServerRequestInterface;

class RequestEvent
{
    private $request;
    private $connection;

    public function __construct(ServerRequestInterface $request, ResourceInterface $connection)
    {
        $this->request = $request;
        $this->connection = $connection;
    }

    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function getConnection(): ResourceInterface
    {
        return $this->connection;
    }
}
