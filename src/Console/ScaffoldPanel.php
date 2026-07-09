<?php

namespace Callcocam\WhatsAppCloud\Console;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Copy the NATIVE (design-system) template-panel pages into the host's
 * `resources/js/pages/WhatsAppCloud`, following the shadcn model: the code lands
 * in the host's tree so it resolves the host aliases (`@/components/ui/*`,
 * `@/layouts/AppLayout.vue`, `@lucide/vue`, `vue-sonner`) at build time. From
 * then on the host owns the page; the package keeps owning the backend and the
 * frozen props contract, so package upgrades never force a re-copy.
 *
 * Apps without a design system don't need this — they publish the self-contained
 * fallback page via `vendor:publish --tag=whatsapp-cloud-inertia`.
 */
class ScaffoldPanel extends Command
{
    protected $signature = 'whatsapp:panel:scaffold {--force : Overwrite existing files}';

    protected $description = 'Copy the native (shadcn-vue) template-panel pages into the host resources/js/pages';

    /** Source of the native stubs, relative to this file. */
    private function stubRoot(): string
    {
        return __DIR__.'/../../resources/stubs/inertia-native/WhatsAppCloud';
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $source = $this->stubRoot();

        if (! is_dir($source)) {
            $this->components->error("Native stubs not found at {$source}.");

            return self::FAILURE;
        }

        $target = resource_path('js/pages/WhatsAppCloud');

        $copied = 0;
        $skipped = 0;

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            // Path under the WhatsAppCloud root, with the `.stub` suffix dropped.
            $relative = Str::after($file->getPathname(), $source.DIRECTORY_SEPARATOR);
            $relative = Str::endsWith($relative, '.stub') ? Str::beforeLast($relative, '.stub') : $relative;

            $destination = $target.DIRECTORY_SEPARATOR.$relative;

            if (! $force && file_exists($destination)) {
                $this->components->twoColumnDetail("js/pages/WhatsAppCloud/{$relative}", '<fg=yellow>skipped (exists)</>');
                $skipped++;

                continue;
            }

            if (! is_dir($dir = dirname($destination))) {
                mkdir($dir, 0755, true);
            }

            copy($file->getPathname(), $destination);
            $this->components->twoColumnDetail("js/pages/WhatsAppCloud/{$relative}", '<fg=green>copied</>');
            $copied++;
        }

        $this->newLine();
        $this->components->info("Native panel scaffolded ({$copied} copied, {$skipped} skipped).");

        if ($skipped > 0 && ! $force) {
            $this->components->warn('Some files already existed — re-run with --force to overwrite them.');
        }

        $this->components->bulletList([
            'Point the panel at the native page: set `WHATSAPP_CLOUD_PANEL_COMPONENT=WhatsAppCloud/Templates/Index` (or `panel.component` in config/whatsapp-cloud.php).',
            'Ensure the host has shadcn-vue (@/components/ui/*), @lucide/vue, vue-sonner and @/layouts/AppLayout.vue.',
            'Wire success toasts: render `flash.toast` through vue-sonner in your Inertia setup.',
            'Restrict access: set `WHATSAPP_CLOUD_PANEL_GATE` to an authorization gate — the panel mutates the shared WABA.',
            'Compile the pages: `npm run build` (and typecheck with `vue-tsc --noEmit`).',
        ]);

        return self::SUCCESS;
    }
}
