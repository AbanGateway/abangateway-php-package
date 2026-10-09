<?php

declare(strict_types=1);

namespace AbanGateway\Tests\Laravel;

use AbanGateway\Laravel\AbanGatewayServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    public const KEY = 'ag_test_aaaaaaaaaaaaaaaabbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** Package discovery is what a real app relies on; list it the same way. */
    protected function getPackageProviders($app)
    {
        return array(AbanGatewayServiceProvider::class);
    }

    protected function getPackageAliases($app)
    {
        return array('AbanGateway' => \AbanGateway\Laravel\Facades\AbanGateway::class);
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('abangateway.key', self::KEY);
        $app['config']->set('abangateway.webhook_secret', $this->fixture()['secret']);
    }

    /** @return array{body: string, secret: string, signature: string} */
    protected function fixture(): array
    {
        // Signed by the backend's own services.webhooks.sign.
        return json_decode((string) file_get_contents('/pkg/tests/fixtures/webhook-paid.json'), true);
    }
}
