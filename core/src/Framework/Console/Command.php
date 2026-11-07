<?php

declare(strict_types=1);

namespace Kestello\Framework\Console;

/**
 * Base class for all console commands.
 */
abstract class Command
{
    /** The command signature (e.g. "migrate:rollback"). */
    protected string $name = '';

    /** A short description shown in the command list. */
    protected string $description = '';

    /** Internal commands (run by Kestello itself) stay out of `list`. */
    protected bool $hidden = false;

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    /** Output helper — injected before handle() is called. */
    protected Output $output;

    /**
     * Execute the command.
     *
     * @param array<string>        $arguments Positional arguments (after the command name).
     * @param array<string, string> $options   Named options (--key=value or --flag).
     *
     * @return int Exit code (0 = success).
     */
    abstract public function handle(array $arguments, array $options): int;

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setOutput(Output $output): void
    {
        $this->output = $output;
    }

    /**
     * Get a positional argument by index with an optional default.
     */
    protected function argument(array $arguments, int $index, ?string $default = null): ?string
    {
        return $arguments[$index] ?? $default;
    }

    /**
     * Get a named option value.
     */
    protected function option(array $options, string $key, ?string $default = null): ?string
    {
        return $options[$key] ?? $default;
    }

    /**
     * Check if a flag option is present.
     */
    protected function hasFlag(array $options, string $key): bool
    {
        return array_key_exists($key, $options);
    }
}
