<?php

declare(strict_types=1);

namespace AbanGateway;

use AbanGateway\Exception\ApiException;
use AbanGateway\Exception\ConfigurationException;
use AbanGateway\Exception\ServerException;
use AbanGateway\Http\CurlTransport;
use AbanGateway\Http\Transport;

/**
 * The AbanGateway API, for one shop's key.
 *
 *     $aban = new Client('ag_live_…');
 *     $invoice = $aban->createInvoice(['amount_toman' => 150000, 'order_id' => 'ORD-1043',
 *         'callback_url' => 'https://shop.example/aban/webhook', 'return_url' => 'https://shop.example/thanks']);
 *     header('Location: ' . $invoice->paymentUrl);
 *
 * A key starting ag_test_ makes test invoices, which simulatePayment() can
 * mark paid; nothing else changes.
 */
final class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://api.abangateway.ir';

    /** What invoiceForOrder() puts between your order id and an attempt number. */
    public const ATTEMPT_SEPARATOR = '#';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    /** @var Transport */
    private $transport;

    /**
     * @param array{base_url?: string, timeout?: int, connect_timeout?: int, transport?: Transport} $options
     *
     * @throws ConfigurationException when the key cannot be a real one
     */
    public function __construct(string $apiKey, array $options = array())
    {
        $apiKey = trim($apiKey);
        self::assertKeyShape($apiKey);
        $this->apiKey = $apiKey;

        $base = isset($options['base_url']) && $options['base_url'] !== '' ? (string) $options['base_url'] : self::DEFAULT_BASE_URL;
        $base = rtrim($base, '/');
        if (substr($base, -7) === '/api/v1') {
            $base = substr($base, 0, -7);
        }
        $this->baseUrl = $base;

        $this->transport = isset($options['transport']) && $options['transport'] instanceof Transport
            ? $options['transport']
            : new CurlTransport(
                isset($options['connect_timeout']) ? (int) $options['connect_timeout'] : 10,
                isset($options['timeout']) ? (int) $options['timeout'] : 30
            );
    }

    public function isTestKey(): bool
    {
        return strpos($this->apiKey, 'ag_test_') === 0;
    }

    // ─── Invoices ────────────────────────────────────────────────────────────

    /**
     * Opens an invoice. Give the amount as amount_rial or amount_toman, not
     * both. Other keys: order_id, callback_url (your webhook, https), return_url
     * (where the buyer is offered a way back), description, metadata (array,
     * echoed in webhooks), expiry_minutes, channel.
     *
     * The same order_id twice returns the first invoice, whatever its status —
     * which makes a retry after a timeout safe, and also means an expired one
     * comes back expired. {@see invoiceForOrder()} handles that for you.
     *
     * @param array<string, mixed> $params
     */
    public function createInvoice(array $params): Invoice
    {
        return Invoice::fromArray($this->request('POST', '/api/v1/invoices', self::invoiceBody($params)));
    }

    public function getInvoice(string $invoiceId): Invoice
    {
        return Invoice::fromArray($this->request('GET', '/api/v1/invoices/' . rawurlencode($invoiceId)));
    }

    /**
     * Claims a paid invoice, exactly once: the first call succeeds, every later
     * one throws ApiException with already_verified. Deliver the order on
     * success, so two simultaneous requests can never deliver it twice.
     * Throws with not_yet_paid (402) or invoice_expired (410) otherwise.
     */
    public function verifyInvoice(string $invoiceId): Verification
    {
        return Verification::fromArray($this->request('POST', '/api/v1/invoices/' . rawurlencode($invoiceId) . '/verify'));
    }

    public function cancelInvoice(string $invoiceId): Invoice
    {
        return Invoice::fromArray($this->request('POST', '/api/v1/invoices/' . rawurlencode($invoiceId) . '/cancel'));
    }

    /** Test keys only: marks a test invoice paid and sends its webhook. */
    public function simulatePayment(string $invoiceId): Invoice
    {
        return Invoice::fromArray($this->request('POST', '/api/v1/invoices/' . rawurlencode($invoiceId) . '/simulate-payment'));
    }

    /**
     * The invoice that should be shown for one of your orders right now.
     *
     * An order can outlive an invoice: the buyer comes back tomorrow, the
     * invoice expired an hour after it was made, and creating again with the
     * same order_id returns that expired invoice. This walks attempts —
     * `ORD-1043`, then `ORD-1043#2`, `ORD-1043#3` — and returns the first one
     * that is open for this amount or already paid, opening a new attempt
     * when every earlier one is expired or cancelled. An open attempt for a
     * different amount (the order total changed) is cancelled and replaced.
     *
     * Check isPaid() on what comes back: an order already paid on an earlier
     * attempt returns that invoice rather than a new one to pay again.
     * Webhooks carry the attempt's order_id; baseOrderId() gives yours back.
     *
     * @param array<string, mixed> $params as createInvoice(), without order_id
     */
    public function invoiceForOrder(string $orderId, array $params, int $maxAttempts = 20): Invoice
    {
        if ($orderId === '' || strpos($orderId, self::ATTEMPT_SEPARATOR) !== false) {
            throw new ConfigurationException('order id must be non-empty and must not contain "' . self::ATTEMPT_SEPARATOR . '"');
        }
        $body = self::invoiceBody($params);
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $body['order_id'] = $attempt === 1 ? $orderId : $orderId . self::ATTEMPT_SEPARATOR . $attempt;
            $invoice = Invoice::fromArray($this->request('POST', '/api/v1/invoices', $body));
            if ($invoice->isPaid()) {
                return $invoice;
            }
            if ($invoice->status === Invoice::PARTIALLY_PAID) {
                return $invoice; // money already arrived against it: never abandon it
            }
            if ($invoice->status === Invoice::PENDING) {
                if ($invoice->amountRial === (int) $body['amount_rial']) {
                    return $invoice;
                }
                $this->cancelInvoice($invoice->id);
            }
        }
        throw new ConfigurationException('more than ' . $maxAttempts . ' attempts for order ' . $orderId);
    }

    /** `ORD-1043#3` → `ORD-1043`; anything else unchanged. */
    public static function baseOrderId(string $orderId): string
    {
        $cut = preg_replace('/' . preg_quote(self::ATTEMPT_SEPARATOR, '/') . '\d+$/', '', $orderId);
        return is_string($cut) ? $cut : $orderId;
    }

    // ─── Plumbing ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function invoiceBody(array $params): array
    {
        $hasRial = array_key_exists('amount_rial', $params);
        $hasToman = array_key_exists('amount_toman', $params);
        if ($hasRial === $hasToman) {
            throw new ConfigurationException('give the amount as exactly one of amount_rial or amount_toman');
        }
        if ($hasToman) {
            $params['amount_rial'] = (int) $params['amount_toman'] * 10;
            unset($params['amount_toman']);
        }
        if (!is_int($params['amount_rial']) && !(is_string($params['amount_rial']) && ctype_digit($params['amount_rial']))) {
            throw new ConfigurationException('the amount must be a whole number of Rial or Toman');
        }
        $params['amount_rial'] = (int) $params['amount_rial'];
        if ($params['amount_rial'] <= 0) {
            throw new ConfigurationException('the amount must be positive');
        }
        return $params;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = array(
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'abangateway-php/' . self::VERSION . ' PHP/' . PHP_VERSION,
        );
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new ConfigurationException('the request could not be encoded as JSON: ' . json_last_error_msg());
            }
        }

        $response = $this->transport->send($method, $this->baseUrl . $path, $headers, $payload);
        if ($response->status < 200 || $response->status >= 300) {
            throw ApiException::fromResponse($response);
        }
        $data = $response->json();
        if ($data === null) {
            throw new ServerException('AbanGateway sent an answer that is not JSON', $response->status, 'invalid_response');
        }
        return $data;
    }

    /**
     * Catches the commonest setup mistake before it becomes a puzzling 401: a
     * key pasted without its ag_ prefix, or copied from the panel's shortened
     * list view instead of at the moment it was made.
     */
    private static function assertKeyShape(string $key): void
    {
        if (!preg_match('/^ag_(live|test)_(.*)$/', $key, $parts)) {
            throw new ConfigurationException('An AbanGateway key starts with ag_live_ or ag_test_. Make a new one in the panel (Settings, API keys) and copy it whole when it is shown.');
        }
        if (strlen($parts[2]) !== 48 || !preg_match('/^[a-z2-9]+$/', $parts[2])) {
            throw new ConfigurationException('This key is incomplete: after ag_' . $parts[1] . '_ there must be 48 characters. The panel shows the whole key only once, when it is made.');
        }
    }
}
