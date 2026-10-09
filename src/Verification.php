<?php

declare(strict_types=1);

namespace AbanGateway;

/**
 * The answer to verify: this invoice is paid and you are the first to claim
 * it. Deliver the order when you get one of these; a second verify of the same
 * invoice throws ApiException with the code already_verified instead.
 */
final class Verification
{
    /** @var bool */
    public $verified;
    /** @var string */
    public $invoiceId;
    /** @var string|null */
    public $orderId;
    /** @var int */
    public $amountRial;
    /** @var \DateTimeImmutable|null */
    public $paidAt;

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $verification = new self();
        $verification->verified = !empty($data['verified']);
        $verification->invoiceId = (string) $data['invoice_id'];
        $verification->orderId = isset($data['order_id']) ? (string) $data['order_id'] : null;
        $verification->amountRial = (int) $data['amount_rial'];
        $verification->paidAt = isset($data['paid_at']) && is_string($data['paid_at'])
            ? new \DateTimeImmutable($data['paid_at'])
            : null;
        return $verification;
    }
}
