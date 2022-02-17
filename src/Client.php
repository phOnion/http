<?php

declare(strict_types=1);

namespace Onion\Framework\Http;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Onion\Framework\Client\Client as RawClient;
use Onion\Framework\Client\Contexts\SecureContext;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

use function Onion\Framework\Client\gethostbyname;
use function Onion\Framework\Loop\tick;

class Client implements ClientInterface
{
    public static function send(RequestInterface $request): ResourceInterface
    {
        $components = parse_url((string) $request->getUri());
        $secure = $request->getUri()->getScheme() === 'https';
        $port = $components['port'] ?? $secure ? 443 : 80;
        $contexts = [];
        if ($secure) {
            $ctx = new SecureContext();
            $ctx->setSniEnable(true);
            $ctx->setPeerName($components['host']);
            $ctx->setSniServerName($components['host']);

            $contexts[] = $ctx;
        }

        return RawClient::send(
            sprintf(
                '%s://%s:%d',
                $secure ? 'tls' : 'tcp',
                gethostbyname($components['host']),
                $port
            ),
            Message::toString($request),
            contexts: $contexts
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $stream = static::send($request);

        $messageHeaders = '';

        /*
         * ensure we encounter the header delimiter
         */
        while (!$stream->eof()) {
            $messageHeaders .= $stream->read(1);
            tick();

            if (preg_match('/\r?\n\r?\n$/i', $messageHeaders) === 1) {
                break;
            }
        }


        $headers = Message::parseResponse($messageHeaders);

        if ($headers->getStatusCode() >= 300 && $headers->getStatusCode() <= 399 && $headers->hasHeader('Location')) {
            return $this->sendRequest(
                $request->withUri(
                    Uri::fromParts(parse_url($headers->getHeaderLine('location'))),
                ),
            );
        }

        if ($headers->hasHeader('transfer-encoding') && $headers->getHeaderLine('transfer-encoding') === 'chunked') {
            $body = '';
            do {
                $body .= $stream->read(8192);
                tick();
            } while (preg_match('/^0\r?\n\r?\n$/im', $body) === 0);

            $headers = $headers->withBody(
                $this->processChunkedMessage($body)
            );
        } else if ($headers->hasHeader('content-length')) {
            $body =  Utils::streamFor();

            while ($body->getSize() < (int) $headers->getHeaderLine('content-length')) {
                $body->write($stream->read(8192));
                tick();
            }

            $headers = $headers->withBody($body);
        }

        return $headers;
    }

    private function processChunkedMessage(string $content): StreamInterface
    {
        $body = Utils::streamFor('');

        for (; !empty($content); $content = trim($content)) {
            $pos = stripos($content, "\r\n");
            $len = hexdec(substr($content, 0, $pos));
            $body->write(substr($content, $pos + 2, $len));
            $content = substr($content, $pos + 2 + $len);

            tick();
        }

        return $body;
    }
}
