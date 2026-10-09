<?php

declare(strict_types=1);

namespace AbanGateway\Tests\Laravel;

final class DisabledRouteTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('abangateway.webhook.enabled', false);
    }

    public function testTheRouteCanBeSwitchedOffForAppsThatHandleWebhooksThemselves(): void
    {
        $this->post('/abangateway/webhook')->assertNotFound();
    }
}
