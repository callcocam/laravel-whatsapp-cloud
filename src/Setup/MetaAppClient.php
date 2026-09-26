<?php

namespace Callcocam\WhatsAppCloud\Setup;

use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Meta APP-level calls the setup panel automates, authenticated with the
 * app access token (`{app_id}|{app_secret}`): check the credentials, register
 * the webhook, inspect a user/business token.
 *
 * What Meta offers NO API for — creating the app, reading its secret, creating
 * the Embedded Signup configuration — stays a guided manual step in the panel.
 */
class MetaAppClient
{
    public function __construct(
        protected readonly string $graphVersion,
        protected readonly string $appId,
        protected readonly string $appSecret,
    ) {}

    /**
     * The app's id and name. Fails when the id/secret pair is wrong — which makes
     * it the credentials check.
     *
     * @return array{id: string, name: string|null}
     */
    public function app(): array
    {
        $response = $this->handle(fn () => $this->client()->get($this->appId, [
            'fields' => 'id,name',
            'access_token' => $this->appToken(),
        ]));

        $name = $response->json('name');

        return ['id' => (string) $response->json('id', $this->appId), 'name' => is_string($name) ? $name : null];
    }

    /**
     * Point the app's `whatsapp_business_account` webhook at our endpoint.
     *
     * Meta calls the callback (GET hub.challenge) DURING this request, so the
     * verify token must already be live in the app answering it.
     */
    public function subscribeWebhook(string $callbackUrl, string $verifyToken): void
    {
        $this->handle(fn () => $this->client()->asForm()->post("{$this->appId}/subscriptions", [
            'object' => 'whatsapp_business_account',
            'callback_url' => $callbackUrl,
            'verify_token' => $verifyToken,
            'fields' => 'messages',
            'include_values' => 'true',
            'access_token' => $this->appToken(),
        ]));
    }

    /**
     * The app's current `whatsapp_business_account` subscription, or null.
     *
     * @return array<string, mixed>|null
     */
    public function webhookSubscription(): ?array
    {
        $response = $this->handle(fn () => $this->client()->get("{$this->appId}/subscriptions", [
            'access_token' => $this->appToken(),
        ]));

        foreach ((array) $response->json('data', []) as $subscription) {
            if (is_array($subscription) && ($subscription['object'] ?? null) === 'whatsapp_business_account') {
                return $subscription;
            }
        }

        return null;
    }

    /**
     * Meta's view of a token: validity, owner app, expiry (0 = never), scopes.
     *
     * @return array<string, mixed>
     */
    public function debugToken(string $token): array
    {
        $response = $this->handle(fn () => $this->client()->get('debug_token', [
            'input_token' => $token,
            'access_token' => $this->appToken(),
        ]));

        return (array) $response->json('data', []);
    }

    protected function appToken(): string
    {
        return "{$this->appId}|{$this->appSecret}";
    }

    /**
     * @param  callable(): Response  $callback
     */
    protected function handle(callable $callback): Response
    {
        try {
            $response = $callback();
        } catch (ConnectionException $exception) {
            throw new CloudApiException('Could not connect to the WhatsApp Cloud API.', previous: $exception);
        }

        if ($response->failed()) {
            throw CloudApiException::fromResponse($response);
        }

        return $response;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl("https://graph.facebook.com/{$this->graphVersion}")
            ->acceptJson()
            ->timeout(30);
    }
}
