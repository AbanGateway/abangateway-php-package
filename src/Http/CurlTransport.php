<?php

declare(strict_types=1);

namespace AbanGateway\Http;

use AbanGateway\Exception\NetworkException;

/** The default transport: plain cURL, TLS verification on, short timeouts. */
final class CurlTransport implements Transport
{
    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $timeout;

    public function __construct(int $connectTimeout = 10, int $timeout = 30)
    {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $curl = curl_init($url);
        $lines = array();
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = array();
        curl_setopt_array($curl, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ));
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($curl);
        if ($raw === false) {
            $message = curl_error($curl);
            curl_close($curl);
            throw new NetworkException('Could not reach AbanGateway: ' . $message);
        }
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return new Response($status, $received, (string) $raw);
    }
}
