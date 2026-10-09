<?php

declare(strict_types=1);

namespace AbanGateway\Exception;

/** A mistake in how the client was set up, caught before any request is sent. */
class ConfigurationException extends AbanGatewayException
{
}
