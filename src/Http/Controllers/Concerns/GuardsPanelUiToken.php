<?php

namespace Callcocam\WhatsAppCloud\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait GuardsPanelUiToken
{
    /**
     * Optional defense-in-depth: when `panel.ui_token` is set every request must
     * carry the same value in the X-WA-UI-Token header.
     */
    private function guardUiToken(Request $request): void
    {
        $expected = config('whatsapp-cloud.panel.ui_token');

        if (blank($expected)) {
            return;
        }

        $provided = (string) $request->header('X-WA-UI-Token', '');

        abort_unless(
            $provided !== '' && hash_equals((string) $expected, $provided),
            401,
            'Não autorizado — informe o WA_UI_TOKEN.',
        );
    }
}
