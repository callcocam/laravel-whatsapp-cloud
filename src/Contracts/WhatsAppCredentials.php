<?php

namespace Callcocam\WhatsAppCloud\Contracts;

use Callcocam\WhatsAppCloud\CloudApiFactory;
use Callcocam\WhatsAppCloud\Models\WhatsAppNumber;
use Callcocam\WhatsAppCloud\Support\HasWhatsAppCredentials;

/**
 * The per-number Meta credentials the {@see CloudApiFactory}
 * needs to build a client. Implement it on your own model (or use
 * {@see HasWhatsAppCredentials}), or use the
 * package's default {@see WhatsAppNumber}.
 */
interface WhatsAppCredentials
{
    /**
     * The Meta phone number id the messages are sent from.
     */
    public function phoneNumberId(): string;

    /**
     * The (permanent) access token authorizing the send.
     */
    public function accessToken(): string;

    /**
     * The WhatsApp Business Account id (used by template management), if known.
     */
    public function wabaId(): ?string;

    /**
     * A per-connection Graph API version override, or null to use the config
     * default.
     */
    public function graphVersion(): ?string;
}
