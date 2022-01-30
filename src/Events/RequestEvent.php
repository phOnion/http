<?php

namespace Onion\Framework\Http\Events;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RequestEvent implements StoppableEventInterface
{
    private ?ResponseInterface $response = null;

    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly ResourceInterface $connection,
    ) {
    }

    public function setResponse(ResponseInterface $response)
    {
        $this->response = $response;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    public function isPropagationStopped(): bool
    {
        return $this->response !== null;
    }
}
