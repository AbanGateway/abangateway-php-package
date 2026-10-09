<?php

declare(strict_types=1);

namespace AbanGateway\Exception;

/**
 * No answer arrived: DNS, TLS, a timeout. The request may or may not have
 * reached the API, so retrying a create with the same order_id is the safe
 * way to find out — the API returns the invoice it already made.
 */
class NetworkException extends AbanGatewayException
{
}
