<?php

declare(strict_types=1);

namespace AbanGateway\Exception;

/** Refused for this key: sandbox_only with a live key, or a suspended shop. */
class PermissionException extends ApiException
{
}
