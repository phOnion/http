<?php

namespace Onion\Framework\Http\Contexts;

class HttpContext
{
    private $options = [];

    public function setMethod(string $method)
    {
        $this->options['method'] = $method;
    }

    public function addHeader(string $header, $value)
    {
        $this->options['header'][] = "{$header}: {$value}";
    }

    public function addHeaders(array $headers)
    {
        foreach ($headers as $header => $value) {
            $this->addHeader($header, $value);
        }
    }

    public function setHeaders(array $headers)
    {
        $this->options['header'] = [];
        $this->addHeaders($headers);
    }

    public function setUserAgent(string $ua)
    {
        $this->options['user_agent'] = $ua;
    }

    public function setContent(string $data)
    {
        $this->options['content'] = $data;
    }

    public function setProxy(string $proxy)
    {
        $this->options['proxy'] = $proxy;
    }

    public function setRequestFullUri(bool $enable)
    {
        $this->options['request_fulluri'] = $enable;
    }

    public function setFollowLocation(bool $enable)
    {
        $this->options['follow_location'] = (int) $enable;
    }

    public function setMaxRedirects(int $count)
    {
        $this->options['max_redirects'] = $count;
    }

    public function setProtocolVersion(string $version)
    {
        $this->options['protocol_version'] = (float) $version;
    }

    public function setTimeout(int $timeout)
    {
        $this->options['timeout'] = (float) $timeout;
    }

    public function setIgnoreErrors(bool $enable)
    {
        $this->options['ignore_errors'] = $enable;
    }


    public function getContextArray(): array
    {
        return [
            'http' => $this->options,
        ];
    }

    public function getContextOptions(): array
    {
        return $this->options;
    }
}
