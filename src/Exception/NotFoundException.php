<?php

declare(strict_types=1);

namespace AbanGateway\Exception;

/** No such invoice for this shop (or it belongs to another key's mode). */
class NotFoundException extends ApiException
{
}
