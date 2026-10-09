<?php

declare(strict_types=1);

namespace AbanGateway\Tests\Fakes;

use AbanGateway\Http\Response;
use AbanGateway\Http\Transport;

/** Answers from a queue and remembers every request it was given. */
final class FakeTransport implements Transport
{
    /** @var list<Response> */
    private $answers = array();

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public $requests = array();

    /** @param array<string, mixed>|string|null $body */
    public function answer(int $status, $body, array $headers = array()): self
    {
        $raw = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : (string) $body;
        $this->answers[] = new Response($status, $headers, $raw);
        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->requests[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);
        if (!$this->answers) {
            throw new \LogicException('FakeTransport: no answer queued for ' . $method . ' ' . $url);
        }
        return array_shift($this->answers);
    }

    /** @return array<string, mixed>|null */
    public function sentJson(int $index): ?array
    {
        $body = $this->requests[$index]['body'];
        return $body === null ? null : json_decode($body, true);
    }

    /** One invoice as the API would describe it. */
    public static function invoice(array $override = array()): array
    {
        return array_merge(array(
            'invoice_id' => 'inv_aaaaaaaaaaaaaaaaaaaa',
            'status' => 'pending',
            'amount_rial' => 1500000,
            'payable_rial' => 1500070,
            'payable_toman' => 150007,
            'fee_rial' => 15000,
            'order_id' => 'ORD-1',
            'card_number' => '6037991234567890',
            'card_holder' => 'نمونه',
            'card_last4' => '7890',
            'iban' => null,
            'account_number' => null,
            'payment_url' => 'https://api.abangateway.ir/pay/inv_aaaaaaaaaaaaaaaaaaaa',
            'expires_at' => '2026-10-10T10:00:00+00:00',
            'paid_at' => null,
            'is_test' => true,
        ), $override);
    }
}
