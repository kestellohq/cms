<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Cms;
use Kestello\Cms\Seed\SeedException;
use Kestello\Cms\Seed\SeedFolder;
use Kestello\Cms\Seed\SiteSeeder;
use Kestello\Framework\Console\Command;

/**
 * site:seed <folder> [--as=username] [--dry-run]
 *
 * Fills an empty site from a seed folder: pages, media, menus, settings and
 * the front page (Seed\SeedFolder describes the files). --dry-run does the
 * whole seed and rolls it back, so CI can check a seed against a fresh site.
 */
final class SeedCommand extends Command
{
    protected string $name = 'site:seed';
    protected string $description = 'Fill an empty site from a seed folder of pages, media, menus and settings: site:seed <folder> [--as=user] [--dry-run]';

    public function handle(array $arguments, array $options): int
    {
        $folder = $this->argument($arguments, 0);
        if ($folder === null) {
            $this->output->error('  Usage: site:seed <folder> [--as=username] [--dry-run]');
            return 1;
        }
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $seeder = new SiteSeeder($cms);

        try {
            $plan = SeedFolder::read($folder);
        } catch (SeedException $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }
        $this->output->label('Pages', (string) count($plan['pages']));
        $this->output->label('Media', (string) count($plan['media']));
        $this->output->label('Menus', (string) count($plan['menus']));

        [$user, $error] = CommandUser::find($this->option($options, 'as'), 'seed sites', 'seeds it');
        if ($user === null) {
            $this->output->error('  ' . $error);
            return 1;
        }
        $principal = $cms->auth->principalFor($user, 'cli');

        if ($this->hasFlag($options, 'dry-run')) {
            $problems = $seeder->check($plan, $principal);
            foreach ($problems as $problem) {
                $this->output->error('  ' . $problem);
            }
            if ($problems !== []) {
                return 1;
            }
            $this->output->success('  The seed goes into this site. Dry run: nothing was written.');
            return 0;
        }

        try {
            $summary = $seeder->seed($plan, $principal);
        } catch (SeedException $e) {
            foreach (explode("\n", $e->getMessage()) as $line) {
                $this->output->error('  ' . $line);
            }
            return 1;
        }

        $this->output->success("  Seeded {$summary['pages']} pages, {$summary['media']} files, {$summary['menus']} menus and {$summary['settings']} settings.");
        foreach ($summary['unassigned'] as $menu) {
            $this->output->muted("  The theme has no location for the menu \"{$menu}\"; it was made and can be placed under Appearance → Menus.");
        }

        return 0;
    }
}
