<?php

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentialsResolver;
use Callcocam\WhatsAppCloud\Exceptions\WhatsAppNotConfiguredException;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;
use Callcocam\WhatsAppCloud\Support\ArrayCredentials;
use Callcocam\WhatsAppCloud\Templates\MetaTemplate;
use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends through the config default credentials when no tenant is given', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w.1']]])]);

    $manager = app(WhatsAppManager::class);
    $manager->registerTemplate('ping', new MetaTemplate('coordena_ping'));

    $manager->for()->sendTemplate('5548000000000', TemplateMessage::make('ping'));

    // config default phone_number_id is 111222333 (see TestCase).
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v21.0/111222333/messages'));
});

it('resolves per-tenant credentials through the bound resolver', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w.2']]])]);

    app()->bind(WhatsAppCredentialsResolver::class, fn () => new class implements WhatsAppCredentialsResolver
    {
        public function resolve(mixed $context): ?WhatsAppCredentials
        {
            return $context === 'team-a'
                ? new ArrayCredentials('555000111', 'team-a-token', 'waba-a', 'v20.0')
                : null;
        }
    });

    $manager = app(WhatsAppManager::class);
    $manager->registerTemplate('ping', new MetaTemplate('coordena_ping'));

    $manager->for('team-a')->sendTemplate('5548000000000', TemplateMessage::make('ping'));

    Http::assertSent(function (Request $r) {
        return str_contains($r->url(), '/v20.0/555000111/messages')
            && $r->hasHeader('Authorization', 'Bearer team-a-token');
    });
});

it('throws when the tenant has no resolvable credentials', function () {
    app()->bind(WhatsAppCredentialsResolver::class, fn () => new class implements WhatsAppCredentialsResolver
    {
        public function resolve(mixed $context): ?WhatsAppCredentials
        {
            return null;
        }
    });

    expect(fn () => app(WhatsAppManager::class)->for('unknown'))
        ->toThrow(WhatsAppNotConfiguredException::class);
});

it('throws when no default credentials are configured and no tenant is given', function () {
    config()->set('whatsapp-cloud.default', []);

    // Rebuild the manager so it picks up the emptied default config.
    app()->forgetInstance(WhatsAppManager::class);

    expect(fn () => app(WhatsAppManager::class)->for())
        ->toThrow(WhatsAppNotConfiguredException::class);
});
