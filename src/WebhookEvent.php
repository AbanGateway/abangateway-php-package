<?php

declare(strict_types=1);

namespace AbanGateway;

/**
 * One webhook, signature already checked. It says something happened; it is
 * not proof. Before delivering an order, read the invoice back with
 * {@see Webhook::confirm()} (or {@see Client::getInvoice()}) — the Laravel
 * route in this package does that for you.
 */
final class WebhookEvent
{
    public const INVOICE_PAID = 'invoice.paid';
    public const INVOICE_PARTIALLY_PAID = 'invoice.partially_paid';
    public const INVOICE_EXPIRED = 'invoice.expired';
    public const INVOICE_CANCELLED = 'invoice.cancelled';

    /** @var string */
    public $type;
    /** @var string */
    public $invoiceId;
    /** @var string|null */
    public $orderId;
    /** @var string|null */
    public $status;
    /** @var int|null */
    public $amountRial;
    /** @var bool */
    public $isTest;
    /** @var array<string, mixed> */
    public $metadata;
    /** @var string|null the X-Delivery-Id header, unique per delivery attempt */
    public $deliveryId;

    /** @var array<string, mixed> */
    private $raw;

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?string $deliveryId = null): self
    {
        $event = new self();
        $event->raw = $data;
        $event->type = (string) ($data['event'] ?? '');
        $event->invoiceId = (string) ($data['invoice_id'] ?? '');
        $event->orderId = isset($data['order_id']) ? (string) $data['order_id'] : null;
        $event->status = isset($data['status']) ? (string) $data['status'] : null;
        $event->amountRial = isset($data['amount_rial']) ? (int) $data['amount_rial'] : null;
        $event->isTest = !empty($data['is_test']);
        $event->metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array();
        $event->deliveryId = $deliveryId;
        return $event;
    }

    /** Your own order id, without an attempt suffix. */
    public function baseOrderId(): ?string
    {
        return $this->orderId === null ? null : Client::baseOrderId($this->orderId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
