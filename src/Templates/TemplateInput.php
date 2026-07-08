<?php

namespace Callcocam\WhatsAppCloud\Templates;

use InvalidArgumentException;

/**
 * Turns a flat array (a web form, a JSON body, …) into a {@see TemplateBuilder},
 * reusing the builder's guard-rails.
 *
 * Expected input shape:
 *   [
 *     'name' => 'coordena_lembrete', 'language' => 'pt_BR', 'category' => 'UTILITY',
 *     'header' => 'optional text', 'headerExamples' => ['Fulano'],
 *     'body' => 'Olá, {{1}}! ...', 'bodyExamples' => ['Maria'],
 *     'footer' => 'Coordena',
 *     'buttons' => [
 *         ['type' => 'QUICK_REPLY', 'text' => 'Confirmar'],
 *         ['type' => 'URL', 'text' => 'Abrir', 'url' => 'https://...'],
 *         ['type' => 'PHONE_NUMBER', 'text' => 'Ligar', 'phone_number' => '+55...'],
 *     ],
 *   ]
 */
final class TemplateInput
{
    /**
     * @param  array<string, mixed>  $in
     */
    public static function toBuilder(array $in): TemplateBuilder
    {
        $name = trim((string) ($in['name'] ?? ''));
        $language = (string) ($in['language'] ?? 'pt_BR');
        $category = (string) ($in['category'] ?? 'UTILITY');

        if (! preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException(
                'Invalid name: use only lowercase letters, numbers and _ (e.g. coordena_lembrete).'
            );
        }

        if (strlen($name) > 512) {
            throw new InvalidArgumentException('Name too long (max 512 chars).');
        }

        $builder = TemplateBuilder::make($name, $language, $category);

        $header = trim((string) ($in['header'] ?? ''));
        if ($header !== '') {
            $builder->headerText($header, self::normalizeExamples($in['headerExamples'] ?? []));
        }

        $body = (string) ($in['body'] ?? '');
        if (trim($body) === '') {
            throw new InvalidArgumentException('The template body is required.');
        }
        $builder->body($body, self::normalizeExamples($in['bodyExamples'] ?? []));

        $footer = trim((string) ($in['footer'] ?? ''));
        if ($footer !== '') {
            $builder->footer($footer);
        }

        foreach ((array) ($in['buttons'] ?? []) as $btn) {
            if (! is_array($btn)) {
                continue;
            }
            $type = strtoupper((string) ($btn['type'] ?? ''));
            $text = trim((string) ($btn['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            switch ($type) {
                case 'QUICK_REPLY':
                    $builder->quickReply($text);
                    break;
                case 'URL':
                    $url = trim((string) ($btn['url'] ?? ''));
                    if ($url === '') {
                        throw new InvalidArgumentException("URL button '{$text}' has no address.");
                    }
                    $builder->urlButton($text, $url);
                    break;
                case 'PHONE_NUMBER':
                    $phone = trim((string) ($btn['phone_number'] ?? ''));
                    if ($phone === '') {
                        throw new InvalidArgumentException("Phone button '{$text}' has no number.");
                    }
                    $builder->phoneButton($text, $phone);
                    break;
                default:
                    throw new InvalidArgumentException("Unknown button type: '{$type}'.");
            }
        }

        return $builder;
    }

    /**
     * Full payload (name, language, category, components) — used to create.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public static function toPayload(array $in): array
    {
        return self::toBuilder($in)->toArray();
    }

    /**
     * Normalize variable examples: a reindexed list of strings.
     *
     * @return array<int, string>
     */
    public static function normalizeExamples(mixed $examples): array
    {
        if (! is_array($examples)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $examples));
    }
}
