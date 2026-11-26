<?php

namespace Callcocam\WhatsAppCloud\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Meta told something about a WhatsApp Business Account or one of its numbers,
 * outside of messages: a phone number's quality dropped or its messaging limit
 * changed (`phone_number_quality_update`), the account was restricted, banned
 * or reinstated (`account_update`), and so on. The app subscribes to these
 * fields in the Meta app (Webhooks > WhatsApp Business Account) and decides
 * what to do; the package only parses and dispatches.
 */
class WhatsAppAccountUpdated
{
    use Dispatchable;

    /**
     * @param  string  $field  the webhook field (`phone_number_quality_update`, `account_update`, …)
     * @param  array<string, mixed>  $value  the full `changes[].value`
     * @param  string|null  $wabaId  the WhatsApp Business Account id (`entry[].id`)
     */
    public function __construct(
        public readonly string $field,
        public readonly array $value,
        public readonly ?string $wabaId = null,
    ) {}

    /**
     * Meta's event name inside the value (`FLAGGED`, `DOWNGRADE`, `ACCOUNT_VIOLATION`…).
     */
    public function event(): ?string
    {
        $event = data_get($this->value, 'event');

        return is_string($event) ? $event : null;
    }

    /**
     * The number the update is about, digits only, when Meta names one.
     */
    public function displayPhoneNumber(): ?string
    {
        $number = data_get($this->value, 'display_phone_number', data_get($this->value, 'phone_number'));

        return is_scalar($number) && ($digits = preg_replace('/\D+/', '', (string) $number)) !== '' ? $digits : null;
    }
}
