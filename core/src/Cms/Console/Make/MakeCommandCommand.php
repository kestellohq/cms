<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Make;

/**
 * Scaffold a new custom console command.
 */
final class MakeCommandCommand extends GeneratorCommand
{
    protected string $name = 'make:command';
    protected string $description = 'Create a new console command';


    public function handle(array $arguments, array $options): int
    {
        if (!$this->target($options)) {
            return 1;
        }

        $name = $this->argument($arguments, 0);

        if ($name === null) {
            $this->output->error('Usage: make:command <Name>');
            return 1;
        }

        // Ensure it ends with "Command"
        if (!str_ends_with($name, 'Command')) {
            $name .= 'Command';
        }

        // Derive a default command signature from the class name
        // e.g. "SendNewsletterCommand" -> "app:send-newsletter"
        $signature = $this->option($options, 'name') ?? $this->deriveSignature($name);

        $dir = $this->plugin['src'] . '/Commands';
        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            $this->output->error("Command already exists: {$path}");
            return 1;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$this->plugin['namespace']}Commands;

        
        final class {$name} extends GeneratorCommand
        {
            protected string \$name = '{$signature}';
            protected string \$description = '';

            public function handle(array \$arguments, array \$options): int
            {
                \$this->output->info('Running {$signature}...');

                // Your command logic here

                \$this->output->success('Done.');

                return 0;
            }
        }
        PHP;

        file_put_contents($path, $content);

        $this->output->success("  Created: {$this->plugin['rel']}/src/Commands/{$name}.php");
        $this->output->muted("  Register it in your plugin provider's boot(): \$cms->console->command(Commands\\{$name}::class);");
        $this->output->muted("  Signature: {$signature}");

        return 0;
    }

    /**
     * Derive a command signature from a class name.
     * "SendNewsletterCommand" -> "app:send-newsletter"
     */
    private function deriveSignature(string $className): string
    {
        $name = preg_replace('/Command$/', '', $className);
        $kebab = strtolower(preg_replace('/[A-Z]/', '-$0', lcfirst($name)));

        return 'app:' . $kebab;
    }
}
