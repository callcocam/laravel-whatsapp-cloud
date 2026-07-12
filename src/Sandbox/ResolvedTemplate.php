<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

use Callcocam\WhatsAppCloud\CloudApiClient;

/**
 * A template's actual content — the body text, the header, the footer and the
 * buttons — flattened out of Meta's `components` array.
 *
 * The same shape whether it came from a local definition file or from Meta: the
 * file IS the payload that gets submitted, so there is only one shape to read.
 */
final class ResolvedTemplate
{
    public const SOURCE_DEFINITION = 'definition';

    public const SOURCE_META = 'meta';

    /**
     * @param  list<array<string, mixed>>  $components
     */
    public function __construct(
        public readonly string $name,
        public readonly string $language,
        public readonly array $components,
        public readonly string $source,
    ) {}

    public function headerText(): ?string
    {
        $header = $this->component('HEADER');

        return ($header['format'] ?? 'TEXT') === 'TEXT' && is_string($header['text'] ?? null)
            ? $header['text']
            : null;
    }

    public function bodyText(): ?string
    {
        $text = $this->component('BODY')['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    public function footerText(): ?string
    {
        $text = $this->component('FOOTER')['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buttons(): array
    {
        /** @var list<array<string, mixed>> $buttons */
        $buttons = array_values((array) ($this->component('BUTTONS')['buttons'] ?? []));

        return $buttons;
    }

    /**
     * The buttons a person can actually tap back at you. URL and phone buttons
     * open something on the device — Meta never tells you they were pressed, so
     * the sandbox must not pretend otherwise.
     *
     * @return list<string>
     */
    public function quickReplies(): array
    {
        return array_values(array_map(
            static fn (array $button): string => (string) ($button['text'] ?? ''),
            array_filter($this->buttons(), static fn (array $button): bool => ($button['type'] ?? '') === 'QUICK_REPLY'),
        ));
    }

    /**
     * How many {{n}} the body actually has — the number of params it expects.
     */
    public function variableCount(): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $this->bodyText(), $matches);

        return $matches[1] === [] ? 0 : max(array_map('intval', $matches[1]));
    }

    /**
     * The body with {{1}}, {{2}}… filled in — what the person on the other end
     * will read.
     *
     * A param that was not supplied renders as an empty string, exactly as
     * {@see CloudApiClient::sendTemplate()} sends it. If
     * the registry's param ORDER disagrees with the body's {{n}}, this is where
     * you finally see it: the wrong value lands in the wrong slot, visibly,
     * instead of silently reaching a customer.
     *
     * @param  list<string>  $params  positional values for {{1}}, {{2}}…
     */
    public function render(array $params): string
    {
        $body = (string) $this->bodyText();

        return (string) preg_replace_callback(
            '/\{\{\s*(\d+)\s*\}\}/',
            static fn (array $match): string => (string) ($params[((int) $match[1]) - 1] ?? ''),
            $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function component(string $type): array
    {
        foreach ($this->components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === $type) {
                return $component;
            }
        }

        return [];
    }
}
