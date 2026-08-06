<?php

namespace Callcocam\WhatsAppCloud\Contracts;

use Callcocam\WhatsAppCloud\Sandbox\SandboxRecipient;
use Callcocam\WhatsAppCloud\Support\NullSandboxRecipientProvider;

/**
 * The people the sandbox can strike up a conversation with.
 *
 * The sandbox itself has no idea who a project's contacts are — one app's
 * recipients are volunteers and congregation leaders, another's are patients or
 * drivers. So the package asks: it defines this contract and ships an empty
 * default ({@see NullSandboxRecipientProvider}); the app binds an implementation
 * that reads its OWN tables, applies its OWN rules (team scoping, opt-outs), and
 * hands back a flat, deduplicated list. The screen lists them; importing one
 * turns it into a conversation.
 */
interface SandboxRecipientProvider
{
    /**
     * The recipients to offer on the sandbox screen, already normalized and
     * deduplicated by wa_id.
     *
     * @return list<SandboxRecipient>
     */
    public function recipients(): array;
}
