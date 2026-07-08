<?php

namespace Callcocam\WhatsAppCloud\Support;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Illuminate\Database\Eloquent\Model;

/**
 * Implements {@see WhatsAppCredentials} for an
 * Eloquent model whose columns follow the package convention (`phone_number_id`,
 * `cloud_access_token`, `waba_id`, optional `graph_version`). Add
 * `implements WhatsAppCredentials` and `use HasWhatsAppCredentials` to glue the
 * contract onto an existing model.
 *
 * @mixin Model
 */
trait HasWhatsAppCredentials
{
    public function phoneNumberId(): string
    {
        return (string) $this->getAttribute('phone_number_id');
    }

    public function accessToken(): string
    {
        return (string) $this->getAttribute('cloud_access_token');
    }

    public function wabaId(): ?string
    {
        $value = $this->getAttribute('waba_id');

        return $value === null ? null : (string) $value;
    }

    public function graphVersion(): ?string
    {
        $value = $this->getAttribute('graph_version');

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Whether the Meta Cloud credentials are present and usable.
     */
    public function hasCloudCredentials(): bool
    {
        return filled($this->phoneNumberId()) && filled($this->accessToken());
    }
}
