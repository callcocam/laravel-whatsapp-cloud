<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Events\WhatsAppStatusReceived;
use Callcocam\WhatsAppCloud\Exceptions\SandboxException;
use Callcocam\WhatsAppCloud\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Feeds a simulated payload into the app the same way Meta would: signed with the
 * real HMAC, through the real {@see WebhookController}, so the signature check
 * runs for real and the app's listeners fire exactly as in production.
 *
 * It invokes the controller DIRECTLY rather than re-entering the HTTP kernel, and
 * that is a deliberate, load-bearing choice:
 *
 *  - Kernel::handle() catches Throwable and turns it into a 500. A listener that
 *    blows up would vanish into the exception handler — killing the one thing the
 *    inspector exists to show. Here the exception reaches the caller.
 *  - Kernel::handle() also does `app()->instance('request', $fake)`, which
 *    re-binds the request across the container (UrlGenerator, AuthManager…). The
 *    outer Inertia response, its `back()` and its shared props would then see the
 *    WEBHOOK's request. Under Octane it would leak into the next user's request.
 *
 * What that costs: the app's `webhook.middleware` (default ['api']) does not run.
 * The controller reads only the header, the raw body and all() — none of which
 * that middleware provides. But if you mounted middleware on the webhook (say, a
 * tenant resolver keyed on the phone number id), the sandbox will NOT exercise it.
 */
final class WebhookSimulator
{
    /**
     * @param  array<string, mixed>  $payload  from {@see InboundPayloadFactory}
     */
    public function deliver(array $payload): SimulatedWebhook
    {
        $secret = (string) config('whatsapp-cloud.app_secret', '');

        // Without a secret the controller rejects everything with a silent 403:
        // the developer would click "reply", see nothing happen, and have no idea
        // why. Fail loudly instead.
        if ($secret === '') {
            throw new SandboxException(
                'The sandbox cannot sign a webhook: `whatsapp-cloud.app_secret` is empty, '
                .'so every simulated payload would be rejected with a 403. '
                .'Set WHATSAPP_CLOUD_APP_SECRET (in the sandbox it can be any string).',
            );
        }

        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        $request = Request::create(
            uri: $this->webhookUrl(),
            method: 'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            content: $body,
        );

        $status = null;
        $failure = null;

        try {
            $status = app(WebhookController::class)->store($request)->getStatusCode();
        } catch (Throwable $exception) {
            // The app's listener threw. That is a finding, not a crash — hand it
            // back so the inspector can show it.
            $failure = $exception;
        }

        return new SimulatedWebhook(
            payload: $payload,
            body: $body,
            signature: $signature,
            status: $status,
            listeners: $this->listeners(),
            failure: $failure,
        );
    }

    /**
     * Who is listening for the events this payload fires.
     *
     * Registration, not execution: a ShouldQueue listener is listed here but runs
     * in a worker process, so its effects show up later, via polling — never in
     * this call's result.
     *
     * @return list<string>
     */
    private function listeners(): array
    {
        $raw = Event::getRawListeners();

        $listeners = [];

        foreach ([WhatsAppMessageReceived::class, WhatsAppStatusReceived::class] as $event) {
            foreach ((array) ($raw[$event] ?? []) as $listener) {
                $listeners[] = match (true) {
                    is_string($listener) => $listener,
                    is_object($listener) => $listener::class,
                    default => 'Closure',
                };
            }
        }

        return $listeners;
    }

    private function webhookUrl(): string
    {
        $prefix = (string) config('whatsapp-cloud.webhook.prefix', 'webhooks/whatsapp/cloud');

        return url($prefix);
    }
}
