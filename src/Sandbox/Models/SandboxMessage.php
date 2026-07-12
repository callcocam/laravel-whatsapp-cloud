<?php

namespace Callcocam\WhatsAppCloud\Sandbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One simulated message, with both raw payloads kept verbatim — what we POSTed
 * and what the webhook carried back. The inspector shows them unedited: a tidy
 * summary would hide exactly the drift you opened the sandbox to find.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $direction
 * @property string $wamid
 * @property string $type
 * @property array<string, mixed>|null $envelope
 * @property array<string, mixed>|null $inbound_payload
 * @property string|null $template_name
 * @property array<int, mixed>|null $template_components
 * @property string|null $rendered_text
 * @property string|null $delivery_status
 * @property int|null $error_code
 * @property array<string, mixed>|null $meta
 */
class SandboxMessage extends Model
{
    public const OUTBOUND = 'outbound';

    public const INBOUND = 'inbound';

    protected $table = 'whatsapp_sandbox_messages';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'envelope' => 'array',
            'inbound_payload' => 'array',
            'template_components' => 'array',
            'meta' => 'array',
            'error_code' => 'integer',
        ];
    }

    /** @return BelongsTo<SandboxConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SandboxConversation::class, 'conversation_id');
    }

    public function isOutbound(): bool
    {
        return $this->direction === self::OUTBOUND;
    }
}
