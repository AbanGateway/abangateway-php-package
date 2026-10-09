<?php

declare(strict_types=1);

namespace AbanGateway\Laravel\Facades;

use AbanGateway\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AbanGateway\Invoice createInvoice(array $params)
 * @method static \AbanGateway\Invoice invoiceForOrder(string $orderId, array $params, int $maxAttempts = 20)
 * @method static \AbanGateway\Invoice getInvoice(string $invoiceId)
 * @method static \AbanGateway\Verification verifyInvoice(string $invoiceId)
 * @method static \AbanGateway\Invoice cancelInvoice(string $invoiceId)
 * @method static \AbanGateway\Invoice simulatePayment(string $invoiceId)
 * @method static bool isTestKey()
 *
 * @see Client
 */
final class AbanGateway extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
