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
use function Onion\Framework\Loop\{suspend, pipe, read};

class Client implements ClientInterface
{
    private const GREETING_REGEX = '/^(?:HTTP\/|^[A-Z]+ \S+ HTTP\/)(?P<version>\d+(?:\.\d+)?)[ \t]+(?P<code>[0-9]{3})[ \t]+(?P<reason>.*)\r?\n/i';
    private const HEADERS_REGEX = '/^(?P<name>[^()<>@,;:\\\"\/[\]?={}\x01-\x20\x7F]++):[ \t]*+(?P<value>(?:[ \t]*+[\x21-\x7E\x80-\xFF]++)*+)[ \t]*+\r?\n/m';

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

        pipe(stringify_message(
            $request->withHeader('Connection', 'close')
                ->withHeader('Accept-Encoding', 'gzip, deflate'),
        ), $client);

        return $client;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $connection = $this->send($request);

        $content = read($connection, function (ResourceInterface $connection) {
            $buffer = '';
            while (preg_match('/\r?\n\r?\n$/i', substr($buffer, -4, 4)) !== 1) {
                $buffer .= $connection->read(1);
                suspend();
            }

            return $buffer;
        });
        $code = 200;
        $reason = 'OK';
        $version = '1.1';

        if (preg_match(self::GREETING_REGEX, $content, $matches) === 1) {
            [,$version, $code, $reason] = $matches;
        }

        $message = $this->factory->createResponse((int) $code, $reason)
            ->withProtocolVersion($version);


        preg_match_all(self::HEADERS_REGEX, $content, $headerLines, PREG_SET_ORDER);
        foreach ($headerLines as $headerLine) {
            $message = $message->withAddedHeader(strtolower($headerLine['name']), $headerLine['value']);
        }

        if ($message->getStatusCode() >= 300 && $message->getStatusCode() <= 399 && $message->hasHeader('Location')) {
            $connection->close();
            return $this->sendRequest(
                $request->withUri(
                    $this->factory->createUri($message->getHeaderLine('location')),
                ),
            );
        }


        $buffer = new \Onion\Framework\Loop\Resources\Buffer();


        if ($message->hasHeader('transfer-encoding')) {
            $buffer = new \Onion\Framework\Http\Resources\DecodingBuffer(
                array_unique(array_map(trim(...), explode(',', "{$message->getHeaderLine('transfer-encoding')}, {$message->getHeaderLine('content-encoding')}")))
            );
        }

        if ($message->hasHeader('content-length')) {
            read($connection, function (ResourceInterface $connection) use ($message, $buffer) {
                $total = (int) $message->getHeaderLine('content-length');
                while ($buffer->size() < $total) {
                    $buffer->write($connection->read($total - strlen($buffer)));
                    suspend();
                }

                return $buffer;
            });
        } else {
            read($connection, function (ResourceInterface $connection) use ($buffer) {
                do {
                    // read by chunks
                    $buffer->write($chunk = $connection->read(4096));
                    suspend();
                } while (preg_match('/\r?\n\r?\n$/i', substr($chunk, -4, 4)) !== 1 && !$connection->eof());

                return $buffer;
            });
        }
        $connection->close();


        $message = $message->withBody($this->factory->createStream((string) $buffer));

        return $message;
    }
}
