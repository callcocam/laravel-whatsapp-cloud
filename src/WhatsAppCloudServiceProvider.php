<?php

namespace Callcocam\WhatsAppCloud;

use Callcocam\WhatsAppCloud\Console\CreateTemplate;
use Callcocam\WhatsAppCloud\Console\GetTemplate;
use Callcocam\WhatsAppCloud\Console\InstallCommand;
use Callcocam\WhatsAppCloud\Console\ListTemplates;
use Callcocam\WhatsAppCloud\Console\SendTemplate;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentialsResolver;
use Callcocam\WhatsAppCloud\Support\ConfigCredentialsResolver;
use Callcocam\WhatsAppCloud\Templates\TemplateRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class WhatsAppCloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/whatsapp-cloud.php', 'whatsapp-cloud');

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

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerPanelRoutes();
        $this->registerPublishing();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ListTemplates::class,
                GetTemplate::class,
                CreateTemplate::class,
                SendTemplate::class,
                InstallCommand::class,
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

        Route::group([
            'prefix' => $config->get('whatsapp-cloud.panel.prefix', 'whatsapp/cloud/templates'),
            'middleware' => $config->get('whatsapp-cloud.panel.middleware', ['web', 'auth']),
            'as' => $config->get('whatsapp-cloud.panel.name', 'whatsapp.cloud.panel').'.',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/panel.php');
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

        // The panel's Vue pages must be compiled by the host app's Vite build, so
        // publish them into resources/js/Pages/ where the default Inertia page
        // resolver (`resolvePageComponent('./Pages/**/*.vue')`) finds them.
        $this->publishes([
            __DIR__.'/../resources/js/Pages/WhatsAppCloud' => resource_path('js/Pages/WhatsAppCloud'),
        ], 'whatsapp-cloud-inertia');
    }
}
