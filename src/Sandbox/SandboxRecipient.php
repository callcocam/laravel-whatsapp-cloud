<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

/**
 * One importable contact offered on the sandbox screen: a phone, a name, the
 * role it plays in a conversation, and the group it belongs to so the list can
 * be sectioned (e.g. "Voluntários", "Congregações").
 *
 * A value object on purpose — the app builds these from its own models; the
 * package only ever reads them.
 */
final class SandboxRecipient
{
    public readonly string $waId;

    /**
     * @param  string  $waId  Phone in wa_id form (digits only, with DDI/DDD).
     * @param  string  $name  Display name; falls back to the number when blank.
     * @param  string  $role  Conversation role: customer | operator | other.
     * @param  string|null  $group  Optional section label for the UI.
     */
    public function __construct(
        string $waId,
        public readonly string $name,
        public readonly string $role = 'customer',
        public readonly ?string $group = null,
    ) {
        $this->waId = (string) preg_replace('/\D+/', '', $waId);
    }

    /**
     * Whether the number is a plausible wa_id (8–15 digits, as Meta expects).
     */
    public function isValid(): bool
    {
        return preg_match('/^\d{8,15}$/', $this->waId) === 1;
    }

    /**
     * @return array{wa_id: string, name: string, role: string, group: string|null}
     */
    public function toArray(): array
    {
        return [
            'wa_id' => $this->waId,
            'name' => $this->name !== '' ? $this->name : $this->waId,
            'role' => $this->role,
            'group' => $this->group,
        ];
    }
}
