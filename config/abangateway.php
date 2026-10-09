<?php

/*
 * AbanGateway for Laravel. Publish with:
 *   php artisan vendor:publish --tag=abangateway-config
 */

return [

    // The shop's API key from the panel (Settings, API keys). ag_test_ keys
    // make test invoices; ag_live_ keys take real payments.
    'key' => env('ABANGATEWAY_KEY', ''),

    // The webhook signing key (panel: Settings, Webhook, «ساخت کلید امضا»).
    // Without it webhooks still arrive, unsigned; every one is confirmed with
    // the API before an event fires either way.
    'webhook_secret' => env('ABANGATEWAY_WEBHOOK_SECRET', ''),

    // Leave empty unless AbanGateway support gives you another address.
    'base_url' => env('ABANGATEWAY_BASE_URL', ''),

    'timeout' => 30,
    'connect_timeout' => 10,

    'webhook' => [
        // Registers POST /{path}. Use route('abangateway.webhook') as the
        // callback_url of your invoices. Outside the "web" group on purpose:
        // a webhook carries no CSRF token.
        'enabled' => true,
        'path' => 'abangateway/webhook',
        'middleware' => [],
    ],

];
