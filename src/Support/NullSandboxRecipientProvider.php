<?php

namespace Callcocam\WhatsAppCloud\Support;

use Callcocam\WhatsAppCloud\Contracts\SandboxRecipientProvider;

/**
 * The default when the app binds nothing: the sandbox offers no contacts, and
 * you add them by hand. The package stays useful on its own, and an app opts in
 * to auto-discovery by binding its own provider.
 */
final class NullSandboxRecipientProvider implements SandboxRecipientProvider
{
    public function recipients(): array
    {
        return [];
    }
}
