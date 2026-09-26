<?php

namespace Callcocam\WhatsAppCloud\Onboarding;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Events\WhatsAppNumberConnected;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Exceptions\WhatsAppNotConfiguredException;
use Callcocam\WhatsAppCloud\Models\WhatsAppNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Meta's Embedded Signup, server side. The browser half (FB.login with the
 * `config_id`) hands back a one-shot `code` plus the WABA and phone number the
 * business picked; this class turns that into a stored, working number:
 *
 *  1. exchange the code for the business token (app id + app secret);
 *  2. prove the phone number belongs to that WABA — the ids came from the
 *     browser, so they are not trusted until the token can see them;
 *  3. subscribe this app to the WABA, so its webhooks reach our endpoint;
 *  4. register the number on the Cloud API (only when a PIN is available);
 *  5. persist everything on the credentials model.
 *
 * Steps 3 and 4 are best-effort: a failure there is reported as a warning and
 * the number is still saved, so the operator can fix it and "refresh" instead of
 * repeating the whole signup.
 */
class EmbeddedSignup
{
    /**
     * @param  class-string<Model&WhatsAppCredentials>  $model
     */
    public function __construct(
        protected readonly string $graphVersion,
        protected readonly ?string $appId,
        protected readonly ?string $appSecret,
        protected readonly string $model = WhatsAppNumber::class,
    ) {}

    /**
     * Run the whole connection. Returns the stored number and any non-fatal
     * warnings (webhook subscription / registration problems).
     *
     * @return array{number: Model&WhatsAppCredentials, warnings: list<string>}
     *
     * @throws CloudApiException when Meta rejects the code or the number does not belong to the WABA
     * @throws WhatsAppNotConfiguredException when the app id/secret are missing
     */
    public function connect(string $code, string $wabaId, string $phoneNumberId, ?string $businessId = null, ?string $pin = null): array
    {
        $token = $this->exchangeCode($code);
        $phone = $this->findPhoneNumber($wabaId, $phoneNumberId, $token['access_token']);

        $warnings = [];

        try {
            $this->subscribeApp($wabaId, $token['access_token']);
        } catch (CloudApiException $e) {
            $warnings[] = 'Não foi possível inscrever o app na WABA (webhooks): '.$e->getMessage();
        }

        if (filled($pin)) {
            try {
                $this->registerPhone($phoneNumberId, $token['access_token'], (string) $pin);
            } catch (CloudApiException $e) {
                $warnings[] = 'Não foi possível registrar o número na Cloud API: '.$e->getMessage();
            }
        }

        /** @var Model&WhatsAppCredentials $number */
        $number = $this->model::query()->firstOrNew(['phone_number_id' => $phoneNumberId]);

        $number->fill([
            'waba_id' => $wabaId,
            'business_id' => $businessId,
            'cloud_access_token' => $token['access_token'],
            'token_expires_at' => $token['expires_at'],
            'app_id' => $this->appId,
            'display_phone_number' => $phone['display_phone_number'] ?? null,
            'verified_name' => $phone['verified_name'] ?? null,
            'quality_rating' => $phone['quality_rating'] ?? null,
            'connected_at' => now(),
        ])->save();

        WhatsAppNumberConnected::dispatch($number, $warnings);

        return ['number' => $number, 'warnings' => $warnings];
    }

    /**
     * Re-read the number's public profile and re-subscribe the app to its WABA.
     *
     * @return list<string> non-fatal warnings
     */
    public function refresh(Model&WhatsAppCredentials $number): array
    {
        $wabaId = (string) $number->wabaId();
        $phone = $this->findPhoneNumber($wabaId, $number->phoneNumberId(), $number->accessToken());

        $number->fill([
            'display_phone_number' => $phone['display_phone_number'] ?? null,
            'verified_name' => $phone['verified_name'] ?? null,
            'quality_rating' => $phone['quality_rating'] ?? null,
        ])->save();

        try {
            $this->subscribeApp($wabaId, $number->accessToken());
        } catch (CloudApiException $e) {
            return ['Não foi possível inscrever o app na WABA (webhooks): '.$e->getMessage()];
        }

        return [];
    }

    /**
     * Forget the number, and stop receiving its WABA's webhooks when no other
     * stored number shares that WABA — the subscription is per WABA, not per
     * number. The unsubscribe is best-effort: a revoked token must not keep a
     * dead row around.
     */
    public function disconnect(Model&WhatsAppCredentials $number): void
    {
        $sharesWaba = filled($number->wabaId()) && $this->model::query()
            ->where('waba_id', $number->wabaId())
            ->whereKeyNot($number->getKey())
            ->exists();

        if (filled($number->wabaId()) && ! $sharesWaba) {
            try {
                $this->handle(fn () => $this->request($number->accessToken())
                    ->delete("{$number->wabaId()}/subscribed_apps"));
            } catch (CloudApiException) {
                // Token already revoked or app already removed — nothing to undo.
            }
        }

        $number->delete();
    }

    /**
     * Trade the Embedded Signup `code` for the business integration token.
     *
     * @return array{access_token: string, expires_at: Carbon|null}
     */
    public function exchangeCode(string $code): array
    {
        if (blank($this->appId) || blank($this->appSecret)) {
            throw new WhatsAppNotConfiguredException(
                'Embedded Signup needs WHATSAPP_CLOUD_APP_ID and WHATSAPP_CLOUD_APP_SECRET.',
            );
        }

        $response = $this->handle(fn () => $this->client()->get('oauth/access_token', [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'code' => $code,
        ]));

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new CloudApiException('WhatsApp Cloud API error: the code exchange returned no access token.');
        }

        $expiresIn = $response->json('expires_in');

        return [
            'access_token' => $token,
            'expires_at' => is_numeric($expiresIn) && (int) $expiresIn > 0 ? now()->addSeconds((int) $expiresIn) : null,
        ];
    }

    /**
     * The phone number's public profile, looked up THROUGH the WABA — which is
     * also the ownership check for ids that came from the browser.
     *
     * @return array<string, mixed>
     */
    public function findPhoneNumber(string $wabaId, string $phoneNumberId, string $accessToken): array
    {
        $response = $this->handle(fn () => $this->request($accessToken)->get("{$wabaId}/phone_numbers", [
            'fields' => 'id,display_phone_number,verified_name,quality_rating',
            'limit' => 100,
        ]));

        foreach ((array) $response->json('data', []) as $phone) {
            if (is_array($phone) && (string) ($phone['id'] ?? '') === $phoneNumberId) {
                return $phone;
            }
        }

        throw new CloudApiException("WhatsApp Cloud API error: phone number {$phoneNumberId} does not belong to WABA {$wabaId}.");
    }

    public function subscribeApp(string $wabaId, string $accessToken): void
    {
        $this->handle(fn () => $this->request($accessToken)->post("{$wabaId}/subscribed_apps"));
    }

    /**
     * Register the number on the Cloud API. The PIN becomes (or must match) the
     * number's two-step verification PIN.
     */
    public function registerPhone(string $phoneNumberId, string $accessToken, string $pin): void
    {
        $this->handle(fn () => $this->request($accessToken)->post("{$phoneNumberId}/register", [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ]));
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

    protected function request(string $accessToken): PendingRequest
    {
        return $this->client()->withToken($accessToken);
    }
}
