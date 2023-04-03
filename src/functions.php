<?php

namespace Onion\Framework\Http;

use GuzzleHttp\Psr7\{Message, Query, ServerRequest, UploadedFile};
use Psr\Http\Message\ServerRequestInterface;

use function Onion\Framework\Loop\tick;

if (!function_exists(__NAMESPACE__ . '\build_request')) {
    function build_request(string $message): ServerRequestInterface
    {
        $queryParams = [];

        $req = Message::parseRequest($message);
        parse_str($req->getUri()->getQuery(), $queryParams);

        $request = (new ServerRequest(
            $req->getMethod(),
            $req->getUri(),
            $req->getHeaders(),
            $req->getBody(),
            $req->getProtocolVersion()
        ))->withQueryParams($queryParams);

        $bodyLength = (int) $req->getHeaderLine('content-length');
        if ($bodyLength > 0) {
            $stream = $req->getBody();
            $stream->rewind();
            $body = $stream->getContents();
            $stream->rewind();

            if ($req->hasHeader('transfer-encoding') && $req->getHeaderLine('transfer-encoding') === 'chunked') {
                $body = process_chunked_message($body);
            }

            $pattern = '/^multipart\/form-data; boundary=(?P<boundary>.*)$/i';
            if (preg_match($pattern, $request->getHeaderLine('content-type'), $matches)) {
                $request = extract_multipart($request, $body, $matches['boundary']);
            } elseif (
                preg_match('/^application\/x-www-form-urlencoded/', $request->getHeaderLine('content-type'), $matches)
            ) {
                $request = $request->withParsedBody(Query::parse($body));
            } elseif (preg_match('/^application\/json/', $request->getHeaderLine('content-type'))) {
                $request = $request->withParsedBody(json_decode($body, true));
            }
        }

        $cookies = [];
        foreach ($request->getHeader('cookie') as $rawValue) {
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

        return $request->withCookieParams($cookies);
    }
}

if (!function_exists(__NAMESPACE__ . '\extract_multipart')) {
    function extract_multipart(ServerRequestInterface $request, string $body, string $boundary): ServerRequestInterface
    {
        $parts = explode('--' . $boundary, $body);
        $files = [];
        $parsed = [];

        foreach ($parts as $part) {
            $sections = explode("\r\n\r\n", trim($part), 2);

            $mediaType = 'application/octet-stream';
            $filename = null;
            $name = null;

            foreach (explode("\r\n", $sections[0]) as $header) {
                if (preg_match('/^(?P<name>[a-z0-9-_]+):\s?(?P<value>.+)$/im', $header, $matches)) {
                    if ($matches['name'] === 'Content-Disposition') {
                        preg_match(
                            '/form-data; name=\"(?P<name>[^"]+)\"(?:; filename=\"(?P<filename>[^"]+)\")?/',
                            $matches['value'],
                            $names
                        );

                        if (isset($names['filename']) && $names['filename'] !== '') {
                            $filename = urldecode($names['filename']);
                        }

                        if (isset($names['name']) && $names['name'] !== '') {
                            $name = urldecode($names['name']);
                        }
                    }

                    if ($matches['name'] === 'Content-Type') {
                        $mediaType = $matches['value'];
                    }
                }
            }

            if ($filename === null && $name !== null) {
                $parsed[$name] = $sections[1] ?? '';
            } else {
                $file = fopen(tempnam(sys_get_temp_dir(), (string) time()), 'w+b');
                $size = fwrite($file, $sections[1] ?? '');

                $files[] = new UploadedFile($file, $size, 0, $filename, $mediaType);
            }
        }

        return $request->withParsedBody($parsed)
            ->withUploadedFiles($files);
    }
}

if (!function_exists(__NAMESPACE__ . '\process_chunked_message')) {
    function process_chunked_message(string $content): string
    {
        $body = '';

        for (; !empty($content); $content = trim($content)) {
            $pos = stripos($content, "\r\n");
            $len = hexdec(substr($content, 0, $pos));
            $body .= substr($content, $pos + 2, $len);
            $content = substr($content, $pos + 2 + $len);

            tick();
        }

        return $body;
    }
}
