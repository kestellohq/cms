<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Platform;

use Closure;
use Kestello\Cms\Platform\PlatformManager;
use Kestello\Framework\Console\Command;

abstract class PlatformCommand extends Command
{
    /** @param Closure(): PlatformManager $manager */
    public function __construct(private readonly Closure $manager) {}

    protected function manager(): PlatformManager
    {
        return ($this->manager)();
    }

    protected function requireSubsystem(?string $name): ?string
    {
        $known = array_keys($this->manager()->registry()->subsystems());
        if ($name === null || !in_array($name, $known, true)) {
            $this->output->error($name === null ? 'Name a subsystem.' : "Unknown subsystem \"{$name}\".");
            $this->output->muted('  Subsystems: ' . implode(', ', $known));
            return null;
        }

        return $name;
    }

    /**
     * Parse "key=value" arguments. "key=@env:NAME" reads the value from an
     * environment variable, keeping secrets out of shell history.
     *
     * @param list<string> $arguments
     * @return array<string, string>|null
     */
    protected function parseOptions(array $arguments): ?array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (!str_contains($argument, '=')) {
                $this->output->error("Expected key=value, got \"{$argument}\".");
                return null;
            }
            [$key, $value] = explode('=', $argument, 2);
            if (str_starts_with($value, '@env:')) {
                $env = getenv(substr($value, 5));
                if ($env === false) {
                    $this->output->error('Environment variable ' . substr($value, 5) . ' is not set.');
                    return null;
                }
                $value = $env;
            }
            $options[trim($key)] = $value;
        }

        return $options;
    }

    /** @param array<string, list<string>> $errors */
    protected function printErrors(array $errors): void
    {
        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $this->output->muted("  {$field}: {$message}");
            }
        }
    }
}
