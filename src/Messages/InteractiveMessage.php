<?php

namespace Callcocam\WhatsAppCloud\Messages;

/**
 * A question with a fixed set of options, sent as a Meta interactive list or
 * reply buttons. `selectableCount` carries the multiple-choice semantics (equal
 * to the number of options = free multi-select); Meta lists are single-select,
 * which the Meta adapter reconciles.
 */
final class InteractiveMessage
{
    /**
     * @param  list<string>  $options
     */
    public function __construct(
        public readonly string $body,
        public readonly array $options,
        public readonly int $selectableCount = 1,
        public readonly ?string $header = null,
        public readonly ?string $footer = null,
        public readonly ?string $buttonLabel = null,
    ) {}

    /**
     * A free multiple-choice question (any number of options selectable).
     *
     * @param  list<string>  $options
     */
    public static function multiChoice(string $body, array $options): self
    {
        return new self($body, $options, count($options));
    }
}
