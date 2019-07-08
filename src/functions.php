<?php
namespace Onion\Framework\Http;

use function GuzzleHttp\Psr7\parse_query;
use function GuzzleHttp\Psr7\parse_request;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;

if (!function_exists(__NAMESPACE__ . '\build_request')) {
    function build_request($message): ServerRequestInterface {
        $req = parse_request($message);
        $request = new ServerRequest(
            $req->getMethod(),
            $req->getUri(),
            $req->getHeaders(),
            $req->getBody(),
            $req->getProtocolVersion()
        );

        $bodyLength = (int) $req->getHeaderLine('content-length');
        if ($bodyLength > 0) {
            $body = (string) $req->getBody();
            $pattern = '/^multipart\/form-data; boundary=(?P<boundary>.*)$/i';
            if (preg_match($pattern, $request->getHeaderLine('content-type'), $matches)) {
                $request = extract_multipart($request, $body, $matches['boundary']);
            } else if (preg_match('/^application\/x-www-form-urlencoded/', $request->getHeaderLine('content-type'), $matches)) {
                $request = $request->withParsedBody(parse_query($body));
            } else if (preg_match('/^application\/json/', $request->getHeaderLine('content-type'))) {
                $request = $request->withParsedBody(json_decode($body, true));
            }
        }

        return $request;
    }
}

if (!function_exists(__NAMESPACE__ . '\extract_multipart')) {
    function extract_multipart(ServerRequestInterface $request, string $body, string $boundary) {
        $parts = explode('--' . $boundary, $body);
        $files = [];
        $parsed = [];

        foreach ($parts as $part) {
            $sections = explode ("\r\n\r\n", trim($part), 2);

            $mediaType = 'application/octet-stream';
            $filename = null;
            $name = null;

            foreach (explode("\r\n", $sections[0]) as $header) {
                if (preg_match('/^(?J)(?P<name>.*): (?P<value>.*)$/im', $header, $matches)) {
                    if ($matches['name'] === 'Content-Disposition') {
                        preg_match(
                            '/form-data; name=\"(?P<name>[^"]+)\"(?:; filename=\"(?P<filename>[^"]+)\")?/',
                            $matches['value'],
                            $names
                        );

                        if (isset($names['filename']) && $names['filename'] !== '' && $names['filename'] !== null) {
                            $filename = $names['filename'];
                        }

                        if (isset($names['name']) && $names['name'] !== ''&& $names['name'] !== null) {
                            $name = $names['name'];
                        }
                    }

                    if ($matches['name'] === 'Content-Type') {
                        $mediaType = $matches['value'];
                    }
                }
            }

            if ($filename === null) {
                $parsed[$name] = $sections[1] ?? '';
            } else {
                $file = fopen(tempnam(sys_get_temp_dir(), time()), 'w+b');
                $size = fwrite($file, $sections[1] ?? '');

                $files[] = new UploadedFile($file, $size, 0, $filename, $mediaType);
            }
        }

        return $request->withParsedBody($parsed)
            ->withUploadedFiles($files);
    }
}
