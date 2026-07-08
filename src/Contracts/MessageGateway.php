<?php

namespace Callcocam\WhatsAppCloud\Contracts;

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Messages\InteractiveMessage;
use Callcocam\WhatsAppCloud\Messages\SendResult;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;

/**
 * The message channel of the WhatsApp provider, oriented by intent rather than
 * by transport. {@see CloudApiClient} implements it.
 *
 * The three intents map onto Meta's messaging model:
 *  - sendTemplate:    business-initiated message — a pre-approved template (the
 *                     only thing allowed outside the 24h session window).
 *  - sendSessionText: free text, only valid inside Meta's 24h session window
 *                     (after the user replied).
 *  - sendInteractive: a question with options (a Meta interactive list/buttons).
 */
interface MessageGateway
{
    public function sendTemplate(string $to, TemplateMessage $template): SendResult;

    public function sendSessionText(string $to, string $text): SendResult;

    public function sendInteractive(string $to, InteractiveMessage $message): SendResult;
}
