<?php

declare(strict_types=1);

namespace AbanGateway;

/**
 * An AbanGateway invoice, as the API last described it.
 *
 * Amounts are in Rial, as the API sends them. `payableRial` is what the buyer
 * must transfer: the amount, sometimes plus a few Rial that make it unique, so
 * the bank's message can be matched to this invoice and no other.
 */
final class Invoice
{
    public const PENDING = 'pending';
    public const PARTIALLY_PAID = 'partially_paid';
    public const PAID = 'paid';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';

    /** @var string */
    public $id;
    /** @var string */
    public $status;
    /** @var int */
    public $amountRial;
    /** @var int */
    public $payableRial;
    /** @var int */
    public $payableToman;
    /** @var int */
    public $feeRial;
    /** @var string|null */
    public $orderId;
    /** @var string|null */
    public $cardNumber;
    /** @var string|null */
    public $cardHolder;
    /** @var string|null */
    public $cardLast4;
    /** @var string|null */
    public $iban;
    /** @var string|null */
    public $accountNumber;
    /** @var string */
    public $paymentUrl;
    /** @var \DateTimeImmutable|null */
    public $expiresAt;
    /** @var \DateTimeImmutable|null */
    public $paidAt;
    /** @var bool */
    public $isTest;

    /** @var array<string, mixed> */
    private $raw;

    /** @param array<string, mixed> $data the API's JSON for one invoice */
    public static function fromArray(array $data): self
    {
        $invoice = new self();
        $invoice->raw = $data;
        $invoice->id = (string) $data['invoice_id'];
        $invoice->status = (string) $data['status'];
        $invoice->amountRial = (int) $data['amount_rial'];
        $invoice->payableRial = (int) ($data['payable_rial'] ?? $data['amount_rial']);
        $invoice->payableToman = (int) ($data['payable_toman'] ?? intdiv($invoice->payableRial, 10));
        $invoice->feeRial = (int) ($data['fee_rial'] ?? 0);
        $invoice->orderId = isset($data['order_id']) ? (string) $data['order_id'] : null;
        $invoice->cardNumber = isset($data['card_number']) ? (string) $data['card_number'] : null;
        $invoice->cardHolder = isset($data['card_holder']) ? (string) $data['card_holder'] : null;
        $invoice->cardLast4 = isset($data['card_last4']) ? (string) $data['card_last4'] : null;
        $invoice->iban = isset($data['iban']) ? (string) $data['iban'] : null;
        $invoice->accountNumber = isset($data['account_number']) ? (string) $data['account_number'] : null;
        $invoice->paymentUrl = (string) ($data['payment_url'] ?? '');
        $invoice->expiresAt = self::date($data['expires_at'] ?? null);
        $invoice->paidAt = self::date($data['paid_at'] ?? null);
        $invoice->isTest = !empty($data['is_test']);
        return $invoice;
    }

    /** Waiting for the buyer's transfer (or the rest of a split invoice). */
    public function isOpen(): bool
    {
        return $this->status === self::PENDING || $this->status === self::PARTIALLY_PAID;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isExpired(): bool
    {
        return $this->status === self::EXPIRED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /**
     * Your own order id, without the attempt suffix that
     * {@see Client::invoiceForOrder()} adds after an expired attempt.
     */
    public function baseOrderId(): ?string
    {
        return $this->orderId === null ? null : Client::baseOrderId($this->orderId);
    }

    /** @return array<string, mixed> exactly what the API sent */
    public function toArray(): array
    {
        return $this->raw;
    }

    private static function date($value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $problem) {
            return null;
        }
    }
}
