<?php

declare(strict_types=1);

namespace AbanGateway\Tests\E2E;

use AbanGateway\Client;
use AbanGateway\Exception\ApiException;
use AbanGateway\Exception\AuthenticationException;
use AbanGateway\Exception\PermissionException;
use PHPUnit\Framework\TestCase;

/**
 * Against a running AbanGateway API with a scratch shop (whmcs/tests/setup.sh
 * in the main repository makes one). Skipped unless these are set:
 *
 *   ABAN_E2E_BASE_URL   e.g. http://host.docker.internal:8011
 *   ABAN_E2E_TEST_KEY   an ag_test_ key of that shop
 *   ABAN_E2E_LIVE_KEY   an ag_live_ key of the same shop
 *
 * Never point this at production: it opens and pays test invoices.
 */
final class ApiTest extends TestCase
{
    /** @var string */
    private $base;

    protected function setUp(): void
    {
        $this->base = (string) getenv('ABAN_E2E_BASE_URL');
        if ($this->base === '' || !getenv('ABAN_E2E_TEST_KEY')) {
            $this->markTestSkipped('ABAN_E2E_* not set');
        }
        if (strpos($this->base, 'abangateway.ir') !== false) {
            $this->fail('refusing to run end-to-end tests against production');
        }
    }

    private function client(string $which = 'TEST'): Client
    {
        return new Client((string) getenv('ABAN_E2E_' . $which . '_KEY'), array('base_url' => $this->base));
    }

    private function orderId(string $tag): string
    {
        return 'sdk-' . $tag . '-' . bin2hex(random_bytes(4));
    }

    public function testAnInvoiceGoesFromOpenToPaidToVerifiedOnce(): void
    {
        $client = $this->client();
        $invoice = $client->createInvoice(array(
            'amount_toman' => 123400,
            'order_id' => $this->orderId('life'),
            'callback_url' => 'https://1.1.1.1/hook',
            'description' => 'آزمون بسته PHP',
            'metadata' => array('from' => 'sdk-e2e'),
        ));
        $this->assertTrue($invoice->isOpen());
        $this->assertTrue($invoice->isTest);
        $this->assertSame(1234000, $invoice->amountRial);
        $this->assertGreaterThanOrEqual($invoice->amountRial, $invoice->payableRial);
        $this->assertStringContainsString('/pay/' . $invoice->id, $invoice->paymentUrl);

        $this->assertSame($invoice->id, $client->getInvoice($invoice->id)->id);

        try {
            $client->verifyInvoice($invoice->id);
            $this->fail('verified an unpaid invoice');
        } catch (ApiException $error) {
            $this->assertSame('not_yet_paid', $error->getErrorCode());
            $this->assertSame(402, $error->getHttpStatus());
        }

        $this->assertTrue($client->simulatePayment($invoice->id)->isPaid());
        $verification = $client->verifyInvoice($invoice->id);
        $this->assertTrue($verification->verified);
        $this->assertSame($invoice->orderId, $verification->orderId);

        try {
            $client->verifyInvoice($invoice->id);
            $this->fail('verified twice');
        } catch (ApiException $error) {
            $this->assertSame('already_verified', $error->getErrorCode());
        }
    }

    public function testTheSameOrderIdReturnsTheSameInvoice(): void
    {
        $client = $this->client();
        $params = array('amount_rial' => 50000, 'order_id' => $this->orderId('same'));
        $this->assertSame($client->createInvoice($params)->id, $client->createInvoice($params)->id);
    }

    public function testInvoiceForOrderFollowsAChangedTotalAndStopsAtPaid(): void
    {
        $client = $this->client();
        $order = $this->orderId('order');
        $first = $client->invoiceForOrder($order, array('amount_toman' => 10000));
        $this->assertSame($order, $first->orderId);

        $second = $client->invoiceForOrder($order, array('amount_toman' => 12000));
        $this->assertSame($order . '#2', $second->orderId);
        $this->assertTrue($client->getInvoice($first->id)->isCancelled(), 'the old amount was closed');

        $this->assertSame($second->id, $client->invoiceForOrder($order, array('amount_toman' => 12000))->id, 'reused');

        $client->simulatePayment($second->id);
        $paid = $client->invoiceForOrder($order, array('amount_toman' => 12000));
        $this->assertTrue($paid->isPaid());
        $this->assertSame($second->id, $paid->id, 'paid on attempt 2: nothing new to pay');
        $this->assertSame($order, $paid->baseOrderId());
    }

    public function testCancelClosesAnOpenInvoice(): void
    {
        $client = $this->client();
        $invoice = $client->createInvoice(array('amount_rial' => 70000, 'order_id' => $this->orderId('cancel')));
        $this->assertTrue($client->cancelInvoice($invoice->id)->isCancelled());
    }

    public function testAWellFormedButUnknownKeyIsRefused(): void
    {
        $this->expectException(AuthenticationException::class);
        (new Client('ag_test_' . str_repeat('a', 48), array('base_url' => $this->base)))->getInvoice('inv_nothing');
    }

    public function testALiveKeyCannotSimulateAPayment(): void
    {
        if (!getenv('ABAN_E2E_LIVE_KEY')) {
            $this->markTestSkipped('ABAN_E2E_LIVE_KEY not set');
        }
        $live = $this->client('LIVE');
        $invoice = $live->createInvoice(array('amount_rial' => 80000, 'order_id' => $this->orderId('live')));
        $this->assertFalse($invoice->isTest);
        try {
            $live->simulatePayment($invoice->id);
            $this->fail('a live key simulated a payment');
        } catch (PermissionException $error) {
            $this->assertSame('sandbox_only', $error->getErrorCode());
        } finally {
            $live->cancelInvoice($invoice->id);
        }
    }
}
