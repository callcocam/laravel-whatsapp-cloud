<?php

use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Events\WhatsAppStatusReceived;
use Callcocam\WhatsAppCloud\Sandbox\FaultCatalog;
use Callcocam\WhatsAppCloud\Sandbox\InboundPayloadFactory;
use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * The point of this suite: every simulated payload is pushed through the REAL
 * webhook route, with a REAL HMAC signature, and must arrive as a real event.
 *
 * If a shape here drifts from Meta's, the sandbox becomes a machine for
 * validating code against a WhatsApp that does not exist.
 */
function deliver(array $payload): TestResponse
{
    $body = (string) json_encode($payload);

    return test()->call('POST', 'webhooks/whatsapp/cloud', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-app-secret'),
    ], $body);
}

function factory(): InboundPayloadFactory
{
    return InboundPayloadFactory::for(
        app(WhatsAppManager::class)->credentials(),
        waId: '5548999999999',
        profileName: 'Maria',
    );
}

it('delivers a text reply through the real webhook route', function () {
    Event::fake([WhatsAppMessageReceived::class]);

    deliver(factory()->text('Olá!'))->assertOk();

    Event::assertDispatched(WhatsAppMessageReceived::class, function ($event) {
        expect($event->message['type'])->toBe('text')
            ->and($event->message['text']['body'])->toBe('Olá!')
            ->and($event->message['from'])->toBe('5548999999999')
            ->and($event->message['id'])->toStartWith('wamid.SANDBOX.')
            // The app resolves its tenant from this. A made-up id strands it.
            ->and($event->phoneNumberId)->toBe('111222333')
            ->and($event->value['contacts'][0]['profile']['name'])->toBe('Maria')
            ->and($event->value['metadata']['phone_number_id'])->toBe('111222333');

        return true;
    });
});

it('carries the context that correlates a reply to what we sent', function () {
    Event::fake([WhatsAppMessageReceived::class]);

    deliver(factory()->text('Aceito', replyTo: 'wamid.ORIGINAL'))->assertOk();

    Event::assertDispatched(WhatsAppMessageReceived::class, function ($event) {
        // Without context.id there is no way to know WHICH message was answered.
        expect($event->message['context']['id'])->toBe('wamid.ORIGINAL');

        return true;
    });
});

it('omits context on a message that answers nothing', function () {
    Event::fake([WhatsAppMessageReceived::class]);

    deliver(factory()->text('oi'))->assertOk();

    Event::assertDispatched(
        WhatsAppMessageReceived::class,
        fn ($event) => ! array_key_exists('context', $event->message),
    );
});

it('distinguishes a TEMPLATE button tap from an INTERACTIVE one', function () {
    Event::fake([WhatsAppMessageReceived::class]);

    // A template quick-reply comes back as type:button …
    deliver(factory()->templateButton('Aceitar', replyTo: 'wamid.T'))->assertOk();
    // … while an interactive button comes back as type:interactive. An app that
    // only handles one of the two silently ignores the other.
    deliver(factory()->buttonReply('btn_yes', 'Sim', replyTo: 'wamid.I'))->assertOk();

    $received = [];
    Event::assertDispatched(WhatsAppMessageReceived::class, function ($event) use (&$received) {
        $received[] = $event->message;

        return true;
    });

    expect($received[0]['type'])->toBe('button')
        ->and($received[0]['button'])->toBe(['payload' => 'Aceitar', 'text' => 'Aceitar'])
        ->and($received[0])->not->toHaveKey('interactive')
        ->and($received[1]['type'])->toBe('interactive')
        ->and($received[1]['interactive']['type'])->toBe('button_reply')
        ->and($received[1]['interactive']['button_reply'])->toBe(['id' => 'btn_yes', 'title' => 'Sim'])
        ->and($received[1])->not->toHaveKey('button');
});

it('delivers a list reply, the shape sendInteractive() provokes', function () {
    Event::fake([WhatsAppMessageReceived::class]);

    deliver(factory()->listReply('opt_0', 'Sábado', 'Sábado de manhã', replyTo: 'wamid.L'))->assertOk();

    Event::assertDispatched(WhatsAppMessageReceived::class, function ($event) {
        expect($event->message['type'])->toBe('interactive')
            ->and($event->message['interactive']['type'])->toBe('list_reply')
            ->and($event->message['interactive']['list_reply'])->toBe([
                'id' => 'opt_0', 'title' => 'Sábado', 'description' => 'Sábado de manhã',
            ]);

        return true;
    });
});

it('delivers a delivery status', function () {
    Event::fake([WhatsAppStatusReceived::class]);

    deliver(factory()->status('wamid.SENT', 'delivered'))->assertOk();

    Event::assertDispatched(WhatsAppStatusReceived::class, function ($event) {
        expect($event->status['id'])->toBe('wamid.SENT')
            ->and($event->status['status'])->toBe('delivered')
            ->and($event->status['recipient_id'])->toBe('5548999999999')
            ->and($event->status)->toHaveKey('pricing')
            ->and($event->phoneNumberId)->toBe('111222333');

        return true;
    });
});

it('delivers a failed status carrying Metas errors array', function () {
    Event::fake([WhatsAppStatusReceived::class]);

    $fault = FaultCatalog::find('window_closed');

    deliver(factory()->status('wamid.SENT', 'failed', $fault))->assertOk();

    Event::assertDispatched(WhatsAppStatusReceived::class, function ($event) {
        expect($event->status['status'])->toBe('failed')
            ->and($event->status['errors'][0]['code'])->toBe(131047)
            ->and($event->status['errors'][0])->toHaveKey('error_data')
            // A failed status has no pricing — nothing was billed.
            ->and($event->status)->not->toHaveKey('pricing');

        return true;
    });
});

it('agrees with the production exception about what is terminal', function () {
    expect(FaultCatalog::find('window_closed')->isTerminal())->toBeTrue()
        ->and(FaultCatalog::find('template_paused')->isTerminal())->toBeTrue()
        // Retryable on purpose: this is the branch that exercises queue backoff.
        ->and(FaultCatalog::find('rate_limited')->isTerminal())->toBeFalse();
});

it('rejects a simulated payload that is not signed', function () {
    // Proof that the payloads go through the same door as Meta's, not a side one.
    $body = (string) json_encode(factory()->text('oi'));

    test()->call('POST', 'webhooks/whatsapp/cloud', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body)->assertForbidden();
});
