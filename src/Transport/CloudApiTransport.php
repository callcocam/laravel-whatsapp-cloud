<?php

namespace Callcocam\WhatsAppCloud\Transport;

use Callcocam\WhatsAppCloud\Contracts\MessageTransport;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The real wire: an HTTP POST to Meta's Graph API. This is the production
 * transport, and the default one.
 *
 * It holds the error contract of the whole package — every delivery failure,
 * network or API, leaves here as a {@see CloudApiException} carrying Meta's
 * error code, so the caller can ask isTerminal() instead of guessing.
 */
class CloudApiTransport implements MessageTransport
{
    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function postMessage(WhatsAppCredentials $credentials, array $envelope): array
    {
        $graphVersion = $credentials->graphVersion() ?? (string) config('whatsapp-cloud.graph_version', 'v21.0');
        $url = "https://graph.facebook.com/{$graphVersion}/{$credentials->phoneNumberId()}/messages";

        $response = $this->handle(
            fn () => $this->request($credentials->accessToken())->post($url, $envelope),
        );

        return (array) $response->json();
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

    protected function request(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->timeout(10);
    }
}
