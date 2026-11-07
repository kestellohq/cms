<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Extensions;

use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\AppKey;

/**
 *   plugin:config social-login                                    list settings (secrets masked)
 *   plugin:config social-login google.enabled=1 google.client_id=abc
 *   plugin:config social-login --secret=google.client_secret     value from stdin (never in shell history)
 *   plugin:config social-login --unset=google.client_id
 */
final class PluginConfigCommand extends Command
{
    protected string $name = 'plugin:config';
    protected string $description = 'Show or change plugin settings: plugin:config <slug> [key=value ...] [--secret=key] [--unset=key]';

    public function __construct(private readonly ExtensionManager $extensions) {}

    public function handle(array $arguments, array $options): int
    {
        $slug = $this->argument($arguments, 0);
        $package = $slug !== null ? ($this->extensions->plugins()[$slug] ?? null) : null;
        if ($package === null) {
            $this->output->error('  Usage: plugin:config <installed-plugin-slug> [key=value ...] [--secret=key] [--unset=key]');
            return 1;
        }

        $settings = Settings::getInstance();
        $prefix = $package->settingsPrefix();
        $group = rtrim($prefix, '.');
        $valid = static fn(string $k): bool => preg_match('/^[a-z0-9_][a-z0-9_.-]{0,100}$/', $k) === 1;
        $changed = false;

        foreach (array_slice($arguments, 1) as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, null);
            if ($value === null || !$valid((string) $key)) {
                $this->output->error("  Expected key=value, got \"{$pair}\"");
                return 1;
            }
            $settings->set($prefix . $key, $value, $group);
            $changed = true;
        }

        if (($key = $this->option($options, 'unset')) !== null) {
            $settings->delete($prefix . $key);
            $changed = true;
        }

        if (($key = $this->option($options, 'secret')) !== null) {
            if (!$valid($key)) {
                $this->output->error('  Invalid key.');
                return 1;
            }
            if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
                $this->output->info("  Enter the value for {$key} (input hidden is not available; paste and press Enter):");
            }
            $value = trim((string) fgets(STDIN));
            if ($value === '') {
                $this->output->error('  Empty value: nothing stored.');
                return 1;
            }
            $settings->set($prefix . $key, AppKey::secretStore()->seal($value, 'settings.' . $prefix . $key), $group);
            $changed = true;
        }

        if ($changed) {
            $this->output->success("  Saved settings for {$slug}.");
        }

        $store = AppKey::secretStore();
        $rows = $settings->group($group);
        if ($rows === []) {
            $this->output->info('  (no settings)');
        }
        foreach ($rows as $name => $value) {
            $shown = is_string($value) && $store->isSealed($value) ? '(secret, set)' : (string) $value;
            $this->output->line('  ' . substr((string) $name, strlen($prefix)) . ' = ' . $shown);
        }

        return 0;
    }
}
