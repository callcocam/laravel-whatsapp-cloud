<?php

use Callcocam\WhatsAppCloud\Contracts\MessageTransport;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;
use Callcocam\WhatsAppCloud\Templates\MetaTemplate;
use Callcocam\WhatsAppCloud\Transport\CloudApiTransport;
use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Support\Facades\Http;

/**
 * A transport that records instead of sending. This is the seam the sandbox
 * driver will occupy — if a send path bypasses it here, that path would reach a
 * real phone in sandbox mode.
 */
class RecordingTransport implements MessageTransport
{
    /** @var list<array{credentials: WhatsAppCredentials, envelope: array<string, mixed>}> */
    public array $sent = [];

    public function postMessage(WhatsAppCredentials $credentials, array $envelope): array
    {
        $this->sent[] = ['credentials' => $credentials, 'envelope' => $envelope];

        return ['messages' => [['id' => 'wamid.RECORDED']]];
    }
}

it('routes the data plane through the bound transport', function () {
    Http::preventStrayRequests();
    $transport = new RecordingTransport;
    app()->instance(MessageTransport::class, $transport);

    app(WhatsAppManager::class)
        ->registerTemplate('assignment', new MetaTemplate('coordena_assignment', 'pt_BR', 'utility', ['name']))
        ->for()
        ->sendTemplate('5548999999999', TemplateMessage::make('assignment', ['name' => 'Maria']));

    expect($transport->sent)->toHaveCount(1);

    $sent = $transport->sent[0];

    expect($sent['envelope']['to'])->toBe('5548999999999')
        ->and($sent['envelope']['type'])->toBe('template')
        ->and($sent['envelope']['template']['name'])->toBe('coordena_assignment')
        ->and($sent['credentials']->phoneNumberId())->toBe('111222333');
});

it('routes the control-plane test-send through the SAME transport', function () {
    // The panel's "send test" button calls TemplateManager::send(). If it skipped
    // the transport, the sandbox would still fire a real WhatsApp message.
    Http::preventStrayRequests();
    $transport = new RecordingTransport;
    app()->instance(MessageTransport::class, $transport);

    app(WhatsAppManager::class)->templateApi()->send('coordena_x', '5548888888888', ['Ana']);

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['envelope']['type'])->toBe('template')
        ->and($transport->sent[0]['envelope']['to'])->toBe('5548888888888');
});

it('leaves template MANAGEMENT calls on the real wire', function () {
    // Templates are real objects on a real WABA — there is nothing to simulate.
    // Only delivery is diverted.
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);
    app()->instance(MessageTransport::class, new RecordingTransport);

    app(WhatsAppManager::class)->templateApi()->all();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/message_templates'));
});

it('defaults to the real Cloud API transport', function () {
    expect(app(MessageTransport::class))->toBeInstanceOf(CloudApiTransport::class);
});

it('rejects an unknown driver instead of silently falling back to the real wire', function () {
    config()->set('whatsapp-cloud.driver', 'nonsense');

    expect(fn () => app(MessageTransport::class))
        ->toThrow(InvalidArgumentException::class, 'Unknown whatsapp-cloud driver [nonsense]');
});

it('resolves the driver at send time, not at build time', function () {
    // The manager and the factory are singletons. A client built while the driver
    // was `cloud` must still honour a driver swapped afterwards — otherwise a
    // cached client would keep talking to Meta after the sandbox was turned on.
    Http::preventStrayRequests();

    $client = app(WhatsAppManager::class)
        ->registerTemplate('ping', new MetaTemplate('coordena_ping', 'pt_BR', 'utility', []))
        ->for();

    $transport = new RecordingTransport;
    app()->instance(MessageTransport::class, $transport);

    $client->sendTemplate('5548777777777', TemplateMessage::make('ping'));

    expect($transport->sent)->toHaveCount(1);
});
