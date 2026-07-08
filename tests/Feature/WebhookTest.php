<?php

use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Events\WhatsAppStatusReceived;
use Callcocam\WhatsAppCloud\Events\WhatsAppWebhookVerified;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * POST the webhook with a correctly-signed raw body.
 *
 * @param  array<string, mixed>  $payload
 */
function postWebhook(array $payload, ?string $secret = 'test-app-secret'): TestResponse
{
    $body = json_encode($payload);
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    if ($secret !== null) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', (string) $body, $secret);
    }

    return test()->call('POST', 'webhooks/whatsapp/cloud', [], [], [], $server, $body);
}

it('echoes the hub challenge and dispatches a verified event on a valid handshake', function () {
    Event::fake([WhatsAppWebhookVerified::class]);

    $this->get('webhooks/whatsapp/cloud?hub_mode=subscribe&hub_verify_token=test-verify-token&hub_challenge=CHALLENGE123')
        ->assertOk()
        ->assertSee('CHALLENGE123');

    Event::assertDispatched(WhatsAppWebhookVerified::class, fn ($e) => $e->challenge === 'CHALLENGE123');
});

it('rejects the handshake when the verify token does not match', function () {
    $this->get('webhooks/whatsapp/cloud?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=x')
        ->assertForbidden();
});

it('dispatches a message event for an inbound message with a valid signature', function () {
    Event::fake([WhatsAppMessageReceived::class, WhatsAppStatusReceived::class]);

    postWebhook([
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => '111222333'],
                    'messages' => [['id' => 'wamid.IN', 'from' => '5548999999999', 'type' => 'text']],
                ],
            ]],
        ]],
    ])->assertOk()->assertJson(['handled' => true]);

    Event::assertDispatched(WhatsAppMessageReceived::class, function ($e) {
        return $e->phoneNumberId === '111222333' && $e->message['id'] === 'wamid.IN';
    });
    Event::assertNotDispatched(WhatsAppStatusReceived::class);
});

it('dispatches a status event for a delivery status', function () {
    Event::fake([WhatsAppStatusReceived::class]);

    postWebhook([
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => '111222333'],
                    'statuses' => [['id' => 'wamid.OUT', 'status' => 'delivered', 'recipient_id' => '5548999999999']],
                ],
            ]],
        ]],
    ])->assertOk();

    Event::assertDispatched(WhatsAppStatusReceived::class, function ($e) {
        return $e->status['status'] === 'delivered' && $e->status['id'] === 'wamid.OUT';
    });
});

it('rejects a payload with an invalid signature', function () {
    Event::fake([WhatsAppMessageReceived::class]);

    postWebhook([
        'entry' => [['changes' => [['field' => 'messages', 'value' => ['messages' => [['id' => 'x']]]]]]],
    ], secret: 'wrong-secret')->assertForbidden();

    Event::assertNotDispatched(WhatsAppMessageReceived::class);
});

it('rejects a payload with no signature header', function () {
    postWebhook([
        'entry' => [['changes' => [['field' => 'messages', 'value' => ['messages' => []]]]]],
    ], secret: null)->assertForbidden();
});
