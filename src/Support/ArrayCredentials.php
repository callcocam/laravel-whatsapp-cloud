<?php

namespace Callcocam\WhatsAppCloud\Support;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;

/**
 * A plain-array {@see WhatsAppCredentials} — used for the config `default`
 * credentials (dev / single-tenant) and anywhere you have raw values rather than
 * a model.
 */
final class ArrayCredentials implements WhatsAppCredentials
{
    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $accessToken,
        private readonly ?string $wabaId = null,
        private readonly ?string $graphVersion = null,
    ) {}

    /**
     * Build from a `['phone_number_id' => ..., 'access_token' => ..., 'waba_id'
     * => ..., 'graph_version' => ...]` array, or null when the two required
     * values are missing (so the caller reports "not configured").
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $phoneNumberId = (string) ($data['phone_number_id'] ?? '');
        $accessToken = (string) ($data['access_token'] ?? '');

        if ($phoneNumberId === '' || $accessToken === '') {
            return null;
        }

        $wabaId = isset($data['waba_id']) && $data['waba_id'] !== '' ? (string) $data['waba_id'] : null;
        $graphVersion = isset($data['graph_version']) && $data['graph_version'] !== '' ? (string) $data['graph_version'] : null;

        return new self($phoneNumberId, $accessToken, $wabaId, $graphVersion);
    }

    public function phoneNumberId(): string
    {
        return $this->phoneNumberId;
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function wabaId(): ?string
    {
        return $this->wabaId;
    }

    public function graphVersion(): ?string
    {
        return $this->graphVersion;
    }
}
