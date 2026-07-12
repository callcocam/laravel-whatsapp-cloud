<?php

use Callcocam\WhatsAppCloud\Contracts\MessageTransport;
use Callcocam\WhatsAppCloud\Exceptions\SandboxException;
use Callcocam\WhatsAppCloud\Sandbox\SandboxTransport;
use Callcocam\WhatsAppCloud\Sandbox\TemplateResolver;
use Callcocam\WhatsAppCloud\Transport\CloudApiTransport;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Foundation\Application;

/**
 * The guard has to hold in BOTH directions, because both are dangerous — and both
 * fail silently.
 *
 *  - Sandbox left on in production: real messages become database rows. The
 *    customer never hears from you and the app believes it sent.
 *  - The sandbox screen up while the driver is `cloud`: a page labelled
 *    "simulator" fires real WhatsApp messages at a real phone, and bills for them.
 */
it('refuses to run the sandbox driver in production', function () {
    // Asserted on the constructor rather than by flipping the app's environment:
    // switching the whole harness to production makes Testbench start prompting
    // for confirmation before it will migrate. The guard is what matters here.
    $production = Mockery::mock(Application::class);
    $production->shouldReceive('isProduction')->andReturnTrue();

    expect(fn () => new SandboxTransport(app(TemplateResolver::class), $production))
        ->toThrow(SandboxException::class, 'refuses to run in production');
});

it('does not register the sandbox screen when the driver is cloud', function () {
    // The default driver. The route must not exist at all — a simulator UI on top
    // of the real wire is worse than no simulator.
    expect(config('whatsapp-cloud.driver'))->toBe('cloud');

    $this->actingAs(new GenericUser(['id' => 1]))
        ->get('whatsapp/cloud/sandbox')
        ->assertNotFound();
});

it('keeps the real transport on the default driver', function () {
    expect(app(MessageTransport::class))->toBeInstanceOf(CloudApiTransport::class)
        ->and(app(MessageTransport::class))->not->toBeInstanceOf(SandboxTransport::class);
});
