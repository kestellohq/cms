<?php

declare(strict_types=1);

namespace Kestello\Framework\Console;

/**
 * Console application — parses argv, resolves commands, and runs them.
 */
final class Application
{
    /** @var array<string, Command> Registered commands keyed by name. */
    private array $commands = [];

    private readonly Output $output;

    public function __construct(
        private readonly string $basePath,
    ) {
        $this->output = new Output();
    }

    /**
     * Register a command instance.
     */
    public function register(Command $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    /**
     * Auto-discover and register command classes from a directory.
     *
     * Scans the directory for PHP files, requires them, and registers
     * any class that extends Command.
     *
     * @param string $directory Absolute path to scan.
     * @param string $namespace The PSR-4 namespace for classes in that directory.
     */
    public function discover(string $directory, string $namespace): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . '/*.php');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $className = $namespace . '\\' . pathinfo($file, PATHINFO_FILENAME);

            require_once $file;

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);

            if ($reflection->isAbstract() || !$reflection->isSubclassOf(Command::class)) {
                continue;
            }

            $command = $reflection->newInstance();
            $this->register($command);
        }
    }

    /**
     * Parse argv and run the matched command.
     *
     * @param array<string> $argv The raw $argv from PHP CLI.
     *
     * @return int Exit code.
     */
    public function run(array $argv): int
    {
        // Remove the script name
        array_shift($argv);

        $commandName = array_shift($argv) ?? 'list';

        if ($commandName === 'list' || $commandName === '--help' || $commandName === '-h') {
            return $this->showList();
        }

        if (!isset($this->commands[$commandName])) {
            $this->output->error("Command \"{$commandName}\" not found.");
            $this->output->newLine();
            $this->suggestCommand($commandName);
            return 1;
        }

        $command = $this->commands[$commandName];
        $command->setOutput($this->output);

        [$arguments, $options] = $this->parseArguments($argv);

        if (isset($options['help'])) {
            $this->output->heading($command->getName());
            $this->output->line('  ' . $command->getDescription());
            $this->output->newLine();
            return 0;
        }

        try {
            return $command->handle($arguments, $options);
        } catch (\Throwable $e) {
            $this->output->error("Error: {$e->getMessage()}");
            $this->output->muted("  in {$e->getFile()}:{$e->getLine()}");
            return 1;
        }
    }

    /**
     * Display all registered commands.
     */
    private function showList(): int
    {
        $this->output->heading('Kestello CLI ' . \Kestello\Kernel::version());
        $this->output->newLine();
        $this->output->info('Usage:');
        $this->output->line('  php bin/cms <command> [arguments] [options]');
        $this->output->newLine();
        $this->output->info('Available commands:');

        // Group commands by prefix
        $groups = [];
        foreach ($this->commands as $name => $command) {
            if ($command->isHidden()) {
                continue;
            }
            $parts = explode(':', $name, 2);
            $group = count($parts) > 1 ? $parts[0] : '';
            $groups[$group][$name] = $command;
        }

        // Sort groups
        ksort($groups);

        // Calculate max name length for alignment
        $maxLen = 0;
        foreach ($this->commands as $name => $_) {
            $maxLen = max($maxLen, mb_strlen($name));
        }

        foreach ($groups as $group => $cmds) {
            if ($group !== '') {
                $this->output->newLine();
                $this->output->heading(" {$group}");
            }

            ksort($cmds);
            foreach ($cmds as $name => $cmd) {
                $padded = str_pad($name, $maxLen + 2);
                $this->output->label($padded, $cmd->getDescription());
            }
        }

        $this->output->newLine();

        return 0;
    }

    /**
     * Suggest a similar command name.
     */
    private function suggestCommand(string $input): void
    {
        $closest = null;
        $shortest = PHP_INT_MAX;

        foreach (array_keys($this->commands) as $name) {
            $distance = levenshtein($input, $name);
            if ($distance < $shortest && $distance <= 3) {
                $shortest = $distance;
                $closest = $name;
            }
        }

        if ($closest !== null) {
            $this->output->info("Did you mean: {$closest}?");
            $this->output->newLine();
        }
    }

    /**
     * Parse CLI tokens into positional arguments and named options.
     *
     * Supports:
     *   --key=value    → options['key'] = 'value'
     *   --flag         → options['flag'] = 'true'
     *   -f             → options['f'] = 'true'
     *   anything else  → arguments[]
     *
     * @return array{0: array<string>, 1: array<string, string>}
     */
    private function parseArguments(array $argv): array
    {
        $arguments = [];
        $options = [];

        foreach ($argv as $token) {
            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
                if (str_contains($token, '=')) {
                    [$key, $value] = explode('=', $token, 2);
                    $options[$key] = $value;
                } else {
                    $options[$token] = 'true';
                }
            } elseif (str_starts_with($token, '-')) {
                $options[substr($token, 1)] = 'true';
            } else {
                $arguments[] = $token;
            }
        }

        return [$arguments, $options];
    }
}
