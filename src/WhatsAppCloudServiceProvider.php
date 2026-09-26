<?php

namespace Callcocam\WhatsAppCloud;

use Callcocam\WhatsAppCloud\Console\CreateTemplate;
use Callcocam\WhatsAppCloud\Console\GetTemplate;
use Callcocam\WhatsAppCloud\Console\InstallCommand;
use Callcocam\WhatsAppCloud\Console\ListTemplates;
use Callcocam\WhatsAppCloud\Console\ScaffoldPanel;
use Callcocam\WhatsAppCloud\Console\SendTemplate;
use Callcocam\WhatsAppCloud\Contracts\MessageTransport;
use Callcocam\WhatsAppCloud\Contracts\SandboxRecipientProvider;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentialsResolver;
use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Http\Middleware\RequireConfiguredGate;
use Callcocam\WhatsAppCloud\Listeners\StoreInboundMessage;
use Callcocam\WhatsAppCloud\Models\WhatsAppNumber;
use Callcocam\WhatsAppCloud\Onboarding\EmbeddedSignup;
use Callcocam\WhatsAppCloud\Sandbox\SandboxTransport;
use Callcocam\WhatsAppCloud\Sandbox\TemplateDefinitions;
use Callcocam\WhatsAppCloud\Settings\SettingsStore;
use Callcocam\WhatsAppCloud\Support\ConfigCredentialsResolver;
use Callcocam\WhatsAppCloud\Support\NullSandboxRecipientProvider;
use Callcocam\WhatsAppCloud\Templates\TemplateRegistry;
use Callcocam\WhatsAppCloud\Transport\CloudApiTransport;
use Illuminate\Support\Facades\Event;
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

        // Who the sandbox can talk to. Empty by default — an app binds its own to
        // surface its contacts (see SandboxRecipientProvider).
        $this->app->bind(SandboxRecipientProvider::class, NullSandboxRecipientProvider::class);

        $this->app->singleton(WhatsAppManager::class, fn ($app) => new WhatsAppManager(
            factory: $app->make(CloudApiFactory::class),
            resolver: $app->make(WhatsAppCredentialsResolver::class),
            registry: $app->make(TemplateRegistry::class),
            defaultCredentials: (array) $app['config']->get('whatsapp-cloud.default', []),
        ));

        $this->app->alias(WhatsAppManager::class, 'whatsapp-cloud');

        $this->app->singleton(SettingsStore::class, fn ($app) => new SettingsStore($app['config']));

        $this->app->bind(EmbeddedSignup::class, fn ($app) => new EmbeddedSignup(
            graphVersion: (string) $app['config']->get('whatsapp-cloud.graph_version', 'v21.0'),
            appId: $app['config']->get('whatsapp-cloud.app_id'),
            appSecret: $app['config']->get('whatsapp-cloud.app_secret'),
            model: $app['config']->get('whatsapp-cloud.model', WhatsAppNumber::class),
        ));
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
        $this->applyStoredSettings();
        $this->registerRoutes();
        $this->registerPanelRoutes();
        $this->registerNumbersRoutes();
        $this->registerSetupRoutes();
        $this->registerSandboxRoutes();
        $this->registerInboundStore();
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
     * Layer the values saved from the setup wizard over the config, before
     * anything reads it. A no-op until the settings table exists.
     */
    protected function applyStoredSettings(): void
    {
        if (! $this->app['config']->get('whatsapp-cloud.setup.store', true)) {
            return;
        }

        // Never bake panel secrets into bootstrap/cache/config.php: they would
        // outlive a change made in the panel, in plain text.
        if ($this->app->runningInConsole() && in_array($_SERVER['argv'][1] ?? null, ['config:cache', 'optimize'], true)) {
            return;
        }

        $this->app->make(SettingsStore::class)->apply();
    }

    /**
     * Register the setup wizard. Same conditions as the other pages: enabled
     * AND Inertia installed.
     */
    protected function registerSetupRoutes(): void
    {
        $config = $this->app['config'];

        if (! $config->get('whatsapp-cloud.setup.enabled', true) || ! class_exists(Inertia::class)) {
            return;
        }

        $middleware = (array) $config->get('whatsapp-cloud.setup.middleware', ['web', 'auth']);

        // The wizard reads and exports every secret: never on `auth` alone.
        $gate = $config->get('whatsapp-cloud.setup.gate');
        $middleware[] = filled($gate) ? 'can:'.$gate : RequireConfiguredGate::class.':WHATSAPP_CLOUD_SETUP_GATE';

        Route::group([
            'prefix' => $config->get('whatsapp-cloud.setup.prefix', 'whatsapp/cloud/setup'),
            'middleware' => $middleware,
            'as' => $config->get('whatsapp-cloud.setup.name', 'whatsapp.cloud.setup').'.',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/setup.php');
        });
    }

    /**
     * Register the connected-numbers page (Embedded Signup). Same conditions as
     * the template panel: enabled AND Inertia installed.
     */
    protected function registerNumbersRoutes(): void
    {
        $config = $this->app['config'];

        if (! $config->get('whatsapp-cloud.embedded_signup.enabled', true) || ! class_exists(Inertia::class)) {
            return;
        }

        $middleware = (array) $config->get('whatsapp-cloud.embedded_signup.middleware', ['web', 'auth']);

        // Connecting a number hands out a token over a WABA: never on `auth` alone.
        $gate = $config->get('whatsapp-cloud.embedded_signup.gate');
        $middleware[] = filled($gate) ? 'can:'.$gate : RequireConfiguredGate::class.':WHATSAPP_CLOUD_NUMBERS_GATE';

        Route::group([
            'prefix' => $config->get('whatsapp-cloud.embedded_signup.prefix', 'whatsapp/cloud/numbers'),
            'middleware' => $middleware,
            'as' => $config->get('whatsapp-cloud.embedded_signup.name', 'whatsapp.cloud.numbers').'.',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/numbers.php');
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

    /**
     * Log every inbound message to the store, unless the host opted out. The
     * listener runs synchronously in the webhook request so a message survives
     * a stopped queue.
     */
    protected function registerInboundStore(): void
    {
        if (! $this->app['config']->get('whatsapp-cloud.inbound.store', true)) {
            return;
        }

        Event::listen(WhatsAppMessageReceived::class, StoreInboundMessage::class);
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

        // Columns Embedded Signup fills (display number, business id, token
        // expiry). Separate tag so apps that already published the table above
        // can add just this.
        $this->publishes([
            __DIR__.'/../database/migrations/2026_09_26_000000_add_embedded_signup_columns_to_whatsapp_numbers_table.php' => database_path('migrations/'.date('Y_m_d_His', time() + 1).'_add_embedded_signup_columns_to_whatsapp_numbers_table.php'),
        ], 'whatsapp-cloud-embedded-signup-migrations');

        // Settings saved from the setup wizard (encrypted).
        $this->publishes([
            __DIR__.'/../database/migrations/2026_09_27_000000_create_whatsapp_settings_table.php' => database_path('migrations/'.date('Y_m_d_His', time() + 2).'_create_whatsapp_settings_table.php'),
        ], 'whatsapp-cloud-settings-migrations');

        // A separate tag, so an app that only sends messages never acquires the
        // sandbox tables.
        $this->publishes([
            __DIR__.'/../database/migrations/2026_07_12_000000_create_whatsapp_sandbox_tables.php' => database_path('migrations/'.date('Y_m_d_His').'_create_whatsapp_sandbox_tables.php'),
        ], 'whatsapp-cloud-sandbox-migrations');

        // The inbound message store (opt-out via `whatsapp-cloud.inbound.store`).
        $this->publishes([
            __DIR__.'/../database/migrations/2026_08_06_000000_create_whatsapp_inbound_messages_table.php' => database_path('migrations/'.date('Y_m_d_His').'_create_whatsapp_inbound_messages_table.php'),
        ], 'whatsapp-cloud-inbound-migrations');

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

        // Componentes Vue reutilizáveis (fora do painel). Ex.: PhoneInput — campo de
        // telefone que já entrega o `wa_id` no padrão da Meta. CSS puro, autocontido,
        // sem depender do design system do host. Namespace `whatsapp-cloud/` evita
        // colisão com o `components/ui` do app.
        $this->publishes([
            __DIR__.'/../resources/js/components/PhoneInput' => resource_path('js/components/whatsapp-cloud/PhoneInput'),
        ], 'whatsapp-cloud-vue-components');
    }
}
