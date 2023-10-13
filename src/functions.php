<?php
declare(strict_types= 1);

namespace Onion\Framework\Http;

use Nyholm\Psr7\{Stream, UploadedFile, Response};
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Onion\Framework\Http\Resources\DecodingBuffer;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface, ServerRequestInterface};

use function Onion\Framework\Loop\{suspend, read, write};


if (!function_exists(__NAMESPACE__ . '\build_message')) {
    function build_message(ResourceInterface $connection, bool $withBody = true): ResponseInterface|RequestInterface|ServerRequestInterface
    {
        $body = new \Onion\Framework\Loop\Resources\Buffer();
        $content = read($connection, function (ResourceInterface $connection) use ($body) {
            $headers = '';
            do {
                $chunk = $connection->read(4096);

                if (preg_match('/\r?\n\r?\n/im', (string) $chunk, $matches, PREG_OFFSET_CAPTURE)) {
                    $headers .= substr($chunk, 0, $matches[0][1] + 2);
                    $body->write(substr($chunk, $matches[0][1] + strlen($matches[0][0])));
                    break;
                } else {
                    $headers .= $chunk;
                }
                suspend();
            } while (true);

            return $headers;
        });

        $expectBody = false;

        if (preg_match(
            '/^(?:HTTP\/|^[A-Z]+ \S+ HTTP\/)(?P<version>\d+(?:\.\d+)?)[ \t]+' .
                '(?P<code>[0-9]{3})[ \t]+(?P<reason>.*)\r?\n/i',
            $content,
            $matches
        ) === 1) {
            [,$version, $status, $reason] = $matches;

            $message = new Response(
                status: (int) $status,
                version: $version,
                reason: $reason,
            );

            $expectBody = match ($message->getStatusCode()) {
                200, 201, 204, 401, 403, 404, 500, 503 => true,
                default => false,
            };
        } elseif (preg_match(
            '/^(?P<method>[A-Z]+)[ \t]+(?P<path>.*)[ \t]+' .
                '(?:HTTP\/|^[A-Z]+ \S+ HTTP\/)(?P<version>\d+(?:\.\d+)?)\r?\n/i',
            $content,
            $matches
        )) {
            $factory ??= new Psr17Factory();
            $creator ??= new ServerRequestCreator($factory, $factory, $factory, $factory);

            [,$method, $path, $version] = $matches;
            [$path, $query] = explode('?', $path . '?' , 3);
            parse_str($query, $queryParams);

            $message = $creator->fromArrays([
                'SERVER_PROTOCOL' => "HTTP/{$version}",
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $path,
                'QUERY_STRING' => $query,
            ], get: $queryParams);

            $expectBody = match ($message->getMethod()) {
                'post', 'put', 'patch' => true,
                default => false,
            };
        } else {
            throw new \RuntimeException(
                'Connection is in invalid state. Unable to create PSR-7 Message'
            );
        }

        preg_match_all(
            '/^(?P<name>[^()<>@,;:\\\"\/[\]?={}\x01-\x20\x7F]++):[ \t]*+' .
                '(?P<value>(?:[ \t]*+[\x21-\x7E\x80-\xFF]++)*+)[ \t]*+\r?\n/m',
            $content,
            $headerLines,
            PREG_SET_ORDER,
        );

        foreach ($headerLines as $headerLine) {
            $headerLine['name'] = strtolower($headerLine['name']);

            $message = $message->withAddedHeader($headerLine['name'], $headerLine['value']);
        }

        if ($withBody) {
            if ($expectBody || $message->hasHeader('transfer-encoding') || $message->hasHeader('content-encoding')) {
                $expectBody = true;
                $decoder = new DecodingBuffer(
                    array_filter(array_unique(
                        array_map(
                            trim(...),
                            explode(
                                ',',
                                "{$message->getHeaderLine('transfer-encoding')}, {$message->getHeaderLine('content-encoding')}"
                            )
                        )
                    ))
                );

                $decoder->write((string) $body);
                $body = $decoder;
            }

            $bodySize = INF;
            if ($message->hasHeader('content-length')) {
                $expectBody = true;
                $bodySize = (int) $message->getHeader('content-length');
            }

            if ($expectBody && $withBody) {
                read($connection, function (ResourceInterface $connection) use ($body, $bodySize) {
                    do {
                        // read by chunks
                        $body->write($chunk = $connection->read(4096));
                        suspend();
                    } while (
                        $body->size() < $bodySize &&
                        preg_match('/\r?\n\r?\n$/i', $chunk) !== 1 &&
                        !$connection->eof()
                    );

                    return $body;
                });

                $message = $message->withBody(Stream::create((string) $body));
            }
        }

        if ($message instanceof ServerRequestInterface) {
            if ($withBody && $message->getBody()->getSize() > 0) {
                $contentType = $message->getHeaderLine('content-type');
                $pattern = '/^multipart\/form-data; boundary=(?P<boundary>.*)$/i';
                if (preg_match($pattern, $contentType, $matches)) {
                    $message = extract_multipart($message, $message->getBody()->getContents(), $matches['boundary']);
                } elseif (
                    preg_match('/^application\/x-www-form-urlencoded/', $contentType, $matches)
                ) {
                    parse_str($message->getBody()->getContents(), $parsedBody);
                    $message = $message->withParsedBody(array_map(urldecode(...), $parsedBody));
                } elseif ($message->getBody()->getSize() <= 8192 && preg_match('/^application\/(.*\+)json/', $contentType)) {
                    // Do not decode larger than 8kb to prevent OOM issues
                    $message = $message->withParsedBody(json_decode($message->getBody()->getContents(), true) ?? []);
                }

                $message = $message->withBody(Stream::create((string) $body));
            }

            $cookies = [];
            foreach ($message->getHeader('cookie') as $rawValue) {
                foreach (explode('; ', $rawValue) as $line) {
                    list($cookie, $value) = explode('=', $line, 2);
                    if (isset($cookies[$cookie])) {
                        if (!is_array($cookies[$cookie])) {
                            $cookies[$cookie] = [$cookies[$cookie]];
                        }

                        $cookies[$cookie][] = $value;
                    } else {
                        $cookies[$cookie] = $value;
                    }
                }
            }

            $message = $message->withCookieParams($cookies);
        }

        return $message;
    }
}

if (!function_exists(__NAMESPACE__ . '\read_request')) {
    /**
     * @deprecated
     * @see \Onion\Framework\Http\build_message
     */
    function read_request(ResourceInterface $connection): ServerRequestInterface
    {
        return build_message($connection);
    }
}

if (!function_exists(__NAMESPACE__ . '\read_response')) {
    /**
     * @deprecated
     * @see \Onion\Framework\Http\build_message
     */
    function read_response(ResourceInterface $resource): ResponseInterface
    {
        return build_message($resource);
    }
}

if (!function_exists(__NAMESPACE__ . '\extract_multipart')) {
    function extract_multipart(ServerRequestInterface $request, string $body, string $boundary): ServerRequestInterface
    {
        static $factory;
        $factory ??= new Psr17Factory();

        $parts = explode('--' . $boundary, $body);
        $files = [];
        $parsed = [];

        foreach ($parts as $part) {
            $sections = explode("\r\n\r\n", $part, 2);

            $mediaType = 'application/octet-stream';
            $filename = null;
            $name = null;

            foreach (explode("\r\n", $sections[0]) as $header) {
                if (preg_match('/^(?P<name>[a-z0-9-_]+):\s?(?P<value>.+)$/im', $header, $matches)) {
                    if ($matches['name'] === 'Content-Disposition') {
                        preg_match(
                            '/form-data; name=\"(?P<name>[^"]+)\"(?:; filename\*?=\"(?P<filename>[^"]+)\")?/',
                            $matches['value'],
                            $names
                        );

                        if (isset($names['filename']) && $names['filename'] !== '') {
                            $filename = urldecode($names['filename']);
                        }

                        if (isset($names['name']) && $names['name'] !== '') {
                            $name = urldecode($names['name']);
                        }
                    } else if ($matches['name'] === 'Content-Type') {
                        $mediaType = $matches['value'];
                    }
                }

                suspend();
            }

            if ($filename === null && $name !== null) {
                $parsed[$name] = $sections[1] ?? '';
            } else {
                $stream = Stream::create($sections[1] ?? '');
                $files[] = new UploadedFile(
                    $stream,
                    $stream->getSize(),
                    UPLOAD_ERR_OK,
                    $filename,
                    $mediaType
                );
            }

            suspend();
        }

        return $request->withParsedBody($parsed)
            ->withUploadedFiles($files);
    }
}

if (!function_exists(__NAMESPACE__ . '\stringify_message')) {
    function stringify_message(\Psr\Http\Message\MessageInterface $message, bool $withBody = true): ResourceInterface
    {
        $buffer = new \Onion\Framework\Loop\Resources\Buffer();

        if ($message instanceof \Psr\Http\Message\RequestInterface) {
            $path = trim("{$message->getUri()->getPath()}?{$message->getUri()->getQuery()}", '?');
            write($buffer, "{$message->getMethod()} {$path} HTTP/{$message->getProtocolVersion()}\r\n");
        } elseif ($message instanceof \Psr\Http\Message\ResponseInterface) {
            write($buffer, "HTTP/{$message->getProtocolVersion()} {$message->getStatusCode()} {$message->getReasonPhrase()}\r\n");
        }

        foreach ($message->getHeaders() as $name => $headers) {
            foreach ($headers as $header) {
                write($buffer, "{$name}: {$header}\r\n");
            }
        }

        write($buffer, "\r\n");

        if (!$withBody || $message->getBody()->getSize() === 0) {
            return $buffer;
        }

        write($buffer, $message->getBody()->getContents());
        write($buffer, "\r\n\r\n");

        return $buffer;
    }
}
