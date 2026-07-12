<?php

namespace Callcocam\WhatsAppCloud\Contracts;

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Templates\TemplateManager;

/**
 * The wire under the messaging API: it posts a ready message envelope to a
 * number's `/messages` endpoint and hands back Meta's response.
 *
 * Both send paths of the package funnel through this one seam — the data plane
 * ({@see CloudApiClient}) and the template test-send of
 * the control plane ({@see TemplateManager}).
 * Swapping the implementation therefore diverts EVERY outbound message, whether
 * it originates in a queued job, a listener or the panel.
 *
 * The envelope is built by the caller, not here: the two callers disagree on its
 * shape (the data plane sends `recipient_type`, the control plane does not), and
 * that difference is Meta's, not ours to normalise.
 */
interface MessageTransport
{
    /**
     * Deliver one message envelope on behalf of a number.
     *
     * @param  array<string, mixed>  $envelope  the complete `/messages` body
     * @return array<string, mixed> Meta-shaped response: `{"messages":[{"id":"wamid…"}]}`
     *
     * @throws CloudApiException on any delivery failure — carrying Meta's error
     *                           code, so callers can consult isTerminal() and
     *                           decide between logging and retrying.
     */
    public function postMessage(WhatsAppCredentials $credentials, array $envelope): array;
}
