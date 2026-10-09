<?php

declare(strict_types=1);

namespace AbanGateway\Exception;

/** A webhook whose X-Signature does not match its body. Answer it with 401. */
class SignatureVerificationException extends AbanGatewayException
{
}
