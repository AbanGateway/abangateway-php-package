<?php

declare(strict_types=1);

namespace AbanGateway\Tests\Unit;

use AbanGateway\Client;
use AbanGateway\Exception\ApiException;
use AbanGateway\Exception\AuthenticationException;
use AbanGateway\Exception\ConfigurationException;
use AbanGateway\Exception\NotFoundException;
use AbanGateway\Exception\PermissionException;
use AbanGateway\Exception\RateLimitException;
use AbanGateway\Exception\ServerException;
use AbanGateway\Invoice;
use AbanGateway\Tests\Fakes\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const TEST_KEY = 'ag_test_aaaaaaaaaaaaaaaabbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private function client(FakeTransport $fake, array $options = array()): Client
    {
        return new Client(self::TEST_KEY, $options + array('transport' => $fake));
    }

    // ─── The key ─────────────────────────────────────────────────────────────

    public function testAKeyWithoutItsPrefixIsCaughtBeforeAnyRequest(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('ag_live_ or ag_test_');
        new Client('live_' . str_repeat('a', 48));
    }

    public function testAShortenedKeyIsCaughtBeforeAnyRequest(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('48 characters');
        new Client('ag_live_abcd2345');
    }

    public function testATestKeyIsRecognised(): void
    {
        $this->assertTrue($this->client(new FakeTransport())->isTestKey());
        $this->assertFalse((new Client('ag_live_' . str_repeat('k', 48), array('transport' => new FakeTransport())))->isTestKey());
    }

    // ─── Requests ────────────────────────────────────────────────────────────

    public function testCreateSendsTheRightRequest(): void
    {
        $fake = (new FakeTransport())->answer(201, FakeTransport::invoice());
        $invoice = $this->client($fake)->createInvoice(array(
            'amount_toman' => 150000,
            'order_id' => 'ORD-1',
            'callback_url' => 'https://shop.example/aban',
            'metadata' => array('note' => 'سلام'),
        ));

        $request = $fake->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.abangateway.ir/api/v1/invoices', $request['url']);
        $this->assertSame('Bearer ' . self::TEST_KEY, $request['headers']['Authorization']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertStringStartsWith('abangateway-php/' . Client::VERSION, $request['headers']['User-Agent']);
        $sent = $fake->sentJson(0);
        $this->assertSame(1500000, $sent['amount_rial'], 'Toman is sent as Rial');
        $this->assertArrayNotHasKey('amount_toman', $sent);
        $this->assertStringContainsString('سلام', (string) $request['body'], 'Persian stays readable, not \\u-escaped');

        $this->assertSame('inv_aaaaaaaaaaaaaaaaaaaa', $invoice->id);
        $this->assertSame(1500070, $invoice->payableRial);
        $this->assertSame('7890', $invoice->cardLast4);
        $this->assertTrue($invoice->isOpen());
        $this->assertInstanceOf(\DateTimeImmutable::class, $invoice->expiresAt);
    }

    public function testTheAmountMustBeGivenExactlyOnce(): void
    {
        $client = $this->client(new FakeTransport());
        foreach (array(array(), array('amount_rial' => 10, 'amount_toman' => 1), array('amount_rial' => 0), array('amount_rial' => 12.5)) as $bad) {
            try {
                $client->createInvoice($bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (ConfigurationException $expected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testABaseUrlWithApiV1PastedIsNormalised(): void
    {
        $fake = (new FakeTransport())->answer(200, FakeTransport::invoice());
        $this->client($fake, array('base_url' => 'https://gw.example/api/v1/'))->getInvoice('inv_x/y');
        $this->assertSame('https://gw.example/api/v1/invoices/inv_x%2Fy', $fake->requests[0]['url']);
        $this->assertNull($fake->requests[0]['body']);
    }

    public function testVerifyCancelAndSimulateHitTheirPaths(): void
    {
        $fake = (new FakeTransport())
            ->answer(200, array('verified' => true, 'invoice_id' => 'inv_a', 'order_id' => 'ORD-1', 'amount_rial' => 10, 'paid_at' => '2026-10-09T10:00:00+00:00'))
            ->answer(200, FakeTransport::invoice(array('status' => 'cancelled')))
            ->answer(200, FakeTransport::invoice(array('status' => 'paid')));
        $client = $this->client($fake);
        $verification = $client->verifyInvoice('inv_a');
        $this->assertTrue($verification->verified);
        $this->assertSame('ORD-1', $verification->orderId);
        $this->assertTrue($client->cancelInvoice('inv_a')->isCancelled());
        $this->assertTrue($client->simulatePayment('inv_a')->isPaid());
        $this->assertStringEndsWith('/inv_a/verify', $fake->requests[0]['url']);
        $this->assertStringEndsWith('/inv_a/cancel', $fake->requests[1]['url']);
        $this->assertStringEndsWith('/inv_a/simulate-payment', $fake->requests[2]['url']);
    }

    // ─── Errors ──────────────────────────────────────────────────────────────

    public function testErrorsCarryTheApiCodeAndTheRightClass(): void
    {
        $cases = array(
            array(401, array('detail' => 'Authentication failed'), array(), AuthenticationException::class, 'authentication_failed'),
            array(403, array('error' => array('code' => 'sandbox_only', 'message' => 'test only')), array(), PermissionException::class, 'sandbox_only'),
            array(404, array('error' => array('code' => 'invoice_not_found', 'message' => 'no')), array(), NotFoundException::class, 'invoice_not_found'),
            array(402, array('error' => array('code' => 'insufficient_fee_wallet', 'message' => 'top up')), array(), ApiException::class, 'insufficient_fee_wallet'),
            array(409, array('error' => array('code' => 'already_verified', 'message' => 'done')), array(), ApiException::class, 'already_verified'),
            array(410, array('error' => array('code' => 'invoice_expired', 'message' => 'late')), array(), ApiException::class, 'invoice_expired'),
            array(422, array('error' => array('code' => 'invalid_request', 'message' => 'bad', 'details' => array('field' => 'amount_rial'))), array(), ApiException::class, 'invalid_request'),
            array(429, array('detail' => 'Rate limit exceeded'), array('Retry-After' => '7'), RateLimitException::class, 'rate_limited'),
            array(503, array('error' => array('code' => 'capacity_full', 'message' => 'busy')), array(), ServerException::class, 'capacity_full'),
            array(502, '<html>bad gateway</html>', array(), ServerException::class, 'http_502'),
        );
        foreach ($cases as list($status, $body, $headers, $class, $code)) {
            $fake = (new FakeTransport())->answer($status, $body, $headers);
            try {
                $this->client($fake)->getInvoice('inv_a');
                $this->fail("HTTP $status did not throw");
            } catch (ApiException $error) {
                $this->assertInstanceOf($class, $error, "HTTP $status");
                $this->assertSame($code, $error->getErrorCode(), "HTTP $status");
                $this->assertSame($status, $error->getHttpStatus());
                if ($error instanceof RateLimitException) {
                    $this->assertSame(7, $error->getRetryAfter());
                }
                if ($code === 'invalid_request') {
                    $this->assertSame(array('field' => 'amount_rial'), $error->getDetails());
                }
            }
        }
    }

    public function testASuccessThatIsNotJsonIsAnError(): void
    {
        $this->expectException(ServerException::class);
        $this->client((new FakeTransport())->answer(200, 'OK'))->getInvoice('inv_a');
    }

    // ─── An order outliving its invoice ──────────────────────────────────────

    public function testAnOpenInvoiceForTheSameAmountIsReused(): void
    {
        $fake = (new FakeTransport())->answer(200, FakeTransport::invoice(array('order_id' => 'ORD-7')));
        $invoice = $this->client($fake)->invoiceForOrder('ORD-7', array('amount_rial' => 1500000));
        $this->assertSame('ORD-7', $invoice->orderId);
        $this->assertCount(1, $fake->requests);
    }

    public function testAfterExpiryTheNextAttemptIsOpened(): void
    {
        $fake = (new FakeTransport())
            ->answer(200, FakeTransport::invoice(array('order_id' => 'ORD-7', 'status' => 'expired')))
            ->answer(200, FakeTransport::invoice(array('order_id' => 'ORD-7#2', 'status' => 'cancelled')))
            ->answer(201, FakeTransport::invoice(array('order_id' => 'ORD-7#3', 'invoice_id' => 'inv_third')));
        $invoice = $this->client($fake)->invoiceForOrder('ORD-7', array('amount_rial' => 1500000));
        $this->assertSame('inv_third', $invoice->id);
        $this->assertSame(array('ORD-7', 'ORD-7#2', 'ORD-7#3'), array_map(function ($i) use ($fake) {
            return $fake->sentJson($i)['order_id'];
        }, array(0, 1, 2)));
        $this->assertSame('ORD-7', $invoice->baseOrderId());
    }

    public function testAChangedTotalCancelsTheOpenAttemptAndOpensTheNext(): void
    {
        $fake = (new FakeTransport())
            ->answer(200, FakeTransport::invoice(array('order_id' => 'ORD-7', 'amount_rial' => 1000000, 'invoice_id' => 'inv_old')))
            ->answer(200, FakeTransport::invoice(array('invoice_id' => 'inv_old', 'status' => 'cancelled')))
            ->answer(201, FakeTransport::invoice(array('order_id' => 'ORD-7#2', 'invoice_id' => 'inv_new')));
        $invoice = $this->client($fake)->invoiceForOrder('ORD-7', array('amount_rial' => 1500000));
        $this->assertSame('inv_new', $invoice->id);
        $this->assertStringEndsWith('/inv_old/cancel', $fake->requests[1]['url']);
    }

    public function testAnOrderPaidOnAnEarlierAttemptIsNotChargedAgain(): void
    {
        $fake = (new FakeTransport())
            ->answer(200, FakeTransport::invoice(array('order_id' => 'ORD-7', 'status' => 'expired')))
            ->answer(200, FakeTransport::invoice(array('order_id' => 'ORD-7#2', 'status' => 'paid', 'invoice_id' => 'inv_paid')));
        $invoice = $this->client($fake)->invoiceForOrder('ORD-7', array('amount_rial' => 1500000));
        $this->assertTrue($invoice->isPaid());
        $this->assertCount(2, $fake->requests, 'nothing new was opened');
    }

    public function testPartlyPaidIsNeverAbandoned(): void
    {
        $fake = (new FakeTransport())->answer(200, FakeTransport::invoice(array('status' => 'partially_paid', 'amount_rial' => 99)));
        $invoice = $this->client($fake)->invoiceForOrder('ORD-7', array('amount_rial' => 1500000));
        $this->assertSame(Invoice::PARTIALLY_PAID, $invoice->status);
        $this->assertCount(1, $fake->requests, 'not cancelled');
    }

    public function testAnOrderIdCannotContainTheSeparator(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client(new FakeTransport())->invoiceForOrder('ORD#1', array('amount_rial' => 10));
    }

    public function testBaseOrderIdOnlyStripsAnAttemptSuffix(): void
    {
        $this->assertSame('ORD-1043', Client::baseOrderId('ORD-1043#12'));
        $this->assertSame('ORD-1043', Client::baseOrderId('ORD-1043'));
        $this->assertSame('A#B', Client::baseOrderId('A#B'));
    }
}
