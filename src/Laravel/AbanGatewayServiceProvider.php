<?php

declare(strict_types=1);

namespace AbanGateway\Laravel;

use AbanGateway\Client;
use AbanGateway\Laravel\Http\WebhookController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Registered by Laravel's package discovery; nothing to add by hand. */
final class AbanGatewayServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__ . '/../../config/abangateway.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'abangateway');

        // Built on first use, so an app that never touches the gateway does
        // not need a key configured to boot.
        $this->app->singleton(Client::class, static function ($app): Client {
            $config = (array) $app['config']->get('abangateway', array());
            return new Client((string) ($config['key'] ?? ''), array(
                'base_url' => (string) ($config['base_url'] ?? ''),
                'timeout' => (int) ($config['timeout'] ?? 30),
                'connect_timeout' => (int) ($config['connect_timeout'] ?? 10),
            ));
        });
        $this->app->alias(Client::class, 'abangateway');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(array(self::CONFIG => $this->app->configPath('abangateway.php')), 'abangateway-config');
        }

        $config = (array) $this->app['config']->get('abangateway.webhook', array());
        if (!empty($config['enabled'])) {
            Route::post((string) ($config['path'] ?? 'abangateway/webhook'), WebhookController::class)
                ->middleware((array) ($config['middleware'] ?? array()))
                ->name('abangateway.webhook');
        }
    }
}
