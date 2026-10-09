<?php

declare(strict_types=1);

namespace AbanGateway\Tests\Laravel;

use AbanGateway\Client;
use AbanGateway\Laravel\Events\InvoiceCancelled;
use AbanGateway\Laravel\Events\InvoiceExpired;
use AbanGateway\Laravel\Events\InvoicePaid;
use AbanGateway\Laravel\Events\InvoicePartiallyPaid;
use AbanGateway\Tests\Fakes\FakeTransport;
use Illuminate\Support\Facades\Event;

final class WebhookRouteTest extends TestCase
{
    private const OURS = array(InvoicePaid::class, InvoicePartiallyPaid::class, InvoiceExpired::class, InvoiceCancelled::class);

    /** @var FakeTransport */
    private $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new FakeTransport();
        $this->app->instance(Client::class, new Client(self::KEY, array('transport' => $this->api)));
    }

    private function deliver(?string $signature = null, ?string $body = null)
    {
        $f = $this->fixture();
        return $this->call('POST', '/abangateway/webhook', array(), array(), array(), array(
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature === null ? $f['signature'] : $signature,
            'HTTP_X_DELIVERY_ID' => 'whd_test',
        ), $body === null ? $f['body'] : $body);
    }

    /** Laravel fires its own events on every request; only ours are faked. */
    private function fakeOurs(): void
    {
        Event::fake(self::OURS);
    }

    private function assertNoneOfOursDispatched(): void
    {
        foreach (self::OURS as $class) {
            Event::assertNotDispatched($class);
        }
    }

    private function apiSays(string $status): void
    {
        $this->api->answer(200, FakeTransport::invoice(array(
            'invoice_id' => 'inv_9fk2m4qx7t3p8wzy1a6b', 'order_id' => 'ORD-1043#2', 'status' => $status,
        )));
    }

    public function testAPaidInvoiceConfirmedByTheApiFiresInvoicePaid(): void
    {
        $this->fakeOurs();
        $this->apiSays('paid');
        $this->deliver()->assertOk()->assertJson(array('ok' => true, 'status' => 'paid'));

        Event::assertDispatched(InvoicePaid::class, function (InvoicePaid $event) {
            return $event->invoice->id === 'inv_9fk2m4qx7t3p8wzy1a6b'
                && $event->orderId() === 'ORD-1043'
                && $event->webhook->deliveryId === 'whd_test';
        });
        $this->assertStringEndsWith('/api/v1/invoices/inv_9fk2m4qx7t3p8wzy1a6b', $this->api->requests[0]['url']);
    }

    public function testABadSignatureIsRefusedBeforeTheApiIsAsked(): void
    {
        $this->fakeOurs();
        $this->deliver(str_repeat('0', 64))->assertStatus(401);
        $this->assertNoneOfOursDispatched();
        $this->assertCount(0, $this->api->requests);
    }

    public function testAWebhookClaimingPaidForAPendingInvoiceFiresNothing(): void
    {
        $this->fakeOurs();
        $this->apiSays('pending');
        $this->deliver()->assertOk()->assertJson(array('status' => 'pending'));
        $this->assertNoneOfOursDispatched();
    }

    public function testEachStatusFiresItsOwnEvent(): void
    {
        $cases = array(
            'expired' => InvoiceExpired::class,
            'cancelled' => InvoiceCancelled::class,
            'partially_paid' => InvoicePartiallyPaid::class,
        );
        foreach ($cases as $status => $class) {
            $this->fakeOurs();
            $this->apiSays($status);
            $this->deliver()->assertOk();
            Event::assertDispatched($class);
            Event::assertNotDispatched(InvoicePaid::class);
        }
    }

    public function testAnInvoiceTheApiDoesNotKnowIsAcknowledgedAndIgnored(): void
    {
        $this->fakeOurs();
        $this->api->answer(404, array('error' => array('code' => 'invoice_not_found', 'message' => 'no')));
        $this->deliver()->assertOk()->assertJson(array('ignored' => 'unknown invoice'));
        $this->assertNoneOfOursDispatched();
    }

    public function testWhenTheApiCannotBeAskedAbanGatewayIsToldToRetry(): void
    {
        $this->fakeOurs();
        $this->api->answer(503, array('error' => array('code' => 'capacity_full', 'message' => 'busy')));
        $this->deliver()->assertStatus(502);
        $this->assertNoneOfOursDispatched();
    }

    public function testAListenerThatFailsMakesTheDeliveryFailSoItIsRetried(): void
    {
        $this->apiSays('paid');
        Event::listen(InvoicePaid::class, function () {
            throw new \RuntimeException('warehouse offline');
        });
        $this->deliver()->assertStatus(500);
    }

    public function testWithoutASigningKeyUnsignedWebhooksAreStillConfirmed(): void
    {
        config(array('abangateway.webhook_secret' => ''));
        $this->fakeOurs();
        $this->apiSays('pending');
        $this->deliver('')->assertOk();
        $this->assertNoneOfOursDispatched();
        $this->assertCount(1, $this->api->requests, 'asked the API instead of believing the body');
    }
}
