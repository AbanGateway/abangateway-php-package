<?php

declare(strict_types=1);

namespace AbanGateway\Exception;

/** A 5xx: worth retrying later; capacity_full is one of these. */
class ServerException extends ApiException
{
}
