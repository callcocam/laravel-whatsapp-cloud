<?php

namespace Callcocam\WhatsAppCloud\Tests;

use Callcocam\WhatsAppCloud\WhatsAppCloudServiceProvider;
use Inertia\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        $providers = [
            WhatsAppCloudServiceProvider::class,
        ];

        // Inertia powers the template panel; it's a dev dependency here.
        if (class_exists(ServiceProvider::class)) {
            $providers[] = ServiceProvider::class;
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        // The template panel runs under the `web` middleware group, which
        // encrypts cookies and needs a valid key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // Inertia renders into a root view named `app`; provide a minimal one so
        // the panel page can render in tests.
        $app['config']->set('view.paths', array_merge(
            (array) $app['config']->get('view.paths', []),
            [__DIR__.'/fixtures/views'],
        ));

        // Point Inertia's page-existence check at the package's Vue pages so
        // assertInertia() verifies the component file actually ships.
        $app['config']->set('inertia.testing.page_paths', [dirname(__DIR__).'/resources/js/pages']);
        $app['config']->set('whatsapp-cloud.graph_version', 'v21.0');
        $app['config']->set('whatsapp-cloud.app_secret', 'test-app-secret');
        $app['config']->set('whatsapp-cloud.verify_token', 'test-verify-token');
        $app['config']->set('whatsapp-cloud.default', [
            'phone_number_id' => '111222333',
            'access_token' => 'default-token',
            'waba_id' => '999888777',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
