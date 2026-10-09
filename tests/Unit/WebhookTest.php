<?php

declare(strict_types=1);

namespace AbanGateway\Tests\Unit;

use AbanGateway\Client;
use AbanGateway\Exception\SignatureVerificationException;
use AbanGateway\Tests\Fakes\FakeTransport;
use AbanGateway\Webhook;
use AbanGateway\WebhookEvent;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    /** @return array{body: string, secret: string, signature: string} */
    private function fixture(): array
    {
        // Built and signed by the backend's own services.webhooks.sign, so this
        // proves the PHP side agrees with the server byte for byte.
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/webhook-paid.json'), true);
    }

    public function testTheServersSignatureIsAccepted(): void
    {
        $f = $this->fixture();
        $this->assertTrue(Webhook::isValid($f['body'], $f['signature'], $f['secret']));
        $event = Webhook::parse($f['body'], $f['signature'], $f['secret'], 'whd_1');
        $this->assertSame(WebhookEvent::INVOICE_PAID, $event->type);
        $this->assertSame('inv_9fk2m4qx7t3p8wzy1a6b', $event->invoiceId);
        $this->assertSame('ORD-1043', $event->baseOrderId());
        $this->assertSame(5990000, $event->amountRial);
        $this->assertSame('سفارش آزمایشی', $event->metadata['note']);
        $this->assertSame('whd_1', $event->deliveryId);
    }

    public function testAnyChangeToTheBodyBreaksTheSignature(): void
    {
        $f = $this->fixture();
        $this->assertFalse(Webhook::isValid(str_replace('5990000', '5990001', $f['body']), $f['signature'], $f['secret']));
        $reencoded = json_encode(json_decode($f['body'], true));
        $this->assertFalse(Webhook::isValid((string) $reencoded, $f['signature'], $f['secret']), 're-encoded JSON is not the raw body');
        $this->assertFalse(Webhook::isValid($f['body'], $f['signature'], 'another-secret'));
        $this->assertFalse(Webhook::isValid($f['body'], '', $f['secret']));
    }

    public function testAnUppercaseOrPaddedSignatureStillMatches(): void
    {
        $f = $this->fixture();
        $this->assertTrue(Webhook::isValid($f['body'], ' ' . strtoupper($f['signature']) . "\n", $f['secret']));
    }

    public function testAWrongSignatureIsRefused(): void
    {
        $f = $this->fixture();
        $this->expectException(SignatureVerificationException::class);
        Webhook::parse($f['body'], str_repeat('0', 64), $f['secret']);
    }

    public function testWithoutASecretTheEventIsReadButMustBeConfirmed(): void
    {
        $f = $this->fixture();
        $event = Webhook::parse($f['body'], null, null);
        $fake = (new FakeTransport())->answer(200, FakeTransport::invoice(array('invoice_id' => $event->invoiceId, 'status' => 'pending')));
        $client = new Client('ag_test_' . str_repeat('a', 48), array('transport' => $fake));
        $invoice = Webhook::confirm($client, $event);
        $this->assertFalse($invoice->isPaid(), 'the API, not the webhook, decides');
        $this->assertStringEndsWith('/api/v1/invoices/inv_9fk2m4qx7t3p8wzy1a6b', $fake->requests[0]['url']);
    }

    public function testABodyThatIsNotAnEventIsRefused(): void
    {
        $this->expectException(SignatureVerificationException::class);
        Webhook::parse('{"hello":"world"}', null, null);
    }
}
