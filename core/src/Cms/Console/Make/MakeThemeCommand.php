<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Make;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Paths;

/**
 * make:theme <slug> [--parent=base] [--name="Display Name"]
 *
 * With --parent, creates a child theme that inherits every template and
 * only overrides what you add.
 */
final class MakeThemeCommand extends Command
{
    protected string $name = 'make:theme';
    protected string $description = 'Scaffold a new theme (or child theme with --parent=<slug>)';

    public function __construct(
        private readonly Paths $paths,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $slug = $this->argument($arguments, 0);

        if ($slug === null || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $this->output->error('  Usage: make:theme <slug> [--parent=base]');
            return 1;
        }

        $dir = $this->paths->themes() . '/' . $slug;
        if (file_exists($dir)) {
            $this->output->error("  content/themes/{$slug} already exists.");
            return 1;
        }

        $parent = $this->option($options, 'parent');
        $name = $this->option($options, 'name', ucwords(str_replace('-', ' ', $slug))) ?? $slug;

        mkdir("{$dir}/resources/js/pages", 0775, true);
        mkdir("{$dir}/assets/css", 0775, true);

        $manifest = array_filter([
            'slug' => $slug,
            'name' => $name,
            'version' => '0.1.0',
            'requires' => ['core' => MakePluginCommand::coreConstraint()],
            'parent' => $parent,
            'stylesheets' => ['css/theme.css'],
        ], static fn($v) => $v !== null);
        file_put_contents("{$dir}/theme.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        if ($parent === null) {
            mkdir("{$dir}/resources/js/layouts", 0775, true);
            file_put_contents("{$dir}/resources/js/layouts/Site.vue", <<<'VUE'
            <script setup>
            import { computed } from 'vue';
            import { Link, usePage } from '@kestello/app';

            const page = usePage();
            const site = computed(() => page.props.site?.name ?? '');
            </script>

            <template>
                <header><Link href="/">{{ site }}</Link></header>
                <main><slot /></main>
            </template>

            VUE);
            file_put_contents("{$dir}/resources/js/pages/Home.vue", <<<'VUE'
            <script>
            export default { layout: 'Site' };
            </script>

            <template>
                <h1>Hello from your new theme</h1>
            </template>

            VUE);
            file_put_contents("{$dir}/assets/css/theme.css", "body { font-family: system-ui, sans-serif; margin: 2rem; }\n");
        } else {
            touch("{$dir}/resources/js/pages/.gitkeep");
            file_put_contents("{$dir}/assets/css/theme.css", "/* Child theme styles */\n");
        }

        $this->output->success("  Created content/themes/{$slug}" . ($parent !== null ? " (child of {$parent})" : ''));
        $this->output->muted($parent !== null
            ? "  Copy any page from content/themes/{$parent}/resources/js into resources/js/ to override it."
            : '  Pages live in resources/js/pages, layouts in resources/js/layouts (Site.vue is the shell).');
        $this->output->muted('  Build it: npm run build');
        $this->output->muted("  Activate it: php bin/cms theme:activate {$slug}");

        return 0;
    }
}
