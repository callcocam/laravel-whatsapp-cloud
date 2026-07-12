<?php

namespace Callcocam\WhatsAppCloud;

use Callcocam\WhatsAppCloud\Console\CreateTemplate;
use Callcocam\WhatsAppCloud\Console\GetTemplate;
use Callcocam\WhatsAppCloud\Console\InstallCommand;
use Callcocam\WhatsAppCloud\Console\ListTemplates;
use Callcocam\WhatsAppCloud\Console\ScaffoldPanel;
use Callcocam\WhatsAppCloud\Console\SendTemplate;
use Callcocam\WhatsAppCloud\Contracts\MessageTransport;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentialsResolver;
use Callcocam\WhatsAppCloud\Sandbox\SandboxTransport;
use Callcocam\WhatsAppCloud\Sandbox\TemplateDefinitions;
use Callcocam\WhatsAppCloud\Support\ConfigCredentialsResolver;
use Callcocam\WhatsAppCloud\Templates\TemplateRegistry;
use Callcocam\WhatsAppCloud\Transport\CloudApiTransport;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use InvalidArgumentException;

class WhatsAppCloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/whatsapp-cloud.php', 'whatsapp-cloud');

        $this->registerTransport();

        $this->app->singleton(TemplateRegistry::class, fn ($app) => new TemplateRegistry(
            (array) $app['config']->get('whatsapp-cloud.templates', []),
        ));

        $this->app->singleton(CloudApiFactory::class, fn ($app) => new CloudApiFactory(
            graphVersion: (string) $app['config']->get('whatsapp-cloud.graph_version', 'v21.0'),
            templates: $app->make(TemplateRegistry::class),
        ));

        // The default resolver serves the config `default` credentials for any
        // context (dev / single-tenant). Multi-tenant apps rebind this.
        $this->app->bind(WhatsAppCredentialsResolver::class, fn ($app) => new ConfigCredentialsResolver(
            (array) $app['config']->get('whatsapp-cloud.default', []),
        ));

        $this->app->singleton(WhatsAppManager::class, fn ($app) => new WhatsAppManager(
            factory: $app->make(CloudApiFactory::class),
            resolver: $app->make(WhatsAppCredentialsResolver::class),
            registry: $app->make(TemplateRegistry::class),
            defaultCredentials: (array) $app['config']->get('whatsapp-cloud.default', []),
        ));

        $this->app->alias(WhatsAppManager::class, 'whatsapp-cloud');
    }

    /**
     * Bind the wire every outbound message travels on.
     *
     * `bind`, never `singleton`: a test — or a developer flipping the driver —
     * must be able to change the answer after the manager was already resolved.
     * A cached singleton here would silently keep sending to Meta.
     */
    protected function registerTransport(): void
    {
        $this->app->bind(CloudApiTransport::class, fn () => new CloudApiTransport);

        $this->app->bind(TemplateDefinitions::class, fn ($app) => new TemplateDefinitions(
            $app['config']->get('whatsapp-cloud.definitions_path'),
        ));

        $this->app->bind(MessageTransport::class, function ($app) {
            $driver = (string) $app['config']->get('whatsapp-cloud.driver', 'cloud');

            return match ($driver) {
                'cloud' => $app->make(CloudApiTransport::class),
                'sandbox' => $app->make(SandboxTransport::class),
                default => throw new InvalidArgumentException(
                    "Unknown whatsapp-cloud driver [{$driver}]. Expected 'cloud' or 'sandbox'.",
                ),
            };
        });
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerPanelRoutes();
        $this->registerSandboxRoutes();
        $this->registerPublishing();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ListTemplates::class,
                GetTemplate::class,
                CreateTemplate::class,
                SendTemplate::class,
                InstallCommand::class,
                ScaffoldPanel::class,
            ]);
        }
    }

    /**
     * Auto-register the webhook routes under the configured prefix/middleware,
     * unless the app opted to register them itself.
     */
    protected function registerRoutes(): void
    {
        $config = $this->app['config'];

        if (! $config->get('whatsapp-cloud.webhook.enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => $config->get('whatsapp-cloud.webhook.prefix', 'webhooks/whatsapp/cloud'),
            'middleware' => $config->get('whatsapp-cloud.webhook.middleware', ['api']),
            'as' => $config->get('whatsapp-cloud.webhook.name', 'whatsapp.cloud').'.',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
        });
    }

    /**
     * Register the Inertia template-management panel routes. Only wires up when
     * the panel is enabled AND Inertia is installed — the core library stays
     * headless for apps that only send messages.
     */
    protected function registerPanelRoutes(): void
    {
        $config = $this->app['config'];

        if (! $config->get('whatsapp-cloud.panel.enabled', true) || ! class_exists(Inertia::class)) {
            return;
        }

        $middleware = (array) $config->get('whatsapp-cloud.panel.middleware', ['web', 'auth']);

        // The panel mutates the WABA (shared across tenants). When a gate is
        // configured, require it on every panel request via `can:<gate>`.
        if ($gate = $config->get('whatsapp-cloud.panel.gate')) {
            $middleware[] = 'can:'.$gate;
        }

        Route::group([
            'prefix' => $config->get('whatsapp-cloud.panel.prefix', 'whatsapp/cloud/templates'),
            'middleware' => $middleware,
            'as' => $config->get('whatsapp-cloud.panel.name', 'whatsapp.cloud.panel').'.',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/panel.php');
        });
    }

    /**
     * Register the sandbox screen.
     *
     * The guard runs both ways, because both directions are dangerous:
     *
     *  - The screen only exists when the driver IS `sandbox`. A sandbox UI up
     *    while the driver is `cloud` would fire real WhatsApp messages at a real
     *    phone from a page labelled "simulator".
     *  - And never in production, whatever the config says.
     */
    protected function registerSandboxRoutes(): void
    {
        $config = $this->app['config'];

        if ($config->get('whatsapp-cloud.driver') !== 'sandbox' || ! class_exists(Inertia::class)) {
            return;
        }

        if ($this->app->isProduction()) {
            return;
        }

        Route::group([
            'prefix' => $config->get('whatsapp-cloud.sandbox.prefix', 'whatsapp/cloud/sandbox'),
            'middleware' => (array) $config->get('whatsapp-cloud.sandbox.middleware', ['web', 'auth']),
            'as' => 'whatsapp.cloud.sandbox.',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/sandbox.php');
        });
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/whatsapp-cloud.php' => config_path('whatsapp-cloud.php'),
        ], 'whatsapp-cloud-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2026_01_01_000000_create_whatsapp_numbers_table.php' => database_path('migrations/'.date('Y_m_d_His').'_create_whatsapp_numbers_table.php'),
        ], 'whatsapp-cloud-migrations');

        // A separate tag, so an app that only sends messages never acquires the
        // sandbox tables.
        $this->publishes([
            __DIR__.'/../database/migrations/2026_07_12_000000_create_whatsapp_sandbox_tables.php' => database_path('migrations/'.date('Y_m_d_His').'_create_whatsapp_sandbox_tables.php'),
        ], 'whatsapp-cloud-sandbox-migrations');

        // The panel's Vue pages must be compiled by the host app's Vite build, so
        // publish them into resources/js/pages/ where the Inertia page resolver
        // of the Laravel starter kits (`resolvePageComponent('./pages/**/*.vue')`)
        // finds them. Same destination the native scaffold writes to, so both
        // panel modes land in one place.
        $this->publishes([
            __DIR__.'/../resources/js/pages/WhatsAppCloud' => resource_path('js/pages/WhatsAppCloud'),
        ], 'whatsapp-cloud-inertia');

        // The sandbox page lives OUTSIDE resources/js/pages on purpose. That
        // publish above maps a DIRECTORY, recursively — a Sandbox/ folder in there
        // would be copied into every production app that publishes the panel, and
        // compiled into its bundle. Separate source, separate tag.
        $this->publishes([
            __DIR__.'/../resources/js/sandbox/WhatsAppCloud/Sandbox' => resource_path('js/pages/WhatsAppCloud/Sandbox'),
        ], 'whatsapp-cloud-sandbox');
    }
}
