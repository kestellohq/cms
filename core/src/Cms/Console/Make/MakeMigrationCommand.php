<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Make;

/**
 * Scaffold a new migration file.
 */
final class MakeMigrationCommand extends GeneratorCommand
{
    protected string $name = 'make:migration';
    protected string $description = 'Create a new migration file';


    public function handle(array $arguments, array $options): int
    {
        if (!$this->target($options)) {
            return 1;
        }

        $name = $this->argument($arguments, 0);

        if ($name === null) {
            $this->output->error('Usage: make:migration <name>');
            $this->output->muted('  Example: make:migration create_posts_table');
            return 1;
        }

        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";

        $dir = $this->plugin['root'] . '/database/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = "{$dir}/{$filename}";

        if (file_exists($path)) {
            $this->output->error("Migration already exists: {$path}");
            return 1;
        }

        // Try to guess table name from migration name
        $guessed = $this->guessTableName($name);
        $table = $guessed !== null ? $this->plugin['prefix'] . $guessed : null;

        $upBody = $table !== null
            ? $this->createTableBody($table)
            : '        // $this->schema()->...';

        $downBody = $table !== null
            ? "        \$this->schema()->dropIfExists('{$table}');"
            : '        // Reverse the migration';

        $content = <<<PHP
        <?php

        declare(strict_types=1);

        use Kestello\Framework\Database\Migration;
        use Kestello\Framework\Database\Blueprint;

        return new class extends Migration
        {
            public function up(): void
            {
        {$upBody}
            }

            public function down(): void
            {
        {$downBody}
            }
        };
        PHP;

        file_put_contents($path, $content);

        $this->output->success("  Created: {$this->plugin['rel']}/database/migrations/{$filename}");

        return 0;
    }

    private function guessTableName(string $name): ?string
    {
        if (preg_match('/^create_(.+)_table$/', $name, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function createTableBody(string $table): string
    {
        return <<<BODY
                \$this->schema()->create('{$table}', function (Blueprint \$table): void {
                    \$table->id();
                    \$table->timestamps();
                });
        BODY;
    }
}
