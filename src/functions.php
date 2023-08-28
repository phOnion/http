<?php

namespace Onion\Framework\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\{Stream, UploadedFile};
use Nyholm\Psr7Server\ServerRequestCreator;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Onion\Framework\Loop\{suspend, read, write};

if (!function_exists(__NAMESPACE__ . '\build_request')) {
    function build_request(ResourceInterface $connection): ServerRequestInterface
    {
        static $factory;
        static $creator;

        $factory ??= new Psr17Factory();
        $creator ??= new ServerRequestCreator($factory, $factory, $factory, $factory);

        $body = new \Onion\Framework\Loop\Resources\Buffer();
        $message = read($connection, function (ResourceInterface $connection) use ($body) {
            $headers = '';
            do {
                $chunk = $connection->read(4096);

                if (preg_match('/(\r?\n\r?\n)/im', $chunk, $matches, PREG_OFFSET_CAPTURE)) {
                    $headers .= substr($chunk, 0, $matches[1][1]);
                    $body->write(substr($chunk, $matches[1][1] + strlen($matches[1][0])));
                    break;
                } else {
                    $headers .= $chunk;
                }
                suspend();
            } while (true);

            return $headers;
        });

        $method = $path = $version = null;
        $headers = [];

        if (preg_match(
            '/^(?P<method>[A-Z]+)[ \t]+(?P<path>.*)[ \t]+' .
                '(?:HTTP\/|^[A-Z]+ \S+ HTTP\/)(?P<version>\d+(?:\.\d+)?)\r?\n/i',
            $message,
            $matches
        )) {
            [,$method, $path, $version] = $matches;
        }
        preg_match_all(
            '/^(?P<name>[^()<>@,;:\\\"\/[\]?={}\x01-\x20\x7F]++):[ \t]*+' .
                '(?P<value>(?:[ \t]*+[\x21-\x7E\x80-\xFF]++)*+)[ \t]*+\r?\n/m',
            $message,
            $headerLines,
            PREG_SET_ORDER,
        );

        $cookies = [];
        foreach ($headerLines as $headerLine) {
            $headerLine['name'] = strtolower($headerLine['name']);
            if (!isset($headers[$headerLine['name']])) {
                $headers[$headerLine['name']] = [];
            }

            if ($headerLine['name'] === 'cookie') {
                $cookies = array_reduce(explode($headerLine['value'], ';'), function ($carry, $item) {
                    [$key, $value] = explode('=', $item);
                    $carry[$key] = $value;
                    return $carry;
                }, []);
            }

            $headers[$headerLine['name']][] = $headerLine['value'];
        }
        [$path, $query] = explode('?', $path . '?' , 3);
        parse_str($query, $queryParams);

        $message = $creator->fromArrays([
            'SERVER_PROTOCOL' => "HTTP/{$version}",
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'QUERY_STRING' => $query,
        ], $headers, $cookies, $queryParams, null, [], null);


        if ($message->hasHeader('transfer-encoding') || $message->hasHeader('content-encoding')) {
            $body = new \Onion\Framework\Http\Resources\DecodingBuffer(
                array_unique(array_map(trim(...), explode(
                    ', ',
                    "{$message->getHeaderLine('transfer-encoding')}, " .
                        "{$message->getHeaderLine('content-encoding')}"
                )))
            );
        }

        if ($message->hasHeader('content-length')) {
            read($connection, function (ResourceInterface $connection) use ($message, $body) {
                $total = (int) $message->getHeaderLine('content-length');
                while ($body->size() < $total) {
                    $body->write($connection->read($total - strlen($body)));
                    suspend();
                }

                return $body;
            });
        } else {
            read($connection, function (ResourceInterface $connection) use ($body) {
                while (preg_match('/\r?\n\r?\n$/i', $body->read(4)) !== 1 && !$connection->eof()) {
                    // fallback to sequential reading if no content-length is provided
                    // so that HTTP-pipelining is possible, although not necessarily
                    // supported for now
                    $body->write($connection->read(4096));
                    suspend();
                    $body->seek(-4, SEEK_END);
                }

                return trim($body);
            });
        }

        // Ensure we have finalized decoding in case of decoding stream
        $body->close();

        $message = $message->withBody($factory->createStream((string) $body));

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

        return $message->withCookieParams($cookies);
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

if (!function_exists(__NAMESPACE__ . '\process_chunked_message')) {
    function pipe_chunked(ResourceInterface|string $resource, ResourceInterface $buffer): ResourceInterface
    {
        if (!$resource instanceof ResourceInterface) {
            $b = new \Onion\Framework\Loop\Resources\Buffer();
            $b->write($resource);

            $resource = $b;
        }

        return read($resource, function (ResourceInterface $client) use ($buffer) {
            $size = '';
            while ($size !== '0') {
                $chunk = $client->read(1);
                if (preg_match('/\r?\n$/i', $chunk) === 1 && $size !== '0') {
                    $length = (int) hexdec(trim($size));

                    $chunk = '';
                    $size = '';
                    while (strlen($chunk) < $length) {
                        $chunk .= $client->read($length - strlen($chunk));
                        suspend();
                    }

                    $buffer->write($chunk);
                    $chunk .= $client->read(2);
                    continue;
                }

                $size .= $chunk;
            }

            return $buffer;
        });
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

        if (!$withBody) {
            return $buffer;
        }

        write($buffer, $message->getBody()->getContents());
        write($buffer, "\r\n\r\n");

        return $buffer;
    }
}
