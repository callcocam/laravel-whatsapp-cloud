<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

/**
 * The failures worth rehearsing. Each one is a real Meta error that an app WILL
 * meet in production, and that today can only be met in production.
 *
 * Deliberately mixed: terminal codes (log and move on) AND retryable ones (let
 * the queue back off). An app that only ever sees the happy path handles neither
 * — and the retryable branch is exactly the one nobody tests.
 */
final class FaultCatalog
{
    /**
     * @return array<string, Fault>
     */
    public static function all(): array
    {
        $faults = [
            new Fault(
                key: 'window_closed',
                code: 131047,
                title: 'Re-engagement message',
                message: 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.',
                details: 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.',
            ),
            new Fault(
                key: 'undeliverable',
                code: 131026,
                title: 'Message Undeliverable',
                message: 'Message was not delivered to maintain healthy ecosystem engagement.',
                details: 'The recipient phone number is not a WhatsApp phone number.',
            ),
            new Fault(
                key: 'template_paused',
                code: 132015,
                title: 'Template Paused',
                message: 'Template is paused due to low quality so it cannot be sent in a template message.',
                details: 'Template is paused due to low quality.',
            ),
            new Fault(
                key: 'policy_block',
                code: 368,
                title: 'Temporarily blocked for policies violations',
                message: 'The WhatsApp Business Account is restricted from messaging users in this country.',
                details: 'Temporarily blocked for policy violations.',
            ),
            // Not terminal today — and that is the point. It exercises the queue's
            // backoff branch, which the happy path never touches.
            new Fault(
                key: 'rate_limited',
                code: 80007,
                title: 'Rate limit hit',
                message: 'The WhatsApp Business Account has reached its rate limit.',
                details: 'Business account rate limit hit. Retry later.',
            ),
            new Fault(
                key: 'payment_issue',
                code: 131042,
                title: 'Business eligibility payment issue',
                message: 'Message failed to send because there were one or more errors related to your payment method.',
                details: 'Business eligibility payment issue: add a valid payment method to the WhatsApp Business Account.',
            ),
            new Fault(
                key: 'server_error',
                code: 131000,
                title: 'Something went wrong',
                message: 'Message failed to send during a WhatsApp internal error.',
                details: 'An unknown error occurred on the Meta side. Retry.',
            ),
            new Fault(
                key: 'connection_failed',
                code: null,
                title: 'Connection failed',
                message: 'Could not connect to the WhatsApp Cloud API.',
                details: 'The request never reached Meta (DNS, TLS or timeout).',
                connection: true,
            ),
        ];

        return collect($faults)->keyBy(fn (Fault $fault): string => $fault->key)->all();
    }

    public static function find(string $key): ?Fault
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
