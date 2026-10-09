<?php

declare(strict_types=1);

namespace AbanGateway\Http;

use AbanGateway\Exception\NetworkException;

/**
 * How a request reaches the API. The default is cURL; tests, and anyone who
 * must route through their own HTTP stack, pass another implementation.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws NetworkException when no answer arrived at all
     */
    public function send(string $method, string $url, array $headers, ?string $body): Response;
}
