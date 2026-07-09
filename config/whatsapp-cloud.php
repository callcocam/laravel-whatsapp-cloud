<?php

use Callcocam\WhatsAppCloud\Models\WhatsAppNumber;

return [

    /*
    |--------------------------------------------------------------------------
    | Graph API version
    |--------------------------------------------------------------------------
    |
    | The default Graph API version every request targets
    | (https://graph.facebook.com/{version}/...). A tenant's credentials may
    | override this per-connection via WhatsAppCredentials::graphVersion().
    |
    */

    'graph_version' => env('WHATSAPP_CLOUD_GRAPH_VERSION', 'v21.0'),

    /*
    |--------------------------------------------------------------------------
    | Meta App identity
    |--------------------------------------------------------------------------
    |
    | app_secret validates the webhook's X-Hub-Signature-256; verify_token is
    | echoed back on the hub-challenge handshake. Never commit real values —
    | keep them in the environment.
    |
    */

    'app_id' => env('WHATSAPP_CLOUD_APP_ID'),
    'app_secret' => env('WHATSAPP_CLOUD_APP_SECRET'),
    'verify_token' => env('WHATSAPP_CLOUD_VERIFY_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Default credentials (dev / single-tenant)
    |--------------------------------------------------------------------------
    |
    | WhatsApp::for() with no tenant falls back to these. Multi-tenant apps bind
    | a WhatsAppCredentialsResolver instead and leave these blank.
    |
    */

    'default' => [
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_CLOUD_ACCESS_TOKEN'),
        'waba_id' => env('WHATSAPP_CLOUD_WABA_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook route
    |--------------------------------------------------------------------------
    |
    | The package auto-registers GET+POST routes under this prefix. Set enabled
    | to false to register them yourself (point them at the WebhookController).
    |
    */

    'webhook' => [
        'enabled' => env('WHATSAPP_CLOUD_WEBHOOK_ENABLED', true),
        'prefix' => env('WHATSAPP_CLOUD_WEBHOOK_PREFIX', 'webhooks/whatsapp/cloud'),
        'name' => 'whatsapp.cloud',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Template management panel (Inertia + Vue)
    |--------------------------------------------------------------------------
    |
    | A web page to create/list/edit/delete/send templates, powered by the same
    | TemplateManager the CLI uses. It only registers when Inertia is installed
    | (inertiajs/inertia-laravel) and `enabled` is true.
    |
    | The panel is powerful (it can create/delete/send). It defaults to the
    | ['web', 'auth'] middleware — adjust it (e.g. add an authorization gate) to
    | match your app. `ui_token`, when set, is an extra defense: every request
    | must send the same value in the X-WA-UI-Token header.
    |
    */

    'panel' => [
        'enabled' => env('WHATSAPP_CLOUD_PANEL_ENABLED', true),
        'prefix' => env('WHATSAPP_CLOUD_PANEL_PREFIX', 'whatsapp/cloud/templates'),
        'name' => 'whatsapp.cloud.panel',
        'middleware' => ['web', 'auth'],
        'ui_token' => env('WHATSAPP_CLOUD_PANEL_UI_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model implementing WhatsAppCredentials that the default
    | resolver reads from. Swap for your own model (implementing the contract or
    | using HasWhatsAppCredentials) when credentials live elsewhere.
    |
    */

    'model' => WhatsAppNumber::class,

    /*
    |--------------------------------------------------------------------------
    | Template definitions
    |--------------------------------------------------------------------------
    |
    | Maps each app message key to its approved Meta template. `params` is the
    | ORDERED list of body variable names ({{1}}, {{2}}...). Register dynamic
    | ones at runtime with WhatsApp::registerTemplate().
    |
    | 'assignment' => [
    |     'name' => 'my_assignment',
    |     'language' => 'pt_BR',
    |     'category' => 'utility',
    |     'params' => ['name', 'event', 'url'],
    | ],
    |
    */

    'templates' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Template definition files (management CLI)
    |--------------------------------------------------------------------------
    |
    | Directory holding <template>.php files that return a payload array (via
    | TemplateBuilder::...->toArray()). `whatsapp:template:create <name>` reads
    | from here. The copy is app content, so it lives in the app, not the package.
    |
    */

    'definitions_path' => env('WHATSAPP_CLOUD_DEFINITIONS_PATH'),

];
