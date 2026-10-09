<?php

declare(strict_types=1);

namespace AbanGateway;

use AbanGateway\Exception\SignatureVerificationException;

/**
 * Reading AbanGateway's webhooks.
 *
 * The signature is HMAC-SHA256 of the raw request body with the shop's
 * webhook signing key (panel: Settings, Webhook, «ساخت کلید امضا»), hex
 * encoded, in X-Signature. Verify against the raw bytes — decoding and
 * re-encoding the JSON first changes them.
 *
 *     $event = Webhook::parse(file_get_contents('php://input'), $_SERVER['HTTP_X_SIGNATURE'] ?? '', $secret);
 *     $invoice = Webhook::confirm($client, $event);   // the API's own answer
 *     if ($invoice->isPaid()) { ... deliver ... }
 */
final class Webhook
{
    public static function signature(string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }

    /** Constant-time comparison; never compare signatures with ==. */
    public static function isValid(string $rawBody, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }
        return hash_equals(self::signature($rawBody, $secret), strtolower(trim($signature)));
    }

    /**
     * Checks the signature and reads the event.
     *
     * Pass null as the secret only if the shop has no signing key; the event
     * is then unauthenticated and must be confirmed with the API before
     * anything is done with it (which you should do anyway).
     *
     * @throws SignatureVerificationException
     */
    public static function parse(string $rawBody, ?string $signature, ?string $secret, ?string $deliveryId = null): WebhookEvent
    {
        if ($secret !== null && $secret !== '') {
            if (!self::isValid($rawBody, (string) $signature, $secret)) {
                throw new SignatureVerificationException('Webhook signature does not match its body');
            }
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data) || !isset($data['invoice_id'])) {
            throw new SignatureVerificationException('Webhook body is not an AbanGateway event');
        }
        return WebhookEvent::fromArray($data, $deliveryId);
    }

    /**
     * The invoice as the API describes it now — the only thing to act on. A
     * webhook can be replayed, delayed or, without a signing key, forged; the
     * API's answer to your own key cannot.
     */
    public static function confirm(Client $client, WebhookEvent $event): Invoice
    {
        return $client->getInvoice($event->invoiceId);
    }
}
