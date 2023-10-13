<?php

declare(strict_types=1);

namespace Onion\Framework\Http;

use \Nyholm\Psr7\Factory\Psr17Factory;
use Onion\Framework\Client\Client as RawClient;
use Onion\Framework\Client\Contexts\SecureContext;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};

use function Onion\Framework\Client\gethostbyname;
use function Onion\Framework\Loop\pipe;

class Client implements ClientInterface
{
    public function __construct(
        private ?Psr17Factory $factory = null,
    ) {
        $this->factory ??= new Psr17Factory();
    }
    public function send(RequestInterface $request): ResourceInterface
    {
        $uri = $request->getUri();
        $secure = $request->getUri()->getScheme() === 'https';
        $port = $uri->getPort() ?? ($secure ? 443 : 80);
        $contexts = [];
        if ($secure) {
            $ctx = new SecureContext();
            $ctx->setSniEnable(true);
            $ctx->setVerifyPeer(true);
            $ctx->setVerifyPeerName(true);
            $ctx->setVerifyDepth(9);
            $ctx->setPeerName($uri->getHost());
            $ctx->setSniServerName($uri->getHost());

            $contexts[] = $ctx;
        }

        $client = RawClient::connect(
            sprintf(
                '%s://%s:%d',
                'tcp',
                \gethostbyname($uri->getHost()),
                $port
            ),
            ...$contexts
        );

        $client->write((string) stringify_message(
            $request->withHeader('Connection', 'close')
                ->withHeader('Accept-Encoding', 'gzip, deflate'),
        ));

        return $client;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $connection = $this->send($request);
        $message = read_response($connection);
        $connection->close();

        if (
            $message->getStatusCode() >= 300 &&
            $message->getStatusCode() <= 399 &&
            $message->hasHeader('Location')
        ) {
            $redirect = $this->factory->createUri($message->getHeaderLine('location'));
            $uri = $request->getUri();

            if ($redirect->getHost() === 0) {
                $redirect = $redirect->withPath($uri->getPath())
                    ->withQuery($uri->getQuery())
                    ->withFragment($uri->getFragment());
            }

            return $this->sendRequest(
                $request
                    ->withMethod(match($message->getStatusCode()) {
                        303 => 'GET',
                        default => $request->getMethod(),
                    })
                    ->withUri($redirect),
            );
        }

        return $message;
    }
}
