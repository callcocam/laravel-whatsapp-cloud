<?php

namespace Callcocam\WhatsAppCloud\Sandbox\Models;

use Callcocam\WhatsAppCloud\Sandbox\Fault;
use Callcocam\WhatsAppCloud\Sandbox\FaultCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One simulated thread: a business number and one person.
 *
 * @property int $id
 * @property string $phone_number_id
 * @property string $wa_id
 * @property string $name
 * @property string|null $role
 * @property Carbon|null $window_expires_at
 * @property array<int, string>|null $faults
 */
class SandboxConversation extends Model
{
    protected $table = 'whatsapp_sandbox_conversations';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'window_expires_at' => 'datetime',
            'faults' => 'array',
        ];
    }

    /** @return HasMany<SandboxMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SandboxMessage::class, 'conversation_id');
    }

    /**
     * Meta's 24h rule: free text and interactive messages are only allowed while
     * this window is open. It opens when the person replies, and only then.
     */
    public function windowIsOpen(): bool
    {
        return $this->window_expires_at !== null && $this->window_expires_at->isFuture();
    }

    /**
     * The person said something — the window opens (or restarts) for 24 hours.
     */
    public function openWindow(): void
    {
        $this->forceFill(['window_expires_at' => now()->addDay()])->save();
    }

    /**
     * Jump to the moment the window has just lapsed, without waiting a day for
     * it. This is how you rehearse error 131047 — the single most common surprise
     * in production, and one nobody can currently provoke on purpose.
     */
    public function closeWindow(): void
    {
        $this->forceFill(['window_expires_at' => now()->subSecond()])->save();
    }

    /**
     * The failure armed for the next send, if any.
     */
    public function armedFault(): ?Fault
    {
        $key = ($this->faults ?? [])[0] ?? null;

        return is_string($key) ? FaultCatalog::find($key) : null;
    }

    /**
     * Faults fire once. Leaving one armed would make every later send fail and
     * turn a deliberate rehearsal into a confusing dead sandbox.
     */
    public function consumeFault(): ?Fault
    {
        $fault = $this->armedFault();

        if ($fault instanceof Fault) {
            $this->forceFill(['faults' => array_slice($this->faults ?? [], 1)])->save();
        }

        return $fault;
    }

    public function arm(string $faultKey): void
    {
        $this->forceFill(['faults' => [...($this->faults ?? []), $faultKey]])->save();
    }
}
