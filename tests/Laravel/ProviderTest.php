<?php

declare(strict_types=1);

namespace AbanGateway\Tests\Laravel;

use AbanGateway\Client;
use AbanGateway\Laravel\Facades\AbanGateway;
use Illuminate\Support\Facades\Route;

final class ProviderTest extends TestCase
{
    public function testTheClientIsOneSharedInstanceBuiltFromConfig(): void
    {
        $client = $this->app->make(Client::class);
        $this->assertSame($client, $this->app->make('abangateway'));
        $this->assertTrue($client->isTestKey());
        $this->assertTrue(AbanGateway::isTestKey(), 'the facade reaches the same client');
    }

    public function testDefaultsAreMergedSoNothingIsRequiredToBoot(): void
    {
        $this->assertSame('abangateway/webhook', config('abangateway.webhook.path'));
        $this->assertSame(30, config('abangateway.timeout'));
    }

    public function testTheWebhookRouteIsRegisteredOutsideTheWebGroup(): void
    {
        $route = Route::getRoutes()->getByName('abangateway.webhook');
        $this->assertNotNull($route);
        $this->assertSame(array('POST'), $route->methods());
        $this->assertSame('abangateway/webhook', $route->uri());
        $this->assertNotContains('web', $route->gatherMiddleware(), 'a webhook carries no CSRF token');
    }

    public function testTheConfigCanBePublished(): void
    {
        $this->artisan('vendor:publish', array('--tag' => 'abangateway-config', '--force' => true))->assertExitCode(0);
        $this->assertFileExists(config_path('abangateway.php'));
        @unlink(config_path('abangateway.php'));
    }
}
