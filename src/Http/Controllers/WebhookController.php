<?php

namespace Callcocam\WhatsAppCloud\Http\Controllers;

use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Events\WhatsAppStatusReceived;
use Callcocam\WhatsAppCloud\Events\WhatsAppWebhookVerified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook for the official Meta Cloud API. Two entry points:
 *  - verify() (GET): the one-time hub-challenge handshake Meta performs when the
 *    callback URL is registered.
 *  - store() (POST): inbound messages and delivery statuses, authenticated by
 *    the X-Hub-Signature-256 HMAC (app secret) over the raw body.
 *
 * The controller only authenticates, parses and dispatches events. It carries no
 * business logic — the app listens to {@see WhatsAppMessageReceived} /
 * {@see WhatsAppStatusReceived} and decides what to do.
 */
class WebhookController
{
    /**
     * Meta's verification handshake: echo hub.challenge when the token matches.
     */
    public function verify(Request $request): Response
    {
        $token = config('whatsapp-cloud.verify_token');
        $challenge = $request->query('hub_challenge');

        if (
            blank($token)
            || $request->query('hub_mode') !== 'subscribe'
            || ! is_string($request->query('hub_verify_token'))
            || ! hash_equals((string) $token, (string) $request->query('hub_verify_token'))
        ) {
            return response('Forbidden', 403);
        }

        $challenge = is_string($challenge) ? $challenge : '';

        WhatsAppWebhookVerified::dispatch($challenge);

        return response($challenge, 200);
    }

    /**
     * Inbound events. Validates the signature, parses the payload and dispatches
     * a message/status event per entry. The package stores nothing.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        foreach ($this->changes($request->all()) as $value) {
            $phoneNumberId = data_get($value, 'metadata.phone_number_id');
            $phoneNumberId = is_string($phoneNumberId) ? $phoneNumberId : null;

            foreach ((array) data_get($value, 'messages', []) as $message) {
                WhatsAppMessageReceived::dispatch((array) $message, $value, $phoneNumberId);
            }

            foreach ((array) data_get($value, 'statuses', []) as $status) {
                WhatsAppStatusReceived::dispatch((array) $status, $value, $phoneNumberId);
            }
        }

        return response()->json(['handled' => true]);
    }

    /**
     * Every `messages`-field change value across all entries of the payload.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    protected function changes(array $payload): array
    {
        $values = [];

        foreach (data_get($payload, 'entry', []) as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                if (data_get($change, 'field') === 'messages' && is_array($value = data_get($change, 'value'))) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Validate Meta's X-Hub-Signature-256 (HMAC-SHA256 of the raw body with the
     * app secret). Without a configured app secret we reject — never process an
     * unauthenticated payload.
     */
    protected function hasValidSignature(Request $request): bool
    {
        $secret = config('whatsapp-cloud.app_secret');
        $header = $request->header('X-Hub-Signature-256');

        if (blank($secret) || ! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $secret);

        return hash_equals($expected, $header);
    }
}
