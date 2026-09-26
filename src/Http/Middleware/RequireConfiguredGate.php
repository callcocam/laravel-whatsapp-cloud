<?php

namespace Callcocam\WhatsAppCloud\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands in for `can:<gate>` on the pages that handle Meta credentials (setup,
 * connected numbers) when the host has NOT configured a gate.
 *
 * `auth` alone would let ANY logged-in user read, export and replace the
 * WhatsApp credentials. So without a gate those pages only open on a local
 * machine; everywhere else they answer 403 and name the setting to fix.
 */
class RequireConfiguredGate
{
    public function handle(Request $request, Closure $next, string $env = 'WHATSAPP_CLOUD_SETUP_GATE'): Response
    {
        abort_unless(
            app()->environment('local', 'testing'),
            403,
            "Defina {$env} (um Gate que só administradores passam) para abrir esta página fora do ambiente local.",
        );

        return $next($request);
    }
}
