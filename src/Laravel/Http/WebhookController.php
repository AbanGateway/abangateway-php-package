<?php

declare(strict_types=1);

namespace AbanGateway\Laravel\Http;

use AbanGateway\Client;
use AbanGateway\Exception\AbanGatewayException;
use AbanGateway\Exception\NotFoundException;
use AbanGateway\Exception\SignatureVerificationException;
use AbanGateway\Invoice;
use AbanGateway\Laravel\Events\InvoiceCancelled;
use AbanGateway\Laravel\Events\InvoiceExpired;
use AbanGateway\Laravel\Events\InvoicePaid;
use AbanGateway\Laravel\Events\InvoicePartiallyPaid;
use AbanGateway\Webhook;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /abangateway/webhook.
 *
 * Which event fires is decided by the invoice's status as the API reports it
 * to your key, never by what the webhook claims: a replayed, delayed or (with
 * no signing key) forged webhook can make this route look, not act.
 *
 * Answers 2xx once handled, so AbanGateway stops retrying. A listener that
 * throws turns into a 500 and AbanGateway delivers again later — which is why
 * listeners must be idempotent.
 */
final class WebhookController
{
    public function __invoke(Request $request, Client $client, Dispatcher $events, Config $config): JsonResponse
    {
        $secret = (string) $config->get('abangateway.webhook_secret', '');
        try {
            $webhook = Webhook::parse(
                (string) $request->getContent(),
                $request->header('X-Signature'),
                $secret !== '' ? $secret : null,
                $request->header('X-Delivery-Id')
            );
        } catch (SignatureVerificationException $refused) {
            return new JsonResponse(array('ok' => false, 'error' => $refused->getMessage()), 401);
        }

        try {
            $invoice = Webhook::confirm($client, $webhook);
        } catch (NotFoundException $notOurs) {
            // Another key's or another shop's invoice: nothing here will ever
            // match it, so stop the retries.
            return new JsonResponse(array('ok' => true, 'ignored' => 'unknown invoice'));
        } catch (AbanGatewayException $unreachable) {
            // Could not ask the API. A non-2xx makes AbanGateway try later.
            return new JsonResponse(array('ok' => false, 'error' => 'status check failed'), 502);
        }

        $fired = self::eventFor($invoice, $webhook);
        if ($fired !== null) {
            $events->dispatch($fired);
        }
        return new JsonResponse(array('ok' => true, 'status' => $invoice->status));
    }

    /** @return object|null */
    private static function eventFor(Invoice $invoice, \AbanGateway\WebhookEvent $webhook)
    {
        switch ($invoice->status) {
            case Invoice::PAID:
                return new InvoicePaid($invoice, $webhook);
            case Invoice::PARTIALLY_PAID:
                return new InvoicePartiallyPaid($invoice, $webhook);
            case Invoice::EXPIRED:
                return new InvoiceExpired($invoice, $webhook);
            case Invoice::CANCELLED:
                return new InvoiceCancelled($invoice, $webhook);
            default:
                return null; // still pending: the webhook said more than the API does
        }
    }
}
