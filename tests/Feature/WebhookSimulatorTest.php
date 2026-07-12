<?php

use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Exceptions\SandboxException;
use Callcocam\WhatsAppCloud\Sandbox\InboundPayloadFactory;
use Callcocam\WhatsAppCloud\Sandbox\WebhookSimulator;
use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->payload = InboundPayloadFactory::for(
        app(WhatsAppManager::class)->credentials(),
        waId: '5548999999999',
        profileName: 'Maria',
    )->text('Olá!');
});

it('signs the payload and gets it past the real signature check', function () {
    $result = app(WebhookSimulator::class)->deliver($this->payload);

    expect($result->status)->toBe(200)
        ->and($result->succeeded())->toBeTrue()
        ->and($result->signature)->toStartWith('sha256=')
        // The signature must verify against the raw body we actually sent — the
        // same computation the controller does.
        ->and($result->signature)->toBe('sha256='.hash_hmac('sha256', $result->body, 'test-app-secret'));
});

it('runs the app listeners for real', function () {
    $seen = null;
    Event::listen(WhatsAppMessageReceived::class, function ($event) use (&$seen) {
        $seen = $event->message['text']['body'];
    });

    app(WebhookSimulator::class)->deliver($this->payload);

    expect($seen)->toBe('Olá!');
});

it('hands back a listener exception instead of swallowing it', function () {
    // This is the reason we invoke the controller directly. Kernel::handle()
    // would have caught this and returned an opaque 500 — the developer would
    // see "something failed" and nothing else.
    Event::listen(WhatsAppMessageReceived::class, function () {
        throw new RuntimeException('the app listener blew up');
    });

    $result = app(WebhookSimulator::class)->deliver($this->payload);

    expect($result->failure)->toBeInstanceOf(RuntimeException::class)
        ->and($result->failure->getMessage())->toBe('the app listener blew up')
        ->and($result->succeeded())->toBeFalse()
        ->and($result->toArray()['failure']['class'])->toBe(RuntimeException::class);
});

it('leaves the outer request untouched', function () {
    // The anti-regression test for NOT re-entering the kernel. If the simulator
    // ever rebinds app('request'), the Inertia page that called it starts
    // rendering against the webhook's request — back(), shared props and Ziggy
    // all silently point at the wrong place.
    Route::get('/outer', function () {
        $before = [
            'request' => app('request'),
            'url' => url()->current(),
            'route' => Route::current()?->uri(),
        ];

        app(WebhookSimulator::class)->deliver(
            InboundPayloadFactory::for(
                app(WhatsAppManager::class)->credentials(),
                waId: '5548999999999',
                profileName: 'Maria',
            )->text('oi'),
        );

        return response()->json([
            'same_request' => app('request') === $before['request'],
            'same_url' => url()->current() === $before['url'],
            'same_route' => Route::current()?->uri() === $before['route'],
            'url' => url()->current(),
        ]);
    })->middleware('web');

    $this->get('/outer')->assertOk()->assertJson([
        'same_request' => true,
        'same_url' => true,
        'same_route' => true,
        'url' => 'http://localhost/outer',
    ]);
});

it('reports the registered listeners for the inspector', function () {
    Event::listen(WhatsAppMessageReceived::class, 'App\\Listeners\\RouteToOperator@handle');

    $result = app(WebhookSimulator::class)->deliver($this->payload);

    expect($result->listeners)->toContain('App\\Listeners\\RouteToOperator@handle');
});

it('refuses to run without an app secret instead of failing with a silent 403', function () {
    // With no secret the controller rejects every payload with a 403 and no
    // explanation. The developer would click "reply" and watch nothing happen.
    config()->set('whatsapp-cloud.app_secret', null);

    expect(fn () => app(WebhookSimulator::class)->deliver($this->payload))
        ->toThrow(SandboxException::class, 'app_secret` is empty');
});
