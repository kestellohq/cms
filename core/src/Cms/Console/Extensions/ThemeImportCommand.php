<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Extensions;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Design\ThemePackages;
use Kestello\Framework\Console\Command;

/** theme:import <file.zip> [--replace] [--activate] */
final class ThemeImportCommand extends Command
{
    protected string $name = 'theme:import';
    protected string $description = 'Import a theme zip exported from Appearance → Themes (data-only themes)';

    public function handle(array $arguments, array $options): int
    {
        $file = $this->argument($arguments, 0);
        if ($file === null || !is_file($file)) {
            $this->output->error('  Usage: theme:import <file.zip> [--replace] [--activate]');
            return 1;
        }
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        try {
            $result = $cms->container->make(ThemePackages::class)->import($file, $this->hasFlag($options, 'replace'));
            if ($this->hasFlag($options, 'activate')) {
                $cms->extensions()->activateTheme($result['theme']->slug());
            }
        } catch (ContentException $e) {
            $messages = $e->errors !== [] ? array_merge(...array_values($e->errors)) : [$e->getMessage()];
            $this->output->error('  ' . implode("\n  ", $messages));
            return 1;
        } catch (\Throwable $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }
        $theme = $result['theme'];
        $this->output->success('  ' . ($result['replaced'] ? 'Updated' : 'Imported') . " {$theme->manifest->name} {$theme->version()}" . ($result['fonts'] !== [] ? ' with fonts: ' . implode(', ', $result['fonts']) : '') . ($this->hasFlag($options, 'activate') ? ', now active' : ''));

        return 0;
    }
}
