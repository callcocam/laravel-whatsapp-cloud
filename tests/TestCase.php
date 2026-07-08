<?php

namespace Callcocam\WhatsAppCloud\Tests;

use Callcocam\WhatsAppCloud\WhatsAppCloudServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            WhatsAppCloudServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
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
