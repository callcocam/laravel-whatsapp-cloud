<?php

namespace Callcocam\WhatsAppCloud\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored inbound WhatsApp message (`whatsapp_inbound_messages`). Filled by
 * {@see \Callcocam\WhatsAppCloud\Listeners\StoreInboundMessage} when
 * `whatsapp-cloud.inbound.store` is on, giving any host app a durable log of
 * everything that arrived — and a place to record what it did with each one.
 *
 * @property int $id
 * @property string|null $phone_number_id
 * @property string $wa_id
 * @property string|null $contact_name
 * @property string $wamid
 * @property string $type
 * @property string|null $text
 * @property string|null $context_id
 * @property array<string, mixed>|null $payload
 * @property string $status
 * @property string|null $forwarded_to
 * @property \Illuminate\Support\Carbon|null $forwarded_at
 * @property \Illuminate\Support\Carbon|null $handled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class WhatsAppInboundMessage extends Model
{
    /**
     * The moment it was received; nothing has acted on it yet.
     */
    public const STATUS_RECEIVED = 'received';

    /**
     * The host routed it on to someone (e.g. the responsible person).
     */
    public const STATUS_FORWARDED = 'forwarded';

    /**
     * The host looked at it but had nowhere to send it (no recipient, or the
     * 24h window was closed).
     */
    public const STATUS_UNHANDLED = 'unhandled';

    protected $table = 'whatsapp_inbound_messages';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'forwarded_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * Record that the message was routed on to `$phone`.
     */
    public function markForwarded(string $phone): bool
    {
        return $this->forceFill([
            'status' => self::STATUS_FORWARDED,
            'forwarded_to' => $phone,
            'forwarded_at' => now(),
            'handled_at' => now(),
        ])->save();
    }

    /**
     * Record that the message was seen but could not be routed anywhere.
     */
    public function markUnhandled(): bool
    {
        return $this->forceFill([
            'status' => self::STATUS_UNHANDLED,
            'handled_at' => now(),
        ])->save();
    }
}
