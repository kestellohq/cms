<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Make;

/**
 * Scaffold a new model class.
 */
final class MakeModelCommand extends GeneratorCommand
{
    protected string $name = 'make:model';
    protected string $description = 'Create a new model class';


    public function handle(array $arguments, array $options): int
    {
        if (!$this->target($options)) {
            return 1;
        }

        $name = $this->argument($arguments, 0);

        if ($name === null) {
            $this->output->error('Usage: make:model <Name>');
            return 1;
        }

        $dir = $this->plugin['src'] . '/Models';
        $path = "{$dir}/{$name}.php";

        if (file_exists($path)) {
            $this->output->error("Model already exists: {$path}");
            return 1;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Derive table name: "UserProfile" -> "user_profiles"
        $snake = strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($name)));
        $plural = $snake . 's';
        $table = $this->plugin['prefix'] . $plural;

        $content = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$this->plugin['namespace']}Models;

        use Kestello\Framework\Database\Model;

        final class {$name} extends Model
        {
            protected string \$table = '{$table}';

            /** Columns create()/fill() may set; anything else throws. */
            protected array \$fillable = [
                //
            ];

            /** e.g. 'data' => 'array', 'published_at' => 'datetime', 'secret' => 'encrypted' */
            protected array \$casts = [
                //
            ];

            protected array \$hidden = [
                //
            ];

            // Relations are public methods with a relation return type:
            // public function author(): \\Kestello\\Framework\\Database\\Orm\\Relations\\BelongsTo { return \$this->belongsTo(\\Kestello\\Cms\\Models\\User::class, 'author_id'); }
        }
        PHP;

        file_put_contents($path, $content);

        $this->output->success("  Created: {$this->plugin['rel']}/src/Models/{$name}.php");

        // Optionally create a migration
        if ($this->hasFlag($options, 'migration') || $this->hasFlag($options, 'm')) {
            $migrationName = "create_{$plural}_table";
            $this->createMigration($migrationName, $table);
        }

        return 0;
    }

    private function createMigration(string $migrationName, string $table): void
    {
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$migrationName}.php";

        $dir = $this->plugin['root'] . '/database/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = <<<PHP
        <?php

        declare(strict_types=1);

        use Kestello\Framework\Database\Migration;
        use Kestello\Framework\Database\Blueprint;

        return new class extends Migration
        {
            public function up(): void
            {
                \$this->schema()->create('{$table}', function (Blueprint \$table): void {
                    \$table->id();
                    \$table->timestamps();
                });
            }

            public function down(): void
            {
                \$this->schema()->dropIfExists('{$table}');
            }
        };
        PHP;

        file_put_contents("{$dir}/{$filename}", $content);

        $this->output->success("  Created: {$this->plugin['rel']}/database/migrations/{$filename}");
    }
}
