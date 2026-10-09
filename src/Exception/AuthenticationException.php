<?php

declare(strict_types=1);

namespace AbanGateway\Exception;

/**
 * The key was not accepted. A full key starts with ag_live_ or ag_test_ and
 * has 48 characters after it; the panel shows it whole only once, when made.
 */
class AuthenticationException extends ApiException
{
}
