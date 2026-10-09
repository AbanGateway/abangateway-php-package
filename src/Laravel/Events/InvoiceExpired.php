<?php

declare(strict_types=1);

namespace AbanGateway\Laravel\Events;

use AbanGateway\Invoice;
use AbanGateway\WebhookEvent;

/**
 * The invoice ran out of time unpaid. invoiceForOrder() opens a fresh attempt if the buyer comes back.
 *
 * Fired from the webhook route only after the invoice was read back from the
 * API with your key: $invoice is the API's answer, $webhook what triggered it.
 */
final class InvoiceExpired
{
    /** @var Invoice */
    public $invoice;

    /** @var WebhookEvent */
    public $webhook;

    public function __construct(Invoice $invoice, WebhookEvent $webhook)
    {
        $this->invoice = $invoice;
        $this->webhook = $webhook;
    }

    /** Your own order id, without an attempt suffix such as #2. */
    public function orderId(): ?string
    {
        return $this->invoice->baseOrderId();
    }
}
