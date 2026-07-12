<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

/**
 * Reads the local template definition files — the `<name>.php` payloads under
 * `whatsapp-cloud.definitions_path` that `whatsapp:template:create` submits.
 *
 * These exist BEFORE the template does. That is the whole point: it lets the
 * sandbox render a template's real body and real buttons while the template is
 * still a file in a branch, so a flow can be rehearsed and fixed before the name
 * is burned on Meta (`create` is one-way — resubmitting the same name+language
 * fails, and there is no CLI edit or delete).
 *
 * Indexed by the payload's own `name` + `language`, not by the file name: the
 * create command takes the FILE name, but what Meta registers is `payload['name']`,
 * and the two are free to disagree.
 */
final class TemplateDefinitions
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $index = null;

    public function __construct(private readonly ?string $path = null) {}

    /**
     * The definition payload for a template, or null when there is no file for it.
     *
     * @return array<string, mixed>|null `['name', 'language', 'category', 'components']`
     */
    public function find(string $name, string $language): ?array
    {
        return $this->index()[$this->key($name, $language)] ?? null;
    }

    /**
     * Every definition on disk — what the sandbox UI offers you to send.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->index());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $path = $this->path ?? (string) config('whatsapp-cloud.definitions_path', '');

        if ($path === '' || ! is_dir($path)) {
            return $this->index = [];
        }

        $index = [];

        foreach ((array) glob(rtrim($path, '/').'/*.php') as $file) {
            if (! is_string($file)) {
                continue;
            }

            $payload = require $file;

            // A definition file that returns something else is the app's bug to
            // fix, not ours to guess at. Skip it rather than crash the sandbox.
            if (! is_array($payload) || ! is_string($payload['name'] ?? null)) {
                continue;
            }

            $language = is_string($payload['language'] ?? null) ? $payload['language'] : 'pt_BR';

            $index[$this->key($payload['name'], $language)] = $payload;
        }

        return $this->index = $index;
    }

    private function key(string $name, string $language): string
    {
        return $name.'|'.$language;
    }
}
