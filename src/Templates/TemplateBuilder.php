<?php

namespace Callcocam\WhatsAppCloud\Templates;

use LogicException;

/**
 * Fluent builder for a template-creation payload, with the guard-rails that
 * catch Meta's most common rejection (code 100) BEFORE hitting the API.
 *
 * Example:
 *   TemplateBuilder::make('teste_lembrete', 'pt_BR', 'UTILITY')
 *       ->body("Olá, {{1}}! Lembrete: {{2}} em {{3}}", ['Maria', 'Reunião', '10/07'])
 *       ->footer('Coordena')
 *       ->quickReply('Confirmar presença')
 *       ->toArray();
 */
final class TemplateBuilder
{
    /** @var array<int, array<string, mixed>> */
    private array $components = [];

    private function __construct(
        private readonly string $name,
        private readonly string $language,
        private readonly string $category,
    ) {}

    public static function make(string $name, string $language = 'pt_BR', string $category = 'UTILITY'): self
    {
        return new self($name, $language, strtoupper($category));
    }

    /**
     * Body of the template. Use {{1}}, {{2}}… for variables and pass one example
     * per variable.
     *
     * @param  array<int, string|int|float>  $examples
     */
    public function body(string $text, array $examples = []): self
    {
        $this->assertBodyShape($text, $examples);

        $component = ['type' => 'BODY', 'text' => $text];

        if ($examples !== []) {
            $component['example'] = [
                'body_text' => [array_values(array_map('strval', $examples))],
            ];
        }

        $this->components[] = $component;

        return $this;
    }

    /**
     * Validate the body against the Meta rules that cause rejection (code 100),
     * BEFORE calling the API — so the error surfaces locally, not on a round-trip.
     *
     * @param  array<int, string|int|float>  $examples
     */
    private function assertBodyShape(string $text, array $examples): void
    {
        if (preg_match('/^\s*\{\{\s*\d+\s*\}\}/', $text)) {
            throw new LogicException(
                "Template '{$this->name}': the body cannot START with a variable "
                .'(Meta rejects it, code 100). Put fixed text before it.'
            );
        }

        if (preg_match('/\{\{\s*\d+\s*\}\}\s*$/', $text)) {
            throw new LogicException(
                "Template '{$this->name}': the body cannot END with a variable "
                .'(Meta rejects it, code 100). Add a fixed line after it.'
            );
        }

        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);
        $maxIndex = $matches[1] ? max(array_map('intval', $matches[1])) : 0;

        if ($examples !== [] && count($examples) !== $maxIndex) {
            throw new LogicException(
                "Template '{$this->name}': the body has {$maxIndex} variable(s), but "
                .count($examples).' example(s) were passed. They must match 1:1.'
            );
        }

        foreach ($examples as $value) {
            if (preg_match('/[\n\t]|\s{4,}/', (string) $value)) {
                throw new LogicException(
                    "Template '{$this->name}': the example \"{$value}\" contains a line "
                    .'break, tab or 4+ spaces — Meta forbids that in a variable.'
                );
            }
        }
    }

    /**
     * Text header (optional). Accepts at most one variable {{1}}.
     *
     * @param  array<int, string|int|float>  $examples
     */
    public function headerText(string $text, array $examples = []): self
    {
        $component = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $text];

        if ($examples !== []) {
            $component['example'] = [
                'header_text' => array_values(array_map('strval', $examples)),
            ];
        }

        $this->components[] = $component;

        return $this;
    }

    /** Footer (optional, no variables — Meta rule). */
    public function footer(string $text): self
    {
        $this->components[] = ['type' => 'FOOTER', 'text' => $text];

        return $this;
    }

    /** Quick-reply button (the user taps it and you receive it on the webhook). */
    public function quickReply(string $text): self
    {
        return $this->addButton(['type' => 'QUICK_REPLY', 'text' => $text]);
    }

    public function urlButton(string $text, string $url): self
    {
        return $this->addButton(['type' => 'URL', 'text' => $text, 'url' => $url]);
    }

    public function phoneButton(string $text, string $phoneNumber): self
    {
        return $this->addButton(['type' => 'PHONE_NUMBER', 'text' => $text, 'phone_number' => $phoneNumber]);
    }

    /**
     * @param  array<string, mixed>  $button
     */
    private function addButton(array $button): self
    {
        foreach ($this->components as $index => $component) {
            if (($component['type'] ?? null) === 'BUTTONS') {
                $this->components[$index]['buttons'][] = $button;

                return $this;
            }
        }

        $this->components[] = ['type' => 'BUTTONS', 'buttons' => [$button]];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->components === []) {
            throw new LogicException(
                "Template '{$this->name}' has no components. Add at least ->body()."
            );
        }

        return [
            'name' => $this->name,
            'language' => $this->language,
            'category' => $this->category,
            'components' => $this->components,
        ];
    }
}
